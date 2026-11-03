<?php

declare(strict_types=1);

namespace App\Nfe;

use App\Http\ApiException;
use App\Security\Crypto;
use App\Storage\JsonStore;
use App\Support\Env;
use App\Support\Errors;
use App\Support\Validate;
use App\Tenancy\Tenant;

/**
 * Dados do emitente do tenant. Persistido em storage/tenants/<id>/config.json.
 *
 * CSC (NFC-e): guardado cifrado (csc_enc, mesma Crypto do certificado) e nunca devolvido
 * pela API/MCP/painel — só "csc_configurado". O csc_id não é segredo e é exibido.
 *
 * Numeração por modelo + série:
 *  - NF-e (55): série padrão "serie" / "proximo_numero";
 *  - NFC-e (65): série padrão "serie_nfce" / "proximo_numero_nfce";
 *  - outras séries: mapa "numeracao" com chave "<modelo>:<serie>" => próximo número.
 */
final class ConfigService
{
    private static function file(): string
    {
        return Tenant::path('config.json');
    }

    /** @return array<string,mixed>|null */
    public static function get(): ?array
    {
        $c = JsonStore::read(self::file());
        if ($c !== null && array_key_exists('csc', $c)) {
            // versões anteriores gravavam o CSC em texto claro: cifra na primeira leitura
            $c = JsonStore::update(self::file(), function (?array $d) {
                if ($d === null) {
                    return [null, null];
                }
                if (is_string($d['csc'] ?? null) && $d['csc'] !== '') {
                    $d['csc_enc'] = Crypto::encrypt($d['csc']);
                }
                unset($d['csc']);
                return [$d, $d];
            });
        }
        $shared=JsonStore::read(Tenant::path('dados-emitente.json'));
        if($c===null&&$shared!==null){$c=['serie'=>1,'proximo_numero'=>1,'serie_nfce'=>1,'proximo_numero_nfce'=>1];}
        if($c!==null){$c=array_replace($c,$shared??[]);$c['ambiente']=\App\Tenancy\FiscalScope::ambiente(Tenant::id());unset($c['responsavel_tecnico']);}
        return $c;
    }

    /**
     * CSC decifrado (uso interno: QR-Code da NFC-e). Nunca devolver ao cliente.
     * @return array{csc:string, csc_id:string}|null null se token ou id não estiverem configurados
     */
    public static function csc(): ?array
    {
        $c = \App\Tenancy\FiscalScope::forModelo(65,fn()=>self::get());
        if ($c === null || empty($c['csc_enc']) || empty($c['csc_id'])) {
            return null;
        }
        return ['csc' => Crypto::decrypt((string) $c['csc_enc']), 'csc_id' => (string) $c['csc_id']];
    }

    /** Série padrão do modelo. @param array<string,mixed> $cfg */
    public static function defaultSerie(array $cfg, int $modelo): int
    {
        return $modelo === 65 ? (int) ($cfg['serie_nfce'] ?? 1) : (int) $cfg['serie'];
    }

    /** Próximo número (sem reservar) para modelo + série. @param array<string,mixed> $cfg */
    public static function nextNumber(array $cfg, int $modelo, int $serie): int
    {
        if ($serie === self::defaultSerie($cfg, $modelo)) {
            return (int) ($modelo === 65 ? ($cfg['proximo_numero_nfce'] ?? 1) : $cfg['proximo_numero']);
        }
        return (int) ($cfg['numeracao']["$modelo:$serie"] ?? 1);
    }

    /** @return array<string,mixed> */
    public static function require(): array
    {
        $c = self::get();
        if ($c === null) {
            throw new ApiException(409, 'config_ausente', 'Configure o emitente antes (PUT /api/v1/config)');
        }
        return $c;
    }

    /** @param array<string,mixed>|null $c @return array<string,mixed> */
    public static function publicView(?array $c): array
    {
        if ($c === null) {
            return ['configurado' => false, 'ambiente' => \App\Tenancy\FiscalScope::ambiente(Tenant::id()),
                'ambiente_nfe'=>\App\Tenancy\FiscalScope::ambiente(Tenant::id(),55),'ambiente_nfce'=>\App\Tenancy\FiscalScope::ambiente(Tenant::id(),65),'ambiente_nfse'=>\App\Tenancy\FiscalScope::ambiente(Tenant::id(),'nfse')];
        }
        $out = $c;
        unset($out['csc'], $out['csc_enc'], $out['responsavel_tecnico']);
        $nfce=\App\Tenancy\FiscalScope::forModelo(65,fn()=>self::get());
        foreach(['serie_nfce','proximo_numero_nfce','csc_id'] as $k){if(isset($nfce[$k])){$out[$k]=$nfce[$k];}}
        $out['ambiente_nfe']=\App\Tenancy\FiscalScope::ambiente(Tenant::id(),55);
        $out['ambiente_nfce']=\App\Tenancy\FiscalScope::ambiente(Tenant::id(),65);
        $out['ambiente_nfse']=\App\Tenancy\FiscalScope::ambiente(Tenant::id(),'nfse');
        $out['serie_nfce'] = (int) ($nfce['serie_nfce'] ?? 1);
        $out['proximo_numero_nfce'] = (int) ($nfce['proximo_numero_nfce'] ?? 1);
        $out['csc_id'] = $nfce['csc_id'] ?? null;
        $out['csc_configurado'] = (!empty($nfce['csc_enc']) || !empty($nfce['csc'])) && !empty($nfce['csc_id']);
        $out['configurado'] = true;
        return $out;
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function put(array $in): array
    {
        $e = new Errors();
        $out = [];

        $cnpj = Validate::digits($in['cnpj'] ?? '');
        if (!Validate::cnpj($cnpj)) {
            $e->add('cnpj', 'CNPJ inválido (14 dígitos com DV correto)');
        }
        $out['cnpj'] = $cnpj;
        $company=\App\Tenancy\TenantStore::get(Tenant::id());
        if($company!==null&&Validate::cnpj((string)$company['cnpj'])&&$company['cnpj']!==$cnpj){$e->add('cnpj','O CNPJ deve corresponder à empresa selecionada');}

        $ie = is_string($in['ie'] ?? null) ? strtoupper(trim($in['ie'])) : '';
        if (isset($in['ie']) && !is_string($in['ie'])) { $e->add('ie', 'Informe IE como texto ou omita para prestador sem inscrição estadual'); }
        if ($ie !== '' && $ie !== 'ISENTO') {
            $ie = Validate::digits($ie);
            if (!preg_match('/^\d{2,14}$/', $ie)) {
                $e->add('ie', 'IE deve ter 2 a 14 dígitos ou "ISENTO"');
            }
        }
        $out['ie'] = $ie === '' ? null : $ie;

        $out['razao_social'] = Validate::text($in['razao_social'] ?? null, 2, 60) ?? $e->add('razao_social', 'Obrigatório, 2 a 60 caracteres');
        $fant = $in['nome_fantasia'] ?? null;
        if ($fant !== null) {
            $out['nome_fantasia'] = Validate::text($fant, 1, 60) ?? $e->add('nome_fantasia', '1 a 60 caracteres');
        }

        $uf = is_string($in['uf'] ?? null) ? strtoupper($in['uf']) : '';
        if (!isset(Validate::UFS[$uf])) {
            $e->add('uf', 'UF inválida');
        }
        $out['uf'] = $uf;

        $amb = \App\Tenancy\FiscalScope::ambiente(Tenant::id());
        if (array_key_exists('ambiente',$in) && $in['ambiente'] !== $amb) {
            $e->add('ambiente','Altere o ambiente pela gestão da empresa antes de configurar este perfil fiscal');
        }
        if (!in_array($amb, [1, 2], true)) {
            $e->add('ambiente', 'Use 1 (produção) ou 2 (homologação)');

        }
        $out['ambiente'] = $amb;

        $crt = $in['crt'] ?? null;
        if (!in_array($crt, [1, 2, 3, 4], true)) {
            $e->add('crt', 'CRT: 1=Simples Nacional, 2=SN excesso, 3=Regime Normal, 4=MEI');
        }
        $out['crt'] = $crt;

        $end = $in['endereco'] ?? null;
        if (!is_array($end)) {
            $e->add('endereco', 'Obrigatório');
        } else {
            $out['endereco'] = self::endereco($end, 'endereco', $e, $uf);
        }

        $serie = $in['serie'] ?? 1;
        if (!is_int($serie) || $serie < 0 || $serie > 889) {
            $e->add('serie', 'Série entre 0 e 889');
        }
        $out['serie'] = $serie;

        $current = self::get();
        $nfceCurrent=\App\Tenancy\FiscalScope::forModelo(65,fn()=>self::get());
        if($current!==null&&$nfceCurrent!==null){$current['numeracao']=array_filter($current['numeracao']??[],fn($k)=>!str_starts_with((string)$k,'65:'),ARRAY_FILTER_USE_KEY)+array_filter($nfceCurrent['numeracao']??[],fn($k)=>str_starts_with((string)$k,'65:'),ARRAY_FILTER_USE_KEY);foreach(['serie_nfce','proximo_numero_nfce','csc_enc','csc_id'] as $k){if(array_key_exists($k,$nfceCurrent)){$current[$k]=$nfceCurrent[$k];}else{unset($current[$k]);}}}
        $prox = $in['proximo_numero'] ?? ($current['proximo_numero'] ?? 1);
        if (!is_int($prox) || $prox < 1 || $prox > 999999999) {
            $e->add('proximo_numero', 'Entre 1 e 999999999');
        }
        $out['proximo_numero'] = $prox;

        // NFC-e (modelo 65): série e numeração próprias
        $serieNfce = $in['serie_nfce'] ?? ($current['serie_nfce'] ?? 1);
        if (!is_int($serieNfce) || $serieNfce < 0 || $serieNfce > 889) {
            $e->add('serie_nfce', 'Série da NFC-e entre 0 e 889');
        }
        $out['serie_nfce'] = $serieNfce;
        $proxNfce = $in['proximo_numero_nfce'] ?? ($current['proximo_numero_nfce'] ?? 1);
        if (!is_int($proxNfce) || $proxNfce < 1 || $proxNfce > 999999999) {
            $e->add('proximo_numero_nfce', 'Entre 1 e 999999999');
        }
        $out['proximo_numero_nfce'] = $proxNfce;

        
        $numeracao = is_array($current['numeracao'] ?? null) ? $current['numeracao'] : [];
        if ($current !== null && is_int($serie) && is_int($serieNfce)) {
            if ((int) $current['serie'] !== $serie) {
                $numeracao['55:' . $current['serie']] = (int) $current['proximo_numero'];
                if (!array_key_exists('proximo_numero', $in)) {
                    $out['proximo_numero'] = (int) ($numeracao["55:$serie"] ?? 1);
                }
            }
            $oldNfce = (int) ($current['serie_nfce'] ?? 1);
            if ($oldNfce !== $serieNfce) {
                $numeracao["65:$oldNfce"] = (int) ($current['proximo_numero_nfce'] ?? 1);
                if (!array_key_exists('proximo_numero_nfce', $in)) {
                    $out['proximo_numero_nfce'] = (int) ($numeracao["65:$serieNfce"] ?? 1);
                }
            }
            unset($numeracao["55:$serie"], $numeracao["65:$serieNfce"]);
        }
        if ($numeracao !== []) {
            $out['numeracao'] = $numeracao;
        }

        // CSC: somente escrita. Omitido = mantém; null ou "" = remove; valor = substitui (cifrado).
        if (array_key_exists('csc', $in)) {
            $csc = $in['csc'];
            if ($csc === null || $csc === '') {
                $out['csc_enc'] = null;
            } elseif (!is_string($csc) || !preg_match('/^[A-Za-z0-9-]{16,36}$/', $csc)) {
                $e->add('csc', 'CSC inválido: 16 a 36 caracteres (letras, números e hífen), como gerado no portal da SEFAZ');
            } else {
                $out['csc_enc'] = Crypto::encrypt($csc);
            }
        } else {
            $out['csc_enc'] = $current['csc_enc'] ?? null;
        }
        $cscId = array_key_exists('csc_id', $in) ? $in['csc_id'] : ($current['csc_id'] ?? null);
        if (is_int($cscId)) {
            $cscId = (string) $cscId;
        }
        if ($cscId === '') {
            $cscId = null;
        }
        if ($cscId !== null && (!is_string($cscId) || !preg_match('/^\d{1,6}$/', $cscId))) {
            $e->add('csc_id', 'Identificador do CSC: número de 1 a 6 dígitos (ex.: "000001")');
        }
        $out['csc_id'] = $cscId;
        if (($out['csc_enc'] ?? null) === null) {
            unset($out['csc_enc']);
        }

        if(array_key_exists('responsavel_tecnico',$in)){$e->add('responsavel_tecnico','Configure o desenvolvedor em PUT /api/v1/admin/config');}

        $e->throwIfAny();
        $out['atualizado_em'] = date('c');
        $shared=array_intersect_key($out,array_flip(['cnpj','ie','razao_social','nome_fantasia','uf','crt','endereco']));
        JsonStore::write(Tenant::path('dados-emitente.json'),$shared);
        foreach([55,65] as $model){
            \App\Tenancy\FiscalScope::forModelo($model,function()use($out,$shared,$model){
                JsonStore::update(self::file(),function($old)use($out,$shared,$model){
                    $new=array_replace($old??['serie'=>1,'proximo_numero'=>1,'serie_nfce'=>1,'proximo_numero_nfce'=>1],$shared);
                    $new['ambiente']=\App\Tenancy\FiscalScope::ambiente(Tenant::id());$new['atualizado_em']=$out['atualizado_em'];
                    foreach($model===55?['serie','proximo_numero']:['serie_nfce','proximo_numero_nfce','csc_enc','csc_id'] as $k){if(array_key_exists($k,$out)){$new[$k]=$out[$k];}else{unset($new[$k]);}}
                    $map=array_filter($new['numeracao']??[],fn($k)=>!str_starts_with((string)$k,$model.':'),ARRAY_FILTER_USE_KEY);
                    $map+=array_filter($out['numeracao']??[],fn($k)=>str_starts_with((string)$k,$model.':'),ARRAY_FILTER_USE_KEY);
                    if($map!==[]){$new['numeracao']=$map;}else{unset($new['numeracao']);}
                    unset($new['responsavel_tecnico']);return [$new,null];
                });
            });
        }
        return $out;
    }

    /**
     * Valida endereço (emitente ou destinatário).
     * @param array<string,mixed> $end
     * @return array<string,mixed>
     */
    public static function endereco(array $end, string $prefix, Errors $e, ?string $ufEsperada = null): array
    {
        $o = [];
        $o['logradouro'] = Validate::text($end['logradouro'] ?? null, 2, 60) ?? $e->add("$prefix.logradouro", '2 a 60 caracteres');
        $o['numero'] = Validate::text($end['numero'] ?? null, 1, 60) ?? $e->add("$prefix.numero", '1 a 60 caracteres');
        if (isset($end['complemento']) && $end['complemento'] !== '') {
            $o['complemento'] = Validate::text($end['complemento'], 1, 60) ?? $e->add("$prefix.complemento", '1 a 60 caracteres');
        }
        $o['bairro'] = Validate::text($end['bairro'] ?? null, 2, 60) ?? $e->add("$prefix.bairro", '2 a 60 caracteres');
        $uf = is_string($end['uf'] ?? null) ? strtoupper($end['uf']) : ($ufEsperada ?? '');
        if (!isset(Validate::UFS[$uf])) {
            $e->add("$prefix.uf", 'UF inválida');
        }
        $o['uf'] = $uf;
        $cMun = Validate::digits($end['codigo_municipio'] ?? '');
        if (!preg_match('/^\d{7}$/', $cMun)) {
            $e->add("$prefix.codigo_municipio", 'Código IBGE do município com 7 dígitos');
        } elseif (isset(Validate::UFS[$uf]) && (int) substr($cMun, 0, 2) !== Validate::UFS[$uf]) {
            $e->add("$prefix.codigo_municipio", 'Código do município não pertence à UF informada');
        } elseif (($dfErr = Validate::municipioIbgeErro($cMun)) !== null) {
            $e->add("$prefix.codigo_municipio", $dfErr);
        }
        $o['codigo_municipio'] = $cMun;
        $o['municipio'] = Validate::text($end['municipio'] ?? null, 2, 60) ?? $e->add("$prefix.municipio", '2 a 60 caracteres');
        $cep = Validate::digits($end['cep'] ?? '');
        if (!preg_match('/^\d{8}$/', $cep)) {
            $e->add("$prefix.cep", 'CEP com 8 dígitos');
        }
        $o['cep'] = $cep;
        if (!empty($end['telefone'])) {
            $fone = Validate::digits($end['telefone']);
            preg_match('/^\d{6,14}$/', $fone) ? $o['telefone'] = $fone : $e->add("$prefix.telefone", '6 a 14 dígitos');
        }
        return $o;
    }

    /** Reserva atomicamente o próximo número de modelo + série (série padrão do modelo se omitida). */
    public static function reserveNumber(int $modelo = 55, ?int $serie = null): int
    {
        return JsonStore::update(self::file(), function (?array $c) use ($modelo, $serie) {
            $c ??= self::get();
            if ($c === null) {
                throw new ApiException(409, 'config_ausente', 'Configure o emitente antes (PUT /api/v1/config)');
            }
            $serie ??= self::defaultSerie($c, $modelo);
            $n = self::nextNumber($c, $modelo, $serie);
            if ($n > 999999999) {
                throw new ApiException(409, 'numeracao_esgotada', "Numeração esgotada na série $serie do modelo $modelo");
            }
            if ($serie === self::defaultSerie($c, $modelo)) {
                $c[$modelo === 65 ? 'proximo_numero_nfce' : 'proximo_numero'] = $n + 1;
            } else {
                $c['numeracao']["$modelo:$serie"] = $n + 1;
            }
            return [$c, $n];
        });
    }
}
