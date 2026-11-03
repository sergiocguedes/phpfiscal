<?php
declare(strict_types=1);
namespace App\Fiscal;

use App\Storage\JsonStore;
use App\Support\{Errors,Validate};

/** Configuração do desenvolvedor, compartilhada por todos os emitentes. */
final class ApiConfigService
{
    public const PATH='system/api-config.json';
    public static function get(): array {return JsonStore::read(self::PATH)??['responsavel_tecnico'=>null];}
    public static function put(array $in): array
    {
        $e=new Errors();$rt=$in['responsavel_tecnico']??null;$out=['responsavel_tecnico'=>null];
        if($rt!==null){
            if(!is_array($rt)||array_is_list($rt)){$e->add('responsavel_tecnico','Objeto esperado');}
            else{
                $cnpj=Validate::digits($rt['cnpj']??'');$email=$rt['email']??'';$fone=Validate::digits($rt['fone']??'');
                if(!Validate::cnpj($cnpj)){$e->add('responsavel_tecnico.cnpj','CNPJ inválido');}
                if(!is_string($email)||!filter_var($email,FILTER_VALIDATE_EMAIL)){$e->add('responsavel_tecnico.email','E-mail inválido');}
                if(!preg_match('/^\d{6,14}$/D',$fone)){$e->add('responsavel_tecnico.fone','6 a 14 dígitos');}
                $contato=Validate::text($rt['contato']??null,2,60);if($contato===null){$e->add('responsavel_tecnico.contato','2 a 60 caracteres');}
                $out['responsavel_tecnico']=['cnpj'=>$cnpj,'contato'=>$contato,'email'=>$email,'fone'=>$fone];
            }
        }
        $e->throwIfAny();$out['atualizado_em']=date('c');JsonStore::write(self::PATH,$out);return $out;
    }
    public static function migrate(): void
    {
        if(JsonStore::read('system/api-config-migration-v2.json')!==null){return;}
        JsonStore::withLock('api-config-migration',function(){
            if(JsonStore::read('system/api-config-migration-v2.json')!==null){return;}
            $found=[];
            foreach(JsonStore::files('tenants/*/ambientes/*/config.json') as $p){$c=JsonStore::read($p);if(!empty($c['responsavel_tecnico'])){$rt=$c['responsavel_tecnico'];ksort($rt);$found[hash('sha256',json_encode($rt))]=$rt;}}
            if(count($found)===1&&JsonStore::read(self::PATH)===null){self::put(['responsavel_tecnico'=>array_values($found)[0]]);}
            JsonStore::write('system/api-config-migration-v2.json',['quantidade_candidatos'=>count($found),'candidatos'=>array_values($found),'revisao_necessaria'=>count($found)>1,'em'=>date('c')]);
        });
    }
}
