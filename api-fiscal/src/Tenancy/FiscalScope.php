<?php
declare(strict_types=1);
namespace App\Tenancy;

use App\Storage\JsonStore;

final class FiscalScope
{
    private static array $overrides=[];
    private static string $modelo='nfe';
    public static function reset(): void {self::$modelo='nfe';self::$overrides=[];}
    public static function modelo(): string {return self::$modelo;}
    public static function setModelo(int|string $modelo): void
    {
        self::$modelo=match($modelo){55,'55','nfe'=>'nfe',65,'65','nfce'=>'nfce','nfse'=>'nfse',default=>throw new \InvalidArgumentException('Tipo de documento inválido')};
    }
    public static function forModelo(int|string $modelo,callable $fn): mixed
    {
        $prev=self::$modelo;self::setModelo($modelo);try{return $fn();}finally{self::$modelo=$prev;}
    }
    public static function ambiente(string $tid,int|string|null $modelo=null): int
    {
        if(isset(self::$overrides[$tid])){return self::$overrides[$tid];}
        $t=JsonStore::read('tenants/'.$tid.'/tenant.json');
        $tipo=$modelo===null?self::$modelo:match($modelo){55,'nfe'=>'nfe',65,'nfce'=>'nfce','nfse'=>'nfse'};
        return (int)($t['ambiente_'.$tipo]??$t['ambiente']??2);
    }
    public static function run(string $tid,int $amb,callable $fn,int|string|null $modelo=null): mixed
    {
        if($modelo!==null){return self::forModelo($modelo,fn()=>self::run($tid,$amb,$fn));}
        $prev=self::$overrides[$tid]??null; self::$overrides[$tid]=$amb;
        try{return Tenant::run($tid,$fn);}finally{if($prev===null){unset(self::$overrides[$tid]);}else{self::$overrides[$tid]=$prev;}}
    }
    /** Cópia verificada dos registros anteriores; originais permanecem para recuperação. */
    public static function migrate(): void
    {
        foreach(TenantStore::ids() as $tid){
            $t=TenantStore::get($tid);
            if(!isset($t['ambiente_nfe'],$t['ambiente_nfce'],$t['ambiente_nfse'])){
                JsonStore::update('tenants/'.$tid.'/tenant.json',function($r){$old=(int)($r['ambiente']??2);foreach(['nfe','nfce','nfse'] as $tipo){$r['ambiente_'.$tipo]??=$old;}return [$r,null];});
            }
            $root='tenants/'.$tid.'/';
            if(JsonStore::read($root.'fiscal-scope.json')!==null){continue;}
            JsonStore::withLock('scope-'.$tid,function()use($root,$tid){
                if(JsonStore::read($root.'fiscal-scope.json')!==null){return;}
                $cfg=JsonStore::read($root.'config.json'); $nfse=JsonStore::read($root.'nfse-config.json');
                $default=(int)($cfg['ambiente']??TenantStore::get($tid)['ambiente']??2);
                if($cfg!==null){self::copy($root.'config.json',$root.'ambientes/'.$default.'/config.json');}
                if($nfse!==null){self::copy($root.'nfse-config.json',$root.'ambientes/'.(int)$nfse['ambiente'].'/nfse-config.json');}
                foreach(['nfe','nfse','pdv','numeros','idempotency'] as $dir){
                    foreach(JsonStore::files($root.$dir.'/*') as $path){
                        $name=basename($path);$amb=$dir==='nfse'?(int)($nfse['ambiente']??$default):$default;
                        $record=str_ends_with($path,'.json')?JsonStore::read($path):null;
                        if($record!==null){$amb=(int)($record['ambiente']??$amb);}else{
                            $base=$dir==='nfe'?substr($name,0,44):(preg_match('/^(DPS\d{42})/',$name,$m)?$m[1]:'');
                            $meta=$base!==''?JsonStore::read($root.$dir.'/'.$base.'.json'):null;
                            $amb=(int)($meta['ambiente']??$amb);
                        }
                        self::copy($path,$root.'ambientes/'.$amb.'/'.$dir.'/'.$name);
                    }
                }
                if(JsonStore::read($root.'inutilizacoes.json')!==null){self::copy($root.'inutilizacoes.json',$root.'ambientes/'.$default.'/inutilizacoes.json');}
                JsonStore::update($root.'tenant.json',fn($t)=>[array_replace($t,['ambiente'=>$default,'ativa'=>$t['ativa']??true]),null]);
                JsonStore::write($root.'fiscal-scope.json',['versao'=>1,'migrado_em'=>date('c')]);
            });
        }
    }
    private static function copy(string $from,string $to): void
    {
        $raw=JsonStore::readRaw($from);
        if($raw===null){return;}
        $old=JsonStore::readRaw($to);
        if($old!==null){
            $equal=str_ends_with($to,'.json')?json_decode($old,true)==json_decode($raw,true):hash_equals(hash('sha256',$raw),hash('sha256',$old));
            if(!$equal){throw new \RuntimeException('Conflito na migração fiscal: '.$to);}
            return;
        }
        JsonStore::writeRaw($to,$raw);
    }
}
