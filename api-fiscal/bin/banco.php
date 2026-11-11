<?php
declare(strict_types=1);
// Executado a cada início dos containers: cria/atualiza o schema, importa configurações de um .env antigo
// e traz para o banco os XML gravados em arquivo por versões anteriores. Idempotente.
require dirname(__DIR__).'/bootstrap.php';
use App\Storage\{PostgresStore,XmlArchive};
use App\Support\Settings;

if(!PostgresStore::enabled()){fwrite(STDERR,"Esta versão usa somente PostgreSQL.\n");exit(1);}
PostgresStore::migrate();
$importadas=Settings::importarArquivo('inicializacao');
if($importadas!==[]){echo 'Configurações importadas do arquivo para o banco: '.implode(', ',$importadas).". Remova-as do arquivo.\n";}
$x=XmlArchive::migrarLegado();
if($x['migrados']+$x['falhas']+$x['ausentes']>0){echo "XML de versão anterior: {$x['migrados']} migrado(s) para o banco, {$x['falhas']} com falha, {$x['ausentes']} não encontrado(s).\n";}
