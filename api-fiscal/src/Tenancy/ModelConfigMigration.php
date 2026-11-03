<?php
declare(strict_types=1);
namespace App\Tenancy;

use App\Storage\JsonStore;

final class ModelConfigMigration
{
    public static function run(): void
    {
        foreach(TenantStore::ids() as $tid){$root='tenants/'.$tid.'/';
            if(JsonStore::read($root.'config-modelos-v1.json')!==null){continue;}
            JsonStore::withLock('config-modelos-'.$tid,function()use($root,$tid){
                if(JsonStore::read($root.'config-modelos-v1.json')!==null){return;}
                $active=FiscalScope::ambiente($tid,55);$base=JsonStore::read($root.'ambientes/'.$active.'/config.json');
                if($base!==null&&JsonStore::read($root.'dados-emitente.json')===null){JsonStore::write($root.'dados-emitente.json',array_intersect_key($base,array_flip(['cnpj','ie','razao_social','nome_fantasia','uf','crt','endereco'])));}
                $nfse=JsonStore::read($root.'ambientes/'.FiscalScope::ambiente($tid,'nfse').'/nfse-config.json');
                if($nfse!==null&&JsonStore::read($root.'nfse-prestador.json')===null){foreach(['ambiente','serie','proximo_numero','numeracao','municipio_convenio_ativo','parametros_municipio','servicos'] as $k){unset($nfse[$k]);}JsonStore::write($root.'nfse-prestador.json',$nfse);}
                foreach([1,2] as $amb){
                    foreach(['config.json','nfse-config.json'] as $name){$path=$root.'ambientes/'.$amb.'/'.$name;$c=JsonStore::read($path);
                        if($c!==null){$new=$c;unset($new['responsavel_tecnico'],$new['servicos'],$new['reforma_tributaria']);if($new!==$c){JsonStore::write($path,$new);}}
                    }
                }
                JsonStore::delete($root.'reforma-tributaria.json');
                JsonStore::update($root.'tenant.json',function($t){unset($t['ambiente']);return [$t,null];});
                JsonStore::write($root.'config-modelos-v1.json',['em'=>date('c')]);
            });
        }
    }
}
