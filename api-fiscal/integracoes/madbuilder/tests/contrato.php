<?php
require dirname(__DIR__, 3) . '/bootstrap.php';
require dirname(__DIR__) . '/app/service/NfseApiPayload.php';
require dirname(__DIR__) . '/app/service/NfseApiPhpService.php';
$count = 0;
$check = function ($ok, $message) use (&$count) { ++$count; if (!$ok) { throw new RuntimeException($message); } };
$old = [
    'identificacaoRps' => ['numero' => '27', 'serie' => '1', 'competencia' => '01/11/2026'],
    'servico' => ['itemListaServico' => '080201', 'discriminacao' => 'Curso de teste', 'codigoMunicipio' => '3550308', 'codigoTributacaoMunicipio' => '100'],
    'prestador' => ['opSimpNac' => 3],
    'valores' => ['valorServicos' => 1000, 'descontoIncondicionado' => 10],
    'tribMun' => ['tribISSQN' => 1, 'tpRetISSQN' => 1], '_aliquota_iss' => 2,
    'totTrib' => ['pTotTribSN' => 6],
    'tribFederal' => ['CST' => '01', 'vBCPisCofins' => 990, 'pAliqPis' => 0.65, 'pAliqCofins' => 3,
        'vPis' => 6.44, 'vCofins' => 29.7, 'tpRetPisCofins' => 3, 'vRetCSLL' => 10, 'vRetIRRF' => 15, 'vRetCP' => 0],
    'tomador' => ['CNPJCPF' => '52998224725', 'razaoSocial' => 'Cliente de teste', 'codigoMunicipio' => '3550308',
        'cep' => '01001000', 'logradouro' => 'Praça da Sé', 'numero' => '1', 'bairro' => 'Sé'],
];
$p = NfseApiPayload::converter($old);
$check($p['competencia'] === '2026-11-01', 'Data ISO');
$check($p['numero'] === 27 && $p['serie'] === 1, 'Numeração inteira');
$check(!isset($p['servico']['codigo_tributacao_municipal']), 'Não copiar código municipal fixo');
$municipal=$old;$municipal['_codigo_tributacao_municipal']='100';
$check(NfseApiPayload::converter($municipal)['servico']['codigo_tributacao_municipal']==='100','Código municipal informado pelo item é enviado');
$check($p['tributos_federais']['retencao_cp'] === 0, 'Preservar zero explícito');
$check($p['tributos_federais']['pis_cofins']['valor_pis'] === 6.44, 'Não recalcular PIS');
$check($p['tributos_federais']['retencao_csll'] === 10, 'Não somar PIS/COFINS a CSLL');
$cfg = ['opcao_simples_nacional' => 3, 'regime_apuracao_sn' => 1, 'regime_especial' => 0,
    'codigo_municipio' => '3550308', 'ambiente' => 2, 'municipio_convenio_ativo' => true, 'inscricao_municipal' => '12345'];
$emit = ['cnpj' => '11444777000161'];
$normalized = App\Nfse\DpsBuilder::validatePayload($p, $cfg, $emit);
$b = App\Nfse\DpsBuilder::build($normalized, $cfg, $emit, 27, 1);
$check(App\Nfse\NfseXml::validate($b['xml'], 'DPS_v1.01.xsd', $b['leiaute']) === [], 'DPS deve passar no XSD real');
$check(str_contains($b['xml'], '<vRetCSLL>10.00</vRetCSLL>'), 'Valor no XML');
$rtc=$old;
$rtc['servico']['codigoNBS']='122051900';
$rtc['IBSCBSDPS']=['finNFSe'=>0,'indFinal'=>1,'cIndOp'=>'100301','indDest'=>0];
$rtc['gIBSCBS']=['CST'=>'510','cClassTrib'=>'510001'];
$rtc['gDif']=['pDifUF'=>'0.00','pDifMun'=>'12.50','pDifCBS'=>'100.00'];
$convertedRtc=NfseApiPayload::converter($rtc);
$check($convertedRtc['ibs_cbs']['cst']==='510' && $convertedRtc['ibs_cbs']['classificacao_tributaria']==='510001','Converter classificação ACBr');
$check($convertedRtc['ibs_cbs']['diferimento']===['percentual_uf'=>'0.00','percentual_municipal'=>'12.50','percentual_cbs'=>'100.00'],'Preservar percentuais informados, inclusive zero');
$check($convertedRtc['servico']['nbs']==='122051900','Preservar NBS');
$normalizedRtc=App\Nfse\DpsBuilder::validatePayload($convertedRtc,$cfg,$emit);
$rtcXml=App\Nfse\DpsBuilder::build($normalizedRtc,$cfg,$emit,27,1);
$check(App\Nfse\NfseXml::validate($rtcXml['xml'],'DPS_v1.01.xsd',$rtcXml['leiaute'])===[],'XML com RTC convertido válido');
$check(str_contains($rtcXml['xml'],'<pDifMun>12.50</pDifMun>'),'Percentual no XML');
foreach (['intermediador' => ['CNPJCPF' => '11444777000161'], 'IBSCBS' => ['cClassTrib' => '000001']] as $key => $value) {
    try { NfseApiPayload::converter($old + [$key => $value]); $check(false, 'Campo sem suporte não pode desaparecer'); }
    catch (RuntimeException $e) { $check(true, 'Campo recusado'); }
}
$susp = $old; $susp['tribMun'] += ['tpSusp' => 1, 'nProcesso' => str_repeat('0', 30)];
$check(NfseApiPayload::converter($susp)['valores']['suspensao']['tipo'] === 1, 'Suspensão judicial');
$bad = $old; $bad['servico']['itemListaServico'] = '0802';
try { NfseApiPayload::converter($bad); $check(false, 'Não adivinhar desdobramento'); } catch (RuntimeException $e) { $check(true, 'Código nacional obrigatório'); }
$temp = tempnam(sys_get_temp_dir(), 'mad-api-');
file_put_contents($temp, '<?php return ' . var_export(['url' => 'https://api.example.com:8080/api/v1', 'empresas' => [
    '11444777000161' => ['chave' => 'somente-teste', 'empresa_id' => 't_teste']]], true) . ';');
putenv('NFSE_API_CONFIG=' . $temp);
$requests = []; $created=false; $record = ['status' => 'autorizada', 'id' => 'DPS355030821144477700016100001000000000000027', 'chave' => str_repeat('2', 50), 'numero_nfse' => '123'];
NfseApiPhpService::$transport = function ($cfg, $method, $path, $body, $key) use (&$requests, &$created, $record) {
    $requests[] = [$method, $path, $body, $key];
    if($method==='GET'&&$path==='/nfse/'.$record['id']&&!$created){throw new RuntimeException('HTTP 404: inexistente',404);}
    if($method==='POST'&&$path==='/nfse'){$created=true;}
    $json = $path === '/config' ? ['cnpj' => '11444777000161','endereco'=>['codigo_municipio'=>'3550308']] : $record;
    if (str_ends_with($path, '/cancelar')) { $json = ['sucesso' => true, 'nfse' => $record]; }
    if (str_contains($path, '/danfse?formato=base64')) { $json = ['pdf_base64'=>base64_encode('%PDF-teste'), 'tipo_conteudo'=>'application/pdf']; }
    $raw = str_contains($path, '/danfse') ? '%PDF-teste' : '<NFSe>teste</NFSe>';
    return ['json' => $json, 'raw' => $raw, 'http_code' => 200];
};
try {
    $p['_cnpj'] = '11444777000161'; $p['nota_id'] = 123;
    $a = json_decode(NfseApiPhpService::dispatch('emitir', $p)['message'], true);
    $check($a['sucesso'] && $a['numeroNota'] === '123' && $a['xmlRetorno'] !== null, 'Adaptar autorização e XML');
    $sent = array_values(array_filter($requests,fn($r)=>$r[0]==='POST'&&$r[1]==='/nfse'))[0];
    $check($sent[1] === '/nfse' && !isset($sent[2]['_cnpj'], $sent[2]['nota_id']), 'Enviar só contrato fiscal');
    $check($sent[3] === 'mad-nfse-11444777000161-1-27', 'Chave idempotência estável');
    NfseApiPhpService::dispatch('emitir', $p);
    $check(count(array_filter($requests,fn($r)=>$r[0]==='POST'&&$r[1]==='/nfse'))===1, 'Nota já autorizada é recuperada sem novo POST');
    $data = ['cnpj' => '11444777000161', 'chave' => str_repeat('1', 42)];
    $consult = json_decode(NfseApiPhpService::dispatch('consultar-dps-por-chave', $data)['message'], true);
    $check($consult['retorno']['numero'] === '123', 'Consulta compatível');
    $pdf = json_decode(NfseApiPhpService::dispatch('imprimir-pdf', $data)['message'], true);
    $check(base64_decode($pdf['pdfBase64']) === '%PDF-teste', 'Base64 gerado pela API para as telas');
    $cancel = json_decode(NfseApiPhpService::dispatch('cancelar', ['Cnpj' => '11444777000161', 'ChaveNFSe' => str_repeat('2', 50), 'CodCancelamento' => '1', 'MotCancelamento' => 'Erro informado pelo contribuinte'])['message'], true);
    $check($cancel['sucesso'] && !empty($cancel['xmlEvento']), 'Cancelamento e evento');
    $files = json_decode(NfseApiPhpService::dispatch('xml-arquivos', $data)['message'], true);
    $check(isset($files['retorno']['nfse']['xml'], $files['retorno']['dps']['xml']), 'Arquivos XML');
    $event = json_decode(NfseApiPhpService::dispatch('consultar-evento', $data)['message'], true);
    $check(!empty($event['xmlEvento']), 'Consulta evento');
    $requests = [];
    NfseApiPhpService::$transport = function ($cfg, $method, $path, $body, $key) use (&$requests) {
        $requests[] = [$method, $path, $body];
        $json = match ($path) {
            '/tenant' => ['id'=>'t_teste','cnpj'=>'11444777000161','ambiente_nfse'=>2],
            '/config' => ['cnpj'=>'11444777000161','serie'=>2,'proximo_numero'=>77,'serie_nfce'=>3,'proximo_numero_nfce'=>88],
            '/nfse/config' => $method === 'PUT' ? array_replace(['serie'=>1,'proximo_numero'=>99,'numeracao'=>['2'=>55]], $body) : ['serie'=>1,'proximo_numero'=>99,'numeracao'=>['2'=>55]],
            '/certificado' => ['cnpj_confere'=>true,'validade'=>'2027-01-01'],
        };
        return ['json'=>$json,'raw'=>'','http_code'=>200];
    };
    $sync = ['cnpj'=>'11444777000161','empresa'=>['nome'=>'Prestador teste','ambiente_nfse'=>2],
        'emitente'=>['cnpj'=>'11444777000161','ie'=>null],'prestador'=>['serie'=>1,'opcao_simples_nacional'=>3],
        'certificado'=>['certificado_base64'=>'A1TESTE','senha'=>'segredo-fixture']];
    $synced = NfseApiPhpService::sincronizar($sync);
    $check($synced['sucesso'] && $synced['ambiente_nfse']===2,'Sincronização pela API');
    $check($requests[1][1]==='/tenant' && !isset($requests[1][2]['ambiente_nfe'],$requests[1][2]['ambiente_nfce']),'Somente ambiente NFS-e');
    $check($requests[3][2]['ie']===null && $requests[3][2]['proximo_numero']===77 && $requests[3][2]['proximo_numero_nfce']===88,'Sem IE e sem reiniciar numeração');
    $check(!isset($requests[4][2]['proximo_numero']),'Não reduzir numeração NFS-e');
    $check($requests[5][1]==='/certificado' && $requests[5][2]['senha']==='segredo-fixture','A1 enviado por API');
    $check(!str_contains(json_encode($synced),'segredo-fixture') && !str_contains(json_encode($synced),'A1TESTE'),'Retorno sem segredos');
    $sync['certificado']=null;$sync['prestador']['proximo_numero']=10;$requests=[];
    $synced=NfseApiPhpService::sincronizar($sync);
    $check($synced['proximo_numero']===99,'Não retroceder contador atual da API');
    $check(!$synced['certificado_sincronizado'] && !in_array('POST /certificado',array_map(fn($r)=>$r[0].' '.$r[1],$requests),true),'Salvar parâmetros sem reenviar A1');
    $check(count($synced['avisos'])>0,'Informar piso maior de numeração');
    $sync['prestador']['serie']=2;$sync['prestador']['proximo_numero']=1;
    $synced=NfseApiPhpService::sincronizar($sync);
    $check($synced['serie']===2 && $synced['proximo_numero']===55,'Respeitar histórico da série de destino');
    $sync['prestador']['proximo_numero']=120;
    $check(NfseApiPhpService::sincronizar($sync)['proximo_numero']===120,'Avançar para próximo número solicitado');
    $sync['emitente']['cnpj']='11229442000120';
    try { NfseApiPhpService::sincronizar($sync);$check(false,'CNPJ divergente deve impedir configuração'); }
    catch (RuntimeException $e) { $check(true,'CNPJ divergente bloqueado'); }
    $keys=[];$contextIm='13064001';$nextNumber=149;
    NfseApiPhpService::$transport=function($cfg,$method,$path,$body,$key)use(&$keys,&$contextIm,&$nextNumber,$record){
        $rec=$record;$rec['status']='rejeitada';$rec['erros']=[['codigo'=>'E0116','descricao'=>'IM']];
        if($path==='/config'){$j=['cnpj'=>'11444777000161','endereco'=>['codigo_municipio'=>'3550308']];}
        elseif($path==='/nfse/config'){$j=['inscricao_municipal'=>$contextIm,'codigo_municipio'=>'3550308','ambiente'=>2,'proximo_numero'=>$nextNumber];}
        else{$j=$rec;}
        if($method==='POST'){
            if(!str_ends_with($path,'/corrigir')){throw new RuntimeException('Rejeitada deve usar correção explícita');}
            $keys[]=$key;
        }
        return ['json'=>$j,'raw'=>'','http_code'=>200];
    };
    $p['_cnpj']='11444777000161';NfseApiPhpService::dispatch('emitir',$p);
    $contextIm='000000013064001';NfseApiPhpService::dispatch('emitir',$p);
    $nextNumber=200;NfseApiPhpService::dispatch('emitir',$p);
    $check($keys[0]!==$keys[1],'Correção de IM não repete cache da tentativa anterior');
    $check($keys[1]===$keys[2],'Contador não altera a chave da mesma tentativa corrigida');
} finally { unlink($temp); putenv('NFSE_API_CONFIG'); NfseApiPhpService::$transport = null; }
echo "OK: $count verificações; contrato, XSD, idempotência e operações. Nenhuma transmissão fiscal.\n";
