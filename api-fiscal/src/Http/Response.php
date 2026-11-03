<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\RequestContext;

final class Response
{
    /**
     * Arquivo enviado por streaming em send() (em vez de $body), para downloads grandes
     * sem carregar tudo em memória. Com $deleteFile o arquivo é apagado após o envio.
     */
    public ?string $file = null;
    public bool $deleteFile = false;

    /** @param array<string,string> $headers */
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public array $headers = [],
    ) {
    }

    /** @param array<string,string> $headers */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        return new self(
            $status,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            ['Content-Type' => 'application/json; charset=utf-8'] + $headers
        );
    }

    /**
     * @param array<mixed> $details
     * @param array<string,string> $headers
     */
    public static function error(int $status, string $code, string $message, array $details = [], array $headers = []): self
    {
        $err = ['codigo' => $code, 'mensagem' => $message, 'request_id' => RequestContext::$requestId];
        if ($details !== []) {
            $err['detalhes'] = $details;
        }
        return self::json(['erro' => $err], $status, $headers);
    }

    /** @param array<string,string> $headers */
    public static function file(string $path, array $headers, bool $deleteAfterSend = false): self
    {
        $r = new self(200, '', $headers + ['Content-Length' => (string) (int) @filesize($path)]);
        $r->file = $path;
        $r->deleteFile = $deleteAfterSend;
        return $r;
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . str_replace(["\r", "\n"], '', $v), true);
        }
        if ($this->file === null) {
            echo $this->body;
            return;
        }
        if ($this->deleteFile) {
            // cliente que desconecta no meio do download aborta o script: apaga no shutdown também
            $f = $this->file;
            register_shutdown_function(static fn() => @unlink($f));
        }
        try {
            readfile($this->file);
        } finally {
            if ($this->deleteFile) {
                @unlink($this->file);
            }
        }
    }
}
