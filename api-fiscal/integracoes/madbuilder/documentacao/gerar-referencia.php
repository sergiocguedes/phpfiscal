<?php
// Somente leitura dos fontes; gera documentação sem carregar o sistema ou acessar bancos/APIs.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=$argv[1]??'/var/www/html/emissor_servicos';
$out=__DIR__.'/referencia';if(!is_dir($out)){mkdir($out,0755,true);}
$targets=[
 'app/control/NFSeService.php'=>['configurarCliente','sincronizarParametros','sincronizarConfiguracao','emitirNFSe','emitirNFSePorId','inscricaoMunicipal','montarIbsCbs','cepTomador','montarPayload','proximoNumeroRps','cancelarNotaFiscalCabecalho','extrairEventoCancelamento','requestNfseApi'],
 'app/control/configuracoes/ParametrosGeraisForm.php'=>['onSave'],
 'app/service/PainelApiService.php'=>['postNfse'],
];
$manifest=['descricao'=>'Referência dos fontes validados; divergência de hash exige revisão, não substituição automática.','files'=>[]];
foreach($targets as $path=>$methods){
 $code=file_get_contents($root.'/'.$path);$tokens=[];$offset=0;
 foreach(token_get_all($code) as $t){$text=is_array($t)?$t[1]:$t;$tokens[]=['id'=>is_array($t)?$t[0]:null,'text'=>$text,'offset'=>$offset];$offset+=strlen($text);}
 $md='# Trechos de '.$path."\n\nMétodos completos extraídos do fonte atual. No editor de ação que já contém a assinatura, copie apenas o corpo. Não duplique declarações.\n\n";$found=[];
 foreach($tokens as $i=>$t){if($t['id']!==T_FUNCTION)continue;$j=$i+1;while(isset($tokens[$j])&&($tokens[$j]['id']===T_WHITESPACE||$tokens[$j]['text']==='&')){$j++;}
  $name=$tokens[$j]['id']===T_STRING?$tokens[$j]['text']:null;if(!in_array($name,$methods,true))continue;
  $start=strrpos(substr($code,0,$t['offset']),"\n");$start=$start===false?0:$start+1;
  while($tokens[$j]['text']!=='{'){$j++;}$depth=0;$end=null;
  for(;$j<count($tokens);$j++){$v=$tokens[$j];if($v['text']==='{'||in_array($v['id'],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true)){$depth++;}elseif($v['text']==='}'&&--$depth===0){$end=$v['offset']+1;break;}}
  if($end===null){throw new RuntimeException('Método incompleto: '.$name);}
  $line=substr_count(substr($code,0,$start),"\n")+1;
  $md.='## '.$name.' — linha de referência '.$line."\n\n```php\n".substr($code,$start,$end-$start)."\n```\n\n";$found[]=$name;
 }
 if(array_diff($methods,$found)){throw new RuntimeException('Métodos ausentes: '.implode(',',array_diff($methods,$found)));}
 file_put_contents($out.'/'.basename($path,'.php').'-trechos.md',$md);
 $manifest['files'][$path]=['sha256'=>hash('sha256',$code),'metodos'=>$methods];
}
foreach(['NfseApiPhpService','NfseApiPayload','NfseApiConfiguracao'] as $name){$path='app/service/'.$name.'.php';$code=file_get_contents($root.'/'.$path);file_put_contents($out.'/'.$name.'.php',$code);$manifest['files'][$path]=['sha256'=>hash('sha256',$code),'tipo'=>'servico_personalizado_integral'];}
foreach(['app/model/NotaFiscalCabecalho.php','app/model/NotaFiscalDetalhe.php'] as $path){$manifest['files'][$path]=['sha256'=>hash_file('sha256',$root.'/'.$path),'tipo'=>'modelo_com_campo_adicional'];}
file_put_contents($out.'/manifesto.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
echo "Referência gerada: 3 serviços completos, 3 anexos de métodos e manifesto de 8 arquivos. Sem credenciais ou execução fiscal.\n";
