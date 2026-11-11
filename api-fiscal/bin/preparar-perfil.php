<?php
declare(strict_types=1);
// Gera .env.local (instalação local/Windows) só com os dados de inicialização: senha do PostgreSQL e chave-mestra.
// Na VPS use deploy/instalar-vps.sh, que grava esses dados em /etc/phpfiscal, fora do projeto.
// Sem bootstrap/vendor/banco: pode ser executado antes do composer install.
$root=dirname(__DIR__);$mode=$argv[1]??'';
if($mode!=='local'){fwrite(STDERR,"Uso: php bin/preparar-perfil.php local\n(VPS: sudo bash deploy/instalar-vps.sh <dominio> <email>)\n");exit(1);}
$target=$root.'/.env.local';
if(file_exists($target)){fwrite(STDERR,"Arquivo .env.local já existe; nada foi alterado.\n");exit(1);}
$text="# Inicialização da instalação local. Não versionar. Demais configurações: /configuracao (banco).\n"
 ."DATABASE_DSN=pgsql:host=db;port=5432;dbname=fiscal\nDATABASE_USER=fiscal\nSTORAGE_NAMESPACE=fiscal_local\n"
 ."DATABASE_PASSWORD=".bin2hex(random_bytes(32))."\nCERT_ENCRYPTION_KEY=".base64_encode(random_bytes(32))."\n"
 ."# Valores iniciais: no primeiro início vão para o banco (bin/banco.php); depois altere em /configuracao e apague daqui.\n"
 ."APP_URL=http://localhost:8081\nSESSION_COOKIE_SECURE=false\n";
$mask=umask(0077);$h=fopen($target,'x');umask($mask);
if($h===false){fwrite(STDERR,"Não foi possível criar o perfil.\n");exit(1);}
fwrite($h,$text);fclose($h);chmod($target,0600);
echo ".env.local criado. Nenhum segredo foi exibido; guarde uma cópia da chave-mestra fora deste computador.\n";
