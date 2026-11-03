<?php
// Execute via CLI; não carrega init.php, não altera fontes e não acessa rede/banco.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=rtrim($argv[1]??getcwd(),'/\\');$manifest=json_decode(file_get_contents(__DIR__.'/referencia/manifesto.json'),true);$diff=0;
foreach($manifest['files'] as $path=>$info){$file=$root.'/'.$path;$status=!is_file($file)?'AUSENTE':(hash_equals($info['sha256'],hash_file('sha256',$file))?'IGUAL':'DIVERGENTE — revisar trechos');if($status!=='IGUAL'){$diff++;}echo $status.' | '.$path."\n";}
echo "O resultado compara bytes com a referência; alterações legítimas do gerador também divergem. Nenhum arquivo foi substituído, nenhuma nota foi transmitida.\n";
exit($diff?2:0);
