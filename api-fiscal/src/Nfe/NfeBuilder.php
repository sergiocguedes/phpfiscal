<?php

declare(strict_types=1);

namespace App\Nfe;

use App\Http\ApiException;
use App\Support\Errors;
use App\Support\Validate;
use NFePHP\NFe\Make;

/**
 * Converte o payload JSON da API em XML NF-e (modelo 55) ou NFC-e (modelo 65), layout 4.00,
 * usando NFePHP\NFe\Make. O emitente vem sempre da configuração.
 *
 * NFC-e (regras aplicadas localmente, antes da SEFAZ): venda a consumidor final (indFinal=1),
 * operação interna (idDest=1, CFOP 5xxx), saída (tpNF=1), finalidade normal, presença 1
 * (presencial) ou 4 (entrega em domicílio), destinatário opcional (CPF/CNPJ, sem IE),
 * DANFCE (tpImp 4, ou 5 = mensagem eletrônica), pagamento obrigatório (sem tPag 90) e troco.
 */
final class NfeBuilder
{
    private const FORMAS_PAG = ['01', '02', '03', '04', '05', '10', '11', '12', '13', '15', '16', '17', '18', '19', '20', '21', '22', '90', '99'];
    private const TAX_KEY = '/^[A-Za-z][A-Za-z0-9]{0,24}$/';
    public const MODELOS = [55, 65];

    /**
     * Valida e normaliza o payload. Lança ApiException(422) com todos os erros.
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    public static function validatePayload(array $p): array
    {
        $e = new Errors();
        $o = [];
        $rtcInformada = \App\Fiscal\RtcInformada::ativa($p);
        if (isset($p['reforma_tributaria'])) {
            $o['reforma_tributaria'] = \App\Fiscal\RtcInformada::validarConfiguracao($p['reforma_tributaria'], $e);
        }

        $o['natureza_operacao'] = Validate::text($p['natureza_operacao'] ?? null, 1, 60) ?? $e->add('natureza_operacao', 'Obrigatório, 1 a 60 caracteres');

        $mod = $p['modelo'] ?? 55;
        if (is_string($mod) && ctype_digit($mod)) {
            $mod = (int) $mod;
        }
        if (!in_array($mod, self::MODELOS, true)) {
            $e->add('modelo', 'Modelo inválido: use 55 (NF-e) ou 65 (NFC-e)');
            $mod = 55;
        }
        $o['modelo'] = $mod;
        $nfce = $mod === 65;
        foreach (['numero' => [1, 999999999], 'serie' => [0, 889]] as $f => [$min, $max]) {
            if (isset($p[$f])) {
                is_int($p[$f]) && $p[$f] >= $min && $p[$f] <= $max ? $o[$f] = $p[$f] : $e->add($f, "Inteiro entre $min e $max");
            }
        }
        $enum = $nfce ? [
            // NFC-e: venda presencial/entrega a consumidor final
            'tipo_operacao' => [[1], 1, 'NFC-e é sempre de saída (tipo_operacao = 1)'],
            'finalidade' => [[1], 1, 'NFC-e só admite finalidade 1 (normal); para complementar, ajuste ou devolução use NF-e (modelo 55)'],
            'consumidor_final' => [[1], 1, 'NFC-e é sempre para consumidor final (consumidor_final = 1)'],
            'presenca' => [[1, 4], 1, 'NFC-e aceita presença 1 (operação presencial) ou 4 (entrega em domicílio)'],
            'tipo_impressao' => [[4, 5], 4, 'NFC-e: 4 (DANFCE) ou 5 (DANFCE em mensagem eletrônica)'],
        ] : [
            'tipo_operacao' => [[0, 1], 1, null],
            'finalidade' => [[1, 2, 3, 4], 1, null],
            'consumidor_final' => [[0, 1], 1, null],
            'presenca' => [[0, 1, 2, 3, 4, 5, 9], 1, null],
            'tipo_impressao' => [[1, 2], 1, 'NF-e: 1 (DANFE retrato) ou 2 (paisagem)'],
        ];
        foreach ($enum as $f => [$allowed, $def, $msg]) {
            $v = $p[$f] ?? $def;
            in_array($v, $allowed, true) ? $o[$f] = $v : $e->add($f, $msg ?? 'Valores aceitos: ' . implode(', ', $allowed));
        }

        $refs = $p['nfe_referenciadas'] ?? [];
        if (!is_array($refs) || count($refs) > 99) {
            $e->add('nfe_referenciadas', 'Lista de até 99 chaves');
            $refs = [];
        }
        if ($nfce && $refs !== []) {
            $e->add('nfe_referenciadas', 'NFC-e não referencia outras notas; use NF-e (modelo 55)');
            $refs = [];
        }
        foreach ($refs as $i => $ch) {
            if (!is_string($ch) || !Validate::chave($ch)) {
                $e->add("nfe_referenciadas[$i]", 'Chave de acesso inválida');
            }
        }
        $o['nfe_referenciadas'] = array_values($refs);
        if (in_array($o['finalidade'] ?? 1, [2, 3, 4], true) && $refs === []) {
            $e->add('nfe_referenciadas', 'Obrigatório para finalidade 2, 3 ou 4');
        }

        if (isset($p['pedido_id'])) {
            is_string($p['pedido_id']) && preg_match('/^[A-Za-z0-9_.:\-\/]{1,64}$/', $p['pedido_id'])
                ? $o['pedido_id'] = $p['pedido_id'] : $e->add('pedido_id', 'Até 64 caracteres [A-Za-z0-9_.:-/]');
        }

        
        if (isset($p['intermediador'])) {
            $im = $p['intermediador'];
            if (!is_array($im)) {
                $e->add('intermediador', 'Objeto {"cnpj", "identificador"}');
            } elseif (!in_array($o['presenca'] ?? 1, [2, 3, 4, 9], true)) {
                $e->add('intermediador', 'Intermediador só em operação não presencial (presença 2, 3, 4 ou 9)');
            } else {
                $ic = Validate::digits($im['cnpj'] ?? '');
                $ii = Validate::text($im['identificador'] ?? null, 2, 60);
                Validate::cnpj($ic) ?: $e->add('intermediador.cnpj', 'CNPJ do intermediador inválido');
                $ii !== null ?: $e->add('intermediador.identificador', 'Identificação do vendedor no intermediador, 2 a 60 caracteres');
                $o['intermediador'] = ['cnpj' => $ic, 'identificador' => (string) $ii];
            }
        }

        // Destinatário (obrigatório na NF-e; opcional na NFC-e — "consumidor não identificado")
        $d = $p['destinatario'] ?? null;
        if ($nfce && ($d === null || $d === [])) {
            $o['destinatario'] = null;
            if (($o['presenca'] ?? 1) === 4) {
                $e->add('destinatario', 'Obrigatório na NFC-e com entrega em domicílio (presença 4): informe CPF/CNPJ e endereço de entrega');
            }
        } elseif (!is_array($d)) {
            $e->add('destinatario', $nfce ? 'Objeto esperado (ou omita para consumidor não identificado)' : 'Obrigatório');
        } else {
            $endObrig = !$nfce || ($o['presenca'] ?? 1) === 4;
            $o['destinatario'] = self::validarDestinatario($d, $e, $nfce, $endObrig);
        }

        // Itens
        $itens = $p['itens'] ?? null;
        if (!is_array($itens) || !array_is_list($itens) || count($itens) < 1 || count($itens) > 990) {
            $e->add('itens', 'Lista com 1 a 990 itens');
            $itens = [];
        }
        $o['itens'] = [];
        $totalProdutos = 0.0;
        foreach ($itens as $i => $it) {
            $f = "itens[$i]";
            if (!is_array($it)) {
                $e->add($f, 'Objeto esperado');
                continue;
            }
            $oi = self::validarItem($it, $f, $e, $nfce, false, $rtcInformada);
            $totalProdutos += $oi['valor_total'] ?? 0;
            $o['itens'][] = $oi;
        }

        $fr = $p['frete']['modalidade'] ?? 9;
        if (!in_array($fr, [0, 1, 2, 3, 4, 9], true)) {
            $e->add('frete.modalidade', '0,1,2,3,4 ou 9');
        } elseif ($nfce && $fr !== 9 && ($o['presenca'] ?? 1) !== 4) {
            $e->add('frete.modalidade', 'NFC-e presencial não tem frete (use 9); frete só com entrega em domicílio (presença 4)');
        } else {
            $o['frete'] = ['modalidade' => $fr];
        }

        $pags = $p['pagamentos'] ?? null;
        if (!is_array($pags) || !array_is_list($pags) || count($pags) < 1 || count($pags) > 100) {
            $e->add('pagamentos', $nfce ? 'Obrigatório na NFC-e: lista com 1 a 100 formas de pagamento (detPag/tPag)' : 'Lista com 1 a 100 formas de pagamento');
            $pags = [];
        }
        $o['pagamentos'] = [];
        foreach ($pags as $i => $pg) {
            $f = "pagamentos[$i]";
            if (!is_array($pg)) {
                $e->add($f, 'Objeto esperado');
                continue;
            }
            $op = [];
            $forma = $pg['forma'] ?? null;
            is_string($forma) && in_array($forma, self::FORMAS_PAG, true) ? $op['forma'] = $forma : $e->add("$f.forma", 'Código tPag válido (ex.: "01" dinheiro, "03" crédito, "17" PIX, "90" sem pagamento)');
            if (!array_key_exists('valor', $pg) && count($pags) === 1) {
                // forma única sem valor: preenchida com o total da nota (vNF) na montagem (usado no pedido split)
                $op['valor'] = 0.0;
                $op['valor_auto'] = true;
            } else {
                $v = Validate::decimal($pg['valor'] ?? null, 2);
                $v !== null ? $op['valor'] = $v : $e->add("$f.valor", 'Número >= 0, 2 casas (pode ser omitido só quando há uma única forma de pagamento: vale o total da nota)');
            }
            $ip = $pg['indicador'] ?? 0;
            in_array($ip, [0, 1], true) ? $op['indicador'] = $ip : $e->add("$f.indicador", '0 à vista, 1 a prazo');
            if ($forma === '99') {
                $op['descricao'] = Validate::text($pg['descricao'] ?? null, 2, 60) ?? $e->add("$f.descricao", 'Obrigatória para forma 99');
            }
            if ($nfce && $forma === '90') {
                $e->add("$f.forma", 'NFC-e não aceita "90" (sem pagamento): informe a forma usada pelo consumidor');
            }
            // grupo card (cartão/pagamento eletrônico): opcional; na NFC-e com cartão o padrão é tpIntegra=2 (não integrado)
            $card = $pg['cartao'] ?? null;
            if ($card !== null) {
                if (!is_array($card)) {
                    $e->add("$f.cartao", 'Objeto esperado');
                } else {
                    $oc = [];
                    $ti = $card['integracao'] ?? 2;
                    in_array($ti, [1, 2], true) ? $oc['integracao'] = $ti : $e->add("$f.cartao.integracao", '1 (integrado ao TEF/POS) ou 2 (não integrado)');
                    if (isset($card['cnpj_credenciadora'])) {
                        $cc = Validate::digits($card['cnpj_credenciadora']);
                        Validate::cnpj($cc) ? $oc['cnpj_credenciadora'] = $cc : $e->add("$f.cartao.cnpj_credenciadora", 'CNPJ inválido');
                    }
                    if (isset($card['bandeira'])) {
                        is_string($card['bandeira']) && preg_match('/^\d{2}$/', $card['bandeira']) ? $oc['bandeira'] = $card['bandeira'] : $e->add("$f.cartao.bandeira", 'Código tBand com 2 dígitos (ex.: "01" Visa, "02" Mastercard)');
                    }
                    if (isset($card['autorizacao'])) {
                        is_string($card['autorizacao']) && preg_match('/^[A-Za-z0-9]{1,128}$/', $card['autorizacao']) ? $oc['autorizacao'] = $card['autorizacao'] : $e->add("$f.cartao.autorizacao", '1 a 128 letras/números');
                    }
                    $op['cartao'] = $oc;
                }
            } elseif ($nfce && in_array($forma, ['03', '04'], true)) {
                $op['cartao'] = ['integracao' => 2];
            }
            $o['pagamentos'][] = $op;
        }
        $troco = Validate::decimal($p['troco'] ?? 0, 2);
        $troco !== null ? $o['troco'] = $troco : $e->add('troco', 'Número >= 0');
        if (!empty($o['pagamentos'][0]['valor_auto']) && ($o['troco'] ?? 0) > 0) {
            $e->add('troco', 'Com o valor do pagamento omitido (total da nota) não há troco');
        }

        foreach (['informacoes_complementares' => 5000, 'informacoes_fisco' => 2000] as $f => $max) {
            if (isset($p[$f])) {
                $o[$f] = Validate::text($p[$f], 1, $max) ?? $e->add($f, "1 a $max caracteres");
            }
        }

        $e->throwIfAny();
        return $o;
    }

    /**
     * Monta o XML com Make.
     * @param array<string,mixed> $p payload já validado
     * @param array<string,mixed> $cfg configuração do emitente
     * @return array{xml:string, chave:string, vNF:float, numero:int, serie:int, modelo:int, reforma_tributaria:array<string,mixed>, avisos:list<string>}
     */
    public static function build(array $p, array $cfg, int $numero, int $serie, array $identidade = []): array
    {
        if (empty($cfg['ie'])) {
            throw ApiException::validation([['campo' => 'config.ie', 'mensagem' => 'Informe a IE do emitente antes de gerar NF-e/NFC-e. O cadastro sem IE é permitido para NFS-e.']]);
        }
        $p = self::padroesFiscais($p, $cfg);
        $o = static fn(array $a): \stdClass => (object) $a;
        $dhEmi = new \DateTimeImmutable($identidade['data_emissao'] ?? 'now', new \DateTimeZone('America/Sao_Paulo'));
        // Reforma Tributária: grupos IBSCBS/IS por item (NT 2025.002). Sem grupos, a nota sai exatamente como antes.
        $rtcInformada = \App\Fiscal\RtcInformada::ativa($p);
        $rtc = $rtcInformada ? \App\Fiscal\RtcInformada::preparar($p) : \App\Fiscal\ReformaTributaria::semGrupos($p);
        $make = $rtc['usar_pl010'] ? new Make(\App\Fiscal\ReformaTributaria::MAKE_SCHEMA) : new Make();
        $make->setOnlyAscii(false);
        $make->setCheckGtin(true);

        $uf = $cfg['uf'];
        $end = $cfg['endereco'];
        $mod = (int) ($p['modelo'] ?? 55);
        $nfce = $mod === 65;
        $dest = $p['destinatario'] ?? null;
        $ufDest = $dest['endereco']['uf'] ?? $uf;
        if ($nfce && $ufDest !== $uf) {
            // NFC-e é sempre operação interna (idDest=1)
            throw ApiException::validation([['campo' => 'destinatario.endereco.uf', 'mensagem' => "NFC-e é operação interna: a UF do consumidor deve ser a do emitente ($uf)"]]);
        }

        try {
            $make->taginfNFe($o(['versao' => '4.00', 'Id' => null]));
            $make->tagide($o([
                'cUF' => Validate::UFS[$uf],
                'cNF' => $identidade['cnf'] ?? null,
                'natOp' => $p['natureza_operacao'],
                'mod' => $mod,
                'serie' => $serie,
                'nNF' => $numero,
                'dhEmi' => $dhEmi->format('Y-m-d\TH:i:sP'),
                'dhSaiEnt' => null,
                'tpNF' => $p['tipo_operacao'],
                'idDest' => $ufDest === $uf ? 1 : 2,
                'cMunFG' => $end['codigo_municipio'],
                'tpImp' => $p['tipo_impressao'] ?? ($nfce ? 4 : 1),
                'tpEmis' => $identidade['tipo_emissao'] ?? 1,
                'dhCont' => $identidade['data_contingencia'] ?? null,
                'xJust' => $identidade['motivo_contingencia'] ?? null,
                'tpAmb' => (int) $cfg['ambiente'],
                'finNFe' => $p['finalidade'],
                'indFinal' => $p['consumidor_final'],
                'indPres' => $p['presenca'],
                'indIntermed' => in_array($p['presenca'], [2, 3, 4, 9], true) ? (isset($p['intermediador']) ? 1 : 0) : null,
                'procEmi' => 0,
                'verProc' => 'sistema-nfephp 1.0',
            ]));
            foreach ($p['nfe_referenciadas'] as $ch) {
                $make->tagrefNFe($o(['refNFe' => $ch]));
            }
            $make->tagemit($o([
                'xNome' => $cfg['razao_social'],
                'xFant' => $cfg['nome_fantasia'] ?? null,
                'IE' => $cfg['ie'],
                'CRT' => $cfg['crt'],
                'CNPJ' => $cfg['cnpj'],
            ]));
            $make->tagenderEmit($o([
                'xLgr' => $end['logradouro'], 'nro' => $end['numero'], 'xCpl' => $end['complemento'] ?? null,
                'xBairro' => $end['bairro'], 'cMun' => $end['codigo_municipio'], 'xMun' => $end['municipio'],
                'UF' => $uf, 'CEP' => $end['cep'], 'cPais' => '1058', 'xPais' => 'BRASIL', 'fone' => $end['telefone'] ?? null,
            ]));
            if ($dest !== null) {
                $make->tagdest($o([
                    'xNome' => $dest['nome'] ?? null,
                    'indIEDest' => $dest['indicador_ie'],
                    'IE' => ($dest['indicador_ie'] === 1) ? ($dest['ie'] ?? null) : null,
                    'email' => $dest['email'] ?? null,
                    'CNPJ' => $dest['cnpj'] ?? null,
                    'CPF' => $dest['cpf'] ?? null,
                ]));
                if (isset($dest['endereco'])) {
                    $de = $dest['endereco'];
                    $make->tagenderDest($o([
                        'xLgr' => $de['logradouro'], 'nro' => $de['numero'], 'xCpl' => $de['complemento'] ?? null,
                        'xBairro' => $de['bairro'], 'cMun' => $de['codigo_municipio'], 'xMun' => $de['municipio'],
                        'UF' => $de['uf'], 'CEP' => $de['cep'], 'cPais' => '1058', 'xPais' => 'BRASIL', 'fone' => $de['telefone'] ?? null,
                    ]));
                }
            }

            foreach ($p['itens'] as $idx => $it) {
                $n = $idx + 1;
                $make->tagprod($o([
                    'item' => $n,
                    'cProd' => $it['codigo'],
                    'cEAN' => $it['gtin'],
                    'xProd' => $it['descricao'],
                    'NCM' => $it['ncm'],
                    'CFOP' => $it['cfop'],
                    'uCom' => $it['unidade'],
                    'qCom' => $it['quantidade'],
                    'vUnCom' => $it['valor_unitario'],
                    'vProd' => $it['valor_total'],
                    'cEANTrib' => $it['gtin'],
                    'uTrib' => $it['unidade'],
                    'qTrib' => $it['quantidade'],
                    'vUnTrib' => $it['valor_unitario'],
                    'vFrete' => $it['frete'] ?? null,
                    'vSeg' => $it['seguro'] ?? null,
                    'vDesc' => $it['desconto'] ?? null,
                    'vOutro' => $it['outras_despesas'] ?? null,
                    'indTot' => 1,
                ]));
                if (isset($it['cest'])) {
                    $make->tagCEST($o(['item' => $n, 'CEST' => $it['cest']]));
                }
                if (isset($it['informacoes_adicionais'])) {
                    $make->taginfAdProd($o(['item' => $n, 'infAdProd' => $it['informacoes_adicionais']]));
                }
                $make->tagimposto($o(['item' => $n, 'vTotTrib' => $it['valor_aproximado_tributos'] ?? null]));
                $icms = $it['impostos']['icms'];
                if (isset($icms['CSOSN'])) {
                    $make->tagICMSSN($o(['item' => $n] + $icms));
                } else {
                    $make->tagICMS($o(['item' => $n] + $icms));
                }
                if (isset($it['impostos']['ipi'])) {
                    $make->tagIPI($o(['item' => $n] + $it['impostos']['ipi']));
                }
                $make->tagPIS($o(['item' => $n] + $it['impostos']['pis']));
                $make->tagCOFINS($o(['item' => $n] + $it['impostos']['cofins']));
                if ($rtc['is'][$idx] !== null) {
                    $make->tagIS($o($rtc['is'][$idx]));
                }
                if ($rtc['itens'][$idx] !== null) {
                    $make->tagIBSCBS($o($rtc['itens'][$idx]));
                }
            }

            $make->tagtransp($o(['modFrete' => $p['frete']['modalidade']]));
            $make->tagpag($o(['vTroco' => $p['troco'] > 0 ? $p['troco'] : null]));
            foreach ($p['pagamentos'] as $pg) {
                $make->tagdetPag($o([
                    'indPag' => $pg['indicador'],
                    'tPag' => $pg['forma'],
                    'xPag' => $pg['descricao'] ?? null,
                    'vPag' => $pg['valor'],
                    'tpIntegra' => $pg['cartao']['integracao'] ?? null,
                    'CNPJ' => $pg['cartao']['cnpj_credenciadora'] ?? null,
                    'tBand' => $pg['cartao']['bandeira'] ?? null,
                    'cAut' => $pg['cartao']['autorizacao'] ?? null,
                ]));
            }
            if (isset($p['informacoes_complementares']) || isset($p['informacoes_fisco'])) {
                $make->taginfAdic($o([
                    'infCpl' => $p['informacoes_complementares'] ?? null,
                    'infAdFisco' => $p['informacoes_fisco'] ?? null,
                ]));
            }
            if (isset($p['intermediador'])) {
                
                $make->tagIntermed($o(['CNPJ' => $p['intermediador']['cnpj'], 'idCadIntTran' => $p['intermediador']['identificador']]));
            }
            $rt=\App\Fiscal\ApiConfigService::get()['responsavel_tecnico']??null;
            if ($rt!==null) {
                $make->taginfRespTec($o(['CNPJ' => $rt['cnpj'], 'xContato' => $rt['contato'], 'email' => $rt['email'], 'fone' => $rt['fone']]));
            }
            $xml = $make->getXML();
        } catch (ApiException $ex) {
            throw $ex;
        } catch (\Throwable $ex) {
            $errs = $make->getErrors();
            throw ApiException::validation(
                $errs !== [] ? self::fmtErrors($errs) : [['campo' => 'nfe', 'mensagem' => self::safeMsg($ex->getMessage())]],
                'Não foi possível montar a NF-e'
            );
        }

        $errs = $make->getErrors();
        if ($errs !== []) {
            throw ApiException::validation(self::fmtErrors($errs), 'A NF-e montada contém erros');
        }

        if ($rtcInformada) {
            $xml = \App\Fiscal\RtcInformada::aplicar($xml, $p);
        }
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $vNF = (float) ($dom->getElementsByTagName('vNF')->item(0)?->nodeValue ?? 0);
        if ($rtcInformada) {
            $vNF = (float) $p['reforma_tributaria']['totais']['vNFTot'];
        }

        if (!empty($p['pagamentos'][0]['valor_auto'])) {
            // pagamento único sem valor: vPag = vNF (a forma "90" continua com zero)
            $vPag = $dom->getElementsByTagName('vPag')->item(0);
            if ($vPag !== null && $p['pagamentos'][0]['forma'] !== '90') {
                $vPag->nodeValue = number_format($vNF, 2, '.', '');
                $xml = $dom->saveXML($dom->documentElement) ?: $xml;
                $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xml;
                $p['pagamentos'][0]['valor'] = $vNF;
            }
        }

        // Coerência pagamento x total (SEFAZ rejeita divergências)
        $semPagamento = count($p['pagamentos']) === 1 && $p['pagamentos'][0]['forma'] === '90';
        if (!$semPagamento) {
            $soma = array_sum(array_column($p['pagamentos'], 'valor'));
            if (abs(($soma - $p['troco']) - $vNF) > 0.009) {
                throw ApiException::validation([[
                    'campo' => 'pagamentos',
                    'mensagem' => sprintf('Soma dos pagamentos (%.2f) menos troco (%.2f) deve ser igual ao total da nota (%.2f)', $soma, $p['troco'], $vNF),
                ]]);
            }
        }

        return ['xml' => $xml, 'chave' => $make->getChave(), 'vNF' => $vNF, 'numero' => $numero, 'serie' => $serie, 'modelo' => $mod,
            'reforma_tributaria' => $rtc['resumo'], 'avisos' => $rtc['avisos']];
    }

    /**
     * Valida o destinatário (mesmas regras na emissão e no cadastro de clientes do catálogo).
     * @param array<mixed> $d
     * @return array<string,mixed>
     */
    public static function validarDestinatario(array $d, Errors $e, bool $nfce, bool $endObrig, string $prefix = 'destinatario'): array
    {
        $od = [];
        $cpf = Validate::digits($d['cpf'] ?? '');
        $cnpj = Validate::digits($d['cnpj'] ?? '');
        if ($cnpj !== '') {
            Validate::cnpj($cnpj) ? $od['cnpj'] = $cnpj : $e->add("$prefix.cnpj", 'CNPJ inválido');
        } elseif ($cpf !== '') {
            Validate::cpf($cpf) ? $od['cpf'] = $cpf : $e->add("$prefix.cpf", 'CPF inválido');
        } else {
            $e->add($prefix, $nfce ? 'Informe cpf ou cnpj do consumidor (ou omita o destinatário)' : 'Informe cpf ou cnpj');
        }
        if ($nfce) {
            if (isset($d['nome']) && $d['nome'] !== '') {
                $od['nome'] = Validate::text($d['nome'], 2, 60) ?? $e->add("$prefix.nome", '2 a 60 caracteres');
            }
            if ((isset($d['ie']) && $d['ie'] !== '') || (isset($d['indicador_ie']) && $d['indicador_ie'] !== 9)) {
                $e->add("$prefix.ie", 'NFC-e não aceita inscrição estadual do destinatário (indicador_ie é sempre 9)');
            }
            $od['indicador_ie'] = 9;
        } else {
            $od['nome'] = Validate::text($d['nome'] ?? null, 2, 60) ?? $e->add("$prefix.nome", '2 a 60 caracteres');
            $ie = $d['ie'] ?? null;
            if ($ie !== null && $ie !== '') {
                $ie = strtoupper(trim((string) $ie));
                if ($ie !== 'ISENTO') {
                    $ie = Validate::digits($ie);
                    preg_match('/^\d{2,14}$/', $ie) ?: $e->add("$prefix.ie", '2 a 14 dígitos ou ISENTO');
                }
                $od['ie'] = $ie;
            }
            $ind = $d['indicador_ie'] ?? (isset($od['ie']) ? ($od['ie'] === 'ISENTO' ? 2 : 1) : 9);
            in_array($ind, [1, 2, 9], true) ? $od['indicador_ie'] = $ind : $e->add("$prefix.indicador_ie", '1, 2 ou 9');
        }
        if (isset($d['email'])) {
            is_string($d['email']) && strlen($d['email']) <= 60 && filter_var($d['email'], FILTER_VALIDATE_EMAIL)
                ? $od['email'] = $d['email'] : $e->add("$prefix.email", 'E-mail inválido');
        }
        if (is_array($d['endereco'] ?? null)) {
            $od['endereco'] = ConfigService::endereco($d['endereco'], "$prefix.endereco", $e);
        } elseif ($endObrig || isset($d['endereco'])) {
            $e->add("$prefix.endereco", $nfce ? 'Obrigatório na NFC-e com entrega em domicílio (presença 4)' : 'Obrigatório');
        }
        return $od;
    }

    
    public static function validarItem(array $it, string $f, Errors $e, bool $nfce, bool $catalogo = false, bool $rtcInformada = false): array
    {
        $oi = [];
        if (isset($it['valor_item'])) {
            if (!$rtcInformada || !is_string($it['valor_item']) || !preg_match('/^\d{1,13}\.\d{2}$/D', $it['valor_item'])) {
                $e->add("$f.valor_item", 'Informe vItem como texto decimal com 2 casas no modo RTC informado');
            } else {
                $oi['valor_item'] = $it['valor_item'];
            }
        }
        $oi['codigo'] = Validate::text($it['codigo'] ?? null, 1, 60) ?? $e->add("$f.codigo", '1 a 60 caracteres');
        $oi['descricao'] = Validate::text($it['descricao'] ?? null, 1, 120) ?? $e->add("$f.descricao", '1 a 120 caracteres');
        $ncm = Validate::digits($it['ncm'] ?? '');
        preg_match('/^(\d{8}|00)$/', $ncm) ? $oi['ncm'] = $ncm : $e->add("$f.ncm", 'NCM com 8 dígitos');
        $cfop = Validate::digits($it['cfop'] ?? '');
        if (!preg_match('/^[1-35-7]\d{3}$/', $cfop)) {
            $e->add("$f.cfop", 'CFOP com 4 dígitos');
        } elseif ($nfce && $cfop[0] !== '5') {
            $e->add("$f.cfop", 'NFC-e só aceita CFOP de saída interna (5xxx, ex.: 5102 ou 5405)');
        } else {
            $oi['cfop'] = $cfop;
        }
        $oi['unidade'] = Validate::text($it['unidade'] ?? null, 1, 6) ?? $e->add("$f.unidade", '1 a 6 caracteres');
        if (!$catalogo) {
            $q = Validate::decimal($it['quantidade'] ?? null, 4, 99999999999.9999);
            $q !== null && $q > 0 ? $oi['quantidade'] = $q : $e->add("$f.quantidade", 'Número > 0, até 4 casas');
        }
        $vu = Validate::decimal($it['valor_unitario'] ?? null, 10, 9999999999.9999999999);
        $vu !== null && $vu > 0 ? $oi['valor_unitario'] = $vu : $e->add("$f.valor_unitario", 'Número > 0, até 10 casas');
        $gtin = $it['gtin'] ?? 'SEM GTIN';
        is_string($gtin) && preg_match('/^(SEM GTIN|\d{8}|\d{12,14})$/', $gtin) ? $oi['gtin'] = $gtin : $e->add("$f.gtin", '"SEM GTIN" ou 8/12/13/14 dígitos');
        if (isset($it['cest'])) {
            $cest = Validate::digits($it['cest']);
            preg_match('/^\d{7}$/', $cest) ? $oi['cest'] = $cest : $e->add("$f.cest", 'CEST com 7 dígitos');
        }
        foreach (['desconto', 'frete', 'seguro', 'outras_despesas', 'valor_aproximado_tributos'] as $vf) {
            if (isset($it[$vf])) {
                $v = Validate::decimal($it[$vf], 2);
                $v !== null ? $oi[$vf] = $v : $e->add("$f.$vf", 'Número >= 0, 2 casas');
            }
        }
        if (isset($it['informacoes_adicionais'])) {
            $oi['informacoes_adicionais'] = Validate::text($it['informacoes_adicionais'], 1, 500) ?? $e->add("$f.informacoes_adicionais", '1 a 500 caracteres');
        }
        if (isset($oi['quantidade'], $oi['valor_unitario'])) {
            $oi['valor_total'] = round($oi['quantidade'] * $oi['valor_unitario'], 2);
        }
        // impostos: repassados ao Make com os nomes de campo do leiaute (CST, CSOSN, vBC, pICMS...)
        // no catálogo os impostos são um padrão opcional; se informados, seguem as mesmas regras
        $semImpostos = $catalogo && !isset($it['impostos']);
        if (isset($it['impostos']) && (!is_array($it['impostos']) || array_is_list($it['impostos']))) {
            $e->add("$f.impostos", 'Objeto com icms, pis, cofins e (opcional) ipi');
            $semImpostos = true;
        }
        if (!$semImpostos && is_array($it['impostos'] ?? null)) {
            // IBS/CBS e IS: estrutura XSD e valores informados pelo consumidor, sem tabelas ou cálculo.
            if (isset($it['impostos']['ibscbs'])) {
                $rt = $rtcInformada
                    ? \App\Fiscal\RtcInformada::validarGrupo($it['impostos']['ibscbs'], 'TTribNFe', "$f.impostos.ibscbs", $e)
                    : ($e->add("$f.impostos.ibscbs", 'Envie reforma_tributaria.modo=informado e os valores calculados no JSON') ?? null);
                if ($rt !== null) {
                    $oi['impostos']['ibscbs'] = $rt;
                }
            }
            if (isset($it['impostos']['is'])) {
                $rt = $rtcInformada
                    ? \App\Fiscal\RtcInformada::validarGrupo($it['impostos']['is'], 'TIS', "$f.impostos.is", $e)
                    : ($e->add("$f.impostos.is", 'Envie reforma_tributaria.modo=informado e os valores calculados no JSON') ?? null);
                if ($rt !== null) {
                    $oi['impostos']['is'] = $rt;
                }
            }
        }
        foreach (['icms' => true, 'pis' => true, 'cofins' => true, 'ipi' => false] as $tax => $required) {
            if ($semImpostos) {
                break;
            }
            $t = $it['impostos'][$tax] ?? null;
            if ($t === null) {
                if ($required) {
                    $e->add("$f.impostos.$tax", 'Obrigatório');
                }
                continue;
            }
            if (!is_array($t) || array_is_list($t) || count($t) > 40) {
                $e->add("$f.impostos.$tax", 'Objeto com campos do leiaute');
                continue;
            }
            $clean = [];
            foreach ($t as $k => $v) {
                if (!is_string($k) || !preg_match(self::TAX_KEY, $k) || strtolower($k) === 'item') {
                    $e->add("$f.impostos.$tax.$k", 'Campo não permitido');
                } elseif (!is_scalar($v) || (is_string($v) && !preg_match('/^[A-Za-z0-9 .,\-\/]{0,60}$/', $v))) {
                    $e->add("$f.impostos.$tax.$k", 'Valor inválido');
                } else {
                    $clean[$k] = $v;
                }
            }
            if ($tax === 'icms' && !isset($clean['CST']) && !isset($clean['CSOSN'])) {
                $e->add("$f.impostos.icms", 'Informe CST (regime normal) ou CSOSN (Simples Nacional)');
            }
            if (in_array($tax, ['pis', 'cofins', 'ipi'], true) && !isset($clean['CST'])) {
                $e->add("$f.impostos.$tax", 'CST obrigatório');
            }
            // PIS/COFINS "outras operações" (49, 98, 99) sem valores: zerados, como no Simples Nacional.
            // Sem isso o leiaute (PISOutr/COFINSOutr) exige vBC/aliquota/valor e a nota é rejeitada.
            if (in_array($tax, ['pis', 'cofins'], true) && in_array((string) ($clean['CST'] ?? ''), ['49', '98', '99'], true)) {
                $U = strtoupper($tax);
                $temValor = array_intersect_key($clean, array_flip(['vBC', "p$U", 'qBCProd', 'vAliqProd', "v$U"])) !== [];
                if (!$temValor) {
                    $clean += ['vBC' => 0, "p$U" => 0, "v$U" => 0];
                }
            }
            $oi['impostos'][$tax] = $clean;
        }
        return $oi;
    }

    public const TEXTO_SIMPLES = 'Documento emitido por ME ou EPP optante pelo Simples Nacional.';

    /**
     * Textos legais automáticos (aplicados no dry-run e na emissão, então a prévia é igual ao enviado):
     *  - CRT 1 (Simples Nacional) ou 4 (MEI) sem "informacoes_fisco": infAdFisco com o texto padrão do Simples;
     *  - itens com "valor_aproximado_tributos": acrescenta ao infCpl a frase da Lei 12.741/2012 com o total,
     *    a menos que o texto já cite a lei (ou que o limite de 5000 caracteres do infCpl seja ultrapassado).
     * @param array<string,mixed> $p payload já validado
     * @param array<string,mixed> $cfg
     * @return array<string,mixed>
     */
    public static function padroesFiscais(array $p, array $cfg): array
    {
        if (in_array((int) ($cfg['crt'] ?? 0), [1, 4], true) && !isset($p['informacoes_fisco'])) {
            $p['informacoes_fisco'] = self::TEXTO_SIMPLES;
        }
        $tributos = 0.0;
        foreach ($p['itens'] ?? [] as $it) {
            $tributos += (float) ($it['valor_aproximado_tributos'] ?? 0);
        }
        $tributos = round($tributos, 2);
        if ($tributos > 0) {
            $cpl = (string) ($p['informacoes_complementares'] ?? '');
            if (!str_contains($cpl, '12.741') && !str_contains($cpl, '12741')) {
                $frase = self::fraseLeiTransparencia($tributos);
                $novo = $cpl === '' ? $frase : rtrim($cpl) . ' ' . $frase;
                if (mb_strlen($novo) <= 5000) {
                    $p['informacoes_complementares'] = $novo;
                }
            }
        }
        return $p;
    }

    public static function fraseLeiTransparencia(float $valor): string
    {
        return 'Valor aproximado dos tributos: R$ ' . number_format($valor, 2, ',', '.') . ' (Lei 12.741/2012)';
    }

    /** @param array<mixed> $errs @return list<array{campo:string,mensagem:string}> */
    private static function fmtErrors(array $errs): array
    {
        $out = [];
        foreach ($errs as $err) {
            $out[] = ['campo' => 'nfe', 'mensagem' => self::safeMsg(is_string($err) ? $err : json_encode($err))];
        }
        return $out;
    }

    private static function safeMsg(string $m): string
    {
        // mensagens da biblioteca são descritivas do leiaute; removemos caminhos de arquivo
        return mb_substr(preg_replace('#(/[\w.\-]+)+\.php(:\d+)?#', '[...]', $m) ?? $m, 0, 500);
    }
}
