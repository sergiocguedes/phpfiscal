<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use App\Storage\{JsonStore,XmlArchive};
use App\Tenancy\TenantStore;

if(\App\Support\Env::get('XML_STORAGE','local')!=='s3'){fwrite(STDERR,"Configure e teste S3, depois ative XML_STORAGE=s3.\n");exit(1);}
$count=0;
foreach(TenantStore::ids() as $tid){foreach([1,2] as $amb){foreach(['nfe','nfse','pdv'] as $dir){
 if($dir==='pdv'){
  foreach(JsonStore::files("tenants/$tid/ambientes/$amb/pdv/*.json") as $opPath){
   JsonStore::withLock('pdv-'.sha1($opPath),function()use($opPath){
    $op=JsonStore::read($opPath);$changed=false;
    foreach(['xml','xml_sem_assinatura'] as $field){if(isset($op[$field])){
     $file=str_replace('.json','-'.$field.'.xml',$opPath);JsonStore::writeRaw($file,$op[$field]);
     $op[$field.'_arquivo']=$file;unset($op[$field]);$changed=true;
    }}
    if($changed){JsonStore::write($opPath,$op);}
   });
  }
 }
 foreach(JsonStore::files("tenants/$tid/ambientes/$amb/$dir/*.xml") as $path){
  if(XmlArchive::exists($path)){continue;}
  $xml=JsonStore::readRaw($path);if($xml!==null){XmlArchive::put($path,$xml);$count++;}
 }
}}}
echo "XML históricos enfileirados: $count. O worker remove a cópia original somente após PUT, GET e conferência SHA-256.\n";
