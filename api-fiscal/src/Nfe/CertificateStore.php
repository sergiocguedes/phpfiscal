<?php

declare(strict_types=1);

namespace App\Nfe;

use App\Http\ApiException;
use App\Security\Crypto;
use App\Storage\JsonStore;
use App\Tenancy\Tenant;
use NFePHP\Common\Certificate;

/**
 * Armazena o certificado A1 (.pfx + senha) do tenant, cifrado, em storage/tenants/<id>/cert.enc.
 * Nem o pfx nem a senha saem por resposta ou log.
 */
final class CertificateStore
{
    private static function file(): string
    {
        return Tenant::path('cert.enc');
    }

    private static function metaFile(): string
    {
        return Tenant::path('cert.meta.json');
    }
    public const MAX_BYTES = 64 * 1024;

    /** @return array<string,mixed> metadados públicos */
    public static function save(string $pfx, string $password): array
    {
        if ($pfx === '' || strlen($pfx) > self::MAX_BYTES) {
            throw ApiException::validation([['campo' => 'certificado', 'mensagem' => 'Arquivo .pfx ausente ou maior que 64 KB']]);
        }
        if ($password === '' || strlen($password) > 256) {
            throw ApiException::validation([['campo' => 'senha', 'mensagem' => 'Senha obrigatória']]);
        }
        try {
            $cert = Certificate::readPfx($pfx, $password);
        } catch (\Throwable) {
            // Muitas A1 brasileiras vêm cifradas com RC2-40/3DES, que o OpenSSL 3 recusa por padrão
            // ("digital envelope routines::unsupported"). Converte para AES e guarda já convertido.
            $modern = self::convertLegacy($pfx, $password);
            try {
                if ($modern === null) {
                    throw new \RuntimeException('legacy');
                }
                $cert = Certificate::readPfx($modern, $password);
                $pfx = $modern;
            } catch (\Throwable) {
                throw ApiException::validation([['campo' => 'certificado', 'mensagem' => 'Não foi possível abrir o certificado (arquivo inválido ou senha incorreta)']]);
            }
        }
        $meta = self::metaFrom($cert);
        if ($meta['expirado']) {
            throw ApiException::validation([['campo' => 'certificado', 'mensagem' => 'Certificado expirado em ' . $meta['valido_ate']]]);
        }
        $blob = Crypto::encrypt(json_encode(['pfx' => base64_encode($pfx), 'senha' => $password], JSON_THROW_ON_ERROR));
        JsonStore::writeRaw(self::file(), $blob);
        $meta['enviado_em'] = date('c');
        JsonStore::write(self::metaFile(), $meta);
        return $meta;
    }

    /**
     * Regrava um .pfx de cifra legada (RC2-40/3DES) com AES-256 via CLI do OpenSSL (-legacy).
     * Tudo por pipe: a senha vai por variável de ambiente do processo filho (nunca na linha de
     * comando) e a chave aberta não toca o disco. Retorna null se não conseguir.
     */
    private static function convertLegacy(string $pfx, string $password): ?string
    {
        $env = ['PFX_PASS' => $password, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'];
        $pem = self::runOpenssl(['openssl', 'pkcs12', '-legacy', '-nodes', '-passin', 'env:PFX_PASS'], $pfx, $env);
        if ($pem === null || !str_contains($pem, 'PRIVATE KEY')) {
            return null;
        }
        // -export lê chave e certificados do mesmo arquivo duas vezes: chave vai pelo fd 3, certificados pelo stdin
        preg_match('/-----BEGIN (?:ENCRYPTED |RSA )?PRIVATE KEY-----.*?-----END (?:ENCRYPTED |RSA )?PRIVATE KEY-----/s', $pem, $k);
        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $c);
        sodium_memzero($pem);
        if (empty($k[0]) || empty($c[0])) {
            return null;
        }
        $out = self::runOpenssl(['openssl', 'pkcs12', '-export', '-inkey', '/dev/fd/3', '-passout', 'env:PFX_PASS',
            '-keypbe', 'AES-256-CBC', '-certpbe', 'AES-256-CBC', '-macalg', 'sha256'], implode("\n", $c[0]) . "\n", $env, $k[0] . "\n");
        sodium_memzero($k[0]);
        return $out === null || $out === '' ? null : $out;
    }

    /** @param list<string> $cmd @param array<string,string> $env */
    private static function runOpenssl(array $cmd, string $stdin, array $env, ?string $fd3 = null): ?string
    {
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        if ($fd3 !== null) {
            $spec[3] = ['pipe', 'r'];
        }
        $p = proc_open($cmd, $spec, $pipes, null, $env);
        if (!is_resource($p)) {
            return null;
        }
        if ($fd3 !== null) {
            fwrite($pipes[3], $fd3);
            fclose($pipes[3]);
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]); // descarta: pode citar detalhes do arquivo, não vai para log
        fclose($pipes[2]);
        return proc_close($p) === 0 && $out !== false ? $out : null;
    }

    /** @return array<string,mixed> */
    private static function metaFrom(Certificate $cert): array
    {
        $to = $cert->getValidTo();
        return [
            'titular' => (string) $cert->getCompanyName(),
            'cnpj' => (string) ($cert->getCnpj() ?: ''),
            'cpf' => (string) ($cert->getCpf() ?: ''),
            'valido_de' => $cert->getValidFrom()->format('c'),
            'valido_ate' => $to->format('c'),
            'expirado' => $cert->isExpired(),
            'icp_brasil' => (bool) $cert->getICP(),
        ];
    }

    /** @return array<string,mixed>|null */
    public static function meta(): ?array
    {
        $m = JsonStore::read(self::metaFile());
        if ($m !== null) {
            $m['expirado'] = strtotime((string) $m['valido_ate']) < time();
        }
        return $m;
    }

    public static function exists(): bool
    {
        return JsonStore::readRaw(self::file()) !== null;
    }

    public static function load(): Certificate
    {
        $blob = JsonStore::readRaw(self::file());
        if ($blob === null) {
            throw new ApiException(412, 'certificado_ausente', 'Certificado A1 não cadastrado (POST /api/v1/certificado)');
        }
        $data = json_decode(Crypto::decrypt($blob), true, 4, JSON_THROW_ON_ERROR);
        $cert = Certificate::readPfx(base64_decode($data['pfx']), $data['senha']);
        if ($cert->isExpired()) {
            throw new ApiException(412, 'certificado_expirado', 'Certificado A1 expirado');
        }
        return $cert;
    }

    public static function delete(): void
    {
        JsonStore::delete(self::file());
        JsonStore::delete(self::metaFile());
    }
}
