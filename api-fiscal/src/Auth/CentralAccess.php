<?php
declare(strict_types=1);
namespace App\Auth;

use App\Storage\JsonStore;
use App\Tenancy\Tenant;
use App\Http\ApiException;

final class CentralAccess
{
    public static function createKey(string $nome,string $actor): array
    {
        $token='adm_'.bin2hex(random_bytes(32));$hash=hash('sha256',$token);
        $r=['id'=>'g_'.bin2hex(random_bytes(8)),'nome'=>$nome,'hash'=>$hash,'criado_por'=>$actor,'criado_em'=>date('c'),'revogada_em'=>null];
        JsonStore::write('admin/chaves/'.$r['id'].'.json',$r);
        unset($r['hash']); return $r+['chave'=>$token];
    }
    public static function authenticate(string $token): ?Principal
    {
        if(!preg_match('/^adm_[a-f0-9]{64}$/D',$token)){return null;}
        $hash=hash('sha256',$token);
        foreach(JsonStore::files('admin/chaves/*.json') as $path){$r=JsonStore::read($path);
            if($r!==null&&$r['revogada_em']===null&&hash_equals($r['hash'],$hash)){
                return new Principal(Tenant::DEFAULT_ID,Principal::VIA_API_KEY,UserStore::ROLE_OWNER,null,$r['id'],null,null,false,'admin');
            }
        }
        return null;
    }
    public static function users(): array
    {
        $out=[];foreach(JsonStore::files('users/u_*.json') as $p){$u=JsonStore::read($p);if(in_array($u['acesso_central']??null,['admin','operador'],true)){$out[]=UserStore::publicView($u);}}
        return $out;
    }
    public static function createUser(array $in): array
    {
        $nome=\App\Support\Validate::text($in['nome']??null,2,80);$email=UserStore::normalizeEmail($in['email']??null);
        $role=$in['acesso_central']??'operador';$pw=Password::problem($in['senha']??null);
        if($nome===null||$email===null||$pw!==null||!in_array($role,['admin','operador'],true)){
            throw new ApiException(422,'validacao',$pw??'Informe nome, e-mail e acesso_central admin ou operador');
        }
        $u=UserStore::create(Tenant::DEFAULT_ID,$nome,$email,Password::hash($in['senha']),UserStore::ROLE_OWNER);
        $u=UserStore::update($u['id'],fn($u)=>array_replace($u,['ativo'=>true,'acesso_central'=>$role]));
        return UserStore::publicView($u);
    }
    public static function changeUser(string $id,array $in,string $actor): array
    {
        return JsonStore::withLock('central-users',function()use($id,$in,$actor){
            $old=UserStore::get($id);
            if($old===null||!in_array($old['acesso_central']??null,['admin','operador'],true)){throw new ApiException(404,'nao_encontrado','Usuário não encontrado');}
            $role=array_key_exists('acesso_central',$in)?$in['acesso_central']:$old['acesso_central'];$ativo=array_key_exists('ativo',$in)?$in['ativo']:($old['ativo']??true);
            if(!is_bool($ativo)||!in_array($role,['admin','operador'],true)){throw new ApiException(422,'validacao','Acesso ou situação inválida');}
            if($old['acesso_central']==='admin'&&($old['ativo']??true)&&(!$ativo||$role!=='admin')){
                $admins=array_filter(self::users(),fn($u)=>$u['acesso_central']==='admin'&&$u['ativo']);
                if(count($admins)<=1){throw new ApiException(409,'ultimo_admin','Mantenha pelo menos um administrador ativo');}
            }
            $u=UserStore::update($id,fn($u)=>array_replace($u,['ativo'=>$ativo,'acesso_central'=>$role,'sessao_versao'=>(int)$u['sessao_versao']+1]));
            return UserStore::publicView($u);
        });
    }
}
