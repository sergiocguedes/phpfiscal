<?php

declare(strict_types=1);

namespace App\Nfe;

use App\Http\ApiException;
use App\Support\Logger;
use NFePHP\NFe\Common\Standardize;
use NFePHP\NFe\Complements;

/**
 * Fluxo de emissão, cancelamento, CC-e, inutilização e consultas — NF-e (55) e NFC-e (65).
 * O modelo vem do payload (emissão), do registro/chave (eventos e consultas) ou do parâmetro
 * (inutilização/status); SefazClient::tools($modelo) seleciona os web services do modelo.
 */
final class EmissionService
{
    public static function emitir(array $payload, bool $dryRun, string $origem): array
    {
        return self::emitirNormalizado(self::normalizar($payload), $dryRun, $origem);
    }
    public static function normalizar(array $payload, bool $registrar = true): array
    {
        if (isset($payload['cliente_id']) || array_filter(is_array($payload['itens'] ?? null) ? $payload['itens'] : [], fn($i) => is_array($i) && (isset($i['produto_codigo']) || isset($i['perfil_tributario_id'])))) {
            throw new ApiException(422, 'dados_fiscais_obrigatorios', 'Envie os dados completos da nota; esta edição não mantém catálogo ou perfis de cálculo.');
        }
        // itens com "regra": a API calcula os tributos e preenche itens[].impostos (docs/TRIBUTOS-CALCULO.md)
        [$payload, $calc] = \App\Tributos\EmissaoTributos::aplicar($payload, 55, 'itens', $registrar);
        $p = NfeBuilder::validatePayload($payload);
        if ($calc !== null) {
            $p['_calculo_tributos'] = $calc;
        }
        return $p;
    }
    private const AUTORIZADA = ['100', '150', '120']; // 120 = autorizada com alerta (NT 2026.002)
    private const DENEGADA = ['110', '205', '301', '302', '303'];

    /**
     * @param array<string,mixed> $payload
     * @return array{0:int, 1:array<string,mixed>} [http status, corpo]
     */

    

    /**
     * @param array<string,mixed> $p payload já normalizado
     * @return array{0:int, 1:array<string,mixed>}
     */
    private static function emitirNormalizado(array $p, bool $dryRun, string $origem): array
    {
        $cfg = ConfigService::require();
        $mod = (int) $p['modelo'];
        $serie = $p['serie'] ?? ConfigService::defaultSerie($cfg, $mod);
        if ($mod === Nfce::MODELO) {
            Nfce::assertCsc($cfg); // QR-Code 2.00 (produção) exige CSC + id
        }

        if ($dryRun) {
            $numero = $p['numero'] ?? ConfigService::nextNumber($cfg, $mod, $serie);
            $b = NfeBuilder::build($p, $cfg, $numero, $serie);
            $xml = $mod === Nfce::MODELO ? Nfce::withQrPreview($b['xml'], $cfg) : $b['xml'];
            $errors = XsdValidator::validateUnsignedNFe($xml);
            Logger::audit('nfe.dry_run', $errors === [] ? 'valida' : 'invalida', ['chave' => $b['chave'], 'modelo' => $mod, 'origem' => $origem]);
            $out = [
                'dry_run' => true,
                'valida' => $errors === [],
                'erros_schema' => $errors,
                'modelo' => $mod,
                'chave' => $b['chave'],
                'numero' => $numero,
                'serie' => $serie,
                'ambiente' => (int) $cfg['ambiente'],
                'valor_total' => $b['vNF'],
                'reforma_tributaria' => $b['reforma_tributaria'],
                'avisos' => $b['avisos'],
                'xml' => $xml,
                'observacao' => 'XML não assinado e não enviado. Validação de schema feita com assinatura placeholder; a numeração não foi consumida.',
            ];
            if (isset($p['_calculo_tributos'])) {
                $out['calculo_tributos'] = $p['_calculo_tributos'];
            }
            if ($mod === Nfce::MODELO) {
                $out += self::qrFromXml($xml);
                $out['observacao'] .= ' O QR-Code é o mesmo da emissão on-line (não depende da assinatura).';
            }
            return [$errors === [] ? 200 : 422, $out];
        }

        // Emissão real: exige certificado antes de consumir numeração
        $tools = SefazClient::tools($mod);
        // pré-montagem com número provisório: erros de leiaute (inclusive XSD oficial vigente) não consomem numeração
        $pre = NfeBuilder::build($p, $cfg, $p['numero'] ?? ConfigService::nextNumber($cfg, $mod, $serie), $serie);
        $xsd = XsdValidator::validateUnsignedNFe($pre['xml']);
        if ($xsd !== []) {
            throw ApiException::validation(array_map(fn($m) => ['campo' => 'xml', 'mensagem' => mb_substr($m, 0, 300)], array_slice($xsd, 0, 20)),
                'O XML não passou no schema oficial ' . XsdValidator::PACOTE . '. Valide com ?dry_run=1.');
        }
        if (isset($p['numero'])) {
            $numero = $p['numero'];
        } else {
            $numero = ConfigService::reserveNumber($mod, $serie);
        }
        $b = NfeBuilder::build($p, $cfg, $numero, $serie);
        $chave = $b['chave'];
        if (NfeRepository::find($chave) !== null) {
            throw new ApiException(409, 'nfe_existente', 'Já existe NF-e com esta chave', [['campo' => 'chave', 'mensagem' => $chave]]);
        }

        $rec = [
            'chave' => $chave,
            'numero' => $numero,
            'serie' => $serie,
            'modelo' => $mod,
            'ambiente' => (int) $cfg['ambiente'],
            'valor_total' => $b['vNF'],
            'destinatario' => $p['destinatario']['cnpj'] ?? $p['destinatario']['cpf'] ?? null,
            'valor_troco' => $p['troco'] > 0 ? $p['troco'] : null,
            'pedido_id' => $p['pedido_id'] ?? null,
            'origem' => $origem,
            'reforma_tributaria' => $b['reforma_tributaria']['grupo_informado'] ? $b['reforma_tributaria']['totais'] : null,
            'status' => 'assinando',
            'criado_em' => date('c'),
            'historico' => [],
        ];
        if (isset($p['_calculo_tributos'])) {
            $rec['calculo_tributos'] = \App\Tributos\EmissaoTributos::resumo($p['_calculo_tributos']);
        }

        NfeRepository::claimNumero($rec);
        try {
            $signed = $tools->signNFe($b['xml']); // valida XSD internamente
        } catch (\Throwable $e) {
            Logger::exception($e, 'nfe.assinatura');
            $rec['status'] = 'erro';
            $rec['mensagem'] = 'Falha ao assinar/validar o XML: ' . mb_substr($e->getMessage(), 0, 500);
            self::push($rec, 'erro', null, $rec['mensagem']);
            NfeRepository::save($rec);
            NfeRepository::saveXml($chave, $b['xml']);
            
            return [422, self::publicRec($rec)];
        }
        NfeRepository::saveXml($chave, $signed);
        if ($mod === Nfce::MODELO) {
            $rec += self::qrFromXml($signed);
        }
        $rec['status'] = 'processando';
        NfeRepository::save($rec);
        

        try {
            $idLote = substr(str_replace('.', '', (string) microtime(true)), 0, 15);
            $resp = $tools->sefazEnviaLote([$signed], $idLote, 1);
            NfeRepository::saveXml($chave, $resp, 'ret-envio');
            $std = (new Standardize($resp))->toStd();
            $cStat = (string) ($std->cStat ?? '');
            $finalResp = $resp;
            $prot = $std->protNFe->infProt ?? null;

            if ($cStat === '103' && !empty($std->infRec->nRec)) {
                // lote assíncrono: consulta o recibo algumas vezes
                $rec['recibo'] = (string) $std->infRec->nRec;
                for ($i = 0; $i < 5 && $prot === null; $i++) {
                    sleep(2 + $i);
                    $r2 = $tools->sefazConsultaRecibo($rec['recibo']);
                    $s2 = (new Standardize($r2))->toStd();
                    if ((string) ($s2->cStat ?? '') === '104') {
                        $finalResp = $r2;
                        $prot = $s2->protNFe->infProt ?? null;
                        NfeRepository::saveXml($chave, $r2, 'ret-recibo');
                    } elseif ((string) ($s2->cStat ?? '') !== '105') {
                        $cStat = (string) ($s2->cStat ?? '');
                        $std = $s2;
                        break;
                    }
                }
            }

            if ($prot !== null) {
                $pc = (string) $prot->cStat;
                $rec['cStat'] = $pc;
                $rec['xMotivo'] = (string) $prot->xMotivo;
                $rec['protocolo'] = isset($prot->nProt) ? (string) $prot->nProt : null;
                if (in_array($pc, self::AUTORIZADA, true) || in_array($pc, self::DENEGADA, true)) {
                    $proc = Complements::toAuthorize($signed, $finalResp);
                    NfeRepository::saveXml($chave, $proc);
                    $rec['status'] = in_array($pc, self::AUTORIZADA, true) ? 'autorizada' : 'denegada';
                } else {
                    $rec['status'] = 'rejeitada';
                }
            } elseif ($cStat === '103' || $cStat === '105') {
                $rec['status'] = 'processando';
                $rec['cStat'] = $cStat;
                $rec['xMotivo'] = 'Lote em processamento na SEFAZ. Consulte GET /api/v1/sefaz/consulta/' . $chave;
            } else {
                $rec['status'] = 'rejeitada';
                $rec['cStat'] = $cStat;
                $rec['xMotivo'] = (string) ($std->xMotivo ?? 'Sem resposta');
            }
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Logger::exception($e, 'nfe.envio');
            $rec['status'] = 'erro';
            $rec['mensagem'] = 'Falha de comunicação com a SEFAZ. Consulte a situação antes de reenviar.';
        }

        self::push($rec, $rec['status'], $rec['cStat'] ?? null, $rec['xMotivo'] ?? ($rec['mensagem'] ?? null));
        NfeRepository::save($rec);
        Logger::audit('nfe.emissao', $rec['status'], ['chave' => $chave, 'cStat' => $rec['cStat'] ?? null, 'origem' => $origem]);
        
        
        $http = match ($rec['status']) {
            'autorizada' => 201,
            'processando' => 202,
            'erro' => 502,
            default => 422,
        };
        return [$http, self::publicRec($rec)];
    }

    /** @return array<string,mixed> */
    public static function cancelar(string $chave, string $just): array
    {
        $rec = NfeRepository::get($chave);
        if ($rec['status'] !== 'autorizada' || empty($rec['protocolo'])) {
            throw new ApiException(409, 'estado_invalido', 'Só é possível cancelar NF-e autorizada (status atual: ' . $rec['status'] . ')');
        }
        $tools = SefazClient::tools((int) ($rec['modelo'] ?? Nfce::modeloDaChave($chave)));
        $resp = self::sefaz(fn() => $tools->sefazCancela($chave, $just, (string) $rec['protocolo']));
        NfeRepository::saveXml($chave, $resp, 'ret-cancelamento');
        $std = (new Standardize($resp))->toStd();
        $inf = $std->retEvento->infEvento ?? null;
        $cStat = (string) ($inf->cStat ?? ($std->cStat ?? ''));
        $motivo = (string) ($inf->xMotivo ?? ($std->xMotivo ?? ''));
        $ok = in_array($cStat, ['135', '155'], true);
        if ($ok) {
            $xml = NfeRepository::xml($chave);
            if ($xml !== null && str_contains($xml, '<protNFe')) {
                NfeRepository::saveXml($chave, Complements::cancelRegister($xml, $resp));
            }
            $rec['status'] = 'cancelada';
            $rec['cancelamento'] = ['protocolo' => (string) ($inf->nProt ?? ''), 'justificativa' => $just, 'em' => date('c')];
        }
        self::push($rec, $ok ? 'cancelada' : 'cancelamento_rejeitado', $cStat, $motivo);
        NfeRepository::save($rec);
        Logger::audit('nfe.cancelamento', $ok ? 'sucesso' : 'rejeitado', ['chave' => $chave, 'cStat' => $cStat]);
        return ['sucesso' => $ok, 'cStat' => $cStat, 'xMotivo' => $motivo, 'nfe' => self::publicRec($rec)];
    }

    /** @return array<string,mixed> */
    public static function cce(string $chave, string $correcao): array
    {
        $rec = NfeRepository::get($chave);
        if ((int) ($rec['modelo'] ?? Nfce::modeloDaChave($chave)) === Nfce::MODELO) {
            Logger::audit('nfe.cce', 'recusada_nfce', ['chave' => $chave]);
            throw new ApiException(422, 'cce_nao_permitida', 'Carta de correção (CC-e) não existe para NFC-e (modelo 65). Para corrigir, cancele a NFC-e (dentro do prazo da UF) e emita outra.');
        }
        if ($rec['status'] !== 'autorizada') {
            throw new ApiException(409, 'estado_invalido', 'CC-e só para NF-e autorizada (status atual: ' . $rec['status'] . ')');
        }
        $seq = count($rec['cce'] ?? []) + 1;
        if ($seq > 20) {
            throw new ApiException(409, 'limite_cce', 'Limite de 20 cartas de correção atingido');
        }
        $tools = SefazClient::tools(55);
        $resp = self::sefaz(fn() => $tools->sefazCCe($chave, $correcao, $seq));
        NfeRepository::saveXml($chave, $resp, "ret-cce-$seq");
        $std = (new Standardize($resp))->toStd();
        $inf = $std->retEvento->infEvento ?? null;
        $cStat = (string) ($inf->cStat ?? ($std->cStat ?? ''));
        $motivo = (string) ($inf->xMotivo ?? ($std->xMotivo ?? ''));
        $ok = in_array($cStat, ['135', '136'], true);
        if ($ok) {
            $rec['cce'][] = ['sequencia' => $seq, 'protocolo' => (string) ($inf->nProt ?? ''), 'correcao' => $correcao, 'em' => date('c')];
        }
        self::push($rec, $ok ? 'cce_registrada' : 'cce_rejeitada', $cStat, $motivo);
        NfeRepository::save($rec);
        Logger::audit('nfe.cce', $ok ? 'sucesso' : 'rejeitado', ['chave' => $chave, 'cStat' => $cStat, 'seq' => $seq]);
        return ['sucesso' => $ok, 'sequencia' => $seq, 'cStat' => $cStat, 'xMotivo' => $motivo];
    }

    /** @return array<string,mixed> */
    public static function inutilizar(int $serie, int $ini, int $fim, string $just, int $modelo = 55): array
    {
        $tools = SefazClient::tools($modelo);
        $resp = self::sefaz(fn() => $tools->sefazInutiliza($serie, $ini, $fim, $just));
        $std = (new Standardize($resp))->toStd();
        $inf = $std->infInut ?? null;
        $cStat = (string) ($inf->cStat ?? ($std->cStat ?? ''));
        $motivo = (string) ($inf->xMotivo ?? ($std->xMotivo ?? ''));
        $ok = $cStat === '102';
        $reg = [
            'modelo' => $modelo,
            'serie' => $serie, 'inicio' => $ini, 'fim' => $fim, 'justificativa' => $just,
            'sucesso' => $ok, 'cStat' => $cStat, 'xMotivo' => $motivo,
            'protocolo' => isset($inf->nProt) ? (string) $inf->nProt : null, 'em' => date('c'),
        ];
        \App\Storage\JsonStore::update(\App\Tenancy\Tenant::path('inutilizacoes.json'), function (?array $d) use ($reg) {
            $d ??= [];
            $d[] = $reg;
            return [$d, null];
        });
        Logger::audit('nfe.inutilizacao', $ok ? 'sucesso' : 'rejeitado', ['modelo' => $modelo, 'serie' => $serie, 'inicio' => $ini, 'fim' => $fim, 'cStat' => $cStat]);
        return $reg;
    }

    /** @return array<string,mixed> */
    public static function status(int $modelo = 55): array
    {
        $tools = SefazClient::tools($modelo);
        $resp = self::sefaz(fn() => $tools->sefazStatus());
        $std = (new Standardize($resp))->toStd();
        Logger::audit('sefaz.status', (string) ($std->cStat ?? '?'), ['modelo' => $modelo]);
        return [
            'modelo' => $modelo,
            'cStat' => (string) ($std->cStat ?? ''),
            'xMotivo' => (string) ($std->xMotivo ?? ''),
            'uf' => (string) ($std->cUF ?? ''),
            'ambiente' => (int) ($std->tpAmb ?? 0),
            'tempo_medio' => isset($std->tMed) ? (int) $std->tMed : null,
            'consultado_em' => (string) ($std->dhRecbto ?? date('c')),
            'online' => (string) ($std->cStat ?? '') === '107',
        ];
    }

    /** @return array<string,mixed> */
    public static function consultar(string $chave): array
    {
        NfeRepository::assertChave($chave);
        $tools = SefazClient::tools(Nfce::modeloDaChave($chave) === Nfce::MODELO ? Nfce::MODELO : 55);
        $resp = self::sefaz(fn() => $tools->sefazConsultaChave($chave));
        $arr = (new Standardize($resp))->toArray();
        Logger::audit('sefaz.consulta', (string) ($arr['cStat'] ?? '?'), ['chave' => $chave]);
        return ['chave' => $chave, 'retorno' => $arr];
    }

    /**
     * Executa chamada à SEFAZ convertendo falhas de comunicação em 502 genérico
     * (detalhe vai só para o log do servidor).
     */
    private static function sefaz(callable $fn): string
    {
        try {
            $r = (string) $fn();
        } catch (ApiException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            throw new ApiException(422, 'sefaz_parametros', mb_substr($e->getMessage(), 0, 300));
        } catch (\Throwable $e) {
            Logger::exception($e, 'sefaz');
            throw new ApiException(502, 'sefaz_indisponivel', 'Falha de comunicação com a SEFAZ. Tente novamente mais tarde.');
        }
        if (trim($r) === '') {
            throw new ApiException(502, 'sefaz_indisponivel', 'SEFAZ não retornou resposta.');
        }
        return $r;
    }

    /** @return array{qrcode?:string, url_chave?:string} */
    private static function qrFromXml(string $xml): array
    {
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml, LIBXML_NONET)) {
            return [];
        }
        $qr = $dom->getElementsByTagName('qrCode')->item(0)?->nodeValue;
        $url = $dom->getElementsByTagName('urlChave')->item(0)?->nodeValue;
        return array_filter(['qrcode' => $qr, 'url_chave' => $url], fn($v) => $v !== null && $v !== '');
    }

    /** @param array<string,mixed> $rec */
    private static function push(array &$rec, string $status, ?string $cStat, ?string $msg): void
    {
        $rec['historico'][] = ['em' => date('c'), 'status' => $status, 'cStat' => $cStat, 'mensagem' => $msg];
    }

    /** @param array<string,mixed> $rec @return array<string,mixed> */
    public static function publicRec(array $rec): array
    {
        $rec['modelo'] = (int) ($rec['modelo'] ?? 55);
        $rec['links'] = [
            'self' => '/api/v1/nfe/' . $rec['chave'],
            'xml' => '/api/v1/nfe/' . $rec['chave'] . '/xml',
            'danfe' => '/api/v1/nfe/' . $rec['chave'] . '/danfe',
        ];
        return $rec;
    }

    /** @param array<string,mixed> $rec @return array<string,mixed> */
}
