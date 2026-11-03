<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\ApiException;
use App\Http\Idempotency;
use App\Http\Request;
use App\Http\Response;
use App\Nfse\Listas;
use App\Nfse\NfseConfig;
use App\Nfse\NfseRepository;
use App\Nfse\NfseService;
use App\Support\Logger;
use App\Support\Validate;

/**
 * /api/v1/nfse — NFS-e Padrão Nacional (Sefin Nacional / ADN). Sempre no tenant autenticado
 * (chave de API ou sessão); configuração só owner; cancelamento owner ou fiscal.
 */
final class NfseController
{
    public static function getConfig(): Response
    {
        return Response::json(NfseConfig::publicView(NfseConfig::get()));
    }

    public static function putConfig(Request $req): Response
    {
        $c = NfseConfig::put($req->json());
        Logger::audit('nfse.config', 'sucesso', ['municipio' => $c['codigo_municipio'], 'ambiente' => $c['ambiente'], 'opcao_sn' => $c['opcao_simples_nacional']]);
        return Response::json(NfseConfig::publicView($c));
    }

    public static function parametros(Request $req): Response
    {
        return Response::json(NfseService::parametros($req->queryFlag('atualizar')));
    }

    public static function servicosNacionais(Request $req): Response
    {
        $q = $req->query['busca'] ?? '';
        if (!is_string($q) || mb_strlen($q) > 60) {
            throw ApiException::validation([['campo' => 'busca', 'mensagem' => 'Até 60 caracteres']]);
        }
        return Response::json(['servicos' => Listas::buscarServicos($q, 50), 'fonte' => 'Anexo B v1.01 (gov.br/nfse)']);
    }

    public static function listar(Request $req): Response
    {
        $status = $req->query['status'] ?? null;
        if ($status !== null && !in_array($status, NfseService::STATUS, true)) {
            throw ApiException::validation([['campo' => 'status', 'mensagem' => 'Status inválido: ' . implode(', ', NfseService::STATUS)]]);
        }
        $limit = (int) ($req->query['limite'] ?? 50);
        return Response::json(['nfses' => array_map([NfseService::class, 'publicRec'], NfseRepository::list(max(1, min(200, $limit)), $status))]);
    }

    /**
     * POST /api/v1/nfse — dry_run (Idempotency-Key opcional) ou emissão real (Idempotency-Key OBRIGATÓRIA).
     * Erros de payload/configuração/certificado/trava não consomem a chave de idempotência.
     */
    public static function emitir(Request $req): Response
    {
        $dry = $req->queryFlag('dry_run');
        $exec = function () use ($req, $dry) {
            [$status, $body] = NfseService::emitir($req->json(), $dry, 'api');
            return Response::json($body, $status);
        };
        $key = $req->header('idempotency-key');
        if ($key === null && !$dry) {
            Logger::audit('nfse.emissao', 'sem_idempotency_key');
            throw new ApiException(400, 'idempotency_key_obrigatoria',
                'Emissão real exige o cabeçalho Idempotency-Key (8 a 128 caracteres [A-Za-z0-9_.:-]), por exemplo "Idempotency-Key: pedido-1001". '
                . 'Reenvie com a MESMA chave em caso de timeout: a NFS-e não é emitida duas vezes. Para apenas validar, use ?dry_run=1.',
                [['campo' => 'Idempotency-Key', 'mensagem' => 'Cabeçalho obrigatório na emissão real']]);
        }
        if ($key !== null) {
            if (!Idempotency::validKey($key)) {
                throw new ApiException(400, 'idempotency_key', 'Idempotency-Key inválida (8 a 128 caracteres [A-Za-z0-9_.:-])');
            }
            if (!$dry) {
                NfseService::preValidar(null, $req->json());
            }
            return Idempotency::run('nfse' . ($dry ? ':dry' : ''), $key, $req->body, $exec);
        }
        return $exec();
    }

    public static function obter(Request $req): Response
    {
        $rec = NfseRepository::get($req->params['ref']);
        return Response::json(NfseService::publicRec($rec));
    }

    public static function xml(Request $req): Response
    {
        $ref = $req->params['ref'];
        $tipo = is_string($req->query['tipo'] ?? null) ? $req->query['tipo'] : 'nfse';
        $xml = NfseService::xml($ref, $tipo);
        $rec = NfseRepository::get($ref);
        Logger::audit('nfse.download_xml', 'sucesso', ['id_dps' => $rec['id'], 'tipo' => $tipo]);
        $nome = ($rec['chave'] ?? $rec['id']) . ($tipo === 'nfse' ? '' : "-$tipo") . '.xml';
        return new Response(200, $xml, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $nome . '"',
        ]);
    }

    public static function danfse(Request $req): Response
    {
        // Padrão nacional gerado na API; Base64 para integrações ou PDF binário para download.
        $origem = is_string($req->query['origem'] ?? null) ? $req->query['origem'] : 'auto';
        $formato = $req->query['formato'] ?? 'pdf';
        if (!is_string($formato) || !in_array($formato, ['pdf', 'base64'], true)) {
            throw ApiException::validation([['campo'=>'formato', 'mensagem'=>'Use pdf ou base64']]);
        }
        $r = NfseService::danfsePdf($req->params['ref'], $origem);
        $rec = NfseRepository::get($req->params['ref']);
        Logger::audit('nfse.danfse', 'sucesso', ['id_dps' => $rec['id'], 'chave' => $rec['chave'], 'origem' => $r['origem']]);
        if ($formato === 'base64') {
            return Response::json([
                'pdf_base64' => base64_encode($r['pdf']),
                'tipo_conteudo' => 'application/pdf',
                'nome_arquivo' => 'DANFSe-' . $rec['chave'] . '.pdf',
                'origem' => $r['origem'],
                'modelo' => 'DANFSe v2.0',
            ], 200, ['X-Danfse-Origem'=>$r['origem'], 'Cache-Control'=>'private, no-store']);
        }
        return new Response(200, $r['pdf'], [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="DANFSe-' . $rec['chave'] . '.pdf"',
            'X-Danfse-Origem' => $r['origem'],
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public static function sincronizar(Request $req): Response
    {
        return Response::json(NfseService::sincronizarRef($req->params['ref']));
    }

    public static function reenviarHorario(Request $req): Response
    {
        $key=$req->header('idempotency-key');$j=$req->json();$recuo=$j['recuo_segundos']??180;
        if(!Idempotency::validKey($key)){throw new ApiException(400,'idempotency_key_obrigatoria','Informe Idempotency-Key para o reenvio');}
        if(!is_int($recuo)||$recuo<30||$recuo>300){throw ApiException::validation([['campo'=>'recuo_segundos','mensagem'=>'Inteiro entre 30 e 300']]);}
        return Idempotency::run('nfse:horario:'.$req->params['ref'],$key,$req->body,function()use($req,$recuo){
            $rec=NfseService::reenviarHorario($req->params['ref'],$recuo);
            return Response::json($rec,$rec['status']==='autorizada'?200:422);
        });
    }

    public static function corrigir(Request $req): Response
    {
        $key=$req->header('idempotency-key');
        if(!Idempotency::validKey($key)){throw new ApiException(400,'idempotency_key_obrigatoria','Informe Idempotency-Key para esta tentativa corrigida');}
        return Idempotency::run('nfse:correcao:'.$req->params['ref'],$key,$req->body,function()use($req){
            [$code,$body]=NfseService::corrigir($req->params['ref'],$req->json());return Response::json($body,$code);
        });
    }

    public static function cancelar(Request $req): Response
    {
        $j = $req->json();
        $cod = $j['codigo_motivo'] ?? null;
        $motivo = Validate::text($j['motivo'] ?? null, 15, 255);
        $e = [];
        if (!in_array($cod, [1, 2, 9], true)) {
            $e[] = ['campo' => 'codigo_motivo', 'mensagem' => 'Use 1 (erro na emissão), 2 (serviço não prestado) ou 9 (outros)'];
        }
        if ($motivo === null) {
            $e[] = ['campo' => 'motivo', 'mensagem' => 'Entre 15 e 255 caracteres (sem < >)'];
        }
        if ($e !== []) {
            throw ApiException::validation($e);
        }
        $r = NfseService::cancelar($req->params['ref'], $cod, $motivo);
        return Response::json($r, $r['sucesso'] ? 200 : 422);
    }
}
