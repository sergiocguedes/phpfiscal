<?php

declare(strict_types=1);

namespace App\Tributos;

use App\Nfse\Listas;

/**
 * Textos para o ERP mostrar as regras fiscais ao usuário: nomes de perfil/operação, descrição do CST e cada campo
 * da regra com rótulo e significado do valor. Só apresentação: não altera a regra gravada.
 */
final class RegraDetalhe
{
    public const TIPOS_PESSOA = ['F' => 'Física', 'J' => 'Jurídica', 'E' => 'Estrangeiro'];

    public const CST_ICMS = [
        '00' => 'Tributada integralmente', '02' => 'Monofásico próprio (combustíveis)', '10' => 'Tributada com ICMS ST',
        '20' => 'Com redução de base de cálculo', '30' => 'Isenta/não tributada com ICMS ST', '40' => 'Isenta',
        '41' => 'Não tributada', '50' => 'Suspensão', '51' => 'Diferimento', '60' => 'ICMS cobrado anteriormente por ST',
        '61' => 'Monofásico cobrado anteriormente', '70' => 'Redução de base com ICMS ST', '90' => 'Outras',
        '101' => 'Simples: com permissão de crédito', '102' => 'Simples: sem permissão de crédito',
        '103' => 'Simples: isenção por faixa de receita', '201' => 'Simples: com crédito e ICMS ST',
        '202' => 'Simples: sem crédito e com ICMS ST', '203' => 'Simples: isenção por faixa e ICMS ST', '300' => 'Simples: imune',
        '400' => 'Simples: não tributada', '500' => 'Simples: ICMS cobrado anteriormente por ST', '900' => 'Simples: outros',
    ];

    public const CST_PISCOFINS = [
        '01' => 'Alíquota básica', '02' => 'Alíquota diferenciada', '03' => 'Alíquota por unidade de medida',
        '04' => 'Monofásica, revenda a alíquota zero', '05' => 'Substituição tributária', '06' => 'Alíquota zero',
        '07' => 'Isenta', '08' => 'Sem incidência', '09' => 'Suspensão', '49' => 'Outras operações de saída',
        '50' => 'Crédito: receita tributada no mercado interno', '51' => 'Crédito: receita não tributada no mercado interno',
        '52' => 'Crédito: receita de exportação', '53' => 'Crédito: receitas tributadas e não tributadas no mercado interno',
        '54' => 'Crédito: receitas tributadas no mercado interno e de exportação',
        '55' => 'Crédito: receitas não tributadas no mercado interno e de exportação', '56' => 'Crédito: receitas de todos os mercados',
        '60' => 'Crédito presumido: receita tributada no mercado interno', '61' => 'Crédito presumido: receita não tributada no mercado interno',
        '62' => 'Crédito presumido: receita de exportação', '63' => 'Crédito presumido: receitas tributadas e não tributadas no mercado interno',
        '64' => 'Crédito presumido: receitas tributadas no mercado interno e de exportação',
        '65' => 'Crédito presumido: receitas não tributadas no mercado interno e de exportação',
        '66' => 'Crédito presumido: receitas de todos os mercados', '67' => 'Crédito presumido: outras operações',
        '70' => 'Aquisição sem direito a crédito', '71' => 'Aquisição com isenção', '72' => 'Aquisição com suspensão',
        '73' => 'Aquisição a alíquota zero', '74' => 'Aquisição sem incidência', '75' => 'Aquisição por substituição tributária',
        '98' => 'Outras operações de entrada', '99' => 'Outras operações',
    ];

    /** CST do IBS/CBS (Informe Técnico 2025.002). */
    public const CST_IBSCBS = [
        '000' => 'Tributação integral', '010' => 'Tributação com alíquotas uniformes', '011' => 'Tributação com alíquotas uniformes reduzidas',
        '200' => 'Alíquota reduzida', '220' => 'Alíquota fixa', '221' => 'Alíquota fixa proporcional', '222' => 'Redução de base de cálculo',
        '400' => 'Isenção', '410' => 'Imunidade e não incidência', '510' => 'Diferimento', '515' => 'Diferimento com redução de alíquota',
        '550' => 'Suspensão', '620' => 'Tributação monofásica', '800' => 'Transferência de crédito', '810' => 'Ajuste de IBS na ZFM',
        '811' => 'Ajustes', '820' => 'Tributação em documento específico', '830' => 'Exclusão da base de cálculo',
    ];

    /** Rótulo e, quando é código, o significado de cada valor. */
    private const CAMPOS = [
        'icms' => [
            'CST' => ['CST do ICMS', self::CST_ICMS], 'CSOSN' => ['CSOSN', self::CST_ICMS],
            'modBC' => ['Modalidade da BC', ['0' => 'Margem de valor agregado', '1' => 'Pauta', '2' => 'Preço tabelado máximo', '3' => 'Valor da operação']],
            'pICMS' => ['Alíquota do ICMS (%)'], 'pRedBC' => ['Redução da BC do ICMS (%)'], 'pFCP' => ['FCP (%)'], 'pDif' => ['Diferimento (%)'],
            'pCredSN' => ['Alíquota de crédito do Simples (%)'],
            'modBCST' => ['Modalidade da BC ST', ['0' => 'Preço tabelado (pauta do produto)', '4' => 'Margem de valor agregado (MVA)',
                '5' => 'Pauta (do produto)', '6' => 'Valor da operação']],
            'pMVAST' => ['MVA ST (%)'], 'pICMSST' => ['Alíquota do ICMS ST (%)'], 'pRedBCST' => ['Redução da BC ST (%)'], 'pFCPST' => ['FCP ST (%)'],
            'motDesICMS' => ['Motivo da desoneração', ['1' => 'Táxi', '3' => 'Produtor agropecuário', '4' => 'Frotista/locadora',
                '5' => 'Diplomático/consular', '6' => 'Utilitários e motos (Amazônia Ocidental/ALC)', '7' => 'SUFRAMA', '8' => 'Venda a órgão público',
                '9' => 'Outros', '10' => 'Deficiente condutor', '11' => 'Deficiente não condutor', '12' => 'Órgão de fomento', '16' => 'Olimpíadas Rio 2016',
                '90' => 'Solicitado pelo Fisco']],
            'indDeduzDeson' => ['Deduz a desoneração do total da nota', ['0' => 'Não', '1' => 'Sim']],
            'metodo_desoneracao' => ['Cálculo do ICMS desonerado', ['por_fora' => 'Por fora: base × alíquota',
                'por_dentro' => 'Por dentro: base ÷ (1 − alíquota) × alíquota']],
        ],
        'ipi' => ['CST' => ['CST do IPI'], 'cEnq' => ['Enquadramento legal do IPI'], 'pIPI' => ['Alíquota do IPI (%)'], 'vUnid' => ['IPI por unidade (R$)']],
        'pis' => ['CST' => ['CST do PIS', self::CST_PISCOFINS], 'pPIS' => ['Alíquota do PIS (%)'], 'vAliqProd' => ['PIS por unidade (R$)']],
        'cofins' => ['CST' => ['CST da COFINS', self::CST_PISCOFINS], 'pCOFINS' => ['Alíquota da COFINS (%)'], 'vAliqProd' => ['COFINS por unidade (R$)']],
        'servico' => ['codigo_tributacao_nacional' => ['Código de tributação nacional'], 'codigo_tributacao_municipal' => ['Código de tributação municipal'],
            'nbs' => ['NBS'], 'codigo_interno' => ['Código interno do serviço']],
        'iss' => [
            'aliquota_iss' => ['Alíquota do ISS (%)'],
        ],
        'federais' => [
            'cst_pis_cofins' => ['CST do PIS/COFINS', RegrasIss::CST_PIS_COFINS_DESCRICAO + self::CST_PISCOFINS],
            'aliquota_pis' => ['Alíquota do PIS (%)'], 'aliquota_cofins' => ['Alíquota da COFINS (%)'],
            'aliquota_irrf' => ['Alíquota do IRRF (%)'], 'aliquota_csll' => ['Alíquota da CSLL (%)'],
            'tipo_retencao' => ['Retenção de PIS/COFINS/CSLL', RegrasIss::RETENCAO_PIS_COFINS],
        ],
        'ibs_cbs' => [
            'indicador_operacao' => ['Indicador da operação de fornecimento'], 'classificacao_tributaria' => ['cClassTrib'],
            'percentual_diferimento' => ['Diferimento do IBS/CBS (%)'],
            'operacao_onerosa' => ['Operação onerosa', ['1' => 'Sim', '' => 'Não']],
            'servico_exterior' => ['Serviço para o exterior', ['1' => 'Sim', '' => 'Não']],
        ],
    ];

    /** Linha de listagem da regra de ICMS. @param array<string,mixed> $r @param array<string,mixed> $cad @return array<string,mixed> */
    public static function resumoIcms(array $r, array $cad): array
    {
        $cst = (string) ($r['icms']['CST'] ?? $r['icms']['CSOSN'] ?? '');
        return $r + [
            'perfil_nome' => self::nome($cad['perfis'], $r['perfil']),
            'operacao_nome' => self::nome($cad['operacoes'], $r['operacao']),
            'tipo_pessoa_descricao' => self::TIPOS_PESSOA[$r['tipo_pessoa']] ?? null,
            'cst' => $cst,
            'cst_descricao' => self::CST_ICMS[$cst] ?? null,
        ];
    }

    /** @param array<string,mixed> $r @param array<string,mixed> $cad @return array<string,mixed> */
    public static function detalheIcms(array $r, array $cad): array
    {
        return self::resumoIcms($r, $cad) + ['campos' => self::campos($r, ['icms', 'ipi'], [['regra', 'cfop', 'CFOP', $r['cfop'] ?? null, null]])];
    }

    /** @param array<string,mixed> $r @param array<string,mixed> $cad @return array<string,mixed> */
    public static function resumoPisCofins(array $r, array $cad): array
    {
        return $r + [
            'perfil_nome' => self::nome($cad['perfis'], $r['perfil']),
            'operacao_nome' => self::nome($cad['operacoes'], $r['operacao']),
            'pis_cst_descricao' => self::CST_PISCOFINS[(string) ($r['pis']['CST'] ?? '')] ?? null,
            'cofins_cst_descricao' => self::CST_PISCOFINS[(string) ($r['cofins']['CST'] ?? '')] ?? null,
        ];
    }

    /** @param array<string,mixed> $r @param array<string,mixed> $cad @return array<string,mixed> */
    public static function detalhePisCofins(array $r, array $cad): array
    {
        return self::resumoPisCofins($r, $cad) + ['campos' => self::campos($r, ['pis', 'cofins'])];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function resumoIss(array $r): array
    {
        $ctn = (string) ($r['servico']['codigo_tributacao_nacional'] ?? '');
        $sv = Listas::servico($ctn);
        return $r + [
            'servico_descricao' => $sv['descricao'] ?? null,
            'item_lc116' => $sv['item_lc116'] ?? null,
            'tipo_pessoa_descricao' => RegrasIss::TIPOS_PESSOA[(string) ($r['tipo_pessoa'] ?? '')] ?? null,
            'retencao_federais_descricao' => isset($r['federais']) ? (RegrasIss::RETENCAO_PIS_COFINS[(string) ($r['federais']['tipo_retencao'] ?? '')] ?? null) : null,
        ];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function detalheIss(array $r): array
    {
        $r['iss'] = array_intersect_key($r['iss'] ?? [], ['aliquota_iss' => true]); // regras antigas: só a alíquota vale
        $out = self::resumoIss($r) + ['campos' => self::campos($r, ['servico', 'iss', 'federais', 'ibs_cbs'])];
        foreach ($out['campos'] as &$c) {
            if ($c['campo'] === 'codigo_tributacao_nacional') {
                $c['valor_descricao'] = $out['servico_descricao'];
            } elseif ($c['campo'] === 'nbs') {
                $c['valor_descricao'] = Listas::nbsDescricao((string) $c['valor']);
            }
        }
        unset($c);
        return $out;
    }

    /**
     * @param array<string,mixed> $r
     * @param list<string> $grupos
     * @param list<array{0:string,1:string,2:string,3:mixed,4:?string}> $iniciais
     * @return list<array{grupo:string,campo:string,rotulo:string,valor:mixed,valor_descricao:?string}>
     */
    private static function campos(array $r, array $grupos, array $iniciais = []): array
    {
        $out = [];
        foreach ($iniciais as [$g, $k, $rot, $v, $d]) {
            $out[] = ['grupo' => $g, 'campo' => $k, 'rotulo' => $rot, 'valor' => $v, 'valor_descricao' => $d];
        }
        foreach ($grupos as $g) {
            foreach ($r[$g] ?? [] as $k => $v) {
                $def = self::CAMPOS[$g][$k] ?? [$k];
                $out[] = ['grupo' => $g, 'campo' => (string) $k, 'rotulo' => $def[0], 'valor' => $v,
                    'valor_descricao' => isset($def[1]) ? ($def[1][(string) $v] ?? null) : null];
            }
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $lista */
    private static function nome(array $lista, string $codigo): ?string
    {
        foreach ($lista as $x) {
            if ($x['codigo'] === $codigo) {
                return $x['nome'];
            }
        }
        return null;
    }
}
