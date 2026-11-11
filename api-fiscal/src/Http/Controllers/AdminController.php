<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Auth\CentralAccess;
use App\Http\{ApiException,Request,Response};
use App\Support\{Validate,RequestContext,Logger};
use App\Storage\JsonStore;
use App\Tenancy\{Tenant,TenantStore};

final class AdminController
{
    public static function apiConfig(): Response {return Response::json(\App\Fiscal\ApiConfigService::get());}
    public static function saveApiConfig(Request $r): Response {$c=\App\Fiscal\ApiConfigService::put($r->json());Logger::audit('api.config.atualizada','sucesso');return Response::json($c);}
    public static function storage(): Response {return Response::json(['banco'=>\App\Support\Settings::backend()]+\App\Storage\XmlArchive::status());}
    public static function empresas(): Response
    {
        $companies=[];
        foreach(TenantStore::ids() as $id){$t=TenantStore::get($id);if($id===Tenant::DEFAULT_ID&&!Validate::cnpj((string)$t['cnpj'])){continue;}$companies[]=TenantStore::publicView($t);}
        return Response::json(['empresas'=>$companies]);
    }
    public static function createEmpresa(Request $r): Response
    {
        $in=$r->json();$nome=Validate::text($in['nome']??null,2,60);$cnpj=Validate::digits($in['cnpj']??'');
        if($nome===null||!Validate::cnpj($cnpj)){throw new ApiException(422,'validacao','Informe nome e CNPJ válido');}
        $t=JsonStore::withLock('empresa-cnpj-'.$cnpj,function()use($nome,$cnpj,$in){
            foreach(TenantStore::ids() as $id){if((TenantStore::get($id)['cnpj']??'')===$cnpj){throw new ApiException(409,'cnpj_em_uso','Empresa já cadastrada');}}
            if(array_key_exists('ambiente',$in)){throw new ApiException(422,'ambiente_por_modelo','Informe ambiente_nfe, ambiente_nfce e ambiente_nfse');}
            foreach(['ambiente_nfe','ambiente_nfce','ambiente_nfse'] as $key){if(array_key_exists($key,$in)&&!in_array($in[$key],[1,2],true)){throw new ApiException(422,'validacao',"$key deve ser 1 ou 2");}}
            if(array_key_exists('ativa',$in)&&!is_bool($in['ativa'])){throw new ApiException(422,'validacao','ativa deve ser booleano');}
            $t=TenantStore::create($nome,$cnpj);return TenantStore::settings($t['id'],$in);
        });
        Logger::audit('empresa.criada','sucesso',['empresa_id'=>$t['id']]);return Response::json(TenantStore::publicView($t),201);
    }
    public static function updateEmpresa(Request $r): Response
    {
        $t=TenantStore::settings($r->params['id'],$r->json());
        Logger::audit('empresa.atualizada','sucesso',['empresa_id'=>$t['id']]);return Response::json(TenantStore::publicView($t));
    }
    public static function users(): Response {return Response::json(['usuarios'=>CentralAccess::users()]);}
    public static function createUser(Request $r): Response { $u=CentralAccess::createUser($r->json());Logger::audit('usuario.central.criado','sucesso',['usuario_id'=>$u['id']]);return Response::json($u,201); }
    public static function updateUser(Request $r): Response { $u=CentralAccess::changeUser($r->params['id'],$r->json(),RequestContext::$actor);Logger::audit('usuario.central.atualizado','sucesso',['usuario_id'=>$u['id']]);return Response::json($u); }
    public static function keys(): Response
    {
        $out=[];foreach(JsonStore::files('admin/chaves/*.json') as $p){$k=JsonStore::read($p);unset($k['hash']);$out[]=$k;}
        return Response::json(['chaves'=>$out]);
    }
    public static function createKey(Request $r): Response
    {
        $name=Validate::text($r->json()['nome']??null,2,60);if($name===null){throw new ApiException(422,'validacao','Nome de 2 a 60 caracteres');}
        $k=CentralAccess::createKey($name,RequestContext::$actor);Logger::audit('chave.central.criada','sucesso',['chave_id'=>$k['id']]);return Response::json($k,201);
    }
    public static function revokeKey(Request $r): Response
    {
        $id=$r->params['id'];if(!preg_match('/^g_[a-f0-9]{16}$/D',$id)){throw new ApiException(404,'nao_encontrado','Chave não encontrada');}
        JsonStore::update('admin/chaves/'.$id.'.json',function($k){if($k===null){throw new ApiException(404,'nao_encontrado','Chave não encontrada');}$k['revogada_em']=date('c');return [$k,null];});
        Logger::audit('chave.central.revogada','sucesso',['chave_id'=>$id]);return Response::noContent();
    }
}
