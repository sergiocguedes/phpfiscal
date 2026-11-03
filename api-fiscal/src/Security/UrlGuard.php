<?php

declare(strict_types=1);

namespace App\Security;

use App\Support\Env;

/**
 * Proteção contra SSRF para URLs de webhook de saída.
 *  - apenas http/https, sem usuário/senha embutidos;
 *  - o host é resolvido e TODOS os IPs são verificados;
 *  - bloqueia privado, loopback, link-local, CGNAT, multicast, reservado etc.,
 *    exceto se WEBHOOK_ALLOW_PRIVATE_URLS=true (somente para teste local);
 *  - devolve o IP verificado para ser fixado no cURL (CURLOPT_RESOLVE),
 *    impedindo DNS rebinding entre a verificação e a conexão.
 */
final class UrlGuard
{
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '255.255.255.255/32',
    ];

    /**
     * Valida a URL. Lança \InvalidArgumentException com mensagem segura.
     *
     * @param null|callable(string): list<string> $resolver para testes
     * @return array{url:string, scheme:string, host:string, port:int, ip:string}
     */
    public static function check(string $url, ?callable $resolver = null, ?bool $allowPrivate = null): array
    {
        $allowPrivate ??= Env::bool('WEBHOOK_ALLOW_PRIVATE_URLS', false);
        if (strlen($url) > 2048 || preg_match('/[\s\x00-\x1f\x7f]/', $url)) {
            throw new \InvalidArgumentException('URL inválida');
        }
        $p = parse_url($url);
        if ($p === false || empty($p['scheme']) || empty($p['host'])) {
            throw new \InvalidArgumentException('URL inválida');
        }
        $scheme = strtolower($p['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Somente http e https são permitidos');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new \InvalidArgumentException('Credenciais embutidas na URL não são permitidas');
        }
        $host = strtolower(trim($p['host'], '[]'));
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Porta inválida');
        }

        $pin = self::checkHost($host, $resolver, $allowPrivate);

        return ['url' => $url, 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => $pin];
    }

    
    public static function checkHost(string $host, ?callable $resolver = null, bool $allowPrivate = false): string
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || strlen($host) > 253 || preg_match('/[\s\x00-\x1f\x7f]/', $host)) {
            throw new \InvalidArgumentException('Host inválido');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*\.?$/', $host)) {
                throw new \InvalidArgumentException('Host inválido');
            }
            $ips = $resolver ? $resolver($host) : self::resolve($host);
            if ($ips === []) {
                throw new \InvalidArgumentException('Host não resolve para um endereço IPv4');
            }
        }

        if (!$allowPrivate) {
            foreach ($ips as $ip) {
                if (!self::isPublicIp($ip)) {
                    throw new \InvalidArgumentException('Destino aponta para endereço interno/privado, bloqueado');
                }
            }
        }

        // Fixamos o primeiro IPv4 (conexões forçadas a IPv4).
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ip;
            }
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            throw new \InvalidArgumentException('Destinos IPv6 não são suportados');
        }
        throw new \InvalidArgumentException('Host não resolve para um endereço IPv4');
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $ips = @gethostbynamel($host);
        return $ips === false ? [] : array_values(array_unique($ips));
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // IPv4 mapeado em IPv6 (::ffff:a.b.c.d)
            if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
                return self::isPublicIp($m[1]);
            }
            return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE);
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        $long = ip2long($ip);
        foreach (self::BLOCKED_V4 as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $mask = $bits === '0' ? 0 : (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;
            if (($long & $mask) === (ip2long($net) & $mask)) {
                return false;
            }
        }
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE);
    }
}
