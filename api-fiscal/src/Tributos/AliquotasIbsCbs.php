<?php

declare(strict_types=1);

namespace App\Tributos;

use App\Http\ApiException;
use App\Storage\JsonStore;
use App\Support\Errors;

/**
 * Tabela de alíquotas do IBS (UF e município) e da CBS mantida na API pelo administrador (global).
 *
 * Cada linha tem vigência (início e fim opcional) e abrangência: nacional, por UF ou por município (código IBGE).
 * Busca pela data da operação e pelo município de destino (sem destinatário identificado, o do emitente):
 * município → UF → nacional; empate na mesma abrangência fica com o início mais recente.
 * Sem linha cadastrada vale a alíquota de teste de 2026 da LC 214/2025 (0,10% / 0% / 0,90%).
 * Alíquotas enviadas no JSON da operação (aliquotas_ibscbs) têm prioridade sobre a tabela.
 */
final class AliquotasIbsCbs
{
    private const ARQUIVO = 'rtc/aliquotas.json';
    private const LEI_2026 = ['id' => 'LC214-2026', 'abrangencia' => 'nacional', 'uf' => null, 'codigo_municipio' => null,
        'inicio' => '2026-01-01', 'fim' => '2026-12-31', 'pIBSUF' => 0.1, 'pIBSMun' => 0, 'pCBS' => 0.9,
        'descricao' => 'Alíquotas de teste de 2026 (LC 214/2025, arts. 343 e 346)'];
    private const PESO = ['municipio' => 3, 'uf' => 2, 'nacional' => 1];

    /** @return list<array<string,mixed>> linhas gravadas */
    public static function listar(): array
    {
        $l = array_values(JsonStore::read(self::ARQUIVO)['linhas'] ?? []);
        usort($l, fn($a, $b) => [$b['inicio'], self::PESO[$b['abrangencia']]] <=> [$a['inicio'], self::PESO[$a['abrangencia']]]);
        return array_map([self::class, 'comLocal'], $l);
    }

    /**
     * @return array{pIBSUF:string, pIBSMun:string, pCBS:string, origem:array<string,mixed>}|null
     */
    public static function buscar(string $data, ?string $codigoMunicipio): ?array
    {
        $mun = $codigoMunicipio !== null ? Municipios::get($codigoMunicipio) : null;
        $melhor = null;
        foreach ([...(JsonStore::read(self::ARQUIVO)['linhas'] ?? []), self::LEI_2026] as $l) {
            if ($data < $l['inicio'] || ($l['fim'] !== null && $data > $l['fim'])) {
                continue;
            }
            $vale = match ($l['abrangencia']) {
                'nacional' => true,
                'uf' => $mun !== null && $mun['uf'] === $l['uf'],
                'municipio' => $mun !== null && $mun['codigo'] === $l['codigo_municipio'],
                default => false,
            };
            if (!$vale) {
                continue;
            }
            // a linha da lei só vale se nenhuma linha gravada se aplicar
            $chave = [$l['id'] === self::LEI_2026['id'] ? 0 : 1, self::PESO[$l['abrangencia']], $l['inicio']];
            if ($melhor === null || $chave > $melhor[0]) {
                $melhor = [$chave, $l];
            }
        }
        if ($melhor === null) {
            return null;
        }
        $l = $melhor[1];
        return ['pIBSUF' => Dec::de($l['pIBSUF']), 'pIBSMun' => Dec::de($l['pIBSMun']), 'pCBS' => Dec::de($l['pCBS']),
            'origem' => ['tipo' => $l['id'] === self::LEI_2026['id'] ? 'lei' : 'tabela'] + array_intersect_key(self::comLocal($l),
                array_flip(['id', 'abrangencia', 'uf', 'codigo_municipio', 'municipio', 'inicio', 'fim']))];
    }

    /** @param array<mixed> $in @return array<string,mixed> */
    public static function salvar(?string $id, array $in): array
    {
        $l = self::validar($in);
        return JsonStore::update(self::ARQUIVO, function (?array $t) use ($id, $l) {
            $t ??= ['linhas' => []];
            if ($id !== null && !isset($t['linhas'][$id])) {
                throw new ApiException(404, 'nao_encontrado', 'Linha de alíquota não encontrada');
            }
            $l['id'] = $id ?? ('aliq_' . bin2hex(random_bytes(6)));
            foreach ($t['linhas'] as $o) {
                if ($o['id'] !== $l['id'] && $o['abrangencia'] === $l['abrangencia'] && $o['uf'] === $l['uf']
                    && $o['codigo_municipio'] === $l['codigo_municipio'] && $o['inicio'] <= ($l['fim'] ?? '9999-12-31')
                    && $l['inicio'] <= ($o['fim'] ?? '9999-12-31')) {
                    throw new ApiException(409, 'vigencia_sobreposta', "Já existe linha para o mesmo local com vigência sobreposta ({$o['inicio']} a "
                        . ($o['fim'] ?? 'sem fim') . '). Encerre a anterior (data fim) antes de incluir a nova');
                }
            }
            $l['atualizado_em'] = date('c');
            $t['linhas'][$l['id']] = $l;
            return [$t, self::comLocal($l)];
        });
    }

    public static function excluir(string $id): void
    {
        JsonStore::update(self::ARQUIVO, function (?array $t) use ($id) {
            if (!isset($t['linhas'][$id])) {
                throw new ApiException(404, 'nao_encontrado', 'Linha de alíquota não encontrada');
            }
            unset($t['linhas'][$id]);
            return [$t, null];
        });
    }

    /** @param array<mixed> $in @return array<string,mixed> */
    private static function validar(array $in): array
    {
        $e = new Errors();
        foreach (array_keys($in) as $k) {
            in_array($k, ['abrangencia', 'uf', 'codigo_municipio', 'inicio', 'fim', 'pIBSUF', 'pIBSMun', 'pCBS', 'descricao'], true)
                ?: $e->add((string) $k, 'Campo não permitido');
        }
        $abr = $in['abrangencia'] ?? null;
        in_array($abr, ['nacional', 'uf', 'municipio'], true) ?: $e->add('abrangencia', 'nacional, uf ou municipio');
        $uf = null;
        $mun = null;
        if ($abr === 'uf') {
            $uf = strtoupper((string) ($in['uf'] ?? ''));
            preg_match('/^[A-Z]{2}$/D', $uf) && Municipios::buscar($uf, null, 1) !== [] ?: $e->add('uf', 'UF inválida');
        } elseif ($abr === 'municipio') {
            $m = Municipios::get((string) ($in['codigo_municipio'] ?? ''));
            $m === null ? $e->add('codigo_municipio', 'Código IBGE do município (7 dígitos) inválido') : [$mun, $uf] = [$m['codigo'], $m['uf']];
        }
        $data = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)) ? $v : null;
        $ini = $data($in['inicio'] ?? null);
        $ini !== null ?: $e->add('inicio', 'Data AAAA-MM-DD');
        $fim = null;
        if (($in['fim'] ?? null) !== null) {
            $fim = $data($in['fim']);
            if ($fim === null || ($ini !== null && $fim < $ini)) {
                $e->add('fim', 'Data AAAA-MM-DD igual ou posterior ao início');
            }
        }
        $p = [];
        foreach (['pIBSUF', 'pIBSMun', 'pCBS'] as $k) {
            $d = Entrada::valor($in[$k] ?? null, 4);
            $d !== null && Dec::cmp($d, '100') <= 0 ? $p[$k] = Dec::num($d) : $e->add($k, 'Percentual de 0 a 100 com até 4 casas');
        }
        $desc = ($in['descricao'] ?? null) === null ? null : \App\Support\Validate::text($in['descricao'], 1, 120);
        if (($in['descricao'] ?? null) !== null && $desc === null) {
            $e->add('descricao', '1 a 120 caracteres');
        }
        $e->throwIfAny();
        return ['abrangencia' => $abr, 'uf' => $uf, 'codigo_municipio' => $mun, 'inicio' => $ini, 'fim' => $fim] + $p + ['descricao' => $desc];
    }

    /** @param array<string,mixed> $l @return array<string,mixed> */
    private static function comLocal(array $l): array
    {
        $l['municipio'] = $l['codigo_municipio'] !== null ? (Municipios::get($l['codigo_municipio'])['nome'] ?? null) : null;
        return $l;
    }
}
