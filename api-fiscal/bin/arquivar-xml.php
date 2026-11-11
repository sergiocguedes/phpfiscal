<?php
declare(strict_types=1);
// Envia agora ao Backblaze B2 os XML pendentes (o worker já faz isso a cada 2 s; útil para esvaziar a fila manualmente).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/bootstrap.php';
use App\Storage\XmlArchive;
$limite=max(1,(int)($argv[1]??1000));
XmlArchive::ciclo($limite);
echo json_encode(XmlArchive::status(),JSON_UNESCAPED_UNICODE),"\n";
