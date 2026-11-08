<?php

declare(strict_types=1);

namespace App\Tributos;

use App\Http\ApiException;
use App\Nfse\NfseConfig;
use App\Storage\JsonStore;
use App\Support\Errors;
use App\Tenancy\Tenant;

/**
 * Regras de ISS da NFS-e por empresa (equivalente ao IMPOSTO_ISS do ClickPDV): cada regra tem um código escolhido
 * pelo ERP e guarda os códigos do serviço e o tratamento do ISS. Na emissão (`POST /nfse`), `regra_iss` preenche
 * `servico` e `valores` com esses dados; o que o JSON informar explicitamente prevalece.
 * A alíquota é validada contra o regime do prestador e o convênio do município só na emissão.
 * Gravação: tenants/{id}/tributacao/iss/{codigo}.json (igual em homologação e produção).
 */
final class RegrasIss
{
    private const CODIGO = '/^[A-Za-z0-9_-]{1,30}$/D';
    public const TRIBUTACAO = ['1' => 'Operação tributável', '2' => 'Imunidade', '4' => 'Não incidência'];
    public const RETENCAO = ['1' => 'Não retido', '2' => 'Retido pelo tomador'];
    public const IMUNIDADE = ['0' => 'Imunidade (tipo não informado)', '1' => 'Patrimônio, renda ou serviços entre entes públicos',
        '2' => 'Templos de qualquer culto', '3' => 'Partidos, sindicatos, entidades de educação e assistência social sem fins lucrativos',
        '4' => 'Livros, jornais, periódicos e papel de impressão', '5' => 'Fonogramas e videofonogramas musicais produzidos no Brasil'];
    private const SERVICO = ['codigo_tributacao_nacional', 'codigo_tributacao_municipal', 'nbs', 'codigo_interno'];
    private const ISS = ['tributacao_iss', 'tipo_imunidade', 'retencao_iss', 'aliquota_iss'];

    /** @return list<array<string,mixed>> */
    public static function listar(): array
    {
        $out = [];
        foreach (JsonStore::files(Tenant::path('tributacao/iss/*.json')) as $f) {
            $r = JsonStore::read($f);
            if ($r !== null) {
                $out[] = $r;
            }
        }
        usort($out, fn($a, $b) => strnatcmp((string) $a['codigo'], (string) $b['codigo']));
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function obter(string $codigo): ?array
    {
        return preg_match(self::CODIGO, $codigo) ? JsonStore::read(self::arq($codigo)) : null;
    }

    /** @param array<mixed> $in @return array<string,mixed> */
    public static function salvar(string $codigo, array $in): array
    {
        $r = self::validar($codigo, $in);
        JsonStore::write(self::arq($codigo), $r);
        return $r;
    }

    public static function excluir(string $codigo): void
    {
        self::obter($codigo) ?? throw Tenant::notFound('Regra de ISS');
        JsonStore::delete(self::arq($codigo));
    }

    /**
     * Aplica `regra_iss` ao payload de emissão da NFS-e. Campos informados no JSON prevalecem sobre a regra.
     * @param array<mixed> $in
     * @return array<mixed>
     */
    public static function aplicar(array $in, Errors $e): array
    {
        unset($in['_regra_iss']);
        if (!array_key_exists('regra_iss', $in)) {
            return $in;
        }
        $cod = is_int($in['regra_iss']) ? (string) $in['regra_iss'] : $in['regra_iss'];
        unset($in['regra_iss']);
        $r = is_string($cod) ? self::obter($cod) : null;
        if ($r === null) {
            $e->add('regra_iss', 'Regra de ISS não cadastrada' . (is_string($cod) ? " ($cod)" : ''));
            return $in;
        }
        $sv = is_array($in['servico'] ?? null) ? $in['servico'] : [];
        $in['servico'] = array_merge($r['servico'], array_filter($sv, fn($v) => $v !== null && $v !== ''));
        $v = is_array($in['valores'] ?? null) ? $in['valores'] : ($in['valores'] ?? []);
        if (is_array($v)) {
            foreach ($r['iss'] as $k => $x) {
                if (!array_key_exists($k, $v)) {
                    $v[$k] = $x;
                }
            }
            $in['valores'] = $v;
        }
        $in['_regra_iss'] = ['codigo' => $r['codigo'], 'descricao' => $r['descricao']];
        return $in;
    }

    /** @param array<mixed> $in @return array<string,mixed> */
    private static function validar(string $codigo, array $in): array
    {
        if (!preg_match(self::CODIGO, $codigo)) {
            throw ApiException::validation([['campo' => 'codigo', 'mensagem' => 'Código com 1 a 30 caracteres [A-Za-z0-9_-]']]);
        }
        $e = new Errors();
        foreach (array_keys($in) as $k) {
            in_array($k, ['codigo', 'descricao', 'servico', 'iss'], true) ?: $e->add((string) $k, 'Campo não permitido');
        }
        $descricao = \App\Support\Validate::text($in['descricao'] ?? null, 1, 120);
        $descricao !== null ?: $e->add('descricao', '1 a 120 caracteres');

        $sv = is_array($in['servico'] ?? null) && !array_is_list($in['servico']) ? $in['servico'] : null;
        $servico = [];
        if ($sv === null) {
            $e->add('servico', 'Objeto com codigo_tributacao_nacional (e opcionais codigo_tributacao_municipal, nbs, codigo_interno)');
        } else {
            foreach (array_keys($sv) as $k) {
                in_array($k, self::SERVICO, true) ?: $e->add("servico.$k", 'Campo não permitido');
            }
            $servico = NfseConfig::codigos($sv, 'servico', $e);
        }

        $is = $in['iss'] ?? [];
        $iss = [];
        if (!is_array($is) || ($is !== [] && array_is_list($is))) {
            $e->add('iss', 'Objeto com tributacao_iss, retencao_iss e aliquota_iss');
            $is = [];
        }
        foreach (array_keys($is) as $k) {
            in_array($k, self::ISS, true) ?: $e->add("iss.$k", 'Campo não permitido');
        }
        $trib = $is['tributacao_iss'] ?? 1;
        isset(self::TRIBUTACAO[(string) $trib]) && is_int($trib) ? $iss['tributacao_iss'] = $trib
            : $e->add('iss.tributacao_iss', '1 (operação tributável), 2 (imunidade) ou 4 (não incidência)');
        if ($trib === 2) {
            $imun = $is['tipo_imunidade'] ?? 0;
            is_int($imun) && isset(self::IMUNIDADE[(string) $imun]) ? $iss['tipo_imunidade'] = $imun
                : $e->add('iss.tipo_imunidade', 'Tipo de imunidade de 0 a 5');
        } elseif (isset($is['tipo_imunidade'])) {
            $e->add('iss.tipo_imunidade', 'Só com tributacao_iss 2 (imunidade)');
        }
        $ret = $is['retencao_iss'] ?? 1;
        if (!is_int($ret) || !isset(self::RETENCAO[(string) $ret])) {
            $e->add('iss.retencao_iss', '1 (não retido) ou 2 (retido pelo tomador)');
        } elseif ($ret === 2 && $trib !== 1) {
            $e->add('iss.retencao_iss', 'Serviço imune ou sem incidência não tem retenção (rejeição E0580)');
        } else {
            $iss['retencao_iss'] = $ret;
        }
        if (isset($is['aliquota_iss'])) {
            $a = NfseConfig::aliquota($is['aliquota_iss']);
            if ($a === null) {
                $e->add('iss.aliquota_iss', 'Alíquota do ISS entre 0 e 5 (%), até 2 casas');
            } elseif ($trib !== 1) {
                $e->add('iss.aliquota_iss', 'Só com operação tributável (tributacao_iss 1)');
            } else {
                $iss['aliquota_iss'] = $a;
            }
        }
        $e->throwIfAny();
        return ['codigo' => $codigo, 'descricao' => $descricao, 'servico' => $servico, 'iss' => $iss, 'atualizado_em' => date('c')];
    }

    private static function arq(string $codigo): string
    {
        return Tenant::path("tributacao/iss/$codigo.json");
    }
}
