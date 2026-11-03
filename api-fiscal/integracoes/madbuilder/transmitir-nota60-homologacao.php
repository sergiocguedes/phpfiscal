<?php
/** Operação explícita e limitada à nota 60/CNPJ definido, somente ambiente 2. */
if (PHP_SAPI !== 'cli' || !in_array('--transmitir-homologacao',$argv,true)) { exit(1); }
require getcwd().'/init.php';
require_once getcwd().'/app/service/NfseApiPhpService.php';
$cnpj='11229442000120';$id=60;
TSession::setValue('userunitid',1);TSession::setValue('account_id',1);
$cfg=NfseApiPhpService::config($cnpj);
if($cfg['url']!=='http://host.docker.internal:8081/api/v1'){throw new RuntimeException('Teste restrito à API Docker local.');}
$check=function()use($cfg,$cnpj){
    $t=NfseApiPhpService::request($cfg,'GET','/tenant')['json'];$p=NfseApiPhpService::request($cfg,'GET','/nfse/config')['json'];
    if(($t['cnpj']??'')!==$cnpj||(int)($t['ambiente_nfse']??0)!==2||(int)($p['ambiente']??0)!==2){throw new RuntimeException('Envio bloqueado: empresa/ambiente da API divergente.');}
};
$check();
TTransaction::open('emissor');
$nota=new NotaFiscalCabecalho($id);$empresa=Empresa::where('system_unit_id','=',$nota->system_unit_id)->first();$param=ParametrosGerais::where('system_unit_id','=',$nota->system_unit_id)->first();
if(!$empresa||preg_replace('/\D/','',$empresa->cnpj)!==$cnpj||(int)$param->ambiente_fiscal_id!==2||(int)$nota->serie!==1||(int)$nota->numero_rps!==148){throw new RuntimeException('Envio bloqueado: nota, empresa, série, RPS ou ambiente de origem divergente.');}
$already=(int)$nota->nota_fiscal_status_id===(int)NotaFiscalStatus::AUTORIZADA;
if(!$already && in_array('--corrigir-cadastro',$argv,true)){
    $pessoa=new Pessoa($nota->pessoa_id);
    if(strlen(preg_replace('/\D/','',(string)$pessoa->cpf_cnpj))!==11||strlen(preg_replace('/\D/','',(string)$pessoa->cep))!==8||empty($pessoa->cidade)||empty($pessoa->logradouro)||empty($pessoa->numero)||empty($pessoa->bairro)||empty($pessoa->estado_id)){
        throw new RuntimeException('Tomador ainda sem CPF/endereço nacional completo; nenhum reenvio realizado.');
    }
    $nota->tipo_tomador_id=TipoTomador::BRASIL;$nota->cpf_cnpj_tomador=$pessoa->cpf_cnpj;$nota->nome_tomador=$pessoa->razao_social;
    $nota->store();TTransaction::close();TTransaction::open('emissor');
}
$others=TTransaction::get()->query('SELECT * FROM nota_fiscal_cabecalho WHERE id<>60 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$hashOthers=hash('sha256',json_encode($others));
TTransaction::rollback();
$folder=dirname(__DIR__,3).'/.migration-backups/transmissao-homologacao-nota60-'.date('Ymd-His');mkdir($folder,0700,true);
$requests=[];$transport=function($config,$method,$path,$body,$key)use(&$transport,&$requests,$check){
    NfseApiPhpService::$transport=null;
    try{
        if($method==='POST'&&($path==='/nfse'||str_ends_with($path,'/corrigir'))){
            $check();
            if(($body['numero']??null)!==148||($body['serie']??null)!==1){throw new RuntimeException('Transmissão de outra numeração bloqueada.');}
        }
        $requests[]=$method.' '.$path;
        return NfseApiPhpService::request($config,$method,$path,$body,180,$key);
    }finally{NfseApiPhpService::$transport=$transport;}
};
$result=null;$failure=null;NfseApiPhpService::$transport=$transport;
try{
    if(!$already && in_array('--reenviar-horario',$argv,true)){
        $check();
        NfseApiPhpService::request($cfg,'POST','/nfse/DPS130260321122944200012000001000000000000148/reenviar-horario',['recuo_segundos'=>180],180,'nota60-homologacao-reenvio-horario-001');
    }
    if(!$already){$result=NFSeService::emitirNFSePorId($id);}
}
catch(Throwable $e){$failure=$e->getMessage();}
finally{NfseApiPhpService::$transport=null;}
TTransaction::open('emissor');$saved=new NotaFiscalCabecalho($id);
$stored=json_decode((string)$saved->mensagem_retorno_nfse,true);
$summary=['cnpj'=>$cnpj,'nota'=>$id,'ambiente'=>2,'status_emissor'=>(int)$saved->nota_fiscal_status_id,'numero_nfse'=>$saved->numero,
    'chave_nfse'=>$saved->chave_acesso_nfse,'chave_dps'=>$saved->chave_acesso_rps,'serie'=>$saved->serie,'rps'=>$saved->numero_rps,
    'retorno_gravado'=>!empty($saved->mensagem_retorno_nfse),'falha'=>$failure,
    'outras_notas_preservadas'=>$hashOthers===hash('sha256',json_encode(TTransaction::get()->query('SELECT * FROM nota_fiscal_cabecalho WHERE id<>60 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)))];
$xml=$result['xmlRetorno']??$stored['xmlRetorno']??null;
TTransaction::rollback();
if($result!==null){file_put_contents($folder.'/retorno-emissor.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
if($xml){file_put_contents($folder.'/nfse-autorizada.xml',$xml);$d=new DOMDocument();if(!str_contains($xml,'<!DOCTYPE')&&$d->loadXML($xml,LIBXML_NONET)){$x=new DOMXPath($d);$summary['xml_tpAmb']=$x->evaluate('string(//*[local-name()="tpAmb"])');$summary['xml_numero_nfse']=$x->evaluate('string(//*[local-name()="nNFSe"])');$summary['xml_cnpj_prestador']=$x->evaluate('string(//*[local-name()="prest"]/*[local-name()="CNPJ"])');$summary['xml_assinaturas']=$x->evaluate('count(//*[local-name()="Signature"])');}}
try{$ref=$summary['chave_nfse']?:'DPS130260321122944200012000001000000000000148';$rec=NfseApiPhpService::request($cfg,'GET','/nfse/'.rawurlencode($ref))['json'];$summary['status_api']=$rec['status']??null;$summary['erros_api']=$rec['erros']??[];file_put_contents($folder.'/registro-api.json',json_encode($rec,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}catch(Throwable $e){$summary['consulta_api_falha']=$e->getMessage();}
$summary['chamadas']=$requests;
file_put_contents($folder.'/resultado.json',json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\nArtefatos: $folder\n";
exit(($summary['status_emissor']===2&&($summary['status_api']??null)==='autorizada'&&($summary['xml_tpAmb']??null)==='2'&&$summary['retorno_gravado'])?0:1);
