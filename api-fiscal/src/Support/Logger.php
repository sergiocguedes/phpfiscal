<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Dois logs em JSON Lines:
 *  - storage/logs/audit.log: quem / quando / o quê / resultado
 *  - storage/logs/app.log:   erros internos (sem segredos, sem payloads)
 */
final class Logger
{
    private const REDACT = '/(senha|password|pass|secret|segredo|token|api[_-]?key|authorization|csc|pfx|certificado_base64|private|cookie|hash)/i';

    /** @param array<string,mixed> $ctx */
    public static function audit(string $event, string $result, array $ctx = []): void
    {
        $line = [
            'ts' => date('c'),
            'request_id' => RequestContext::$requestId,
            'actor' => RequestContext::$actor,
            'tenant' => \App\Tenancy\Tenant::current(),
            'ip' => RequestContext::$ip,
            'event' => $event,
            'result' => $result,
        ] + self::sanitize($ctx);
        self::write('audit.log', $line);
    }

    /** @param array<string,mixed> $ctx */
    public static function error(string $message, array $ctx = []): void
    {
        $line = [
            'ts' => date('c'),
            'level' => 'error',
            'request_id' => RequestContext::$requestId,
            'message' => self::scrub($message),
        ] + self::sanitize($ctx);
        self::write('app.log', $line);
    }

    /** @param array<string,mixed> $ctx */
    public static function info(string $message, array $ctx = []): void
    {
        $line = [
            'ts' => date('c'),
            'level' => 'info',
            'request_id' => RequestContext::$requestId,
            'message' => self::scrub($message),
        ] + self::sanitize($ctx);
        self::write('app.log', $line);
    }

    public static function exception(\Throwable $e, string $where = ''): void
    {
        self::error($where !== '' ? $where : 'exception', [
            'exception' => get_class($e),
            'detail' => self::scrub($e->getMessage()),
            'at' => basename($e->getFile()) . ':' . $e->getLine(),
        ]);
    }

    /**
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    private static function sanitize(array $ctx): array
    {
        $out = [];
        foreach ($ctx as $k => $v) {
            if (preg_match(self::REDACT, (string) $k)) {
                $out[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $out[$k] = self::sanitize($v);
            } elseif (is_string($v)) {
                $out[$k] = self::scrub(mb_substr($v, 0, 1000));
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** Remove valores que pareçam segredos conhecidos do .env. */
    private static function scrub(string $s): string
    {
        foreach (['API_KEY', 'WEBHOOK_INBOUND_SECRET', 'WEBHOOK_OUTBOUND_SECRET', 'CERT_ENCRYPTION_KEY', 'SMTP_PASSWORD', 'DATABASE_PASSWORD'] as $k) {
            try { $v = Env::get($k); } catch (\Throwable) { $v = null; } // configuração ilegível não pode impedir o log
            if ($v !== null && strlen($v) >= 8) {
                $s = str_replace($v, '[redacted]', $s);
            }
        }
        // remove quebras de linha (evita log injection)
        return str_replace(["\r", "\n"], ' ', $s);
    }

    /** @param array<string,mixed> $line */
    private static function write(string $file, array $line): void
    {
        $path = Paths::storage('logs/' . $file);
        $old = umask(0077);
        @file_put_contents($path, json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
        umask($old);
    }
}
