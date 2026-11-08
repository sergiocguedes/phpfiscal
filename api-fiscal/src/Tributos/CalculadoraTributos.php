<?php

declare(strict_types=1);

namespace App\Tributos;

use App\Support\Errors;

/**
 * Cálculo de ICMS (CST/CSOSN, ST, FCP, desoneração, diferimento, monofásico), IPI, PIS, COFINS,
 * ISSQN e tributos aproximados (Lei 12.741/2012) de uma venda, item a item, com os totais da nota.
 *
 * Ordem por item: IPI → ICMS (o IPI pode integrar a base) → PIS/COFINS (podem excluir o ICMS) → ISSQN → IBPT.
 * Não grava nada: o resultado vai para a tela do sistema chamador ou para a emissão.
 */
final class CalculadoraTributos
{
    public const VERSAO = '1.0';

    /**
     * @param array<mixed> $entrada JSON do POST /api/v1/tributos/calcular
     * @param array<string,mixed>|null $cfg configuração do emitente (padrões de CRT e UF)
     * @return array<string,mixed>
     */
    public static function calcular(array $entrada, ?array $cfg = null, bool $detalhar = false): array
    {
        $op = Entrada::normalizar($entrada, $cfg);
        $e = new Errors();
        $icms = new Icms($op, $e);
        $ipi = new Ipi($op, $e);
        $pc = new PisCofins($op, $e);
        $iss = new Issqn($op);

        $itens = [];
        $t = array_fill_keys(['vBC', 'vICMS', 'vICMSDeson', 'vICMSDesonDeduz', 'vFCP', 'vBCST', 'vST', 'vFCPST', 'qBCMono', 'vICMSMono',
            'qBCMonoRet', 'vICMSMonoRet', 'vProd', 'vFrete', 'vSeg', 'vDesc', 'vIPI', 'vPIS', 'vCOFINS', 'vOutro', 'vTotTrib',
            'vCredICMSSN', 'sServ', 'sBC', 'sISS', 'sPIS', 'sCOFINS', 'sDescCond', 'sDescIncond'], '0');
        $vistos = [];

        foreach ($op['itens'] as $c) {
            if (isset($vistos[$c->n])) {
                $e->add("$c->campo.item", "Número de item $c->n repetido");
                continue;
            }
            $vistos[$c->n] = true;
            $imp = [];
            if ($c->servico) {
                if (isset($c->regra['icms'])) {
                    $c->alerta('Item de serviço: regra.icms ignorada (ISSQN)');
                }
            } else {
                $g = $ipi->calcular($c);
                if ($g !== null) {
                    $imp['ipi'] = $g;
                }
                $g = $icms->calcular($c);
                if ($g !== null) {
                    $imp['icms'] = $g;
                }
            }
            foreach (['pis', 'cofins'] as $grupo) {
                $g = $pc->calcular($c, $grupo);
                if ($g !== null) {
                    $imp[$grupo] = $g;
                }
            }
            if ($c->servico) {
                $imp['issqn'] = $iss->calcular($c);
            }
            $aprox = self::tributosAproximados($c);
            self::alertasOperacao($c, $op, $imp);

            $saida = [
                'item' => $c->n,
                'cfop' => $c->regra['cfop'] ?? null,
                'servico' => $c->servico,
                'valores' => [
                    'vProd' => Dec::num($c->vProd), 'vDesc' => Dec::num($c->vDesc), 'vFrete' => Dec::num($c->vFrete),
                    'vSeg' => Dec::num($c->vSeg), 'vOutro' => Dec::num($c->vOutro), 'valor_liquido' => Dec::num($c->vLiq),
                ],
                'impostos' => self::ordenar($imp),
            ];
            if ($aprox !== null) {
                $saida['valor_aproximado_tributos'] = Dec::num($aprox['total']);
                $saida['tributos_aproximados'] = array_map([Dec::class, 'num'], $aprox);
            }
            if ($c->regraAplicada !== null) {
                $saida['regra_aplicada'] = $c->regraAplicada;
            }
            $saida['ajustes_manuais'] = array_keys($c->origem);
            $saida['alertas'] = $c->alertas;
            if ($detalhar) {
                $saida['memoria_calculo'] = $c->memoria;
            }
            $itens[] = $saida;
            self::acumular($t, $c, $imp, $aprox, $op['desconto_iss_condicionado']);
        }
        $e->throwIfAny();

        $vNF = Dec::soma($t['vProd'], $t['vST'], $t['vFCPST'], $t['vFrete'], $t['vSeg'], $t['vOutro'], $t['vIPI']);
        $vNF = Dec::sub(Dec::sub($vNF, $t['vDesc']), $t['vICMSDesonDeduz']);
        $totais = [
            'ICMSTot' => array_map([Dec::class, 'num'], [
                'vBC' => $t['vBC'], 'vICMS' => $t['vICMS'], 'vICMSDeson' => $t['vICMSDeson'], 'vFCP' => $t['vFCP'],
                'vBCST' => $t['vBCST'], 'vST' => $t['vST'], 'vFCPST' => $t['vFCPST'],
                'qBCMono' => $t['qBCMono'], 'vICMSMono' => $t['vICMSMono'], 'qBCMonoRet' => $t['qBCMonoRet'], 'vICMSMonoRet' => $t['vICMSMonoRet'],
                'vProd' => $t['vProd'], 'vFrete' => $t['vFrete'], 'vSeg' => $t['vSeg'], 'vDesc' => $t['vDesc'], 'vII' => '0',
                'vIPI' => $t['vIPI'], 'vIPIDevol' => '0', 'vPIS' => $t['vPIS'], 'vCOFINS' => $t['vCOFINS'], 'vOutro' => $t['vOutro'],
                'vNF' => $vNF, 'vTotTrib' => $t['vTotTrib'],
            ]),
            'vCredICMSSN' => Dec::num($t['vCredICMSSN']),
        ];
        if (!Dec::zero($t['sServ'])) {
            $totais['ISSQNtot'] = array_map([Dec::class, 'num'], [
                'vServ' => $t['sServ'], 'vBC' => $t['sBC'], 'vISS' => $t['sISS'], 'vPIS' => $t['sPIS'], 'vCOFINS' => $t['sCOFINS'],
                'vDescIncond' => $t['sDescIncond'], 'vDescCond' => $t['sDescCond'],
            ]);
        }
        $regime = in_array($op['crt'], [1, 4], true) ? 'simples_nacional' : 'normal';
        $alertas = [];
        if ($op['modelo'] === 65 && $op['uf_destino'] !== $op['uf']) {
            $alertas[] = 'NFC-e é sempre operação interna: uf_destino ignorada';
        }
        if ($op['modelo'] === 55 && $op['uf_destino'] !== $op['uf'] && $op['consumidor_final'] && !$op['contribuinte_icms']) {
            $alertas[] = 'Venda interestadual a consumidor final não contribuinte: DIFAL (EC 87/2015) não é calculado nesta versão';
        }
        // o id identifica o resultado fiscal: não depende da memória de cálculo (?detalhar) nem dos alertas
        $semMemoria = array_map(fn(array $i) => array_diff_key($i, ['memoria_calculo' => 1, 'alertas' => 1]), $itens);
        $id = 'calc_' . substr(hash('sha256', json_encode([self::VERSAO, $op['modelo'], $op['crt'], $op['uf'], $op['uf_destino'],
            $semMemoria, $totais], JSON_THROW_ON_ERROR)), 0, 32);
        return [
            'calculo_id' => $id,
            'versao_calculo' => self::VERSAO,
            'modelo' => $op['modelo'],
            'regime' => $regime,
            'operacao' => [
                'codigo' => $op['operacao_codigo'], 'tipo_pessoa' => $op['tipo_pessoa'],
                'uf' => $op['uf'], 'uf_destino' => $op['uf_destino'], 'interestadual' => $op['modelo'] === 55 && $op['uf_destino'] !== $op['uf'],
                'consumidor_final' => $op['consumidor_final'],
            ],
            'itens' => $itens,
            'totais' => $totais,
            'alertas' => $alertas,
        ];
    }

    /** @return array{federal:string, estadual:string, municipal:string, total:string}|null */
    private static function tributosAproximados(ItemContexto $c): ?array
    {
        if (!isset($c->regra['ibpt'])) {
            return null;
        }
        $r = [];
        foreach (['federal', 'estadual', 'municipal'] as $k) {
            $r[$k] = Dec::abnt(Dec::pct($c->vLiq, $c->p('ibpt', $k) ?? '0'));
        }
        $r['total'] = Dec::soma($r['federal'], $r['estadual'], $r['municipal']);
        return $r;
    }

    /** @param array<string,mixed> $op @param array<string,mixed> $imp */
    private static function alertasOperacao(ItemContexto $c, array $op, array $imp): void
    {
        $cfop = $c->regra['cfop'] ?? null;
        $inter = $op['modelo'] === 55 && $op['uf_destino'] !== $op['uf'];
        if ($cfop === null) {
            $c->alerta('CFOP não informado na regra');
        } elseif ($op['modelo'] === 65 && $cfop[0] !== '5') {
            $c->alerta("NFC-e só aceita CFOP 5xxx (informado $cfop)");
        } elseif ($inter && $cfop[0] === '5') {
            $c->alerta("Operação interestadual com CFOP interno $cfop (use 6xxx)");
        } elseif (!$inter && $op['modelo'] === 55 && $cfop[0] === '6') {
            $c->alerta("Operação interna com CFOP interestadual $cfop (use 5xxx)");
        }
        if ($op['modelo'] === 65 && (float) ($imp['icms']['vICMSST'] ?? 0) > 0) {
            $c->alerta('ICMS ST destacado em NFC-e: confirme se a UF aceita');
        }
    }

    /** @param array<string,string> $t @param array<string,mixed> $imp @param array<string,string>|null $aprox */
    private static function acumular(array &$t, ItemContexto $c, array $imp, ?array $aprox, bool $descCond): void
    {
        $s = fn(array $g, string $k): string => isset($g[$k]) ? Dec::de($g[$k]) ?? '0' : '0';
        $t['vProd'] = Dec::soma($t['vProd'], $c->vProd);
        $t['vDesc'] = Dec::soma($t['vDesc'], $c->vDesc);
        $t['vFrete'] = Dec::soma($t['vFrete'], $c->vFrete);
        $t['vSeg'] = Dec::soma($t['vSeg'], $c->vSeg);
        $t['vOutro'] = Dec::soma($t['vOutro'], $c->vOutro);
        if ($aprox !== null) {
            $t['vTotTrib'] = Dec::soma($t['vTotTrib'], $aprox['total']);
        }
        if (isset($imp['icms'])) {
            $i = $imp['icms'];
            foreach (['vBC' => 'vBC', 'vICMS' => 'vICMS', 'vFCP' => 'vFCP', 'vBCST' => 'vBCST', 'vST' => 'vICMSST', 'vFCPST' => 'vFCPST',
                'qBCMono' => 'qBCMono', 'vICMSMono' => 'vICMSMono', 'qBCMonoRet' => 'qBCMonoRet', 'vICMSMonoRet' => 'vICMSMonoRet',
                'vCredICMSSN' => 'vCredICMSSN', 'vICMSDeson' => 'vICMSDeson'] as $tot => $tag) {
                $t[$tot] = Dec::soma($t[$tot], $s($i, $tag));
            }
            if ((int) ($i['indDeduzDeson'] ?? 0) === 1) {
                $t['vICMSDesonDeduz'] = Dec::soma($t['vICMSDesonDeduz'], $s($i, 'vICMSDeson'));
            }
        }
        $t['vIPI'] = Dec::soma($t['vIPI'], $s($imp['ipi'] ?? [], 'vIPI'));
        if ($c->servico) {
            $t['sServ'] = Dec::soma($t['sServ'], $c->vProd);
            $t['sBC'] = Dec::soma($t['sBC'], $s($imp['issqn'], 'vBC'));
            $t['sISS'] = Dec::soma($t['sISS'], $s($imp['issqn'], 'vISSQN'));
            $t['sPIS'] = Dec::soma($t['sPIS'], $s($imp['pis'] ?? [], 'vPIS'));
            $t['sCOFINS'] = Dec::soma($t['sCOFINS'], $s($imp['cofins'] ?? [], 'vCOFINS'));
            $k = $descCond ? 'sDescCond' : 'sDescIncond';
            $t[$k] = Dec::soma($t[$k], $c->vDesc);
        } else {
            $t['vPIS'] = Dec::soma($t['vPIS'], $s($imp['pis'] ?? [], 'vPIS'));
            $t['vCOFINS'] = Dec::soma($t['vCOFINS'], $s($imp['cofins'] ?? [], 'vCOFINS'));
        }
    }

    /** @param array<string,mixed> $imp @return array<string,mixed> */
    private static function ordenar(array $imp): array
    {
        $o = [];
        foreach (['icms', 'ipi', 'pis', 'cofins', 'issqn'] as $k) {
            if (isset($imp[$k])) {
                $o[$k] = $imp[$k];
            }
        }
        return $o;
    }
}
