<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use App\Auth\{UserStore,Password,CentralAccess};
use App\Tenancy\Migration;

if(PHP_SAPI!=='cli'){exit(1);}
Migration::run();
$email=UserStore::normalizeEmail($argv[1]??null);
if($email===null){fwrite(STDERR,"Uso: php bin/administrador.php email [--gerar-senha]\nPromove um usuário existente preservando sua senha; --gerar-senha cria ou redefine a senha.\n");exit(1);}
$user=UserStore::findByEmail($email);
$generate=in_array('--gerar-senha',$argv,true);
if($user===null&&!$generate){fwrite(STDERR,"Usuário não existe. Use --gerar-senha.\n");exit(1);}
$password=$generate?'Ls!'.bin2hex(random_bytes(14)).'9a':null;
if($user===null){$created=CentralAccess::createUser(['nome'=>'Administrador','email'=>$email,'senha'=>$password,'acesso_central'=>'admin']);$user=UserStore::get($created['id']);}
else {UserStore::update($user['id'],function($u)use($password){$u['acesso_central']='admin';$u['ativo']=true;$u['sessao_versao']++;if($password!==null){$u['senha_hash']=Password::hash($password);$u['senha_definida']=true;}return $u;});}
echo "Administrador: $email\n";
if($password!==null){echo "Senha gerada (exibida somente agora): $password\n";}
