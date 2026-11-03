<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\Env;

final class Request
{
    /** @var array<string,string> */
    public array $params = [];
    private ?array $jsonCache = null;

    /**
     * @param array<string,string> $headers (chaves em minúsculas)
     * @param array<string,mixed> $query
     * @param array<string,mixed> $files
     * @param array<string,mixed> $post
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly string $body,
        public readonly string $ip,
        public readonly array $files = [],
        public readonly array $post = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $path = '/' . trim(rawurldecode($path), '/');
        $isMultipart = str_starts_with(strtolower($headers['content-type'] ?? ''), 'multipart/form-data');
        $body = $isMultipart ? '' : (string) file_get_contents('php://input', false, null, 0, 2 * 1024 * 1024 + 1);
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $headers,
            $body,
            self::clientIp((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), $headers['x-forwarded-for'] ?? null, (string) Env::get('TRUSTED_PROXIES', '')),
            $_FILES,
            $_POST,
        );
    }

    /**
     * IP real do cliente para rate limit, bloqueio e auditoria (OWASP A04/A07/A09).
     * X-Forwarded-For só é considerado quando a conexão vem de um proxy listado em TRUSTED_PROXIES
     * (IPs ou CIDRs separados por vírgula, ex.: o HAProxy). A lista é lida da DIREITA para a esquerda,
     * pulando proxies confiáveis: o primeiro IP não confiável é o cliente. Assim um cliente não
     * consegue forjar o próprio IP mandando X-Forwarded-For (o valor que ele envia fica à esquerda).
     * Sem TRUSTED_PROXIES (padrão), vale REMOTE_ADDR.
     */
    public static function clientIp(string $remote, ?string $xff, string $trusted): string
    {
        $proxies = array_values(array_filter(array_map('trim', explode(',', $trusted)), fn($x) => $x !== ''));
        if ($proxies === [] || $xff === null || $xff === '' || !self::ipIn($remote, $proxies)) {
            return $remote;
        }
        $chain = array_reverse(array_map('trim', explode(',', $xff)));
        foreach ($chain as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return $remote; // cabeçalho malformado: não confia em nada além da conexão
            }
            if (!self::ipIn($ip, $proxies)) {
                return $ip;
            }
        }
        return $remote;
    }

    /** @param list<string> $cidrs IPs ou CIDRs (IPv4/IPv6) */
    public static function ipIn(string $ip, array $cidrs): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach ($cidrs as $c) {
            [$net, $bits] = str_contains($c, '/') ? explode('/', $c, 2) : [$c, null];
            $nbin = @inet_pton($net);
            if ($nbin === false || strlen($nbin) !== strlen($bin)) {
                continue;
            }
            $max = strlen($bin) * 8;
            $bits = $bits === null ? $max : (ctype_digit($bits) ? (int) $bits : -1);
            if ($bits < 0 || $bits > $max) {
                continue;
            }
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;
            if (substr($bin, 0, $bytes) !== substr($nbin, 0, $bytes)) {
                continue;
            }
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($bin[$bytes]) & $mask) === (ord($nbin[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Lê um cookie do cabeçalho Cookie (funciona no servidor e nos testes). */
    public function cookie(string $name): ?string
    {
        $raw = $this->header('cookie');
        if ($raw === null || $raw === '') {
            return null;
        }
        foreach (explode(';', $raw) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2 && $kv[0] === $name) {
                return rawurldecode($kv[1]);
            }
        }
        return null;
    }

    public function isMutating(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    public function queryFlag(string $name): bool
    {
        $v = $this->query[$name] ?? null;
        return is_string($v) && in_array(strtolower($v), ['1', 'true', 'sim', 'yes'], true);
    }

    /** @return array<mixed> */
    public function json(): array
    {
        if ($this->jsonCache !== null) {
            return $this->jsonCache;
        }
        if (strlen($this->body) > 2 * 1024 * 1024) {
            throw new ApiException(413, 'payload_grande', 'Corpo da requisição excede 2 MB');
        }
        $ct = strtolower($this->header('content-type') ?? '');
        if (!str_starts_with($ct, 'application/json')) {
            throw new ApiException(415, 'content_type', 'Use Content-Type: application/json');
        }
        if (trim($this->body) === '') {
            throw new ApiException(400, 'json_invalido', 'Corpo JSON vazio');
        }
        try {
            $data = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new ApiException(400, 'json_invalido', 'JSON malformado');
        }
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new ApiException(400, 'json_invalido', 'O corpo deve ser um objeto JSON');
        }
        return $this->jsonCache = $data;
    }
}
