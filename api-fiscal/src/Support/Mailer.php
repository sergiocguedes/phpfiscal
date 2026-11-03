<?php

declare(strict_types=1);

namespace App\Support;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Envio de e-mail.
 *  - SMTP_HOST vazio => MODO DEV: a mensagem (com o link) é gravada em
 *    storage/logs/mail.log, sem envio. Como o link contém token de redefinição/convite (credencial),
 *    isso só acontece se APP_URL apontar para loopback (instalação local) ou MAIL_DEV_LOG=true for
 *    definido explicitamente. Fora disso a mensagem NÃO é gravada (sem token em log — OWASP A09/A02)
 *    e o envio falha com erro no app.log, para o operador configurar o SMTP.
 *  - SMTP_HOST preenchido => envia via SMTP (PHPMailer), STARTTLS/SMTPS conforme SMTP_SECURE.
 */
final class Mailer
{
    /** Permite capturar mensagens nos testes. @var null|\Closure(string,string,string):void */
    public static ?\Closure $transport = null;

    public static function devMode(): bool
    {
        return Env::get('SMTP_HOST') === null;
    }

    public static function send(string $to, string $subject, string $text): bool
    {
        if (self::$transport !== null) {
            (self::$transport)($to, $subject, $text);
            return true;
        }
        if (self::devMode()) {
            if (!self::devLogAllowed()) {
                Logger::error('mail.smtp_nao_configurado', ['assunto' => $subject]);
                return false;
            }
            $line = "----- " . date('c') . " -----\nPara: $to\nAssunto: $subject\n\n$text\n\n";
            $old = umask(0077);
            $ok = @file_put_contents(Paths::storage('logs/mail.log'), $line, FILE_APPEND | LOCK_EX) !== false;
            umask($old);
            Logger::info('mail.dev_mode', ['assunto' => $subject]);
            return $ok;
        }
        try {
            $m = new PHPMailer(true);
            $m->isSMTP();
            $m->Host = (string) Env::get('SMTP_HOST');
            $m->Port = Env::int('SMTP_PORT', 587);
            $secure = strtolower((string) Env::get('SMTP_SECURE', 'tls'));
            $m->SMTPSecure = $secure === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $m->SMTPAutoTLS = true;
            $user = Env::get('SMTP_USER');
            if ($user !== null) {
                $m->SMTPAuth = true;
                $m->Username = $user;
                $m->Password = (string) Env::get('SMTP_PASSWORD', '');
            }
            $m->Timeout = 15;
            $m->CharSet = 'UTF-8';
            $m->setFrom((string) Env::get('SMTP_FROM', 'no-reply@localhost'), (string) Env::get('SMTP_FROM_NAME', 'Sistema NF-e'));
            $m->addAddress($to);
            $m->Subject = $subject;
            $m->Body = $text;
            $m->isHTML(false);
            $m->send();
            return true;
        } catch (\Throwable $e) {
            Logger::error('mail.falha', ['exception' => get_class($e)]);
            return false;
        }
    }

    /** Pode gravar mensagens (com links/tokens) em mail.log? Só em instalação local ou com opt-in explícito. */
    public static function devLogAllowed(): bool
    {
        if (Env::bool('MAIL_DEV_LOG', false)) {
            return true;
        }
        $host = strtolower((string) parse_url(self::appUrl(), PHP_URL_HOST));
        return in_array(trim($host, '[]'), ['127.0.0.1', 'localhost', '::1'], true);
    }

    /** URL pública da aplicação (nunca derivada do cabeçalho Host). */
    public static function appUrl(): string
    {
        $u = (string) Env::get('APP_URL', 'http://127.0.0.1:8095');
        return rtrim($u, '/');
    }
}
