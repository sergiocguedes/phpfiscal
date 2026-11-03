<?php

declare(strict_types=1);

namespace App\Nfse;

/**
 * Tabelas oficiais do Sistema Nacional NFS-e, geradas a partir do
 * ANEXO_B-NBS2-LISTA_SERVICO_NACIONAL-SNNFSe-v1.01-20260122 (gov.br/nfse) e guardadas em resources/nfse/:
 *  - lista-servicos-nacional.json: código de tributação nacional (cTribNac, 6 dígitos = item + subitem + desdobro
 *    da LC 116/2003) e descrição;
 *  - nbs.json: códigos NBS de 9 dígitos (cNBS).
 * Usadas para validar a DPS antes do envio (rejeições E0310 e E0316) e para a busca no painel.
 */
final class Listas
{
    /** @var array<string, array{codigo:string, item_lc116:string, descricao:string}>|null */
    private static ?array $servicos = null;
    /** @var array<string, string>|null */
    private static ?array $nbs = null;

    /** @return array<string, array{codigo:string, item_lc116:string, descricao:string}> */
    public static function servicos(): array
    {
        if (self::$servicos === null) {
            $d = json_decode((string) file_get_contents(APP_ROOT . '/resources/nfse/lista-servicos-nacional.json'), true, 8, JSON_THROW_ON_ERROR);
            self::$servicos = [];
            foreach ($d['servicos'] as $s) {
                self::$servicos[$s['codigo']] = $s;
            }
        }
        return self::$servicos;
    }

    /** @return array{codigo:string, item_lc116:string, descricao:string}|null */
    public static function servico(string $codigo): ?array
    {
        return self::servicos()[$codigo] ?? null;
    }

    public static function nbsDescricao(string $codigo): ?string
    {
        if (self::$nbs === null) {
            $d = json_decode((string) file_get_contents(APP_ROOT . '/resources/nfse/nbs.json'), true, 8, JSON_THROW_ON_ERROR);
            self::$nbs = array_column($d['nbs'], 'descricao', 'codigo');
        }
        return self::$nbs[$codigo] ?? null;
    }

    /**
     * Busca na lista nacional por código (com ou sem pontos) ou trecho da descrição.
     * @return list<array{codigo:string, item_lc116:string, descricao:string}>
     */
    public static function buscarServicos(string $q, int $limite = 30): array
    {
        $q = trim(mb_strtolower($q));
        $dig = preg_replace('/\D/', '', $q) ?? '';
        $out = [];
        foreach (self::servicos() as $s) {
            $ok = $q === ''
                || ($dig !== '' && (str_starts_with($s['codigo'], $dig) || str_starts_with(str_replace('.', '', $s['item_lc116']), $dig)))
                || ($dig === '' && str_contains(mb_strtolower($s['descricao']), $q));
            if ($ok) {
                $out[] = $s;
                if (count($out) >= $limite) {
                    break;
                }
            }
        }
        return $out;
    }
}
