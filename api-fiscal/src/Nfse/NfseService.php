<?php

declare(strict_types=1);

namespace App\Nfse;

use App\Http\ApiException;
use App\Nfe\CertificateStore;
use App\Nfe\ConfigService;
use App\Support\Logger;


/**
 * Fluxo da NFS-e Padrão Nacional: validação (dry-run com XSD), emissão síncrona na Sefin Nacional, sincronização
 * (consulta pela DPS/chave), cancelamento por evento e DANFSe do ADN. Mesmo serviço para REST, MCP e painel.
 *
 * Estados: processando (DPS assinada, envio em curso) -> autorizada | rejeitada | erro (falha de comunicação ou
 * resposta inesperada: consultar antes de reenviar) ; autorizada -> cancelada.
 */
final class NfseService
{
    public const STATUS = ['processando', 'autorizada', 'rejeitada', 'erro', 'cancelada'];
    public const MOTIVOS_CANCELAMENTO = [1 => 'Erro na emissão', 2 => 'Serviço não prestado', 9 => 'Outros'];

    /** Nova tentativa corrigida, apenas após rejeição definitiva e consulta negativa. */
    public static function corrigir(string $ref,array $payload): array
    {
        $id=NfseRepository::resolve($ref);
        return \App\Storage\JsonStore::withLock('nfse-reenvio-'.hash('sha256',\App\Tenancy\Tenant::id().':'.$id),function()use($id,$payload){
            $rec=NfseRepository::get($id);
            if((int)$rec['ambiente']!==2){throw new ApiException(422,'somente_homologacao','Correção disponível somente em homologação');}
            if($rec['status']==='autorizada'){return [200,self::publicRec($rec)];}
            if($rec['status']!=='rejeitada'){throw new ApiException(409,'estado_invalido','Correção exige rejeição definitiva; sincronize antes se houver dúvida');}
            if(($payload['numero']??null)!==(int)$rec['numero_dps']||($payload['serie']??null)!==(int)$rec['serie']){throw new ApiException(422,'identidade_divergente','A correção deve manter série e número da DPS original');}
            SefinClient::validarAmbiente(2);
            $remote=SefinClient::consultarDps(2,$id);
            if($remote['status']===200&&!empty($remote['json']['chaveAcesso'])){self::sincronizar($rec);NfseRepository::save($rec);return [200,self::publicRec($rec)];}
            if($remote['status']!==404){throw new ApiException(502,'consulta_inconclusiva','Não foi confirmada ausência de NFS-e. Nenhuma correção transmitida');}
            // Validar nova identidade completa antes de permitir substituir o registro rejeitado.
            $cfg=NfseConfig::require();$emit=ConfigService::require();$p=DpsBuilder::validatePayload($payload,$cfg,$emit);
            $new=DpsBuilder::build($p,$cfg,$emit,$payload['numero'],$payload['serie']);
            if($new['id']!==$id){throw new ApiException(422,'identidade_divergente','Município/CNPJ da nova DPS divergem da referência original');}
            return self::emitir($payload,false,'correcao');
        });
    }

    /** Recuperação explícita de E0008 em homologação, mantendo DPS/tributos/numeração. */
    public static function reenviarHorario(string $ref, int $recuo): array
    {
        if ($recuo<30 || $recuo>300) { throw ApiException::validation([['campo'=>'recuo_segundos','mensagem'=>'Inteiro entre 30 e 300']]); }
        $id=NfseRepository::resolve($ref);
        return \App\Storage\JsonStore::withLock('nfse-reenvio-'.hash('sha256',\App\Tenancy\Tenant::id().':'.$id),function()use($id,$recuo){
            $rec=NfseRepository::get($id);
            if ((int)$rec['ambiente']!==2) { throw new ApiException(422,'somente_homologacao','Recuperação de horário disponível somente para homologação'); }
            SefinClient::validarAmbiente(2);
            if ($rec['status']==='autorizada') { return self::publicRec($rec); }
            if ($rec['status']!=='rejeitada' || !in_array('E0008',array_column($rec['erros']??[],'codigo'),true) || count($rec['erros']??[])!==1) {
                throw new ApiException(409,'estado_invalido','Reenvio de horário exige rejeição definitiva E0008, sem outros erros');
            }
            $remote=SefinClient::consultarDps(2,$id);
            if ($remote['status']===200 && !empty($remote['json']['chaveAcesso'])) {
                self::sincronizar($rec);NfseRepository::save($rec);return self::publicRec($rec);
            }
            if ($remote['status']!==404) { throw new ApiException(502,'consulta_inconclusiva','Não foi possível confirmar que a DPS não gerou NFS-e; nenhum reenvio realizado'); }
            $old=NfseRepository::file($id,'dps')??throw new ApiException(409,'dps_ausente','DPS assinada original ausente');
            $dom=NfseXml::load($old);$xp=new \DOMXPath($dom);$xp->registerNamespace('n',DpsBuilder::NS);$xp->registerNamespace('ds','http://www.w3.org/2000/09/xmldsig#');
            $dh=$xp->query('/n:DPS/n:infDPS/n:dhEmi')->item(0);
            if($dh===null){throw new ApiException(409,'dps_invalida','DPS sem data de emissão');}
            $dh->nodeValue=date('Y-m-d\TH:i:sP',time()-$recuo);
            foreach($xp->query('//ds:Signature') as $sig){$sig->parentNode->removeChild($sig);}
            $xml=$dom->saveXML();$errors=NfseXml::validate($xml,'DPS_v1.01.xsd',NfseXml::leiaute(2));
            if($errors!==[]){throw new ApiException(422,'validacao_schema','DPS corrigida inválida no XSD',$errors);}
            $signed=NfseXml::sign($xml,'infDPS');
            \App\Storage\JsonStore::writeRaw(\App\Tenancy\Tenant::path('nfse/tentativas/'.$id.'-'.bin2hex(random_bytes(6)).'-dps.xml'),$old);
            $rec['status']='processando';unset($rec['erros'],$rec['mensagem']);
            self::push($rec,'reenvio_horario','E0008: ajustado dhEmi com margem de '.$recuo.' segundos, após consulta negativa da DPS');
            NfseRepository::saveFile($id,'dps',$signed);NfseRepository::save($rec);
            try{self::aplicarRetornoEmissao($rec,SefinClient::emitir(2,$signed));}
            catch(\Throwable $e){$rec['status']='erro';$rec['mensagem']='Falha no reenvio de horário. Sincronize esta DPS antes de qualquer nova tentativa.';Logger::exception($e,'nfse.reenvio_horario');}
            NfseRepository::save($rec);Logger::audit('nfse.reenvio_horario',$rec['status'],['id_dps'=>$id,'ambiente'=>2]);
            return self::publicRec($rec);
        });
    }

    /**
     * @param array<mixed> $payload
     * @return array{0:int, 1:array<string,mixed>}
     */
    public static function emitir(array $payload, bool $dryRun, string $origem): array
    {
        $emit = ConfigService::require();
        $cfg = NfseConfig::require();
        $p = DpsBuilder::validatePayload($payload, $cfg, $emit);
        $serie = $p['serie'] ?? (int) $cfg['serie'];

        if ($dryRun) {
            $numero = $p['numero'] ?? NfseConfig::nextNumber($cfg, $serie);
            $b = DpsBuilder::build($p, $cfg, $emit, $numero, $serie);
            $errors = NfseXml::validate($b['xml'], 'DPS_v1.01.xsd', $b['leiaute']);
            Logger::audit('nfse.dry_run', $errors === [] ? 'valida' : 'invalida', ['id_dps' => $b['id'], 'origem' => $origem]);
            return [$errors === [] ? 200 : 422, [
                'dry_run' => true,
                'valida' => $errors === [],
                'erros_schema' => $errors,
                'avisos' => $p['avisos'],
                'id_dps' => $b['id'],
                'numero_dps' => $numero,
                'serie' => $serie,
                'ambiente' => (int) $cfg['ambiente'],
                'versao_leiaute' => DpsBuilder::VERSAO,
                'leiaute_xsd' => $b['leiaute'],
                'valor_servico' => $p['valores']['servico'],
                'regra_iss' => $p['regra_iss'] ?? null,
                'estimativa' => $p['estimativa'],
                'xml' => $b['xml'],
                'observacao' => 'DPS não assinada e não enviada; validada no XSD oficial v1.01 do ambiente (pacote ' . $b['leiaute'] . '). A numeração não foi consumida. '
                    . 'As regras de negócio que dependem de cadastros do governo (CNC, CNPJ, Simples, parâmetros do município) só são verificadas pela Sefin Nacional.',
            ]];
        }

        self::preValidar($cfg);
        // pré-montagem: erro de leiaute não consome numeração
        $pre = DpsBuilder::build($p, $cfg, $emit, $p['numero'] ?? NfseConfig::nextNumber($cfg, $serie), $serie);
        $pre = NfseXml::validate($pre['xml'], 'DPS_v1.01.xsd', $pre['leiaute']);
        if ($pre !== []) {
            throw new ApiException(422, 'validacao_schema', 'DPS inválida no schema oficial', array_map(fn($m) => ['campo' => 'xml', 'mensagem' => $m], $pre));
        }
        $numero = $p['numero'] ?? NfseConfig::reserveNumber($serie);
        $b = DpsBuilder::build($p, $cfg, $emit, $numero, $serie);
        $existing=NfseRepository::find($b['id']);
        if ($existing !== null && !($origem==='correcao' && $existing['status']==='rejeitada')) {
            throw new ApiException(409, 'nfse_existente', 'Já existe DPS com esta série e número', [['campo' => 'id_dps', 'mensagem' => $b['id']]]);
        }
        $amb = (int) $cfg['ambiente'];
        $rec = [
            'id' => $b['id'],
            'chave' => null,
            'numero_dps' => $numero,
            'serie' => $serie,
            'numero_nfse' => null,
            'ambiente' => $amb,
            'competencia' => $p['competencia'],
            'valor_servico' => $p['valores']['servico'],
            'valor_liquido' => $p['estimativa']['valor_liquido'],
            'iss_retido' => $p['valores']['retencao_iss'] !== 1,
            'codigo_tributacao_nacional' => $p['servico']['codigo_tributacao_nacional'],
            'descricao' => mb_substr($p['servico']['descricao'], 0, 200),
            'tomador' => $p['tomador'] === null ? null : array_intersect_key($p['tomador'], array_flip(['cpf', 'cnpj', 'nome', 'email'])),
            'pedido_id' => $p['pedido_id'] ?? null,
            'regra_iss' => $p['regra_iss'] ?? null,
            'origem' => $origem,
            'status' => 'processando',
            'criado_em' => date('c'),
            'historico' => $origem==='correcao'?($existing['historico']??[]):[],
        ];
        try {
            $signed = NfseXml::sign($b['xml'], 'infDPS');
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Logger::exception($e, 'nfse.assinatura');
            $rec['status'] = 'erro';
            $rec['mensagem'] = 'Falha ao assinar a DPS com o certificado A1.';
            self::push($rec, 'erro', $rec['mensagem']);
            NfseRepository::save($rec);
            NfseRepository::saveFile($rec['id'], 'dps', $b['xml']);
            return [422, self::publicRec($rec)];
        }
        if($origem==='correcao'&&$existing!==null){
            $oldXml=NfseRepository::file($rec['id'],'dps');
            if($oldXml!==null){\App\Storage\JsonStore::writeRaw(\App\Tenancy\Tenant::path('nfse/tentativas/'.$rec['id'].'-'.bin2hex(random_bytes(6)).'-dps.xml'),$oldXml);}
            self::push($rec,'correcao','Nova DPS corrigida com a mesma referência, após consulta negativa de autorização');
        }
        NfseRepository::saveFile($rec['id'], 'dps', $signed);
        NfseRepository::save($rec);

        try {
            $r = SefinClient::emitir($amb, $signed);
            self::aplicarRetornoEmissao($rec, $r, Rejeicoes::contexto() + ['ctribmun' => $p['servico']['codigo_tributacao_municipal'] ?? '']);
            if ($rec['status'] === 'rejeitada' && in_array('E0014', array_column($rec['erros'] ?? [], 'codigo'), true)) {
                // DPS já convertida em NFS-e (reenvio após timeout): recupera a nota em vez de ficar rejeitada
                self::sincronizar($rec);
            }
        } catch (ApiException $e) {
            if (in_array($e->status(), [429, 503], true)) {
                $rec['status'] = 'erro';
                $rec['mensagem'] = $e->getMessage();
                self::push($rec, 'erro', $rec['mensagem']);
                NfseRepository::save($rec);
            }
            throw $e;
        } catch (\Throwable $e) {
            Logger::exception($e, 'nfse.envio');
            $rec['status'] = 'erro';
            $rec['mensagem'] = 'Falha de comunicação com a Sefin Nacional. Use "sincronizar" (POST /api/v1/nfse/{id}/sincronizar) antes de reenviar: a NFS-e pode ter sido gerada.';
            self::push($rec, 'erro', $rec['mensagem']);
        }
        NfseRepository::save($rec);
        Logger::audit('nfse.emissao', $rec['status'], ['id_dps' => $rec['id'], 'chave' => $rec['chave'], 'origem' => $origem, 'ambiente' => $amb]);
        $http = match ($rec['status']) {
            'autorizada' => 201,
            'erro' => 502,
            'processando' => 202,
            default => 422,
        };
        return [$http, self::publicRec($rec) + ['avisos' => $p['avisos']]];
    }

    /**
     * Checagens antes de registrar a Idempotency-Key ou reservar número: config, trava de transmissão/produção e
     * certificado. Erros aqui não "queimam" a chave de idempotência.
     * @param array<string,mixed>|null $cfg
     */
    public static function preValidar(?array $cfg = null, ?array $payload = null): void
    {
        $emit = ConfigService::require();
        $cfg ??= NfseConfig::require();
        if ($payload !== null) {
            DpsBuilder::validatePayload($payload, $cfg, $emit);
        }
        SefinClient::validarAmbiente((int) $cfg['ambiente']);
        if (!CertificateStore::exists()) {
            throw new ApiException(412, 'certificado_ausente', 'Certificado A1 não cadastrado (POST /api/v1/certificado)');
        }
        $meta = CertificateStore::meta();
        if ($meta !== null && $meta['cnpj'] !== '' && $meta['cnpj'] !== $emit['cnpj']) {
            throw new ApiException(412, 'certificado_cnpj', 'O CNPJ do certificado A1 difere do CNPJ do prestador: a Sefin recusaria a assinatura.');
        }
    }

    /**
     * @param array<string,mixed> $rec
     * @param array{status:int, json:array<mixed>|null, body:string, content_type:string} $r
     */
    private static function aplicarRetornoEmissao(array &$rec, array $r, ?array $ctx = null): void
    {
        $j = $r['json'];
        if ($r['status'] >= 200 && $r['status'] < 300 && is_string($j['nfseXmlGZipB64'] ?? null)) {
            self::aplicarNfse($rec, NfseXml::unGzB64($j['nfseXmlGZipB64']), isset($j['chaveAcesso']) ? (string) $j['chaveAcesso'] : null);
            if (!empty($j['alertas']) && is_array($j['alertas'])) {
                $rec['alertas'] = self::mensagens($j['alertas']);
            }
            return;
        }
        $erros = is_array($j) ? self::mensagens($j['erros'] ?? $j['erro'] ?? $j['Erros'] ?? []) : [];
        if ($r['status'] >= 400 && $r['status'] < 500 && $erros !== []) {
            $rec['status'] = 'rejeitada';
            $rec['erros'] = $erros;
            $rec['mensagem'] = implode(' | ', array_map(fn($x) => trim($x['codigo'] . ' ' . $x['descricao']), array_slice($erros, 0, 5)));
            // v2.9.3: rejeições que pedem ação fora da nota (convênio do município, código municipal) ganham orientação
            $orient = Rejeicoes::orientacoes($erros, $ctx);
            if ($orient !== []) {
                $rec['orientacao'] = $orient;
                $rec['mensagem'] = $orient[0]['orientacao'] . ' (' . $rec['mensagem'] . ')';
            }
            if (Rejeicoes::municipioNaoHabilitado($erros)) {
                // a própria Sefin confirmou: guarda para avisar antes da próxima emissão
                NfseConfig::marcarNaoHabilitado((int) $rec['ambiente']);
                Logger::audit('nfse.municipio_nao_habilitado', 'rejeitada', ['id_dps' => $rec['id'], 'codigos' => array_values(array_intersect(array_column($erros, 'codigo'), Rejeicoes::MUNICIPIO_NAO_HABILITADO))]);
            }
            self::push($rec, 'rejeitada', $rec['mensagem']);
            return;
        }
        Logger::error('nfse.resposta_inesperada', ['http' => $r['status'], 'content_type' => mb_substr($r['content_type'], 0, 60)]);
        $rec['status'] = 'erro';
        $rec['mensagem'] = 'Resposta inesperada da Sefin Nacional (HTTP ' . $r['status'] . '). Use "sincronizar" antes de reenviar: a NFS-e pode ter sido gerada.';
        self::push($rec, 'erro', $rec['mensagem']);
    }

    /** @param array<string,mixed> $rec */
    private static function aplicarNfse(array &$rec, string $xml, ?string $chaveResp): void
    {
        $res = NfseXml::resumoNfse($xml);
        if ($res['chave'] === null || ($chaveResp !== null && $chaveResp !== $res['chave'])) {
            throw new \UnexpectedValueException('chave da NFS-e inconsistente');
        }
        if ($res['id_dps'] !== null && $res['id_dps'] !== $rec['id']) {
            throw new \UnexpectedValueException('NFS-e devolvida não corresponde à DPS enviada');
        }
        NfseRepository::saveFile($rec['id'], 'nfse', $xml);
        $rec['status'] = $rec['status'] === 'cancelada' ? 'cancelada' : 'autorizada';
        $rec['chave'] = $res['chave'];
        $rec['numero_nfse'] = $res['numero_nfse'];
        $rec['autorizada_em'] = $res['dh_processamento'];
        $rec['valores_nfse'] = array_filter([
            'base_calculo' => $res['base_calculo'], 'aliquota_aplicada' => $res['aliquota_aplicada'],
            'valor_iss' => $res['valor_iss'], 'valor_liquido' => $res['valor_liquido'],
        ], fn($v) => $v !== null);
        if ($res['valor_liquido'] !== null) {
            $rec['valor_liquido'] = (float) $res['valor_liquido'];
        }
        unset($rec['erros'], $rec['mensagem'], $rec['orientacao']);
        NfseConfig::limparNaoHabilitado((int) $rec['ambiente']);
        self::push($rec, $rec['status'], 'NFS-e ' . $res['numero_nfse'] . ' gerada (chave ' . $res['chave'] . ')');
    }

    /**
     * Consulta a situação na Sefin: pela chave, ou pela DPS quando a chave ainda não é conhecida
     * (timeout/erro na emissão). Atualiza o registro local.
     * @param array<string,mixed> $rec
     */
    private static function sincronizar(array &$rec): void
    {
        $amb = (int) $rec['ambiente'];
        $chave = $rec['chave'] ?? null;
        if ($chave === null) {
            $r = SefinClient::consultarDps($amb, $rec['id']);
            $c = $r['json']['chaveAcesso'] ?? null;
            if ($r['status'] === 404 || !is_string($c) || !preg_match('/^\d{50}$/', $c)) {
                if ($rec['status'] === 'erro') {
                    $rec['mensagem'] = 'Nenhuma NFS-e foi gerada para esta DPS (consulta em ' . date('d/m/Y H:i') . '). Pode emitir de novo.';
                    self::push($rec, 'erro', $rec['mensagem']);
                }
                return;
            }
            $chave = $c;
        }
        $r = SefinClient::consultar($amb, $chave);
        if ($r['status'] === 200 && is_string($r['json']['nfseXmlGZipB64'] ?? null)) {
            self::aplicarNfse($rec, NfseXml::unGzB64($r['json']['nfseXmlGZipB64']), $chave);
        }
    }

    /** POST /nfse/{ref}/sincronizar. @return array<string,mixed> */
    public static function sincronizarRef(string $ref): array
    {
        $rec = NfseRepository::get($ref);
        SefinClient::validarAmbiente((int) $rec['ambiente']);
        try {
            self::sincronizar($rec);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Logger::exception($e, 'nfse.sincronizar');
            throw new ApiException(502, 'sefin_indisponivel', 'Falha de comunicação com a Sefin Nacional. Tente novamente mais tarde.');
        }
        $antes = NfseRepository::find($rec['id'])['status'] ?? null;
        NfseRepository::save($rec);
        Logger::audit('nfse.sincronizar', $rec['status'], ['id_dps' => $rec['id'], 'chave' => $rec['chave']]);
        return self::publicRec($rec);
    }

    /** @return array<string,mixed> */
    public static function cancelar(string $ref, int $codigo, string $motivo): array
    {
        $rec = NfseRepository::get($ref);
        if ($rec['status'] !== 'autorizada' || empty($rec['chave'])) {
            throw new ApiException(409, 'estado_invalido', 'Só é possível cancelar NFS-e autorizada (status atual: ' . $rec['status'] . ')');
        }
        if (!isset(self::MOTIVOS_CANCELAMENTO[$codigo])) {
            throw ApiException::validation([['campo' => 'codigo_motivo', 'mensagem' => 'Use 1 (erro na emissão), 2 (serviço não prestado) ou 9 (outros)']]);
        }
        $emit = ConfigService::require();
        $amb = (int) $rec['ambiente'];
        SefinClient::validarAmbiente($amb);
        $xml = DpsBuilder::pedidoCancelamento((string) $rec['chave'], (string) $emit['cnpj'], $amb, $codigo, $motivo);
        $err = NfseXml::validate($xml, 'pedRegEvento_v1.01.xsd', NfseXml::leiaute($amb));
        if ($err !== []) {
            throw new ApiException(422, 'validacao_schema', 'Pedido de cancelamento inválido no schema oficial', array_map(fn($m) => ['campo' => 'xml', 'mensagem' => $m], $err));
        }
        try {
            $signed = NfseXml::sign($xml, 'infPedReg');
            $r = SefinClient::evento($amb, (string) $rec['chave'], $signed);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Logger::exception($e, 'nfse.cancelamento');
            throw new ApiException(502, 'sefin_indisponivel', 'Falha de comunicação com a Sefin Nacional. Consulte a nota antes de tentar de novo.');
        }
        $j = $r['json'];
        $ok = $r['status'] >= 200 && $r['status'] < 300 && is_string($j['eventoXmlGZipB64'] ?? null);
        $erros = [];
        if ($ok) {
            $evento = NfseXml::unGzB64($j['eventoXmlGZipB64']);
            NfseXml::load($evento); // rejeita XML malformado/DTD
            NfseRepository::saveFile($rec['id'], 'evento-101101', $evento);
            $rec['status'] = 'cancelada';
            $rec['cancelamento'] = ['codigo_motivo' => $codigo, 'motivo' => $motivo, 'em' => date('c')];
            self::push($rec, 'cancelada', 'Cancelamento registrado: ' . self::MOTIVOS_CANCELAMENTO[$codigo]);
        } else {
            $erros = is_array($j) ? self::mensagens($j['erros'] ?? $j['erro'] ?? []) : [];
            $msg = $erros !== [] ? implode(' | ', array_map(fn($x) => trim($x['codigo'] . ' ' . $x['descricao']), array_slice($erros, 0, 5))) : 'Resposta inesperada (HTTP ' . $r['status'] . ')';
            self::push($rec, 'cancelamento_rejeitado', $msg);
        }
        NfseRepository::save($rec);
        Logger::audit('nfse.cancelamento', $ok ? 'sucesso' : 'rejeitado', ['id_dps' => $rec['id'], 'chave' => $rec['chave'], 'codigo_motivo' => $codigo]);
        return ['sucesso' => $ok, 'erros' => $erros, 'nfse' => self::publicRec($rec)];
    }

    /** auto/local geram o modelo nacional atual; adn mantém acesso explícito ao serviço legado. */
    public const DANFSE_ORIGENS = ['auto', 'adn', 'local'];

    /** DANFSe (PDF): mesmo que danfsePdf(), só o conteúdo. */
    public static function danfse(string $ref, string $origem = 'auto'): string
    {
        return self::danfsePdf($ref, $origem)['pdf'];
    }

    /**
     * DANFSe (PDF) da NFS-e autorizada ou cancelada do tenant atual.
     *
     * auto/local: geração local no modelo DANFSe v2.0, NT 008 v1.02, sem rede e sem cache de PDF legado.
     * adn: acesso explícito legado, com cache para autorizadas e reconsulta para canceladas.
     *
     * @return array{pdf:string, origem:string} origem "adn" ou "local"
     */
    public static function danfsePdf(string $ref, string $origem = 'auto'): array
    {
        if (!in_array($origem, self::DANFSE_ORIGENS, true)) {
            throw ApiException::validation([['campo' => 'origem', 'mensagem' => 'Use auto, adn ou local']]);
        }
        $rec = NfseRepository::get($ref); // 404 para nota de outra empresa (busca só no diretório do tenant)
        if (!in_array($rec['status'], ['autorizada', 'cancelada'], true) || empty($rec['chave'])) {
            throw new ApiException(409, 'estado_invalido', 'DANFSe só existe para NFS-e autorizada ou cancelada');
        }
        $amb = (int) $rec['ambiente'];
        // NT 008 v1.02: geração própria no padrão nacional; ADN somente por solicitação explícita.
        if ($origem === 'adn') {
            $cache = NfseRepository::file($rec['id'], 'danfse');
            if ($cache !== null && $rec['status'] === 'autorizada') {
                return ['pdf' => $cache, 'origem' => 'adn'];
            }
            if ($origem === 'adn' || !self::adnEmPausa($amb)) {
                $falha = null;
                try {
                    $r = SefinClient::danfse($amb, (string) $rec['chave']);
                    if ($r['status'] === 200 && str_starts_with($r['body'], '%PDF-')) {
                        NfseRepository::saveFile($rec['id'], 'danfse', $r['body']);
                        self::marcarAdn($amb, true);
                        return ['pdf' => $r['body'], 'origem' => 'adn'];
                    }
                    Logger::error('nfse.danfse_resposta', ['http' => $r['status']]);
                    $falha = 'O Ambiente de Dados Nacional não devolveu o DANFSe (HTTP ' . $r['status'] . '). A NFS-e pode levar alguns minutos para chegar ao ADN.';
                } catch (ApiException $e) {
                    // Erros do transporte legado não modificam a nota.
                    Logger::error('nfse.danfse_adn', ['codigo' => $e->errorCode, 'http' => $e->status()]);
                    if ($origem === 'adn') {
                        throw $e;
                    }
                } catch (\Throwable $e) {
                    Logger::exception($e, 'nfse.danfse');
                    $falha = 'Não foi possível obter o DANFSe no Ambiente de Dados Nacional. Tente mais tarde.';
                }
                if ($falha !== null) {
                    self::marcarAdn($amb, false);
                }
                if ($origem === 'adn') {
                    throw new ApiException(502, 'danfse_indisponivel', $falha ?? 'Não foi possível obter o DANFSe no Ambiente de Dados Nacional.');
                }
            }
        }
        $xml = NfseRepository::file($rec['id'], 'nfse');
        if ($xml === null) {
            throw new ApiException(502, 'danfse_indisponivel', 'O XML autorizado desta NFS-e não está guardado para gerar o DANFSe. Consulte a nota na Sefin para recuperar o XML.');
        }
        try {
            $pdf = DanfseRenderer::render($xml, self::danfseOpcoes($rec));
        } catch (\Throwable $e) {
            Logger::exception($e, 'nfse.danfse_local');
            throw new ApiException(502, 'danfse_indisponivel', 'Não foi possível gerar o DANFSe nacional a partir do XML. Verifique as fontes e recursos de impressão da instalação.');
        }
        Logger::audit('nfse.danfse_local', 'sucesso', ['id_dps' => $rec['id'], 'chave' => $rec['chave']]);
        return ['pdf' => $pdf, 'origem' => 'local'];
    }

    /**
     * Situação atual usada para a marca de cancelamento; o layout usa o logotipo nacional.
     * @param array<string,mixed> $rec
     * @return array{logo: ?string, cancelada: bool, rodape: ?string}
     */
    private static function danfseOpcoes(array $rec): array
    {
        return [
            'logo' => null,
            'cancelada' => $rec['status'] === 'cancelada',
            'rodape' => 'API Fiscal',
        ];
    }

    /** O ADN falhou nesta empresa/ambiente há menos de NFSE_DANFSE_PAUSA_ADN segundos (padrão 300)? */
    private static function adnEmPausa(int $amb): bool
    {
        $pausa = max(0, \App\Support\Env::int('NFSE_DANFSE_PAUSA_ADN', 300));
        $st = \App\Storage\JsonStore::read(\App\Tenancy\Tenant::path('nfse/adn-danfse.json')) ?? [];
        $t = (int) ($st['falha_' . $amb] ?? 0);
        return $pausa > 0 && $t > 0 && (time() - $t) < $pausa;
    }

    private static function marcarAdn(int $amb, bool $ok): void
    {
        try {
            \App\Storage\JsonStore::update(\App\Tenancy\Tenant::path('nfse/adn-danfse.json'), function (?array $d) use ($amb, $ok) {
                $d ??= [];
                $novo = $ok ? 0 : time();
                if ((int) ($d['falha_' . $amb] ?? 0) === $novo) {
                    return [null, null];
                }
                $d['falha_' . $amb] = $novo;
                return [$d, null];
            });
        } catch (\Throwable $e) {
            Logger::exception($e, 'nfse.danfse_pausa'); // nunca impede a entrega do PDF
        }
    }

    /** XML armazenado: "nfse" (padrão, NFS-e autorizada), "dps" (DPS enviada) ou "evento" (cancelamento). */
    public static function xml(string $ref, string $tipo = 'nfse'): string
    {
        $rec = NfseRepository::get($ref);
        $map = ['nfse' => 'nfse', 'dps' => 'dps', 'evento' => 'evento-101101'];
        if (!isset($map[$tipo])) {
            throw ApiException::validation([['campo' => 'tipo', 'mensagem' => 'Use nfse, dps ou evento']]);
        }
        return NfseRepository::file($rec['id'], $map[$tipo]) ?? throw new ApiException(404, 'nao_encontrado', 'XML não disponível para esta nota (' . $tipo . ')');
    }

    /**
     * Parâmetros do convênio do município emissor (Sefin Nacional), com cache de 24 h na configuração.
     * @return array<string,mixed>
     */
    public static function parametros(bool $atualizar = false): array
    {
        $cfg = NfseConfig::require();
        $cache = $cfg['parametros_municipio'] ?? null;
        // cache sem "ambiente" veio da 2.9.1 ou anterior (endpoint errado): descartado
        if (!$atualizar && is_array($cache) && ($cache['ambiente'] ?? null) === (int) $cfg['ambiente'] && ($cache['consultado_em_ts'] ?? 0) > time() - 86400) {
            return $cache + ['cache' => true];
        }
        $cMun = (string) $cfg['codigo_municipio'];
        try {
            $r = SefinClient::convenio((int) $cfg['ambiente'], $cMun);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Logger::exception($e, 'nfse.parametros');
            throw new ApiException(502, 'sefin_indisponivel', 'Falha ao consultar os parâmetros do município na Sefin Nacional.');
        }
        $out = ['codigo_municipio' => $cMun, 'ambiente' => (int) $cfg['ambiente'], 'http' => $r['status'], 'consultado_em' => date('c'), 'consultado_em_ts' => time()];
        $j = $r['json'];
        $msgGov = is_array($j) && is_string($j['mensagem'] ?? null) ? mb_substr($j['mensagem'], 0, 300) : null;
        if ($r['status'] === 200 && is_array($j) && is_array($j['parametrosConvenio'] ?? null)) {
            $out['conveniado'] = self::conveniadoDe($j['parametrosConvenio']);
            $emissor = $j['parametrosConvenio']['aderenteEmissorNacional'] ?? null;
            $out['emissor_nacional'] = $emissor === null ? null : in_array($emissor, [1, '1', true], true);
            $out['dados'] = self::resumirJson($j);
            if ($out['conveniado'] && $out['emissor_nacional'] === false) {
                $out['mensagem'] = 'Município conveniado, mas sem adesão ao Emissor Nacional (API): confirme com a prefeitura.';
            }
        } elseif ($r['status'] === 404 && is_array($j) && array_key_exists('parametrosConvenio', $j)) {
            // resposta de negócio do ADN: o município existe na API mas o convênio não está ativo NESTE ambiente
            $out['conveniado'] = false;
            $out['mensagem'] = ($msgGov ?? 'Convênio do município não está ativo no Sistema Nacional NFS-e')
                . ((int) $cfg['ambiente'] === 2 ? ' (produção restrita/homologação; a produção pode ser diferente).' : '.');
        } else {
            // 404 sem o JSON da API (rota inexistente, proxy, HTML), 5xx etc.: NÃO dá para concluir nada
            $out['conveniado'] = null;
            $out['mensagem'] = 'Não foi possível confirmar o convênio do município (HTTP ' . $r['status'] . ($msgGov !== null ? ': ' . $msgGov : '') . '). Tente "Verificar" novamente mais tarde.';
        }
        NfseConfig::salvarParametros($out);
        Logger::audit('nfse.parametros', (string) $r['status'], ['municipio' => $cMun]);
        return $out;
    }

    /**
     * v2.9.3: na prévia (dry-run), se o envio está habilitado e o município emissor ainda não foi consultado neste
     * ambiente, consulta o convênio (GET /parametrizacao/{cMun}/convenio) para avisar ANTES da emissão. Só informa:
     * falha na consulta é ignorada e nada é bloqueado.
     * @param array<string,mixed> $cfg
     * @return array<string,mixed>
     */
/**
     * Convênio ativo = parametrosConvenio.aderenteAmbienteNacional = 1 (schema ParametrosConfiguracaoConvenio do
     * Swagger "ADN Parâmetros Municipais"). Sem o campo, a API respondeu 200 com parâmetros: considera ativo.
     * @param array<mixed> $p
     */
    private static function conveniadoDe(array $p): bool
    {
        if (!array_key_exists('aderenteAmbienteNacional', $p)) {
            return true;
        }
        return in_array($p['aderenteAmbienteNacional'], [1, '1', true], true);
    }

    /** @param array<mixed> $j @return array<mixed> */
    private static function resumirJson(array $j, int $depth = 0): array
    {
        $out = [];
        foreach (array_slice($j, 0, 40, true) as $k => $v) {
            $out[$k] = is_array($v) ? ($depth < 3 ? self::resumirJson($v, $depth + 1) : '[...]') : (is_string($v) ? mb_substr($v, 0, 200) : $v);
        }
        return $out;
    }

    /**
     * Normaliza as mensagens de erro/alerta da Sefin ({Codigo, Descricao, Complemento} ou variações).
     * @return list<array{codigo:string, descricao:string, complemento?:string}>
     */
    private static function mensagens(mixed $m): array
    {
        if (!is_array($m)) {
            return is_string($m) && $m !== '' ? [['codigo' => '', 'descricao' => mb_substr($m, 0, 500)]] : [];
        }
        if (!array_is_list($m)) {
            $m = [$m];
        }
        $out = [];
        foreach (array_slice($m, 0, 50) as $x) {
            if (!is_array($x)) {
                $out[] = ['codigo' => '', 'descricao' => mb_substr((string) (is_scalar($x) ? $x : ''), 0, 500)];
                continue;
            }
            $lx = array_change_key_case($x, CASE_LOWER);
            $item = [
                'codigo' => mb_substr((string) ($lx['codigo'] ?? ''), 0, 20),
                'descricao' => mb_substr((string) ($lx['descricao'] ?? $lx['mensagem'] ?? ''), 0, 500),
            ];
            if (!empty($lx['complemento']) && is_scalar($lx['complemento'])) {
                $item['complemento'] = mb_substr((string) $lx['complemento'], 0, 500);
            }
            $out[] = $item;
        }
        return $out;
    }

    /** @param array<string,mixed> $rec */
    private static function push(array &$rec, string $status, ?string $msg): void
    {
        $rec['historico'][] = ['em' => date('c'), 'status' => $status, 'mensagem' => $msg];
    }

    /** @param array<string,mixed> $rec @return array<string,mixed> */
    public static function publicRec(array $rec): array
    {
        $ref = $rec['chave'] ?? $rec['id'];
        $rec['links'] = [
            'self' => '/api/v1/nfse/' . $ref,
            'xml' => '/api/v1/nfse/' . $ref . '/xml',
            'danfse' => '/api/v1/nfse/' . $ref . '/danfse',
        ];
        return $rec;
    }

    /** @param array<string,mixed> $rec @return array<string,mixed> */
    private static function eventData(array $rec): array
    {
        $d = array_intersect_key($rec, array_flip(['id', 'chave', 'numero_dps', 'serie', 'numero_nfse', 'ambiente', 'status', 'valor_servico', 'valor_liquido', 'pedido_id', 'mensagem', 'erros']));
        $d['id_dps'] = $d['id'];
        unset($d['id']);
        $d['tomador'] = $rec['tomador']['cnpj'] ?? $rec['tomador']['cpf'] ?? null;
        return $d;
    }
}
