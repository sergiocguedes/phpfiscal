<?php

declare(strict_types=1);

namespace App\Tributos;

/**
 * Municípios do IBGE (código de 7 dígitos, nome e UF) — resources/rtc/municipios.json.
 * Usado para cadastrar alíquotas de IBS/CBS por município e resolver a UF a partir do código.
 */
final class Municipios
{
    /** @var array<string, array{0:string,1:string}>|null */
    private static ?array $lista = null;

    /** @return array<string, array{0:string,1:string}> */
    private static function lista(): array
    {
        if (self::$lista === null) {
            $j = json_decode((string) file_get_contents(APP_ROOT . '/resources/rtc/municipios.json'), true);
            self::$lista = $j['municipios'] ?? [];
        }
        return self::$lista;
    }

    /** @return array{codigo:string, nome:string, uf:string}|null */
    public static function get(string $codigo): ?array
    {
        $m = self::lista()[$codigo] ?? null;
        return $m === null ? null : ['codigo' => $codigo, 'nome' => $m[0], 'uf' => $m[1]];
    }

    /** @return list<array{codigo:string, nome:string, uf:string}> */
    public static function buscar(?string $uf, ?string $termo, int $limite = 50): array
    {
        $t = $termo === null ? '' : self::simples($termo);
        $out = [];
        foreach (self::lista() as $c => [$nome, $u]) {
            if (($uf === null || $u === $uf) && ($t === '' || str_contains(self::simples($nome), $t) || str_starts_with((string) $c, $t))) {
                $out[] = ['codigo' => (string) $c, 'nome' => $nome, 'uf' => $u];
                if (count($out) >= $limite) {
                    break;
                }
            }
        }
        return $out;
    }

    private static function simples(string $s): string
    {
        $s = mb_strtolower(trim($s));
        return strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ü' => 'u', 'ç' => 'c']);
    }
}
