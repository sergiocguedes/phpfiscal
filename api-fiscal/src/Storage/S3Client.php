<?php
declare(strict_types=1);
namespace App\Storage;

use App\Support\Env;

/**
 * Backblaze B2 (API compatível S3) por HTTPS, assinado pelo libcurl com AWS Signature V4.
 * Credenciais vêm da configuração do sistema (cifrada no banco, janela /configuracao → Armazenamento de XML).
 */
final class S3Client
{
    /** Transporte somente para testes (nenhuma credencial real). */
    public static ?\Closure $transport=null;

    /** B2 configurado o suficiente para uso? */
    public static function configurado(?array $cfg=null): bool
    {
        foreach(['S3_ENDPOINT','S3_REGION','S3_BUCKET','S3_ACCESS_KEY_ID','S3_SECRET_ACCESS_KEY'] as $k){if(self::v($k,$cfg)===null){return false;}}
        return true;
    }
    private static function v(string $k,?array $cfg): ?string
    {
        $v=$cfg!==null&&array_key_exists($k,$cfg)?$cfg[$k]:Env::get($k);
        return ($v===null||$v==='')?null:(string)$v;
    }
    private static function req(string $k,?array $cfg): string
    {
        $v=self::v($k,$cfg);if($v===null){throw new \RuntimeException("Configure $k em Configuração → Armazenamento de XML");}return $v;
    }

    /** @param array<string,string>|null $cfg valores a usar no lugar dos salvos (teste de conexão da janela) */
    public static function request(string $method,string $key,?string $body=null,?array $cfg=null): string
    {
        if(self::$transport!==null){return (self::$transport)($method,$key,$body);}
        $endpoint=rtrim(self::req('S3_ENDPOINT',$cfg),'/');
        $url=parse_url($endpoint);
        if(($url['scheme']??'')!=='https'||empty($url['host'])||isset($url['user'])||isset($url['query'])||isset($url['fragment'])||!empty($url['path'])){
            throw new \RuntimeException('Endpoint deve ser uma origem HTTPS sem caminho, credenciais ou parâmetros');
        }
        $bucket=self::req('S3_BUCKET',$cfg);if(!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D',$bucket)){throw new \RuntimeException('Bucket inválido');}
        $region=self::req('S3_REGION',$cfg);if(!preg_match('/^[a-z0-9-]+$/D',$region)){throw new \RuntimeException('Região inválida');}
        $access=self::req('S3_ACCESS_KEY_ID',$cfg);$secret=self::req('S3_SECRET_ACCESS_KEY',$cfg);
        if(strpbrk($access.$secret,"\r\n:")!==false){throw new \RuntimeException('Credencial inválida');}
        $sse=self::v('S3_SSE',$cfg);$sse=$sse===null||in_array(strtolower($sse),['1','true','on','sim','yes'],true);
        $timeout=(int)(self::v('S3_TIMEOUT',$cfg)??20);
        $uri=$endpoint.'/'.rawurlencode($bucket).'/'.implode('/',array_map('rawurlencode',explode('/',$key)));
        $ch=curl_init($uri);
        $headers=['x-amz-content-sha256: '.hash('sha256',$body??'')];
        if($method==='PUT'){$headers[]='Content-Type: application/xml';if($sse){$headers[]='x-amz-server-side-encryption: AES256';}}
        curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_AWS_SIGV4=>'aws:amz:'.$region.':s3',CURLOPT_USERPWD=>$access.':'.$secret,
            CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>max(5,$timeout),
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        if($method==='PUT'){curl_setopt($ch,CURLOPT_POSTFIELDS,$body);}
        $result=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_errno($ch);curl_close($ch);
        if($result===false||$status<200||$status>=300){
            $code='';
            if(is_string($result)&&preg_match('/<Code>([A-Za-z0-9_]{1,80})<\/Code>/',$result,$match)){$code=', código '.$match[1];}
            $motivo=match(true){
                $error===6=>'endereço do endpoint não encontrado (DNS)',
                in_array($error,[7,56],true)=>'conexão recusada ou interrompida',
                $error===28=>'tempo limite esgotado',
                in_array($error,[35,51,58,60],true)=>'falha no certificado/TLS do endpoint',
                $status===403=>'acesso negado: confira keyID, applicationKey e permissão no bucket',
                $status===404=>'bucket ou objeto não encontrado',
                $status===400=>'requisição recusada: confira região e endpoint',
                default=>'resposta inesperada',
            };
            throw new \RuntimeException('Backblaze B2: '.$motivo.' (HTTP '.$status.', transporte '.$error.$code.')');
        }
        return $result;
    }

    /**
     * Grava, lê, compara SHA-256 e apaga um objeto de teste sem dados fiscais.
     * @param array<string,string>|null $cfg
     */
    public static function testar(?array $cfg=null): string
    {
        $prefix=trim((string)(self::v('S3_PREFIX',$cfg)??'fiscal'),'/');
        $key=($prefix!==''?$prefix.'/':'').'verificacao/'.bin2hex(random_bytes(12)).'.xml';
        $xml='<?xml version="1.0" encoding="UTF-8"?><verificacao>teste de armazenamento, sem dados fiscais</verificacao>';
        self::request('PUT',$key,$xml,$cfg);$back=self::request('GET',$key,null,$cfg);
        if(!hash_equals(hash('sha256',$xml),hash('sha256',$back))){throw new \RuntimeException('Conteúdo devolvido pelo B2 é diferente do enviado');}
        self::request('DELETE',$key,null,$cfg);
        return 'Upload, download e SHA-256 conferidos; objeto de teste removido. Nenhum documento fiscal transmitido.';
    }
}
