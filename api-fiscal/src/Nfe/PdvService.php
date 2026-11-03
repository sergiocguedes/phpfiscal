<?php
declare(strict_types=1);

namespace App\Nfe;

use App\Http\ApiException;
use App\Storage\JsonStore;
use App\Support\Env;
use App\Support\Logger;
use App\Support\Paths;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStore;
use NFePHP\NFe\Common\Standardize;
use NFePHP\NFe\Complements;
use NFePHP\NFe\Tools;

/** Operações duráveis do PDV. Nunca troca a chave depois de uma tentativa de envio. */
final class PdvService
{
    private const FINAIS = ['autorizada', 'rejeitada', 'denegada', 'cancelada'];
    private const AUTORIZADAS = ['100', '150', '120'];

    private static function arquivo(string $id): string
    {
        if (!preg_match('/^[A-Za-z0-9_.:-]{8,128}$/D', $id)) {
            throw new ApiException(422, 'operacao_id_invalido', 'operacao_id: 8 a 128 caracteres [A-Za-z0-9_.:-]');
        }
        return Tenant::path('pdv/' . hash('sha256', $id) . '.json');
    }

    private static function lock(string $id, callable $fn): mixed
    {
        return JsonStore::withLock('pdv-' . sha1(self::arquivo($id)), $fn);
    }

    private static function carregar(string $id): array
    {
        return self::hydrate(JsonStore::read(self::arquivo($id)) ?? throw Tenant::notFound('Operação'));
    }

    private static function hydrate(array $op): array
    {
        foreach(['xml','xml_sem_assinatura'] as $k){
            if(isset($op[$k.'_arquivo'])){
                $expected=str_replace('.json','-'.$k.'.xml',self::arquivo($op['operacao_id']));
                if($op[$k.'_arquivo']!==$expected){throw new \RuntimeException('Referência XML da operação inválida');}
                $op[$k]=JsonStore::readRaw($expected)??throw new \RuntimeException('XML da operação indisponível');
            }
        }
        return $op;
    }

    private static function canonical(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        if (!array_is_list($v)) {
            ksort($v);
        }
        return array_map(self::canonical(...), $v);
    }

    public static function emitir(array $entrada): array
    {
        $id = $entrada['operacao_id'] ?? '';
        if (!is_string($id)) {
            throw new ApiException(422, 'operacao_id_invalido', 'operacao_id deve ser texto');
        }
        self::arquivo($id);
        $hash = hash('sha256', json_encode(self::canonical($entrada), JSON_THROW_ON_ERROR));
        return self::lock($id, function () use ($id, $entrada, $hash) {
            $existente = JsonStore::read(self::arquivo($id));
            if ($existente !== null) {
                if (!hash_equals($existente['hash'], $hash)) {
                    throw new ApiException(409, 'operacao_conflito', 'Operação já registrada com outro conteúdo. Consulte a operação original.');
                }
                // Replay consulta o estado atual; não repete uma transmissão.
                return self::resposta(self::hydrate($existente));
            }
            $nota = $entrada['nota'] ?? null;
            if (!is_array($nota) || (int) ($nota['modelo'] ?? 65) !== 65) {
                throw new ApiException(422, 'nota_invalida', 'Informe nota NFC-e (modelo 65)');
            }
            if (isset($nota['numero'])) {
                throw new ApiException(422, 'numero_controlado_api', 'A numeração do PDV é reservada pela API. Informe apenas a série.');
            }
            
            foreach (is_array($nota['itens'] ?? null) ? $nota['itens'] : [] as $i => $item) {
                if (is_array($item) && (isset($item['impostos']['ibscbs']) || isset($item['impostos']['is']))
                    && !\App\Fiscal\RtcInformada::ativa($nota)) {
                    throw ApiException::validation([['campo' => 'nota.reforma_tributaria.modo', 'mensagem' => 'No PDV, informe modo=informado e os valores RTC calculados pelo Delphi']]);
                }
                foreach (['icms', 'pis', 'cofins'] as $imposto) {
                    if (!is_array($item) || !isset($item['impostos'][$imposto])) {
                        throw ApiException::validation([['campo' => "nota.itens[$i].impostos.$imposto", 'mensagem' => 'Informe os impostos calculados pelo PDV nesta etapa']]);
                    }
                }
            }
            $nota['modelo'] = 65;
            $p = NfeBuilder::validatePayload($nota);
            $modo = $entrada['contingencia'] ?? 'automatica';
            if (!in_array($modo, ['automatica', 'offline', 'desativada'], true)) {
                throw new ApiException(422, 'contingencia_invalida', 'contingencia: automatica, offline ou desativada');
            }
            $motivo = $entrada['motivo_contingencia'] ?? 'Falha de comunicacao com o servico de autorizacao da SEFAZ';
            if (!is_string($motivo) || mb_strlen(trim($motivo)) < 15 || mb_strlen($motivo) > 256) {
                throw new ApiException(422, 'motivo_contingencia_invalido', 'Motivo de contingência deve ter 15 a 256 caracteres');
            }
            $cfg = ConfigService::require();
            $tools = SefazClient::tools(65);
            Nfce::assertCsc($cfg);
            $offline = $modo === 'offline';
            if ($modo === 'automatica') {
                // Só decidir offline ANTES de enviar qualquer XML normal.
                try {
                    $status = (new Standardize($tools->sefazStatus()))->toStd();
                    $offline = in_array((string) ($status->cStat ?? ''), ['108', '109'], true);
                    if (!in_array((string) ($status->cStat ?? ''), ['107', '108', '109'], true)) {
                        throw new ApiException(502, 'status_sefaz_invalido', 'Resposta de status não reconhecida; nenhuma nota foi transmitida');
                    }
                } catch (ApiException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    Logger::exception($e, 'pdv.status');
                    $offline = true;
                }
            }
            if ($offline && (int) $cfg['ambiente'] === 1 && !in_array($cfg['uf'], array_map('trim', explode(',', Env::get('NFCE_CONTINGENCIA_UFS', '') ?? '')), true)) {
                throw new ApiException(409, 'contingencia_uf_nao_habilitada', 'Homologue a contingência da UF e habilite NFCE_CONTINGENCIA_UFS no servidor');
            }
            $identidade = [
                'rtc_delphi' => true,
                'cnf' => random_int(10000000, 99999999),
                'data_emissao' => (new \DateTimeImmutable('now', new \DateTimeZone(\NFePHP\Common\TimeZoneByUF::get($cfg['uf']))))->format('c'),
                'tipo_emissao' => $offline ? 9 : 1,
            ];
            if ($offline) {
                $identidade += ['data_contingencia' => $identidade['data_emissao'], 'motivo_contingencia' => trim($motivo)];
            }
            $serie = $p['serie'] ?? ConfigService::defaultSerie($cfg, 65);
            // Validação antes da reserva; só a identidade definitiva é persistida e transmitida.
            $pre = NfeBuilder::build($p, $cfg, ConfigService::nextNumber($cfg, 65, $serie), $serie, $identidade);
            $erros = XsdValidator::validateUnsignedNFe($pre['xml']);
            if ($erros !== []) {
                throw ApiException::validation(array_map(fn($e) => ['campo' => 'nota', 'mensagem' => $e], array_slice($erros, 0, 20)));
            }
            // Mesmo lock de reserva do emissor existente. Falha antes de persistir pode deixar lacuna,
            // mas nunca há transmissão antes da operação durável estar gravada.
            $numero = ConfigService::reserveNumber(65, $serie);
            $b = NfeBuilder::build($p, $cfg, $numero, $serie, $identidade);
            $op = [
                'operacao_id' => $id, 'hash' => $hash, 'modelo' => 65, 'ambiente' => (int) $cfg['ambiente'],
                'cnpj' => $cfg['cnpj'], 'uf' => $cfg['uf'], 'chave' => $b['chave'], 'numero' => $numero, 'serie' => $serie,
                'tipo_emissao' => $identidade['tipo_emissao'], 'data_emissao' => $identidade['data_emissao'],
                'valor_total' => $b['vNF'], 'status' => 'preparando', 'xml_sem_assinatura' => $b['xml'],
                'criado_em' => date('c'), 'tentativas' => 0, 'proxima_tentativa' => time() + 60,
            ];
            NfeRepository::claimNumero($op);
            self::gravar($op);
            Logger::audit('pdv.criada', $op['status'], ['chave' => $op['chave'], 'operacao_id' => $id]);
            return self::resposta(self::preparar($op, $tools));
        });
    }

    private static function preparar(array $op, Tools $tools): array
    {
        if ($op['status'] === 'preparando') {
            // Assinatura e QR-Code offline feitos pela biblioteca; nunca gerar QR manualmente.
            $op['xml'] = $tools->signNFe($op['xml_sem_assinatura']);
            unset($op['xml_sem_assinatura']);
            $op['status'] = $op['tipo_emissao'] === 9 ? 'contingencia_pendente' : 'pronta';
            self::gravar($op);
        }
        return $op['status'] === 'pronta' ? self::transmitir($op, $tools) : $op;
    }

    private static function gravar(array $op): void
    {
        $op['atualizado_em'] = date('c');
        // Fonte da verdade: operação primeiro; projeção para painel/download depois.
        $stored=$op;
        foreach(['xml','xml_sem_assinatura'] as $k){
            if(isset($op[$k])){
                $file=str_replace('.json','-'.$k.'.xml',self::arquivo($op['operacao_id']));
                JsonStore::writeRaw($file,$op[$k]);$stored[$k.'_arquivo']=$file;unset($stored[$k]);
            }else{unset($stored[$k.'_arquivo']);}
        }
        JsonStore::write(self::arquivo($op['operacao_id']), $stored);
        if (isset($op['xml'])) {
            self::projetar($op);
        }
    }

    private static function projetar(array $op): void
    {
        $rec = NfeRepository::find($op['chave']) ?? ['historico' => [], 'origem' => 'pdv'];
        // Cancelamento pelo endpoint existente não pode ser revertido por replay da operação.
        if (($rec['status'] ?? '') === 'cancelada') {
            return;
        }
        $statusPainel = in_array($op['status'], self::FINAIS, true) ? $op['status'] : 'processando';
        NfeRepository::saveXml($op['chave'], $op['xml']);
        if (($rec['status_pdv'] ?? null) !== $op['status']) {
            $rec['historico'][] = ['em' => date('c'), 'status' => $statusPainel, 'cStat' => $op['cStat'] ?? null, 'mensagem' => $op['status'] . ': ' . ($op['xMotivo'] ?? '')];
        }
        $rec = array_replace($rec, array_intersect_key($op, array_flip([
            'chave', 'modelo', 'numero', 'serie', 'ambiente', 'valor_total', 'status', 'criado_em', 'cStat', 'xMotivo', 'protocolo', 'autorizado_em', 'operacao_id',
        ])));
        $rec['status'] = $statusPainel;
        $rec['status_pdv'] = $op['status'];
        NfeRepository::save($rec);
    }

    public static function consultar(string $id): array
    {
        return self::lock($id, fn() => self::resposta(self::carregar($id)));
    }

    private static function resposta(array $op): array
    {
        if (isset($op['xml'])) {
            self::projetar($op); // repara queda entre gravação da operação e projeção
        }
        $r = array_intersect_key($op, array_flip([
            'operacao_id', 'modelo', 'ambiente', 'chave', 'numero', 'serie', 'tipo_emissao', 'data_emissao', 'valor_total',
            'status', 'cStat', 'xMotivo', 'cStat_lote', 'xMotivo_lote', 'protocolo', 'autorizado_em', 'tentativas', 'proxima_tentativa', 'mensagem',
        ]));
        $local = NfeRepository::find($op['chave']);
        if (($local['status'] ?? '') === 'cancelada') {
            $r['status'] = 'cancelada';
        }
        $r['xml'] = (in_array($r['status'], ['autorizada', 'cancelada', 'contingencia_pendente'], true)
            || ($op['tipo_emissao'] === 9 && $r['status'] === 'situacao_incerta'))
            ? (NfeRepository::xml($op['chave']) ?? $op['xml'] ?? null) : null;
        $r['imprimivel'] = $r['xml'] !== null && $r['status'] !== 'cancelada';
        if ($r['xml'] !== null) {
            $dom = new \DOMDocument();
            $dom->loadXML($r['xml'], LIBXML_NONET);
            $r['qrcode'] = $dom->getElementsByTagName('qrCode')->item(0)?->nodeValue;
            $r['url_chave'] = $dom->getElementsByTagName('urlChave')->item(0)?->nodeValue;
            $r['digVal'] = $dom->getElementsByTagName('DigestValue')->item(0)?->nodeValue;
        }
        $r['requer_intervencao'] = !in_array($r['status'], self::FINAIS, true) && $op['tentativas'] >= 12;
        $r['links'] = ['self' => '/api/v1/pdv/nfce/' . $op['operacao_id'], 'sincronizar' => '/api/v1/pdv/nfce/' . $op['operacao_id'] . '/sincronizar'];
        $r['links']['danfe'] = '/api/v1/nfe/' . $op['chave'] . '/danfe';
        return $r;
    }

    private static function tools(array $op): Tools
    {
        $cfg = ConfigService::require();
        if ($cfg['cnpj'] !== $op['cnpj'] || $cfg['uf'] !== $op['uf'] || (int) $cfg['ambiente'] !== $op['ambiente']) {
            throw new ApiException(409, 'config_operacao_divergente', 'Restaure emitente, UF e ambiente originais antes de sincronizar');
        }
        return SefazClient::tools(65);
    }

    public static function sincronizar(string $id): array
    {
        return self::lock($id, function () use ($id) {
            $op = self::carregar($id);
            if (in_array($op['status'], self::FINAIS, true)) {
                return self::resposta($op);
            }
            if (time() < $op['proxima_tentativa']) {
                return self::resposta($op);
            }
            $op['tentativas']++;
            $op['proxima_tentativa'] = time() + min(3600, 60 * (2 ** min(6, $op['tentativas'])));
            self::gravar($op);
            $tools = self::tools($op);
            if (in_array($op['status'], ['preparando', 'pronta'], true)) {
                return self::resposta(self::preparar($op, $tools));
            }
            try {
                // Sempre consultar a chave ANTES de retransmitir o MESMO XML.
                $resp = $tools->sefazConsultaChave($op['chave']);
                JsonStore::writeRaw(Tenant::path('pdv/' . hash('sha256', $id) . '-consulta.xml'), $resp);
                $std = (new Standardize($resp))->toStd();
                if (isset($std->protNFe->infProt)) {
                    $op = self::interpretar($op, $resp);
                } elseif (in_array((string) ($std->cStat ?? ''), ['217', '562'], true)) {
                    $status = (new Standardize($tools->sefazStatus()))->toStd();
                    if ((string) ($status->cStat ?? '') === '107') {
                        $op = self::transmitir($op, $tools);
                    }
                }
            } catch (\Throwable $e) {
                Logger::exception($e, 'pdv.sincronizar');
                $op['mensagem'] = 'Consulta indisponível. Identidade preservada; aguarde a próxima tentativa.';
            }
            self::gravar($op);
            return self::resposta($op);
        });
    }

    private static function transmitir(array $op, Tools $tools): array
    {
        $op['status'] = 'situacao_incerta';
        $op['tentativas']++;
        $op['proxima_tentativa'] = time() + min(3600, 60 * (2 ** min(6, $op['tentativas'])));
        self::gravar($op); // write-ahead: queda durante o envio exige consulta, nunca nova nota
        try {
            $resp = $tools->sefazEnviaLote([$op['xml']], (string) random_int(100000000000000, 999999999999999), 1);
            JsonStore::writeRaw(Tenant::path('pdv/' . hash('sha256', $op['operacao_id']) . '-envio.xml'), $resp);
            $op = self::interpretar($op, $resp);
        } catch (\Throwable $e) {
            Logger::exception($e, 'pdv.envio');
            $op['mensagem'] = 'Resposta de transmissão incerta. Consulte a operação; não crie outra NFC-e.';
        }
        self::gravar($op);
        Logger::audit('pdv.transmissao', $op['status'], ['chave' => $op['chave'], 'operacao_id' => $op['operacao_id']]);
        return $op;
    }

    private static function interpretar(array $op, string $resp): array
    {
        $std = (new Standardize($resp))->toStd();
        $op['cStat_lote'] = (string) ($std->cStat ?? '');
        $op['xMotivo_lote'] = (string) ($std->xMotivo ?? '');
        $prot = $std->protNFe->infProt ?? null;
        if ($prot === null) {
            // Retorno de lote/duplicidade não comprova rejeição individual.
            $op['cStat'] = (string) ($std->cStat ?? '');
            $op['xMotivo'] = (string) ($std->xMotivo ?? 'Resposta sem protocolo');
            return $op;
        }
        if ((string) ($prot->chNFe ?? '') !== $op['chave'] || (int) ($prot->tpAmb ?? 0) !== $op['ambiente']) {
            throw new \RuntimeException('Protocolo de outra chave/ambiente');
        }
        $code = (string) $prot->cStat;
        if (in_array($code, self::AUTORIZADAS, true)) {
            if (empty($prot->nProt)) {
                throw new \RuntimeException('Autorização sem protocolo');
            }
            $op['xml'] = Complements::toAuthorize($op['xml'], $resp);
            $op['status'] = 'autorizada';
            $op['protocolo'] = (string) $prot->nProt;
            $op['autorizado_em'] = (string) $prot->dhRecbto;
            unset($op['mensagem']);
            if ((string) ($std->cStat ?? '') === '101') {
                $op['status'] = 'cancelada';
            }
        } elseif (in_array($code, ['110', '205', '301', '302', '303'], true)) {
            $op['status'] = 'denegada';
        } elseif (!in_array($code, ['204', '539', '105', '103'], true)) {
            $op['status'] = 'rejeitada';
        }
        $op['cStat'] = $code;
        $op['xMotivo'] = (string) $prot->xMotivo;
        return $op;
    }

    /** Worker: fila durável, backoff e limite por rodada; sem remover operações antigas. */
    public static function ciclo(): void
    {
        $restantes = 10;
        foreach (TenantStore::ids() as $tid) {
          foreach ([1,2] as $amb) {
            foreach (JsonStore::files('tenants/'.$tid.'/ambientes/'.$amb.'/pdv/*.json') as $file) {
                $op = JsonStore::read($file);
                if (!is_array($op) || in_array($op['status'] ?? '', self::FINAIS, true)
                    || ($op['tentativas'] ?? 0) >= 12 || ($op['proxima_tentativa'] ?? 0) > time()) {
                    continue;
                }
                try {
                    \App\Tenancy\FiscalScope::run($tid,$amb,fn() => self::sincronizar($op['operacao_id']),65);
                } catch (\Throwable $e) {
                    Logger::exception($e, 'pdv.worker');
                }
                if (--$restantes <= 0) {
                    return;
                }
            }
          }
        }
    }
}
