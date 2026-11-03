<?php
/** Conversão de fronteira: cálculos continuam no emissor MadBuilder. */
class NfseApiPayload
{
    public static function converter(array $d): array
    {
        if (!empty($d['intermediador'])) {
            throw new RuntimeException('A API PHP ainda não suporta intermediador NFS-e. Nenhum envio realizado.');
        }
        foreach (['IBSCBS', 'ibsCbs', 'ibscbs'] as $key) {
            if (!empty($d[$key])) {
                throw new RuntimeException('Formato IBS/CBS não padronizado no adaptador. Converta IBSCBSDPS e gIBSCBS para ibs_cbs.');
            }
        }
        $r = $d['identificacaoRps'];
        $date = DateTimeImmutable::createFromFormat('!d/m/Y', $r['competencia']);
        if (!$date || $date->format('d/m/Y') !== $r['competencia']) {
            throw new RuntimeException('Competência inválida.');
        }
        $code = preg_replace('/\D/', '', (string) $d['servico']['itemListaServico']);
        if (strlen($code) !== 6) {
            throw new RuntimeException('Informe no serviço o código de tributação nacional com 6 dígitos; item LC 116 de 4 dígitos não identifica o desdobramento nacional.');
        }
        $p = [
            'numero' => (int) $r['numero'], 'serie' => (int) $r['serie'],
            'competencia' => $date->format('Y-m-d'),
            'servico' => ['codigo_tributacao_nacional' => $code,
                'descricao' => $d['servico']['discriminacao'],
                'municipio_prestacao' => (string) $d['servico']['codigoMunicipio']],
            'valores' => ['servico' => $d['valores']['valorServicos'],
                'tributacao_iss' => (int) $d['tribMun']['tribISSQN'],
                'retencao_iss' => (int) $d['tribMun']['tpRetISSQN']],
        ];
        if (isset($d['IBSCBSDPS']) || isset($d['gIBSCBS']) || isset($d['gDif'])) {
            if (!is_array($d['IBSCBSDPS']??null) || !is_array($d['gIBSCBS']??null)) { throw new RuntimeException('Informe IBSCBSDPS e gIBSCBS completos.'); }
            $a=$d['IBSCBSDPS'];$g=$d['gIBSCBS'];
            foreach (['finNFSe','indFinal','cIndOp','indDest'] as $key) { if (!array_key_exists($key,$a)) { throw new RuntimeException('IBSCBSDPS incompleto: '.$key); } }
            foreach (['CST','cClassTrib'] as $key) { if (!array_key_exists($key,$g)) { throw new RuntimeException('gIBSCBS incompleto: '.$key); } }
            $p['ibs_cbs']=['finalidade'=>$a['finNFSe'],'consumidor_final'=>$a['indFinal'],'indicador_operacao'=>$a['cIndOp'],
                'indicador_destinatario'=>$a['indDest'],'cst'=>$g['CST'],'classificacao_tributaria'=>$g['cClassTrib']];
            $p['servico']['nbs']=(string)($d['servico']['codigoNBS']??'');
            if (isset($d['gDif'])) {
                $p['ibs_cbs']['diferimento']=[];
                foreach (['pDifUF'=>'percentual_uf','pDifMun'=>'percentual_municipal','pDifCBS'=>'percentual_cbs'] as $from=>$to) {
                    if (!array_key_exists($from,$d['gDif'])) { throw new RuntimeException('gDif incompleto: '.$from); }
                    $p['ibs_cbs']['diferimento'][$to]=$d['gDif'][$from];
                }
            }
        }
        foreach (['descontoIncondicionado' => 'desconto_incondicionado', 'descontoCondicionado' => 'desconto_condicionado'] as $from => $to) {
            if (isset($d['valores'][$from])) { $p['valores'][$to] = $d['valores'][$from]; }
        }
        // Não herda o antigo código municipal fixo "100". Só enviar se explicitamente cadastrado.
        if (!empty($d['_codigo_tributacao_municipal'])) { $p['servico']['codigo_tributacao_municipal'] = (string) $d['_codigo_tributacao_municipal']; }
        if (isset($d['_aliquota_iss'])) { $p['valores']['aliquota_iss'] = $d['_aliquota_iss']; }
        if (isset($d['tribMun']['tpSusp'])) {
            $p['valores']['suspensao'] = ['tipo' => (int) $d['tribMun']['tpSusp'], 'numero_processo' => $d['tribMun']['nProcesso']];
        }
        if (!empty($d['informacoesComplementares']['xInfComp'])) { $p['informacoes_complementares'] = $d['informacoesComplementares']['xInfComp']; }
        if (isset($d['tomador'])) {
            $t = $d['tomador']; $doc = preg_replace('/\D/', '', (string) $t['CNPJCPF']);
            if (!in_array(strlen($doc), [11, 14], true)) { throw new RuntimeException('Documento do tomador deve ter 11 ou 14 dígitos.'); }
            $p['tomador'] = [strlen($doc) === 11 ? 'cpf' : 'cnpj' => $doc, 'nome' => $t['razaoSocial']];
            foreach (['email', 'telefone'] as $key) { if (!empty($t[$key])) { $p['tomador'][$key] = $t[$key]; } }
            $p['tomador']['endereco'] = ['codigo_municipio' => (string) $t['codigoMunicipio'], 'cep' => $t['cep'],
                'logradouro' => $t['logradouro'], 'numero' => $t['numero'], 'bairro' => $t['bairro']];
        }
        $f = $d['tribFederal'] ?? []; $fed = []; $pc = [];
        foreach (['CST' => 'cst', 'vBCPisCofins' => 'base_calculo', 'pAliqPis' => 'aliquota_pis',
            'pAliqCofins' => 'aliquota_cofins', 'vPis' => 'valor_pis', 'vCofins' => 'valor_cofins', 'tpRetPisCofins' => 'tipo_retencao'] as $from => $to) {
            if (isset($f[$from])) { $pc[$to] = $to === 'cst' ? str_pad((string) $f[$from], 2, '0', STR_PAD_LEFT) : $f[$from]; }
        }
        if ($pc) { $fed['pis_cofins'] = $pc; }
        foreach (['vRetCP' => 'retencao_cp', 'vRetIRRF' => 'retencao_irrf', 'vRetCSLL' => 'retencao_csll'] as $from => $to) {
            if (isset($f[$from])) { $fed[$to] = $f[$from]; }
        }
        if ($fed) { $p['tributos_federais'] = $fed; }
        $tt = $d['totTrib'] ?? [];
        if ((int) ($d['prestador']['opSimpNac'] ?? 0) === 2) { $p['tributos_aproximados'] = ['tipo' => 'nao_informar']; }
        elseif (isset($tt['pTotTribSN'])) { $p['tributos_aproximados'] = ['tipo' => 'percentual_sn', 'percentual_sn' => $tt['pTotTribSN']]; }
        else { $p['tributos_aproximados'] = ['tipo' => 'percentual', 'federal' => $tt['pTotTribFed'] ?? 0,
            'estadual' => $tt['pTotTribEst'] ?? 0, 'municipal' => $tt['pTotTribMun'] ?? 0]; }
        return $p;
    }
}
