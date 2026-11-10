<?php

declare(strict_types=1);

namespace App\Tributos;

use App\Http\ApiException;
use App\Nfe\ConfigService;
use App\Support\Logger;
use App\Support\RequestContext;

/**
 * Cálculo de tributos dentro da emissão (POST /nfe e /pdv/nfce).
 *
 * Quando os itens trazem "regra" no lugar de "impostos", a API calcula com o mesmo motor de
 * POST /tributos/calcular e preenche itens[].impostos (e valor_aproximado_tributos) antes de montar o XML.
 * Itens só com "impostos" seguem o fluxo antigo, sem cálculo. Não é permitido misturar os dois na mesma nota.
 *
 * - Operação derivada da nota: uf_destino do endereço do destinatário (NF-e), consumidor_final,
 *   contribuinte_icms = destinatario.indicador_ie 1. Opções em nota.calculo_tributos.
 * - nota.calculo_tributos.calculo_id (opcional): id devolvido pela pré-visualização. Se o cálculo de agora
 *   for diferente (regra, valores ou leiaute mudaram), a emissão é recusada com 409 antes de numerar.
 * - Valor final informado na regra (ajuste manual) exige papel owner ou fiscal; fica no audit log e no registro.
 * - Pagamento único sem "valor" recebe o total calculado (vNF), que o sistema não conhece antes do cálculo.
 * - Perfis (perfil_icms, perfil_piscofins, origem no item; calculo_tributos.operacao): regra da matriz da empresa.
 * - IBS/CBS: cclasstrib no item e calculo_tributos.aliquotas_ibscbs {pIBSUF, pIBSMun, pCBS} informadas pelo sistema.
 * - IBS/CBS calculado (regime normal) vira itens[].impostos.ibscbs, valor_item e reforma_tributaria (totais), montados e
 *   validados no XSD pelo mesmo caminho do modo informado. RTC informada pelo sistema prevalece sobre a calculada.
 *   Tipo de pessoa do destinatário (CNPJ J, CPF F, estrangeiro E). natureza_operacao padrão = nome da operação.
 */
final class EmissaoTributos
{
    private const OPCOES = ['calculo_id', 'operacao', 'tipo_pessoa', 'excluir_icms_base_piscofins', 'aliquotas_ibscbs', 'credito_presumido'];
    /** Campos do item só usados no cálculo (não vão para o montador da NF-e). */
    private const CAMPOS_CALCULO = ['regra', 'perfil_icms', 'perfil_piscofins', 'cclasstrib', 'credito_presumido', 'origem', 'qtd_tributavel_unidade', 'valor_pauta_st'];
    /** Grupos de impostos que podem vir junto com a regra (Reforma Tributária informada). */
    private const IMPOSTOS_COM_REGRA = ['ibscbs', 'is'];

    /**
     * @param array<string,mixed> $nota payload da nota (NF-e ou NFC-e)
     * @param string $prefixo caminho dos itens nos erros ("itens" no /nfe, "nota.itens" no PDV)
     * @return array{0: array<string,mixed>, 1: array<string,mixed>|null} [nota com impostos, resultado do cálculo ou null]
     */
    public static function aplicar(array $nota, int $modeloPadrao, string $prefixo, bool $registrar = true): array
    {
        $itens = is_array($nota['itens'] ?? null) && array_is_list($nota['itens']) ? $nota['itens'] : [];
        $comRegra = array_filter($itens, fn($i) => self::calculado($i));
        $opcoes = $nota['calculo_tributos'] ?? null;
        unset($nota['calculo_tributos']);
        if ($comRegra === []) {
            if ($opcoes !== null) {
                throw ApiException::validation([['campo' => self::campoRaiz($prefixo, 'calculo_tributos'),
                    'mensagem' => 'Envie a regra de cada item (itens[].regra) para a API calcular os tributos']]);
            }
            return [$nota, null];
        }
        if ($opcoes !== null && (!is_array($opcoes) || ($opcoes !== [] && array_is_list($opcoes)))) {
            throw ApiException::validation([['campo' => self::campoRaiz($prefixo, 'calculo_tributos'), 'mensagem' => 'Objeto']]);
        }
        $opcoes ??= [];
        $erros = [];
        foreach (array_keys($opcoes) as $k) {
            if (!in_array($k, self::OPCOES, true)) {
                $erros[] = ['campo' => self::campoRaiz($prefixo, "calculo_tributos.$k"), 'mensagem' => $k === 'metodo_desoneracao'
                    ? 'Agora fica na regra de ICMS da matriz (metodo_desoneracao, CST 30, 40, 41, 50 e 90)' : 'Campo não permitido'];
            }
        }
        $esperado = $opcoes['calculo_id'] ?? null;
        if ($esperado !== null && (!is_string($esperado) || !preg_match('/^calc_[0-9a-f]{32}$/D', $esperado))) {
            $erros[] = ['campo' => self::campoRaiz($prefixo, 'calculo_tributos.calculo_id'), 'mensagem' => 'Use o calculo_id devolvido pelo cálculo (calc_ + 32 hex)'];
        }

        $entradaItens = [];
        foreach ($itens as $i => $it) {
            $f = "{$prefixo}[$i]";
            if (!self::calculado($it)) {
                $erros[] = ['campo' => "$f.regra", 'mensagem' => 'Com cálculo pela API, informe perfis ou regra em todos os itens (não misture com itens só com impostos)'];
                continue;
            }
            $extras = array_diff(array_keys(is_array($it['impostos'] ?? null) ? $it['impostos'] : []), self::IMPOSTOS_COM_REGRA);
            if (isset($it['impostos']) && (!is_array($it['impostos']) || $extras !== [])) {
                $erros[] = ['campo' => "$f.impostos", 'mensagem' => 'Com regra, os impostos são calculados pela API: envie em impostos só ibscbs/is (Reforma Tributária informada)'];
            }
            $regra = $it['regra'] ?? (isset($it['cfop']) ? [] : null);
            if (is_array($regra) && !array_is_list($regra) && isset($it['cfop'], $regra['cfop']) && (string) $it['cfop'] !== (string) $regra['cfop']) {
                $erros[] = ['campo' => "$f.cfop", 'mensagem' => 'CFOP do item diferente do CFOP da regra'];
            }
            if (is_array($regra) && !array_is_list($regra) && !isset($regra['cfop']) && isset($it['cfop'])) {
                $regra['cfop'] = $it['cfop'];
            }
            $e = ['item' => $i + 1, 'quantidade' => $it['quantidade'] ?? null, 'valor_unitario' => $it['valor_unitario'] ?? null];
            if ($regra !== null) {
                $e['regra'] = $regra;
            }
            foreach (['desconto', 'frete', 'seguro', 'outras_despesas', 'qtd_tributavel_unidade', 'valor_pauta_st', 'origem', 'perfil_icms', 'perfil_piscofins', 'cclasstrib', 'credito_presumido'] as $k) {
                if (isset($it[$k]) || ($k === 'credito_presumido' && array_key_exists($k, $it))) {
                    $e[$k] = $it[$k];
                }
            }
            $entradaItens[] = $e;
        }
        if ($erros !== []) {
            throw ApiException::validation($erros);
        }

        $modelo = (int) ($nota['modelo'] ?? $modeloPadrao);
        $dest = is_array($nota['destinatario'] ?? null) ? $nota['destinatario'] : [];
        $operacao = [
            'consumidor_final' => (int) ($nota['consumidor_final'] ?? 1) === 1,
            'contribuinte_icms' => (int) ($dest['indicador_ie'] ?? 9) === 1,
        ];
        // IBS/CBS: alíquotas do município de destino; sem destinatário com endereço, a API usa o município do emitente
        $munDest = $dest['endereco']['codigo_municipio'] ?? null;
        if (is_string($munDest) && preg_match('/^\d{7}$/D', $munDest)) {
            $operacao['municipio_destino'] = $munDest;
        }
        $ufDest = $dest['endereco']['uf'] ?? null;
        if ($modelo === 55 && is_string($ufDest) && $ufDest !== '') {
            $operacao['uf_destino'] = strtoupper($ufDest);
        }
        foreach (['excluir_icms_base_piscofins', 'aliquotas_ibscbs', 'credito_presumido'] as $k) {
            if (array_key_exists($k, $opcoes)) {
                $operacao[$k] = $opcoes[$k];
            }
        }
        if (array_key_exists('operacao', $opcoes)) {
            $operacao['codigo'] = $opcoes['operacao'];
        }
        // tipo de pessoa da matriz: do destinatário (CNPJ → J, CPF → F, estrangeiro → E), salvo se informado
        $operacao['tipo_pessoa'] = $opcoes['tipo_pessoa'] ?? (isset($dest['cnpj']) ? 'J' : (isset($dest['id_estrangeiro']) ? 'E' : 'F'));
        try {
            $calc = CalculadoraTributos::calcular(['modelo' => $modelo, 'operacao' => $operacao, 'itens' => $entradaItens], ConfigService::get());
        } catch (ApiException $ex) {
            throw self::remapear($ex, $prefixo);
        }

        if ($esperado !== null && !hash_equals($esperado, $calc['calculo_id'])) {
            throw new ApiException(409, 'calculo_divergente',
                'O cálculo dos tributos mudou desde a pré-visualização (regra, valores ou alíquotas). Recalcule, confira e envie o novo calculo_id.',
                [['campo' => self::campoRaiz($prefixo, 'calculo_tributos.calculo_id'), 'mensagem' => 'Calculado agora: ' . $calc['calculo_id']]]);
        }

        $ajustes = [];
        foreach ($calc['itens'] as $r) {
            if ($r['ajustes_manuais'] !== []) {
                $ajustes[(string) $r['item']] = $r['ajustes_manuais'];
            }
        }
        $p = RequestContext::$principal;
        if ($ajustes !== [] && $p !== null && !$p->canFiscal()) {
            Logger::audit('tributos.ajuste_manual', 'negado', ['itens' => array_keys($ajustes), 'papel' => $p->role]);
            throw new ApiException(403, 'ajuste_manual_negado',
                'Alterar valores calculados (base ou valor de imposto) exige papel proprietário ou fiscal. Altere só os parâmetros da regra (CST, alíquotas) ou peça a um usuário fiscal.',
                array_map(fn($item, $campos) => ['campo' => "{$prefixo}[" . ((int) $item - 1) . '].regra', 'mensagem' => 'Ajuste manual: ' . implode(', ', $campos)],
                    array_keys($ajustes), $ajustes));
        }
        if ($ajustes !== [] && $registrar) {
            Logger::audit('tributos.ajuste_manual', 'aplicado', ['calculo_id' => $calc['calculo_id'], 'ajustes' => $ajustes]);
        }

        // IBS/CBS calculado: vai para o XML pelo mesmo caminho do modo informado (grupos validados no XSD).
        // Se a nota já trouxe a RTC informada (reforma_tributaria ou impostos.ibscbs), ela prevalece.
        $rtcInformada = isset($nota['reforma_tributaria']) || array_filter($itens, fn($i) => isset($i['impostos']['ibscbs']) || isset($i['impostos']['is'])) !== [];
        $rtcCalculada = !$rtcInformada && isset($calc['totais']['IBSCBSTot']);
        foreach ($calc['itens'] as $k => $r) {
            $orig = $itens[$k];
            $rtc = array_intersect_key(is_array($orig['impostos'] ?? null) ? $orig['impostos'] : [], array_flip(self::IMPOSTOS_COM_REGRA));
            foreach (self::CAMPOS_CALCULO as $campo) {
                unset($orig[$campo]);
            }
            $orig['cfop'] ??= $r['cfop'];
            $impostos = $r['impostos'];
            unset($impostos['ibscbs']);
            if ($rtcCalculada && isset($r['impostos']['ibscbs'])) {
                $impostos['ibscbs'] = IbsCbs::paraXml($r['impostos']['ibscbs']);
                $orig['valor_item'] = Dec::fixo(Dec::de($r['valor_item']) ?? '0', 2);
            }
            $orig['impostos'] = $impostos + $rtc;
            if (isset($r['valor_aproximado_tributos']) && !isset($orig['valor_aproximado_tributos'])) {
                $orig['valor_aproximado_tributos'] = $r['valor_aproximado_tributos'];
            }
            $nota['itens'][$k] = $orig;
        }
        if ($rtcCalculada) {
            $nota['reforma_tributaria'] = ['modo' => 'informado', 'totais' => [
                'IBSCBSTot' => IbsCbs::paraXml($calc['totais']['IBSCBSTot']),
                'vNFTot' => Dec::fixo(Dec::de($calc['totais']['vNFTot']) ?? '0', 2),
            ]];
        }
        // natureza da operação: o nome da operação da matriz, quando a nota não informa
        if (!isset($nota['natureza_operacao']) && $calc['operacao']['codigo'] !== null) {
            $nome = array_values(array_filter(Matriz::cadastro()['operacoes'], fn($o) => $o['codigo'] === $calc['operacao']['codigo']))[0]['nome'] ?? null;
            if ($nome !== null) {
                $nota['natureza_operacao'] = mb_substr($nome, 0, 60);
            }
        }
        // o total só é conhecido depois do cálculo (ST, IPI, desoneração): pagamento único sem valor recebe o vNF
        $pag = $nota['pagamentos'] ?? null;
        if (is_array($pag) && array_is_list($pag) && count($pag) === 1 && is_array($pag[0]) && !array_key_exists('valor', $pag[0])) {
            $nota['pagamentos'][0]['valor'] = $calc['totais']['ICMSTot']['vNF'];
        }
        return [$nota, $calc];
    }

    /**
     * Resumo gravado no registro da nota (o detalhe por item já está no XML).
     * @param array<string,mixed> $calc
     * @return array<string,mixed>
     */
    public static function resumo(array $calc): array
    {
        $ajustes = [];
        $alertas = $calc['alertas'];
        foreach ($calc['itens'] as $r) {
            if ($r['ajustes_manuais'] !== []) {
                $ajustes[(string) $r['item']] = $r['ajustes_manuais'];
            }
            foreach ($r['alertas'] as $a) {
                $alertas[] = 'item ' . $r['item'] . ': ' . $a;
            }
        }
        return [
            'calculo_id' => $calc['calculo_id'], 'versao_calculo' => $calc['versao_calculo'], 'regime' => $calc['regime'],
            'ajustes_manuais' => $ajustes, 'ajustado_por' => $ajustes !== [] ? RequestContext::$actor : null,
            'alertas' => $alertas, 'totais' => $calc['totais'],
        ];
    }

    /** @param mixed $i */
    private static function calculado($i): bool
    {
        return is_array($i) && (array_key_exists('regra', $i) || array_key_exists('perfil_icms', $i) || array_key_exists('perfil_piscofins', $i));
    }

    private static function remapear(ApiException $ex, string $prefixo): ApiException
    {
        if ($ex->errorCode !== 'validacao') {
            return $ex;
        }
        $det = array_map(function ($d) use ($prefixo) {
            if (is_array($d) && isset($d['campo']) && is_string($d['campo'])) {
                $d['campo'] = preg_replace('/^itens(?=\[|$)/', $prefixo, $d['campo']);
                $d['campo'] = match ($d['campo']) {
                    'operacao.codigo' => self::campoRaiz($prefixo, 'calculo_tributos.operacao'),
                    'operacao.tipo_pessoa' => self::campoRaiz($prefixo, 'calculo_tributos.tipo_pessoa'),
                    default => $d['campo'],
                };
            }
            return $d;
        }, $ex->details);
        return new ApiException($ex->status(), $ex->errorCode, $ex->getMessage(), $det, $ex->headers);
    }

    private static function campoRaiz(string $prefixo, string $campo): string
    {
        return str_starts_with($prefixo, 'nota.') ? "nota.$campo" : $campo;
    }
}
