<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Storage\S3Client;
use App\Support\Env;

$key=trim((string)Env::get('S3_PREFIX','fiscal'),'/').'/verificacao/'.bin2hex(random_bytes(12)).'.xml';
$xml='<?xml version="1.0" encoding="UTF-8"?><verificacao>teste de armazenamento, sem dados fiscais</verificacao>';
try{
 S3Client::request('PUT',$key,$xml);$back=S3Client::request('GET',$key);
 if(!hash_equals(hash('sha256',$xml),hash('sha256',$back))){throw new RuntimeException('Conteúdo remoto diferente');}
 S3Client::request('DELETE',$key);
 echo "S3: upload/download e SHA-256 conferidos; objeto de teste removido por nome. Nenhum documento fiscal transmitido.\n";
}catch(Throwable $e){fwrite(STDERR,"Verificação falhou: ".$e->getMessage()."\n");exit(1);}
