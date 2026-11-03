<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use App\Storage\{PostgresStore,JsonStore};

$mode=$argv[1]??'';$file=$argv[2]??'';
if(!PostgresStore::enabled()||!in_array($mode,['exportar','importar'],true)||$file===''){
 fwrite(STDERR,"Uso com STORAGE_DRIVER=pgsql: php bin/backup-banco.php exportar|importar /caminho/backup.jsonl\nImportação exige web e worker parados; destino vazio ou com conteúdo idêntico.\n");exit(1);
}
$db=PostgresStore::db();$ctx=hash_init('sha256');$count=0;
try{
 if($mode==='exportar'){
  $old=umask(0077);$h=fopen($file,'x');umask($old);if($h===false){throw new RuntimeException('Destino deve ser um arquivo novo');}chmod($file,0600);
  fwrite($h,json_encode(['formato'=>'fiscal-backup-v1','namespace'=>PostgresStore::ns(),'criado_em'=>date('c')])."\n");
  $db->beginTransaction();$db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
  $q=$db->prepare("SELECT path,content::text AS raw FROM fiscal_documents WHERE namespace=? UNION ALL SELECT path,encode(content,'base64') AS raw FROM fiscal_blobs WHERE namespace=? UNION ALL SELECT 'system/api-config.json' AS path,config::text AS raw FROM fiscal_api_settings WHERE namespace=? ORDER BY path");
  $q->execute([PostgresStore::ns(),PostgresStore::ns(),PostgresStore::ns()]);
  while($r=$q->fetch(PDO::FETCH_ASSOC)){
   $raw=str_ends_with($r['path'],'.json')?$r['raw']:base64_decode($r['raw'],true);
   $line=json_encode(['path'=>$r['path'],'base64'=>base64_encode($raw)],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
   hash_update($ctx,$line);if(fwrite($h,$line)!==strlen($line)){throw new RuntimeException('Falha ao escrever backup');}$count++;
  }
  $db->commit();fwrite($h,json_encode(['fim'=>true,'registros'=>$count,'sha256'=>hash_final($ctx)])."\n");fclose($h);
 }else{
  PostgresStore::migrate();$h=fopen($file,'r');if($h===false){throw new RuntimeException('Backup não encontrado');}
  $header=json_decode((string)fgets($h),true,512,JSON_THROW_ON_ERROR);if(($header['formato']??'')!=='fiscal-backup-v1'){throw new RuntimeException('Formato inválido');}
  $db->beginTransaction();$final=false;
  while(($line=fgets($h))!==false){
   $r=json_decode($line,true,512,JSON_THROW_ON_ERROR);
   if(($r['fim']??false)===true){
    if($r['registros']!==$count||!hash_equals($r['sha256'],hash_final($ctx))||fgets($h)!==false){throw new RuntimeException('Backup incompleto ou alterado');}$final=true;break;
   }
   hash_update($ctx,$line);$path=$r['path'];JsonStore::path($path);$raw=base64_decode($r['base64'],true);
   if($raw===false){throw new RuntimeException('Conteúdo inválido');}
   $old=PostgresStore::read($path);
   if($old!==null){$equal=str_ends_with($path,'.json')?json_decode($old,true)==json_decode($raw,true):hash_equals(hash('sha256',$old),hash('sha256',$raw));if(!$equal){throw new RuntimeException('Conflito no destino: '.$path);}}
   else{PostgresStore::write($path,$raw);}
   $count++;
  }
  if(!$final){throw new RuntimeException('Backup truncado');}$db->commit();fclose($h);
 }
 echo "$mode concluído: $count registros. XML já arquivado no B2 é referenciado pelo banco e deve ser preservado no bucket.\n";
}catch(Throwable $e){if($db->inTransaction()){$db->rollBack();}fwrite(STDERR,$e->getMessage()."\n");exit(1);}
