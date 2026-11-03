<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Support\Env;
use App\Storage\{PostgresStore,JsonStore};

$ns=$argv[1]??('tests_lock_'.bin2hex(random_bytes(6)));
if(!preg_match('/^tests_lock_[a-f0-9]{12}$/D',$ns)){throw new LogicException('Namespace de teste inválido');}
Env::set('STORAGE_DRIVER','pgsql');Env::set('STORAGE_NAMESPACE',$ns);
if(($argv[2]??'')==='child'){
 for($i=0;$i<25;$i++){JsonStore::update('counter.json',fn($d)=>[['valor'=>($d['valor']??0)+1],null]);}exit;
}
PostgresStore::migrate();$children=[];
try{
 for($i=0;$i<4;$i++){$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,$ns,'child'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$children[]=[$p,$pipes];}
 foreach($children as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0){throw new RuntimeException('Processo de teste falhou: '.$err);}}
 $value=JsonStore::read('counter.json')['valor']??0;if($value!==100){throw new RuntimeException('Atualizações perdidas: '.$value);}
 echo "Concorrência PostgreSQL: 4 processos, 100 atualizações, nenhuma perda.\n";
}finally{foreach(['fiscal_documents','fiscal_blobs','fiscal_api_settings'] as $t){$q=PostgresStore::db()->prepare("DELETE FROM $t WHERE namespace=?");$q->execute([$ns]);}}
