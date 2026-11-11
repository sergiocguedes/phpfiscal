<?php
declare(strict_types=1);
// Testa o Backblaze B2 com a configuração salva no banco: grava, lê, confere SHA-256 e apaga um objeto sem dados fiscais.
// Na janela /configuracao → Armazenamento de XML há o mesmo teste ("Testar conexão").
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/bootstrap.php';
use App\Storage\S3Client;
if(!S3Client::configurado()){fwrite(STDERR,"Backblaze B2 não configurado (Configuração → Armazenamento de XML).\n");exit(1);}
try{echo S3Client::testar(),"\n";}catch(Throwable $e){fwrite(STDERR,"Verificação falhou: ".$e->getMessage()."\n");exit(1);}
