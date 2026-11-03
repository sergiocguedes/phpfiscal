<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use App\Storage\{PostgresStore,JsonStore};
use App\Support\{Env,Paths};

if(PHP_SAPI!=='cli'){exit(1);}
if(!in_array('--confirmar-parada',$argv,true)){fwrite(STDERR,"Pare web e worker; faça backup de storage e .env. Execute com --confirmar-parada usando um container avulso. Nenhum original será removido.\n");exit(1);}
PostgresStore::migrate();
$root=rtrim(Paths::storage(),'/').'/';$count=0;
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
// Uma transação: tudo é importado ou nada, inclusive índices e certificados cifrados.
$db=PostgresStore::db();$db->beginTransaction();
try{
 foreach($iterator as $f){
  if(!$f->isFile()||$f->isLink()){continue;}
  $rel=substr($f->getPathname(),strlen($root));
  if(preg_match('~^(?:logs|tmp|locks|cache|schemas)/~',$rel)||str_starts_with(basename($rel),'.')){continue;}
  if(!preg_match('/\.(?:json|xml|enc|pdf)$/D',$rel)){continue;}
  $raw=file_get_contents($f->getPathname());$old=PostgresStore::read($rel);
  if($old!==null){
   $equal=str_ends_with($rel,'.json')?json_decode($old,true)==json_decode($raw,true):hash_equals(hash('sha256',$old),hash('sha256',$raw));
   if(!$equal){throw new RuntimeException('Destino diferente: '.$rel);}
  }else{PostgresStore::write($rel,$raw);}
  $check=PostgresStore::read($rel);
  $ok=str_ends_with($rel,'.json')?json_decode($check,true)==json_decode($raw,true):hash_equals(hash('sha256',$check),hash('sha256',$raw));
  if(!$ok){throw new RuntimeException('Falha de verificação: '.$rel);}
  $count++;
 }
 $db->commit();echo "Importação verificada: $count registros. Originais preservados. Ative STORAGE_DRIVER=pgsql e reinicie web/worker.\n";
}catch(Throwable $e){$db->rollBack();fwrite(STDERR,"Migração cancelada: ".$e->getMessage()."\n");exit(1);}
