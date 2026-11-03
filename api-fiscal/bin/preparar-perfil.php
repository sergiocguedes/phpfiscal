<?php
declare(strict_types=1);
// Sem bootstrap/vendor/banco: pode ser executado antes do composer install.
$root=dirname(__DIR__);$mode=$argv[1]??'';
if(!in_array($mode,['local','online'],true)){fwrite(STDERR,"Uso: php bin/preparar-perfil.php local|online\n");exit(1);}
$target=$root.'/.env.'.$mode;
if(file_exists($target)){fwrite(STDERR,"Arquivo .env.$mode já existe; nada foi alterado.\n");exit(1);}
$read=static function(string $path):array{
 $out=[];foreach(file($path,FILE_IGNORE_NEW_LINES) as $line){if(preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/',$line,$m)){$out[$m[1]]=$m[2];}}return $out;
};
$vars=array_replace($read($root.'/.env.example'),$read($root.'/.env.'.$mode.'.example'));
$vars['API_KEY']=bin2hex(random_bytes(32));$vars['CERT_ENCRYPTION_KEY']=base64_encode(random_bytes(32));
if($mode==='local'){$vars['DATABASE_PASSWORD']=bin2hex(random_bytes(32));}
$text="# Perfil $mode; segredos exclusivos desta instalação. Não versionar.\n";
foreach($vars as $k=>$v){$text.=$k.'='.$v."\n";}
$mask=umask(0077);$h=fopen($target,'x');umask($mask);
if($h===false){fwrite(STDERR,"Não foi possível criar o perfil.\n");exit(1);}
fwrite($h,$text);fclose($h);chmod($target,0600);
echo ".env.$mode criado. Nenhum segredo foi exibido; revise a configuração antes de iniciar.\n";
