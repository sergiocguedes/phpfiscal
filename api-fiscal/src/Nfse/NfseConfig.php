<?php

declare(strict_types=1);

namespace App\Nfse;

use App\Http\ApiException;
use App\Nfe\ConfigService;
use App\Storage\JsonStore;
use App\Support\Errors;
use App\Support\Validate;
use App\Tenancy\Tenant;

/**
 * Configuração do PRESTADOR para a NFS-e Padrão Nacional, separada da NF-e (storage/tenants/<id>/nfse-config.json):
 * inscrição municipal, município emissor, situação perante o Simples Nacional (inclusive MEI), regime especial,
 * ambiente_nfse por empresa (padrão: homologação), série e numeração da DPS; serviços informados no JSON
 * (código de tributação nacional, NBS, código municipal e alíquota do ISS).
 *
 * O CNPJ e a razão social vêm da configuração do emitente da NF-e (PUT /api/v1/config), que já é obrigatória.
 */
final class NfseConfig
{
    public const MAX_SERVICOS = 50;
    /** Série da DPS emitida por aplicativo do contribuinte (a faixa por tipo de emissor é verificada pela Sefin: E0010). */
    public const SERIE_MAX = 49999;
    public const NUMERO_MAX = 999999999999999;

    private static function file(): string
    {
        return Tenant::path('nfse-config.json');
    }

    /** @return array<string,mixed>|null */
    public static function get(): ?array
    {
        $c=JsonStore::read(self::file());
        if($c===null){$shared=JsonStore::read(Tenant::path('nfse-prestador.json'));if($shared!==null){$c=$shared+['serie'=>1,'proximo_numero'=>1,'municipio_convenio_ativo'=>null];}}
        if($c!==null){unset($c['servicos']);$c['ambiente']=\App\Tenancy\FiscalScope::ambiente(Tenant::id());
            // o regime da empresa manda: CRT alterado nos dados fiscais vale na hora para a NFS-e
            $op=self::opcaoSimples((\App\Nfe\ConfigService::get()??[])['crt']??null);
            if($op!==null){$c['opcao_simples_nacional']=$op;if($op!==3){unset($c['regime_apuracao_sn']);}}}
        return $c;
    }

    /**
     * opSimpNac da NFS-e a partir do CRT da empresa: 3 regime normal → 1 não optante; 4 MEI → 2 MEI;
     * 1 Simples Nacional e 2 Simples com excesso de sublimite → 3 ME/EPP.
     */
    public static function opcaoSimples(mixed $crt): ?int
    {
        return [1 => 3, 2 => 3, 3 => 1, 4 => 2][(int) $crt] ?? null;
    }

    /** @return array<string,mixed> */
    public static function require(): array
    {
        $c = self::get();
        if ($c === null) {
            throw new ApiException(409, 'nfse_config_ausente', 'Configure o prestador da NFS-e antes (PUT /api/v1/nfse/config)');
        }
        return $c;
    }

    /** @param array<string,mixed>|null $c @return array<string,mixed> */
    public static function publicView(?array $c): array
    {
        if ($c === null) {
            $emit = ConfigService::get();
            return [
                'configurado' => false,
                'ambiente' => \App\Tenancy\FiscalScope::ambiente(Tenant::id()),
                'codigo_municipio' => $emit['endereco']['codigo_municipio'] ?? null,
                'opcao_simples_nacional' => self::opcaoSimples($emit['crt'] ?? null),
            ];
        }
        $c['configurado'] = true;
        // v2.9.3: aviso pronto para o painel quando a consulta (ou uma recusa E0037/E0039) indica município não habilitado
        $pm = $c['parametros_municipio'] ?? null;
        $c['aviso_municipio'] = is_array($pm) && ($pm['ambiente'] ?? null) === (int) ($c['ambiente'] ?? 0)
            && ($pm['codigo_municipio'] ?? null) === (string) ($c['codigo_municipio'] ?? '')
            && (($pm['conveniado'] ?? null) === false || ($pm['emissor_nacional'] ?? null) === false)
            ? Rejeicoes::mensagemNaoHabilitado() : null;
        return $c;
    }

    /**
     * @param array<mixed> $in
     * @return array<string,mixed>
     */
    public static function put(array $in): array
    {
        $emit = ConfigService::require(); // CNPJ/razão social do emitente
        $current = self::get();
        $e = new Errors();
        $out = [];

        $im = $in['inscricao_municipal'] ?? null;
        if ($im !== null && $im !== '') {
            if (!is_string($im) || !preg_match('/^[0-9A-Za-z.\-\/]{1,15}$/', $im)) {
                $e->add('inscricao_municipal', 'Inscrição municipal: 1 a 15 caracteres (letras, números, . - /)');
            } else {
                $out['inscricao_municipal'] = $im;
            }
        }

        $cMun = Validate::digits($in['codigo_municipio'] ?? ($emit['endereco']['codigo_municipio'] ?? ''));
        if (!preg_match('/^\d{7}$/', $cMun) || !in_array((int) substr($cMun, 0, 2), Validate::UFS, true)) {
            $e->add('codigo_municipio', 'Código IBGE do município emissor com 7 dígitos');
        } elseif (($dfErr = Validate::municipioIbgeErro($cMun)) !== null) {
            $e->add('codigo_municipio', $dfErr);
        }
        $out['codigo_municipio'] = $cMun;

        // opção pelo Simples (opSimpNac) vem do regime tributário da empresa (CRT dos dados fiscais)
        $op = self::opcaoSimples($emit['crt'] ?? null);
        $inOp = $in['opcao_simples_nacional'] ?? null;
        if ($op === null) {
            $e->add('opcao_simples_nacional', 'Cadastre o regime tributário (CRT) nos dados fiscais da empresa');
        } elseif ($inOp !== null && $inOp !== $op) {
            $e->add('opcao_simples_nacional', "Vem do regime tributário da empresa (CRT {$emit['crt']} → $op): altere o regime nos dados fiscais (PUT /config)");
        }
        $out['opcao_simples_nacional'] = $op;

        $regAp = $in['regime_apuracao_sn'] ?? null;
        if ($op === 3) {
            if (!in_array($regAp, [1, 2, 3], true)) {
                $e->add('regime_apuracao_sn', 'Obrigatório para ME/EPP do Simples: 1 (tributos federais e ISS pelo SN), 2 (federais pelo SN e ISS fora) ou 3 (todos fora do SN)');
            }
            $out['regime_apuracao_sn'] = $regAp;
        } elseif ($regAp !== null) {
            $e->add('regime_apuracao_sn', 'Só existe para opcao_simples_nacional = 3 (ME/EPP). Não informe para MEI nem não optante (rejeição E0162).');
        }

        $regEsp = $in['regime_especial'] ?? 0;
        if (!in_array($regEsp, [0, 1, 2, 3, 4, 5, 6, 9], true)) {
            $e->add('regime_especial', 'Regime especial: 0 nenhum, 1 ato cooperado, 2 estimativa, 3 microempresa municipal, 4 notário/registrador, 5 profissional autônomo, 6 sociedade de profissionais, 9 outros');
        } elseif ($regEsp !== 0 && ($op === 2 || ($op === 3 && $regAp === 1))) {
            $e->add('regime_especial', 'MEI e ME/EPP com apuração pelo Simples devem usar 0 (nenhum) — rejeições E0174/E0175.');
        }
        $out['regime_especial'] = $regEsp;

        // Tributação de serviços da empresa (aba "Tributação Serviços" do ClickPDV): padrões do ISSQN na emissão.
        // Ordem na NFS-e: JSON da nota > regra de ISS > empresa.
        $trib = $in['tributacao_iss'] ?? 1;
        if (!in_array($trib, [1, 2, 4], true)) {
            $e->add('tributacao_iss', '1 (operação tributável), 2 (imunidade) ou 4 (não incidência)');
        }
        $out['tributacao_iss'] = $trib;
        $imun = $in['tipo_imunidade'] ?? null;
        if ($trib === 2) {
            $imun ??= 0;
            in_array($imun, [0, 1, 2, 3, 4, 5], true) ? $out['tipo_imunidade'] = $imun
                : $e->add('tipo_imunidade', 'Tipo de imunidade de 0 a 5 (CF/88 art. 150, VI)');
        } elseif ($imun !== null) {
            $e->add('tipo_imunidade', 'Só com tributacao_iss 2 (imunidade)');
        }
        $ret = $in['retencao_iss'] ?? 1;
        if (!in_array($ret, [1, 2], true)) {
            $e->add('retencao_iss', '1 (não retido) ou 2 (retido pelo tomador)');
        } elseif ($ret === 2 && $op === 2) {
            $e->add('retencao_iss', 'MEI não pode ter ISS retido (rejeição E0583)');
        } elseif ($ret === 2 && $regEsp !== 0) {
            $e->add('retencao_iss', 'Prestador com regime especial de tributação não pode ter ISS retido (rejeição E0588)');
        } elseif ($ret === 2 && $trib !== 1) {
            $e->add('retencao_iss', 'Serviço imune ou sem incidência não tem retenção (rejeição E0580)');
        }
        $out['retencao_iss'] = $ret;
        $susp = $in['suspensao'] ?? null;
        if ($susp !== null) {
            if (!is_array($susp) || !in_array($susp['tipo'] ?? null, [1, 2], true)
                || !is_string($susp['numero_processo'] ?? null) || !preg_match('/^[0-9]{30}$/D', $susp['numero_processo'])
                || array_diff(array_keys($susp), ['tipo', 'numero_processo']) !== []) {
                $e->add('suspensao', 'null (nenhuma) ou {tipo: 1 judicial ou 2 administrativo, numero_processo: 30 dígitos}');
            } elseif ($trib !== 1) {
                $e->add('suspensao', 'Suspensão da exigibilidade só com operação tributável (tributacao_iss 1)');
            } else {
                $out['suspensao'] = ['tipo' => $susp['tipo'], 'numero_processo' => $susp['numero_processo']];
            }
        }

        $amb = $in['ambiente'] ?? \App\Tenancy\FiscalScope::ambiente(Tenant::id());
        if ($amb !== \App\Tenancy\FiscalScope::ambiente(Tenant::id())) {
            $e->add('ambiente','O ambiente deve ser o selecionado na gestão da empresa');
        }
        if (!in_array($amb, [1, 2], true)) {
            $e->add('ambiente', 'Use 2 (produção restrita / homologação) ou 1 (produção)');

        }
        $out['ambiente'] = $amb;

        $serie = $in['serie'] ?? ($current['serie'] ?? 1);
        if (!is_int($serie) || $serie < 1 || $serie > self::SERIE_MAX) {
            $e->add('serie', 'Série da DPS entre 1 e ' . self::SERIE_MAX);
        }
        $out['serie'] = $serie;
        $prox = $in['proximo_numero'] ?? ($current['proximo_numero'] ?? 1);
        if (!is_int($prox) || $prox < 1 || $prox > self::NUMERO_MAX) {
            $e->add('proximo_numero', 'Próximo número da DPS entre 1 e 999999999999999');
        }
        $out['proximo_numero'] = $prox;

        $ativo = array_key_exists('municipio_convenio_ativo', $in) ? $in['municipio_convenio_ativo'] : ($current['municipio_convenio_ativo'] ?? null);
        if ($ativo !== null && !is_bool($ativo)) {
            $e->add('municipio_convenio_ativo', 'true, false ou null (desconhecido)');
        }
        $out['municipio_convenio_ativo'] = $ativo;

        if(array_key_exists('servicos',$in)){$e->add('servicos','Cadastro de serviços removido; envie servico no JSON de cada emissão');}

        $e->throwIfAny();
        
        $num = is_array($current['numeracao'] ?? null) ? $current['numeracao'] : [];
        if ($current !== null && (int) $current['serie'] !== $serie) {
            $num[(string) $current['serie']] = (int) $current['proximo_numero'];
            if (!array_key_exists('proximo_numero', $in)) {
                $out['proximo_numero'] = (int) ($num[(string) $serie] ?? 1);
            }
        }
        unset($num[(string) $serie]);
        if ($num !== []) {
            $out['numeracao'] = $num;
        }
        if (is_array($current['parametros_municipio'] ?? null) && ($current['codigo_municipio'] ?? null) === $cMun) {
            $out['parametros_municipio'] = $current['parametros_municipio'];
        }
        $out['atualizado_em'] = date('c');
        JsonStore::write(self::file(), $out);
        $shared=$out;foreach(['ambiente','serie','proximo_numero','numeracao','municipio_convenio_ativo','parametros_municipio'] as $k){unset($shared[$k]);}JsonStore::write(Tenant::path('nfse-prestador.json'),$shared);
        return $out;
    }

    /**
     * Códigos do serviço (também usados no payload da emissão).
     * @param array<mixed> $s
     * @return array<string,string>
     */
    public static function codigos(array $s, string $p, Errors $e): array
    {
        $o = [];
        $ctn = Validate::digits($s['codigo_tributacao_nacional'] ?? '');
        if (!preg_match('/^\d{6}$/', $ctn)) {
            $e->add("$p.codigo_tributacao_nacional", 'Código de tributação nacional com 6 dígitos (item + subitem + desdobro, ex.: 010101 = 01.01 análise e desenvolvimento de sistemas)');
        } elseif (Listas::servico($ctn) === null) {
            $e->add("$p.codigo_tributacao_nacional", "Código $ctn não existe na lista de serviços nacional (Anexo B v1.01)");
        }
        $o['codigo_tributacao_nacional'] = $ctn;
        $ctm = $s['codigo_tributacao_municipal'] ?? null;
        if ($ctm !== null && $ctm !== '') {
            $ctm = Validate::digits($ctm);
            preg_match('/^\d{3}$/', $ctm) ? $o['codigo_tributacao_municipal'] = $ctm : $e->add("$p.codigo_tributacao_municipal", 'Código de tributação municipal com 3 dígitos');
        }
        $nbs = $s['nbs'] ?? null;
        if ($nbs !== null && $nbs !== '') {
            $nbs = Validate::digits($nbs);
            if (!preg_match('/^\d{9}$/', $nbs)) {
                $e->add("$p.nbs", 'Código NBS com 9 dígitos (ex.: 1.1503.10.00 → 115031000)');
            } elseif (Listas::nbsDescricao($nbs) === null) {
                $e->add("$p.nbs", "Código NBS $nbs não existe na tabela NBS do Anexo B v1.01");
            } else {
                $o['nbs'] = $nbs;
            }
        }
        $ci = $s['codigo_interno'] ?? null;
        if ($ci !== null && $ci !== '') {
            is_string($ci) && preg_match('/^[a-zA-Z0-9]{1,20}$/', $ci) ? $o['codigo_interno'] = $ci : $e->add("$p.codigo_interno", 'Código interno: 1 a 20 letras ou números');
        }
        return $o;
    }

    public static function aliquota(mixed $v): ?float
    {
        $a = Validate::decimal($v, 6, 5.0);
        if ($a === null || abs($a * 100 - round($a * 100)) > 1e-6) {
            return null; // no máximo 2 casas (TSDec1V2) e até 5% (E0595)
        }
        return round($a, 2);
    }

    /** Próximo número da DPS sem reservar. @param array<string,mixed> $c */
    public static function nextNumber(array $c, int $serie): int
    {
        return $serie === (int) $c['serie'] ? (int) $c['proximo_numero'] : (int) ($c['numeracao'][(string) $serie] ?? 1);
    }

    /** Reserva atomicamente o número da DPS na série (série padrão se omitida). */
    public static function reserveNumber(?int $serie = null): int
    {
        return JsonStore::update(self::file(), function (?array $c) use ($serie) {
            $c ??= self::get();
            if ($c === null) {
                throw new ApiException(409, 'nfse_config_ausente', 'Configure o prestador da NFS-e antes (PUT /api/v1/nfse/config)');
            }
            $serie ??= (int) $c['serie'];
            $n = self::nextNumber($c, $serie);
            if ($n > self::NUMERO_MAX) {
                throw new ApiException(409, 'numeracao_esgotada', "Numeração da DPS esgotada na série $serie");
            }
            if ($serie === (int) $c['serie']) {
                $c['proximo_numero'] = $n + 1;
            } else {
                $c['numeracao'][(string) $serie] = $n + 1;
            }
            return [$c, $n];
        });
    }

    /**
     * v2.9.3: a Sefin recusou com E0037/E0039 (município não habilitado no Emissor Nacional). Marca no resultado da
     * consulta de parâmetros deste ambiente para avisar antes da próxima emissão. Não muda o convênio usado na regra
     * da alíquota e não bloqueia nada: uma consulta nova ("Verificar") ou uma nota autorizada desfazem a marca.
     */
    public static function marcarNaoHabilitado(int $ambiente): void
    {
        JsonStore::update(self::file(), function (?array $c) use ($ambiente) {
            if ($c === null) {
                return [null, null];
            }
            $pm = is_array($c['parametros_municipio'] ?? null) && ($c['parametros_municipio']['ambiente'] ?? null) === $ambiente
                && ($c['parametros_municipio']['codigo_municipio'] ?? null) === (string) $c['codigo_municipio']
                ? $c['parametros_municipio'] : ['codigo_municipio' => (string) $c['codigo_municipio'], 'ambiente' => $ambiente, 'conveniado' => null];
            $pm['emissor_nacional'] = false;
            $pm['recusado_pela_sefin_em'] = date('c');
            $c['parametros_municipio'] = $pm;
            return [$c, null];
        });
    }

    /** v2.9.3: nota autorizada = município habilitado; remove a marca de marcarNaoHabilitado(). */
    public static function limparNaoHabilitado(int $ambiente): void
    {
        $c = self::get();
        if (!isset($c['parametros_municipio']['recusado_pela_sefin_em']) || ($c['parametros_municipio']['ambiente'] ?? null) !== $ambiente) {
            return;
        }
        JsonStore::update(self::file(), function (?array $c) {
            if ($c === null || !is_array($c['parametros_municipio'] ?? null)) {
                return [$c, null];
            }
            unset($c['parametros_municipio']['recusado_pela_sefin_em']);
            $c['parametros_municipio']['emissor_nacional'] = null;
            return [$c, null];
        });
    }

    /** Guarda o resultado (resumido) da consulta de parâmetros do município emissor. @param array<string,mixed> $p */
    public static function salvarParametros(array $p): void
    {
        JsonStore::update(self::file(), function (?array $c) use ($p) {
            if ($c === null) {
                return [null, null];
            }
            $antigo = $c['parametros_municipio'] ?? null;
            $c['parametros_municipio'] = $p;
            if (is_bool($p['conveniado'] ?? null)) {
                $c['municipio_convenio_ativo'] = $p['conveniado'];
            } elseif (is_array($antigo) && !isset($antigo['ambiente'])) {
                // o valor anterior veio da consulta da 2.9.1 (endpoint errado, 404 lido como "sem convênio"): desconhecido
                $c['municipio_convenio_ativo'] = null;
            }
            return [$c, null];
        });
    }
}
