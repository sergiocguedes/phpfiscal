<?php
if(PHP_SAPI!=='cli'||!in_array('--cancelar-homologacao',$argv,true)){exit(1);}
require getcwd().'/init.php';require_once getcwd().'/app/service/NfseApiPhpService.php';
TSession::setValue('userunitid',1);
$cfg=NfseApiPhpService::config('11229442000120');
if($cfg['url']!=='http://host.docker.internal:8081/api/v1'){throw new RuntimeException('Somente API local.');}
TTransaction::open('emissor');$n=new NotaFiscalCabecalho(60);$e=Empresa::where('system_unit_id','=',1)->first();$p=ParametrosGerais::where('system_unit_id','=',1)->first();
if(preg_replace('/\D/','',$e->cnpj)!=='11229442000120'||(int)$p->ambiente_fiscal_id!==2||(int)$n->numero!==360){throw new RuntimeException('Nota/empresa/ambiente divergente');}
$key=$n->chave_acesso_nfse;$oldReturn=json_decode($n->mensagem_retorno_nfse,true);$oldHash=hash('sha256',$oldReturn['xmlRetorno']??'');
TSession::setValue('userid',$n->system_users_id);
$otherHash=hash('sha256',json_encode(TTransaction::get()->query('SELECT * FROM nota_fiscal_cabecalho WHERE id<>60 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)));
$already=(int)$n->nota_fiscal_status_id===3;TTransaction::rollback();
$tenant=NfseApiPhpService::request($cfg,'GET','/tenant')['json'];$rec=NfseApiPhpService::request($cfg,'GET','/nfse/'.$key)['json'];
if((int)$tenant['ambiente_nfse']!==2||(int)$rec['ambiente']!==2){throw new RuntimeException('Produção bloqueada');}
$folder=dirname(__DIR__,3).'/.migration-backups/cancelamento-nota60-'.date('Ymd-His');mkdir($folder,0700,true);
file_put_contents($folder.'/retorno-antes.json',json_encode($oldReturn,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
$failure=null;
try{if(!$already){NFSeService::cancelarNotaFiscalCabecalho(60,'9','Cancelamento de nota de teste de integracao em ambiente de homologacao.');}}
catch(Throwable $x){$failure=$x->getMessage();}
TTransaction::open('emissor');$n=new NotaFiscalCabecalho(60);$ret=json_decode($n->mensagem_retorno_nfse,true);
$report=['nota'=>60,'numero_nfse'=>$n->numero,'status_emissor'=>(int)$n->nota_fiscal_status_id,'id_evento'=>$n->id_evento_cancelamento,'data_cancelamento'=>$n->data_cancelamento,'codigo_cancelamento'=>$n->codigo_cancelamento,
 'xml_evento_gravado'=>!empty($n->xml_cancelamento),'xml_autorizacao_preservado'=>$oldHash===hash('sha256',$ret['xmlRetorno']??''),'falha'=>$failure,
 'outras_notas_preservadas'=>$otherHash===hash('sha256',json_encode(TTransaction::get()->query('SELECT * FROM nota_fiscal_cabecalho WHERE id<>60 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)))];
$event=$n->xml_cancelamento;TTransaction::rollback();
$rec=NfseApiPhpService::request($cfg,'GET','/nfse/'.$key)['json'];$report['status_api']=$rec['status'];$report['ambiente']=$rec['ambiente'];
if($event){$apiEvent=NfseApiPhpService::request($cfg,'GET','/nfse/'.$key.'/xml?tipo=evento')['raw'];$report['xml_evento_igual_api']=hash_equals(hash('sha256',$event),hash('sha256',$apiEvent));file_put_contents($folder.'/evento-cancelamento.xml',$event);}
file_put_contents($folder.'/retorno-depois.json',json_encode($ret,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));file_put_contents($folder.'/registro-api.json',json_encode($rec,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));file_put_contents($folder.'/resultado.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\nArtefatos: $folder\n";
exit(($report['status_emissor']===3&&$rec['status']==='cancelada'&&$report['xml_evento_gravado'])?0:1);
