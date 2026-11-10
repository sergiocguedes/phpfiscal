<?php

declare(strict_types=1);

namespace App\Tributos;

use App\Support\Errors;

/**
 * Cálculo de ICMS (CST/CSOSN, ST, FCP, desoneração, diferimento, monofásico), IPI, PIS, COFINS,
 * ISSQN, IBS/CBS (NT 2025.002-RTC) e tributos aproximados (Lei 12.741/2012) de uma venda, item a item, com os totais da nota.
 *
 * Ordem por item: IPI → ICMS (o IPI pode integrar a base) → PIS/COFINS (podem excluir o ICMS) → ISSQN → IBS/CBS (base sem ICMS/PIS/COFINS) → IBPT.
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
        // IBS/CBS (Reforma Tributária): só regime normal; Simples/MEI dispensados em 2026 e sem leiaute definido depois
        $rtcRegime = $op['crt'] === 3;
        $tabCClass = null;
        // alíquotas do IBS/CBS: as do JSON ou as da tabela da API (data + município de destino/emitente)
        $aliqRtc = $op['aliquotas_ibscbs'] === null ? null : $op['aliquotas_ibscbs'] + ['origem' => ['tipo' => 'informada']];
        $aliqBuscada = false;
        $rt = array_fill_keys(['vBC', 'vIBSUF', 'vIBSMun', 'vCBS', 'vDifUF', 'vDifMun', 'vDifCBS', 'vNFTot', 'vCredPresIBS', 'vCredPresCBS'], '0');
        $comRtc = 0;
        $semRtc = [];

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
            $valorItem = null;
            if (isset($c->regra['ibscbs']) && $c->servico) {
                $c->alerta('Item de serviço: IBS/CBS ignorado');
            } elseif (isset($c->regra['ibscbs']) && !$rtcRegime) {
                $c->alerta('IBS/CBS não calculado para Simples Nacional/MEI (CRT ' . $op['crt'] . '): dispensado em 2026 e aguardando leiaute próprio');
            } elseif (isset($c->regra['ibscbs']) && ($aliqRtc ??= ($aliqBuscada ? null : self::aliquotasDaTabela($op, $aliqBuscada))) === null) {
                $e->add('operacao.aliquotas_ibscbs', 'Sem alíquota de IBS/CBS vigente em ' . ($op['data'] ?? '?') . ' para o município '
                    . ($op['municipio_operacao'] ?? 'não informado') . ': cadastre na tabela da API ou informe aliquotas_ibscbs');
                continue;
            } elseif (isset($c->regra['ibscbs'])) {
                $tabCClass ??= TabelaCClassTrib::tabela();
                try {
                    $classe = TabelaCClassTrib::exigir((string) $c->p('ibscbs', 'CST'), (string) $c->p('ibscbs', 'cClassTrib'), $op['data'], $tabCClass, $op['modelo']);
                } catch (\App\Http\ApiException $x) {
                    $e->add("$c->campo.regra.ibscbs.cClassTrib", $x->details[0]['mensagem'] ?? $x->getMessage());
                    continue;
                }
                $r = IbsCbs::calcular($c, $imp, $aliqRtc, $classe);
                if ($c->credPres !== null) {
                    if (($classe['indCredPres'] ?? null) === false) {
                        $msg = "cClassTrib {$classe['cClassTrib']} não admite crédito presumido (tabela cClassTrib)";
                        if ($c->credPres['origem'] === 'item') {
                            $e->add("$c->campo.credito_presumido", $msg);
                            continue;
                        }
                        $c->alerta("$msg: crédito presumido da operação não aplicado neste item");
                    } else {
                        $cp = IbsCbs::creditoPresumido($c, $r['comValores'] ? $r['vBC'] : IbsCbs::base($c, $imp), $c->credPres);
                        $r['grupo']['gCredPresOper'] = $cp['grupo'];
                        $rt['vCredPresIBS'] = Dec::soma($rt['vCredPresIBS'], $cp['vIBS']);
                        $rt['vCredPresCBS'] = Dec::soma($rt['vCredPresCBS'], $cp['vCBS']);
                    }
                }
                $imp['ibscbs'] = IbsCbs::numeros($r['grupo']);
                $vIBS = Dec::soma($r['vIBSUF'], $r['vIBSMun']);
                $valorItem = IbsCbs::valorItem($c, $imp, $op['ano'], $vIBS, $r['vCBS']);
                foreach (['vBC', 'vIBSUF', 'vIBSMun', 'vCBS', 'vDifUF', 'vDifMun', 'vDifCBS'] as $k) {
                    $rt[$k] = Dec::soma($rt[$k], $r[$k]);
                }
                $rt['vNFTot'] = Dec::soma($rt['vNFTot'], $valorItem);
                $comRtc++;
            } elseif (!$c->servico) {
                $semRtc[] = $c;
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
            if ($valorItem !== null) {
                $saida['valor_item'] = Dec::num($valorItem);
            }
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
        if ($comRtc > 0 && $semRtc !== []) {
            foreach ($semRtc as $c) {
                $e->add("$c->campo.cclasstrib", 'Informe o cClassTrib de todos os itens da nota (ou de nenhum)');
            }
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
        if ($comRtc > 0) {
            $f = fn(string $v) => Dec::num($v);
            $totais['IBSCBSTot'] = [
                'vBCIBSCBS' => $f($rt['vBC']),
                'gIBS' => [
                    'gIBSUF' => ['vDif' => $f($rt['vDifUF']), 'vDevTrib' => 0, 'vIBSUF' => $f($rt['vIBSUF'])],
                    'gIBSMun' => ['vDif' => $f($rt['vDifMun']), 'vDevTrib' => 0, 'vIBSMun' => $f($rt['vIBSMun'])],
                    'vIBS' => $f(Dec::soma($rt['vIBSUF'], $rt['vIBSMun'])), 'vCredPres' => $f($rt['vCredPresIBS']), 'vCredPresCondSus' => 0,
                ],
                'gCBS' => ['vDif' => $f($rt['vDifCBS']), 'vDevTrib' => 0, 'vCBS' => $f($rt['vCBS']), 'vCredPres' => $f($rt['vCredPresCBS']), 'vCredPresCondSus' => 0],
            ];
            $totais['vNFTot'] = $f($rt['vNFTot']);
        }
        // fotografia do que foi usado no IBS/CBS (alíquotas e versão da tabela de cClassTrib)
        $rtcInfo = $comRtc === 0 ? null : [
            'aliquotas' => ['pIBSUF' => Dec::num($aliqRtc['pIBSUF']), 'pIBSMun' => Dec::num($aliqRtc['pIBSMun']), 'pCBS' => Dec::num($aliqRtc['pCBS'])],
            'origem_aliquotas' => $aliqRtc['origem'], 'municipio' => $op['municipio_operacao'],
            'tabela_cclasstrib' => ['versao' => $tabCClass['versao'] ?? null, 'atualizado_em' => $tabCClass['atualizado_em'] ?? null],
        ];
        $regime = in_array($op['crt'], [1, 4], true) ? 'simples_nacional' : 'normal';
        $alertas = [];
        if ($rtcRegime && $comRtc === 0) {
            $alertas[] = 'Regime normal: a nota sai sem o grupo IBS/CBS (informe o cclasstrib dos itens e as alíquotas); a SEFAZ passará a rejeitar (1115) quando a regra entrar em produção';
        }
        if ($op['modelo'] === 65 && $op['uf_destino'] !== $op['uf']) {
            $alertas[] = 'NFC-e é sempre operação interna: uf_destino ignorada';
        }
        if ($op['modelo'] === 55 && $op['uf_destino'] !== $op['uf'] && $op['consumidor_final'] && !$op['contribuinte_icms']) {
            $alertas[] = 'Venda interestadual a consumidor final não contribuinte: DIFAL (EC 87/2015) não é calculado nesta versão';
        }
        // o id identifica o resultado fiscal: não depende da memória de cálculo (?detalhar) nem dos alertas
        $semMemoria = array_map(fn(array $i) => array_diff_key($i, ['memoria_calculo' => 1, 'alertas' => 1]), $itens);
        $id = 'calc_' . substr(hash('sha256', json_encode([self::VERSAO, $op['modelo'], $op['crt'], $op['uf'], $op['uf_destino'],
            $op['ano'], $semMemoria, $totais], JSON_THROW_ON_ERROR)), 0, 32);
        return [
            'calculo_id' => $id,
            'versao_calculo' => self::VERSAO,
            'modelo' => $op['modelo'],
            'regime' => $regime,
            'operacao' => [
                'codigo' => $op['operacao_codigo'], 'tipo_pessoa' => $op['tipo_pessoa'],
                'uf' => $op['uf'], 'uf_destino' => $op['uf_destino'], 'interestadual' => $op['modelo'] === 55 && $op['uf_destino'] !== $op['uf'],
                'consumidor_final' => $op['consumidor_final'], 'ano' => $op['ano'],
            ],
            'itens' => $itens,
            'totais' => $totais,
        ] + ($rtcInfo === null ? [] : ['ibscbs' => $rtcInfo]) + [
            'alertas' => $alertas,
        ];
    }

    /** @param array<string,mixed> $op @return array<string,mixed>|null */
    private static function aliquotasDaTabela(array $op, bool &$buscada): ?array
    {
        $buscada = true;
        return AliquotasIbsCbs::buscar($op['data'] ?? date('Y-m-d'), $op['municipio_operacao']);
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
        foreach (['icms', 'ipi', 'pis', 'cofins', 'issqn', 'ibscbs'] as $k) {
            if (isset($imp[$k])) {
                $o[$k] = $imp[$k];
            }
        }
        return $o;
    }
}
