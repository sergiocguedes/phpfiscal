<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Storage\JsonStore;
use App\Support\Paths;
use App\Support\Validate;

/**
 * Registro do tenant: storage/tenants/<id>/tenant.json
 * Subdiretórios do tenant: nfe/, outbox/, idempotency/.
 */
final class TenantStore
{
    public static function newId(): string
    {
        return 't_' . bin2hex(random_bytes(8));
    }

    /** @return array<string,mixed> */
    public static function create(string $nome, string $cnpj, ?string $id = null): array
    {
        $id ??= self::newId();
        $t = [
            'id' => $id,
            'nome' => $nome,
            'cnpj' => $cnpj,
            'ativa' => true,
            'ambiente_nfe' => 2,
            'ambiente_nfce' => 2,
            'ambiente_nfse' => 2,
            'criado_em' => date('c'),
        ];
        self::ensureDirs($id);
        JsonStore::write(Tenant::pathFor($id, 'tenant.json'), $t);
        return $t;
    }

    public static function ensureDirs(string $id): void
    {
        $old = umask(0077);
        foreach (['', 'nfe', 'idempotency', 'pdv', 'numeros'] as $d) {
            $p = Paths::storage(Tenant::pathFor($id, $d));
            if (!is_dir($p)) {
                @mkdir($p, 0700, true);
            }
        }
        umask($old);
    }

    /** @return array<string,mixed>|null */
    public static function get(string $id): ?array
    {
        if (!Tenant::valid($id)) {
            return null;
        }
        return JsonStore::read(Tenant::pathFor($id, 'tenant.json'));
    }

    /** @return array<string,mixed> */
    public static function rename(string $id, string $nome): array
    {
        return JsonStore::update(Tenant::pathFor($id, 'tenant.json'), function (?array $t) use ($nome) {
            if ($t === null) {
                throw Tenant::notFound('Tenant');
            }
            $t['nome'] = $nome;
            $t['atualizado_em'] = date('c');
            return [$t, $t];
        });
    }

    /** @return list<string> ids de todos os tenants (para o worker) */
    public static function ids(): array
    {
        $out = [];
        foreach (JsonStore::files('tenants/t_*/tenant.json') as $d) {
            $id = basename(dirname($d));
            if (Tenant::valid($id)) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $t @return array<string,mixed> */
    public static function publicView(array $t): array
    {
        return [
            'id' => $t['id'],
            'nome' => $t['nome'],
            'cnpj' => $t['cnpj'],
            'ativa' => (bool) ($t['ativa'] ?? true),
            'ambiente_nfe' => (int) ($t['ambiente_nfe'] ?? $t['ambiente'] ?? 2),
            'ambiente_nfce' => (int) ($t['ambiente_nfce'] ?? $t['ambiente'] ?? 2),
            'ambiente_nfse' => (int) ($t['ambiente_nfse'] ?? $t['ambiente'] ?? 2),
            'cnpj_valido' => Validate::cnpj((string) $t['cnpj']),
            'criado_em' => $t['criado_em'],
        ];
    }

    public static function settings(string $id, array $in): array
    {
        return JsonStore::update(Tenant::pathFor($id,'tenant.json'),function(?array $t)use($in){
            if($t===null){throw Tenant::notFound('Empresa');}
            if(array_key_exists('nome',$in)){$name=Validate::text($in['nome'],2,60);if($name===null){throw new \App\Http\ApiException(422,'validacao','Nome inválido');}$t['nome']=$name;}
            if(array_key_exists('ativa',$in)){if(!is_bool($in['ativa'])){throw new \App\Http\ApiException(422,'validacao','ativa deve ser booleano');}$t['ativa']=$in['ativa'];}
            if(array_key_exists('ambiente',$in)){throw new \App\Http\ApiException(422,'ambiente_por_modelo','Informe ambiente_nfe, ambiente_nfce e/ou ambiente_nfse');}
            foreach(['ambiente_nfe','ambiente_nfce','ambiente_nfse'] as $key){if(array_key_exists($key,$in)){if(!in_array($in[$key],[1,2],true)){throw new \App\Http\ApiException(422,'validacao',"$key deve ser 1 (produção) ou 2 (homologação)");}$t[$key]=$in[$key];}}
            $t['atualizado_em']=date('c'); return [$t,$t];
        });
    }

    public static function assertActive(): void
    {
        $t=self::get(Tenant::id());
        if($t!==null&&($t['ativa']??true)===false){throw new \App\Http\ApiException(403,'empresa_inativa','Empresa inativa para novas emissões');}
    }
}
