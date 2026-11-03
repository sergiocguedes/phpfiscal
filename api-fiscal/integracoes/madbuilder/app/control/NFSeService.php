<?php
require_once dirname(__DIR__) . '/service/NfseApiPayload.php';
require_once dirname(__DIR__) . '/service/NfseApiConfiguracao.php';

class NFSeService extends TPage
{
    public function __construct($param)
    {
        parent::__construct();
    }
        
    public static function configurarCliente()
    {
        return self::sincronizarConfiguracao(true);
    }

    public static function sincronizarParametros(): array
    {
        return self::sincronizarConfiguracao(false);
    }

    private static function sincronizarConfiguracao(bool $incluirCertificado): array
    {
        try
        {
            TTransaction::open('emissor');

            $id_user = TSession::getValue('userunitid');

            $empresa = Empresa::where(
                'system_unit_id',
                '=',
                $id_user
            )->first();

            $parametros = ParametrosGerais::where(
                'system_unit_id',
                '=',
                $id_user
            )->first();

            if (!$empresa)
            {
                throw new Exception(
                    'Empresa não encontrada.'
                );
            }

            if (!$parametros)
            {
                throw new Exception(
                    'Parâmetros não encontrados.'
                );
            }

            $payload = NfseApiConfiguracao::montar($empresa, $parametros, $incluirCertificado);

            TTransaction::close();

            $response = self::requestNfseApi('configurar-cliente', $payload, 120);

            if (empty($response))
            {
                throw new Exception(
                    'Erro CURL configurar cliente: resposta vazia'
                );
            }

            $retorno = PainelApiService::decodificarJson($response) ?? json_decode($response, true);

            if (
                !$retorno ||
                !isset($retorno['sucesso'])
            )
            {
                throw new Exception(
                    'Resposta inválida da API.'
                );
            }

            if ($retorno['sucesso'] !== true)
            {
                throw new Exception(
                    $retorno['mensagem']
                    ?? 'Erro ao configurar cliente.'
                );
            }

            return $retorno;
        }
        catch (Exception $e)
        {
            if (TTransaction::get())
            {
                TTransaction::rollback();
            }

            throw $e;
        }
    }

    public static function consultarDPSPorChave($cnpj, $chave)
    {
        $response = self::requestNfseApi('consultar-dps-por-chave', [
            'cnpj' => $cnpj,
            'chave' => $chave,
        ]);

        return PainelApiService::decodificarJson($response) ?? json_decode($response, true);
    }

    public static function consultarDPSPorId($notaId)
    {
        try
        {
            TTransaction::open('emissor');

            $nota = new NotaFiscalCabecalho($notaId);

            if (!$nota)
            {
                throw new Exception('Nota não encontrada');
            }

            $id_user = TSession::getValue('userunitid');

            $empresa = Empresa::where('system_unit_id', '=', $id_user)->first();

            if (!$empresa)
            {
                throw new Exception('Empresa da unidade não encontrada. Verifique em Configurações > Dados da Empresa.');
            }

            $chaveDps = self::somenteDigitos($nota->chave_acesso_rps) ?: self::montarChaveDps($nota, $empresa);

            if (empty($chaveDps))
            {
                throw new Exception(
                    'Nota não possui chave RPS nem número/série do RPS.'
                );
            }

            $cnpjEmpresa = preg_replace(
                '/\D/',
                '',
                $empresa->cnpj
            );

            $retorno = self::consultarDPSPorChave($cnpjEmpresa, 'DPS' . $chaveDps);

            if (empty($retorno['sucesso']))
            {
                $retorno = self::consultarDPSPorChave($cnpjEmpresa, $chaveDps);
            }

            if (
                empty($retorno)
                ||
                empty($retorno['sucesso'])
            )
            {
                throw new Exception(
                    $retorno['erro'] ?? 'Erro ao consultar DPS'
                );
            }

            $dados = $retorno['retorno'] ?? [];

            if (
                !empty($dados['chave'])
                && !empty($dados['xmlRetorno'])
                && !self::nfseCorrespondeNota((string) $dados['xmlRetorno'], $nota)
            )
            {
                throw new Exception(
                    "O RPS {$nota->numero_rps} (série {$nota->serie}) gerou a NFS-e nº " . ($dados['numero'] ?? '?')
                    . ', mas ela é de outro tomador/valor. Reemita esta nota com o RPS '
                    . self::proximoNumeroRps($nota->system_unit_id) . '.'
                );
            }

            $outraNota = self::notaComChaveNfse(
                (string) self::somenteDigitos($dados['chave'] ?? ''),
                $nota->id
            );

            if ($outraNota)
            {
                throw new Exception(
                    "O RPS {$nota->numero_rps} (série {$nota->serie}) gerou a NFS-e nº " . ($dados['numero'] ?? '?')
                    . ', que já está vinculada à nota #' . ($outraNota->id_key_empresa ?: $outraNota->id)
                    . '. Reemita esta nota com o RPS ' . self::proximoNumeroRps($nota->system_unit_id) . '.'
                );
            }

            $nota->chave_acesso_nfse = preg_replace(
                '/\D/',
                '',
                $dados['chave'] ?? ''
            );

            $nota->chave_acesso_rps = preg_replace('/\D/', '', $dados['chaveDps'] ?? '') ?: $chaveDps;

            $nota->numero = $dados['numero'] ?? null;

            $nota->mensagem_retorno_nfse =
                json_encode($retorno);

            if (!empty($nota->chave_acesso_nfse))
            {
                $nota->nota_fiscal_status_id =
                    NotaFiscalStatus::AUTORIZADA;
                $nota->data_emissao = $nota->data_emissao ?: date('Y-m-d H:i:s');
            }

            $nota->store();

            TTransaction::close();

            return $retorno;
        }
        catch (Exception $e)
        {
            if (TTransaction::get())
            {
                TTransaction::rollback();
            }

            throw $e;
        }
    }

    // função executa ao clicar no item de menu
    public static function consultarNFSePorChave($cnpj, $chave)
    {
        $response = self::requestNfseApi('consultar-por-chave', [
            'cnpj' => $cnpj,
            'chave' => $chave,
        ]);

        return PainelApiService::decodificarJson($response) ?? json_decode($response, true);
    }

    public static function emitirNFSe(array $dados)
    {
        LogNf::adicionar(
            $dados['nota_id'] ?? 0,
            'ENVIO API',
            'Enviando NFSe para API',
            $dados
        );

        try
        {
            // A API recebe o JSON completo; não há reenvio automático removendo IBS/CBS.
            $response = self::requestNfseApi('emitir', $dados, 180);
        }
        catch (Exception $e)
        {
            LogNf::adicionar(
                $dados['nota_id'] ?? 0,
                'ERRO CURL',
                $e->getMessage()
            );

            throw $e;
        }

        LogNf::adicionar(
            $dados['nota_id'] ?? 0,
            'RETORNO API',
            'API retornou resposta',
            $response
        );

        return PainelApiService::decodificarJson($response) ?? json_decode($response, true);
    }
    public static function baixarDANFE($cnpj, $chave, bool $cancelada = false)
    {
        $response = self::requestNfseApi('imprimir-pdf', [
            'cnpj' => $cnpj,
            'chave' => $chave,
            'cancelada' => $cancelada,
        ]);

        return PainelApiService::decodificarJson($response) ?? json_decode($response, true);
    }

    /**
     * ACBrLib NFSE_ConsultarEvento do cancelamento (tipo 0, sequência 1).
     * Grava na nota o XML/ID do evento quando ainda não estiverem salvos.
     */
    public static function consultarEventoCancelamento($notaId): string
    {
        TTransaction::open('emissor');

        try
        {
            $nota = new NotaFiscalCabecalho((int) $notaId);

            if (empty($nota->id) || (int) $nota->system_unit_id !== (int) TSession::getValue('userunitid'))
            {
                throw new Exception('Nota não encontrada.');
            }

            $empresa = Empresa::where('system_unit_id', '=', $nota->system_unit_id)->first();
            $cnpj = (string) self::somenteDigitos($empresa->cnpj ?? null);
            $chave = (string) self::somenteDigitos($nota->chave_acesso_nfse);

            TTransaction::close();
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            throw $e;
        }

        if ($cnpj === '' || $chave === '')
        {
            throw new Exception('Nota sem chave de acesso ou empresa sem CNPJ para consultar o cancelamento.');
        }

        $result = PainelApiService::postNfse('consultar-evento', [
            'cnpj' => $cnpj,
            'chave' => $chave,
            'tipoEvento' => 0,
            'numSeq' => 1,
        ], 120);

        if (!empty($result['curl_error']))
        {
            throw new Exception('Erro CURL: ' . $result['curl_error']);
        }

        $retorno = PainelApiService::decodificarJson((string) $result['message']);

        if (!is_array($retorno))
        {
            throw new Exception('Resposta inválida da API ao consultar o evento de cancelamento (HTTP ' . (int) $result['http_code'] . ').');
        }

        if (empty($retorno['sucesso']))
        {
            throw new Exception('Consulta do evento de cancelamento: ' . ($retorno['erro'] ?? $retorno['mensagem'] ?? 'erro desconhecido.'));
        }

        $xml = (string) ($retorno['xmlEvento'] ?? '');

        if ($xml === '')
        {
            throw new Exception('A Sefin não devolveu o XML do evento de cancelamento.');
        }

        TTransaction::open('emissor');
        $nota = new NotaFiscalCabecalho((int) $notaId);
        $alterou = false;

        if (empty($nota->xml_cancelamento))
        {
            $nota->xml_cancelamento = $xml;
            $alterou = true;
        }

        if (empty($nota->id_evento_cancelamento) && preg_match('/<infEvento[^>]*\sId="([^"]+)"/', $xml, $m))
        {
            $nota->id_evento_cancelamento = $m[1];
            $alterou = true;
        }

        if (empty($nota->data_cancelamento) && preg_match('/<dhProc>(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})/', $xml, $m))
        {
            $nota->data_cancelamento = $m[1] . ' ' . $m[2];
            $alterou = true;
        }

        if (empty($nota->codigo_cancelamento) && preg_match('/<cMotivo>(\d+)<\/cMotivo>/', $xml, $m))
        {
            $nota->codigo_cancelamento = $m[1];
            $alterou = true;
        }

        if (empty($nota->motivo_cancelamento) && preg_match('/<xMotivo>(.*?)<\/xMotivo>/s', $xml, $m))
        {
            $nota->motivo_cancelamento = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            $alterou = true;
        }

        if ($alterou)
        {
            $nota->store();
        }

        TTransaction::close();

        return $xml;
    }

    /**
     * Códigos de cancelamento da NFS-e Nacional (cMotivo do evento e101101)
     */
    public static function codigosCancelamento(): array
    {
        return [
            '1' => 'Erro na emissão',
            '2' => 'Serviço não prestado',
            '9' => 'Outros',
        ];
    }

    /**
     * Envia o cancelamento para a API (ACBrLib NFSE_Cancelar - seção [CancelarNFSe])
     */
    public static function cancelarNFSe(array $dados): array
    {
        $result = PainelApiService::postNfse('cancelar', $dados, 120);

        if (! empty($result['curl_error']))
        {
            throw new Exception('Erro CURL: ' . $result['curl_error']);
        }

        $response = (string) $result['message'];
        $httpCode = (int) $result['http_code'];

        $retorno = PainelApiService::decodificarJson($response);

        if (! is_array($retorno))
        {
            $trecho = trim(mb_substr(strip_tags($response), 0, 300));

            throw new Exception(
                "Resposta inválida da API de cancelamento (HTTP {$httpCode}"
                . ' - ' . PainelApiService::urlNfse('cancelar') . ')'
                . ($trecho !== '' ? ": {$trecho}" : ': resposta vazia.')
            );
        }

        if (! empty($retorno['errors']) && is_array($retorno['errors']))
        {
            $erros = [];

            foreach ($retorno['errors'] as $campo => $mensagens)
            {
                $erros[] = $campo . ': ' . implode(' ', (array) $mensagens);
            }

            throw new Exception('API recusou os dados do cancelamento: ' . implode(' | ', $erros));
        }

        if ($httpCode >= 400 && ! isset($retorno['sucesso']))
        {
            throw new Exception("Erro HTTP {$httpCode} na API de cancelamento: " . mb_substr($response, 0, 300));
        }

        if (isset($retorno['sucesso']) && $retorno['sucesso'] !== true)
        {
            throw new Exception(
                $retorno['mensagem']
                ?? $retorno['erro']
                ?? 'Erro ao cancelar NFS-e.'
            );
        }

        if (! empty($retorno['erro']))
        {
            throw new Exception((string) $retorno['erro']);
        }

        return $retorno;
    }

    public static function cancelarNotaFiscalCabecalho(int $notaId, string $codigoCancelamento, string $motivo): void
    {
        $codigoCancelamento = trim($codigoCancelamento);
        $motivo = trim(preg_replace('/\s+/', ' ', $motivo));

        if (! array_key_exists($codigoCancelamento, self::codigosCancelamento()))
        {
            throw new Exception('Selecione o código do cancelamento.');
        }

        if (mb_strlen($motivo) < 15 || mb_strlen($motivo) > 255)
        {
            throw new Exception('O motivo do cancelamento deve ter entre 15 e 255 caracteres.');
        }

        $unitId = (int) TSession::getValue('userunitid');

        TTransaction::open('emissor');

        $nota = new NotaFiscalCabecalho($notaId);

        if (empty($nota->id) || (int) $nota->system_unit_id !== $unitId)
        {
            throw new Exception('Nota fiscal não encontrada.');
        }

        if ($nota->nota_fiscal_status_id != NotaFiscalStatus::AUTORIZADA)
        {
            throw new Exception('Somente NFS-e autorizada pode ser cancelada.');
        }

        if (empty($nota->chave_acesso_nfse))
        {
            throw new Exception('NFS-e sem chave de acesso para cancelamento.');
        }

        $empresa = Empresa::where('system_unit_id', '=', $unitId)->first();

        if (! $empresa || empty($empresa->cnpj))
        {
            throw new Exception('Empresa não encontrada para cancelamento.');
        }

        $dataEmissao = ! empty($nota->data_emissao)
            ? date('Y-m-d', strtotime($nota->data_emissao))
            : date('Y-m-d');

        $dados = [
            'Cnpj'            => preg_replace('/\D/', '', $empresa->cnpj),
            'ChaveNFSe'       => preg_replace('/\D/', '', $nota->chave_acesso_nfse),
            'NumeroNFSe'      => (string) ($nota->numero ?? ''),
            'SerieNFSe'       => (string) ($nota->serie ?? ''),
            'DataEmissaoNFSe' => $dataEmissao,
            'CodCancelamento' => $codigoCancelamento,
            'MotCancelamento' => $motivo,
            'NumeroRps'       => (string) ($nota->numero_rps ?? ''),
            'SerieRps'        => (string) ($nota->serie ?? ''),
            'ValorNFSe'       => (float) ($nota->valor_servico ?? 0),
        ];

        LogNf::adicionar(
            $nota->id,
            'CANCELAMENTO API',
            'Enviando cancelamento NFS-e para API',
            $dados
        );

        try
        {
            $retorno = self::cancelarNFSe($dados);
        }
        catch (Exception $e)
        {
            LogNf::adicionar($nota->id, 'CANCELAMENTO API', 'Erro no cancelamento: ' . $e->getMessage());
            TTransaction::close();
            throw $e;
        }

        LogNf::adicionar(
            $nota->id,
            'CANCELAMENTO API',
            'Retorno do cancelamento NFS-e',
            $retorno
        );

        $evento = self::extrairEventoCancelamento($retorno);

        if ($evento['sucesso'] === false)
        {
            TTransaction::close();
            throw new Exception('Cancelamento não confirmado pela SEFAZ' . ($evento['situacao'] ? ': ' . $evento['situacao'] : '.'));
        }

        $nota->nota_fiscal_status_id = NotaFiscalStatus::CANCELADA;
        $historicoRetorno=json_decode((string)$nota->mensagem_retorno_nfse,true);
        if(!is_array($historicoRetorno)){$historicoRetorno=['retorno_anterior'=>$nota->mensagem_retorno_nfse];}
        $historicoRetorno['cancelamento']=$retorno;
        $nota->mensagem_retorno_nfse = json_encode($historicoRetorno,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $nota->id_evento_cancelamento = $evento['id_evento'];
        $nota->codigo_cancelamento = $codigoCancelamento;
        $nota->motivo_cancelamento = $motivo;
        $nota->data_cancelamento = $evento['data'] ?: date('Y-m-d H:i:s');
        $nota->xml_cancelamento = $evento['xml'];
        $nota->store();

        TTransaction::close();
    }

    /**
     * Lê o retorno do EnviarEvento (INI do ACBr em retornoBruto) do cancelamento
     */
    public static function extrairEventoCancelamento(array $retorno): array
    {
        $bruto = (string) ($retorno['retornoBruto'] ?? '');
        $campos = [];

        foreach (preg_split('/\r\n|\r|\n/', $bruto) as $linha)
        {
            $partes = explode('=', $linha, 2);

            if (count($partes) === 2)
            {
                $campos[trim($partes[0])] = trim($partes[1]);
            }
        }

        $xml = $retorno['xmlEvento'] ?? $campos['XmlRetorno'] ?? '';
        $idEvento = null;
        $data = null;

        if ($xml !== '' && preg_match('/<infEvento[^>]*\sId="([^"]+)"/', $xml, $m))
        {
            $idEvento = $m[1];
        }

        if ($xml !== '' && preg_match('/<dhProc>(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})/', $xml, $m))
        {
            $data = $m[1] . ' ' . $m[2];
        }

        $sucesso = isset($retorno['sucesso']) ? (bool) $retorno['sucesso'] : null;

        if (isset($campos['SucessoCanc']) && $campos['SucessoCanc'] !== '')
        {
            $sucesso = in_array(strtolower($campos['SucessoCanc']), ['1', 'true', 's', 'sim'], true);
        }

        return [
            'sucesso'   => $sucesso,
            'situacao'  => $campos['DescSituacao'] ?? null,
            'id_evento' => $idEvento,
            'data'      => $data,
            'xml'       => $xml !== '' ? $xml : null,
        ];
    }

    public static function emitirNFSePorId($notaId, int $rpsPulados = 0)
    {
        PermissaoService::verificarLimiteEmissaoNf();

        try
        {
            TTransaction::open('emissor');

            $nota = new NotaFiscalCabecalho($notaId);

            if (!$nota)
            {
                throw new Exception('Nota não encontrada');
            }

            if (
                (int)$nota->nota_fiscal_status_id !== (int)NotaFiscalStatus::PENDENTE
                &&
                (int)$nota->nota_fiscal_status_id !== (int)NotaFiscalStatus::DIGITACAO
                &&
                (int)$nota->nota_fiscal_status_id !== (int)NotaFiscalStatus::REJEITADA
            )
            {
                throw new Exception(
                    'Somente notas pendentes ou em digitação podem ser emitidas.'
                );
            }

            $id_user = TSession::getValue('userunitid');

            $empresa = Empresa::where(
                'system_unit_id',
                '=',
                $id_user
            )->first();

            if (!$empresa)
            {
                throw new Exception('Empresa não encontrada.');
            }

            $rpsTrocado = self::trocarRpsSeJaEnviadoPorOutraNota($nota);

            if ($nota->aplicarRetencaoIssDoCliente())
            {
                $nota->store();
            }

            // data/hora da emissão só é gravada quando a Sefin autoriza
            $dataEmissao = date('Y-m-d H:i:s');
            $nota->data_emissao = $dataEmissao;

            $dados = NFSeService::montarPayload(
                $nota,
                $empresa
            );

            // log_nf.nota_fiscal_cabecalho_id tem FK: sem o id real o log é descartado
            $dados['nota_id'] = (int) $nota->id;

            TTransaction::close();

            if ($rpsTrocado)
            {
                LogNf::adicionar($notaId, 'RPS', $rpsTrocado);
            }

            // API e logs fora da transação (evita 25P02 no PostgreSQL)
            $retorno = self::emitirComNovasTentativas($dados);

            error_log(
                'RETORNO API NFSE: '
                . json_encode(
                    $retorno,
                    JSON_UNESCAPED_UNICODE
                )
            );

            TTransaction::open('emissor');

            $nota = new NotaFiscalCabecalho($notaId);

            if (!$retorno || empty($retorno['sucesso']))
            {
                $mensagens = [];

                if (!$retorno)
                {
                    $mensagens[] = 'Sem retorno da API';
                }
                elseif (!empty($retorno['errors']))
                {
                    foreach ($retorno['errors'] as $campo => $erros)
                    {
                        if (is_array($erros))
                        {
                            foreach ($erros as $erro)
                            {
                                $mensagens[] = $erro;
                            }
                        }
                        else
                        {
                            $mensagens[] = $erros;
                        }
                    }
                }
                else
                {
                    $mensagens[] =
                        trim((string) ($retorno['erro'] ?? ''))
                        ?: trim((string) ($retorno['mensagem'] ?? ''))
                        ?: 'A API não informou o motivo (provável falha de comunicação com a Sefin). Tente emitir novamente.';
                }

                if (stripos(implode(' ', $mensagens), self::CODIGO_DPS_JA_UTILIZADA) !== false)
                {
                    TTransaction::close();

                    $existente = self::importarNFSeDaDpsExistente($notaId);

                    if (!empty($existente['sucesso']))
                    {
                        return $existente;
                    }

                    TTransaction::open('emissor');
                    $nota = new NotaFiscalCabecalho($notaId);
                    $rpsAnterior = $nota->numero_rps;

                    if (!empty($existente['outraNota']))
                    {
                        $motivo = "O RPS {$rpsAnterior} (série {$nota->serie}) já gerou a NFS-e nº {$existente['numeroNota']}, vinculada à nota #{$existente['outraNota']}.";
                    }
                    elseif (!empty($existente['numeroNota']))
                    {
                        $motivo = "O RPS {$rpsAnterior} (série {$nota->serie}) já gerou a NFS-e nº {$existente['numeroNota']}, que é de outro tomador/valor.";
                    }
                    else
                    {
                        $motivo = "O RPS {$rpsAnterior} (série {$nota->serie}) já foi usado em outra NFS-e.";
                    }

                    // Se esta nota já tinha enviado este RPS antes, a NFS-e existente pode ser dela (retorno perdido)
                    $dpsDeOutraNota = !empty($existente['numeroNota']) || self::vezesRpsEnviado($nota) <= 1;

                    // Esse RPS nunca mais será aceito para esta nota: já deixa o próximo livre reservado
                    $nota->numero_rps = self::proximoNumeroRps($nota->system_unit_id);
                    $nota->chave_acesso_rps = null;

                    if ($dpsDeOutraNota && $rpsPulados < self::MAX_RPS_PULADOS)
                    {
                        $nota->nota_fiscal_status_id = NotaFiscalStatus::REJEITADA;
                        $nota->mensagem_retorno_nfse = 'Nota rejeitada: ' . $motivo;
                        $nota->store();

                        TTransaction::close();

                        LogNf::adicionar($notaId, 'RPS', $motivo . " Reenviando automaticamente com o RPS {$nota->numero_rps}.");

                        return self::emitirNFSePorId($notaId, $rpsPulados + 1);
                    }

                    $mensagens[] = $motivo . " A nota recebeu o RPS {$nota->numero_rps}; clique em emitir novamente.";
                }

                $mensagemFinal =
                    'Nota rejeitada: '
                    . implode(' | ', $mensagens);

                $nota->mensagem_retorno_nfse = $mensagemFinal;
                $nota->nota_fiscal_status_id = NotaFiscalStatus::REJEITADA;
                $nota->store();

                TTransaction::close();

                throw new Exception($mensagemFinal);
            }

            $numeroNota = $retorno['numeroNota'] ?? null;

            if (empty($numeroNota))
            {
                error_log(
                    'RETORNO SEM NUMERO NOTA: '
                    . json_encode($retorno)
                );

                throw new Exception(
                    'API retornou sem numeroNota.'
                );
            }

            $nota->chave_acesso_nfse = preg_replace(
                '/\D/',
                '',
                $retorno['chaveNFSe'] ?? ''
            );

            $nota->chave_acesso_rps = preg_replace(
                '/\D/',
                '',
                $retorno['chaveDps'] ?? ''
            );

            $nota->nota_fiscal_status_id = NotaFiscalStatus::AUTORIZADA;
            $nota->data_emissao = $dataEmissao;
            $nota->numero = $numeroNota;
            $nota->mensagem_retorno_nfse = json_encode($retorno);
            $retorno['valores_atualizados'] = self::atualizarValoresAutorizados($nota, $retorno['xmlRetorno'] ?? null);
            $nota->store();

            self::registrarUltimoRps($nota);

            TTransaction::close();

            self::registrarValoresAutorizados($notaId, $retorno['valores_atualizados']);

            return $retorno;
        }
        catch (Exception $e)
        {
            error_log(
                'ERRO GERAL EMITIR NFSE: '
                . $e->getMessage()
            );

            if (TTransaction::get())
            {
                TTransaction::rollback();
            }

            throw $e;
        }
    }
        
    public static function emitirComEnvioEmail($param = null)
    {
        $notaId = $param['key'] ?? $param['id'] ?? null;

        if (empty($notaId))
        {
            throw new Exception('Nota não informada.');
        }

        $somenteEnvio =
            !empty($param['somente_envio'])
            && $param['somente_envio'] == 'T';

        if (!$somenteEnvio)
        {
            PermissaoService::verificarLimiteEmissaoNf();
        }

        if (
            empty($param['confirmar_emissao'])
            || $param['confirmar_emissao'] != 'T'
        )
        {
            self::exibirDialogoEmissao($param, $notaId);

            return [
                'emitida' => false,
                'dialogo' => true,
                'email_enviado' => false,
            ];
        }

        if (($param['canal_envio'] ?? 'email') === 'whatsapp')
        {
            return [
                'emitida' => false,
                'dialogo' => false,
                'email_enviado' => false,
            ];
        }

        $email = trim($param['email_tomador'] ?? '');

        if (empty($email))
        {
            throw new Exception(
                'Informe o e-mail para envio da NFS-e.'
            );
        }

        // =========================
        // SALVA EMAIL
        // =========================

        try
        {
            TTransaction::open('emissor');

            $nota = new NotaFiscalCabecalho($notaId);

            self::validarNotaParaEmissao($nota);

            $nota->email_tomador = $email;

            $nota->store();

            if (TTransaction::get())
            {
                TTransaction::close();
            }
        }
        catch (Exception $e)
        {
            if (TTransaction::get())
            {
                TTransaction::rollback();
            }

            throw $e;
        }

        // =========================
        // EMITE NFSE
        // =========================

        try
        {
            $retornoEmissao = self::emitirNFSePorId($notaId);
        }
        catch (Exception $e)
        {
            if (TTransaction::get())
            {
                TTransaction::rollback();
            }

            throw $e;
        }

        $valoresAtualizados = $retornoEmissao['valores_atualizados'] ?? [];

        // =========================
        // ENVIA EMAIL
        // =========================

        try
        {
            self::enviarEmailNFSe($notaId, $email);

            return [
                'emitida' => true,
                'dialogo' => false,
                'email_enviado' => true,
                'valores_atualizados' => $valoresAtualizados,
            ];
        }
        catch (Exception $e)
        {
            return [
                'emitida' => true,
                'dialogo' => false,
                'email_enviado' => false,
                'email_erro' => $e->getMessage(),
                'valores_atualizados' => $valoresAtualizados,
            ];
        }
    }

    public static function exibirMensagemEmissao(array $resultado, ?TAction $aoFechar = null)
    {
        if (!empty($resultado['dialogo']))
        {
            return;
        }

        if (empty($resultado['emitida']) && empty($resultado['email_erro']))
        {
            return;
        }

        $caixaValores = self::caixaValoresAtualizados($resultado['valores_atualizados'] ?? []);

        if (!empty($resultado['email_enviado']))
        {
            new TMessage(
                'info',
                'NFSe emitida e enviada por e-mail com sucesso.' . $caixaValores,
                $aoFechar
            );
            return;
        }

        if (!empty($resultado['email_erro']))
        {
            new TMessage(
                'error',
                'NFSe emitida com sucesso, porém não foi possível enviar o e-mail: '
                . $resultado['email_erro'] . $caixaValores,
                $aoFechar
            );
            return;
        }

        if (!empty($resultado['emitida']))
        {
            new TMessage('info', 'NFSe emitida com sucesso.' . $caixaValores, $aoFechar);
        }
    }

    private static function caixaValoresAtualizados(array $alterados): string
    {
        if (!$alterados)
        {
            return '';
        }

        $linhas = '';

        foreach ($alterados as $a)
        {
            $linhas .= '<tr>'
                . '<td style="padding:4px 8px">' . htmlspecialchars($a['campo']) . '</td>'
                . '<td style="padding:4px 8px;text-align:right">' . htmlspecialchars($a['antes']) . '</td>'
                . '<td style="padding:4px 8px;text-align:right"><b>' . htmlspecialchars($a['depois']) . '</b></td>'
                . '</tr>';
        }

        return '<div style="margin-top:12px;padding:10px;border:1px solid #f0ad4e;border-radius:6px;background:#fcf8e3;text-align:left">'
            . '<b>Valores atualizados automaticamente pelo retorno da NFS-e:</b>'
            . '<table style="width:100%;margin-top:6px;font-size:13px">'
            . '<tr><th style="padding:4px 8px">Campo</th><th style="padding:4px 8px;text-align:right">Na nota</th><th style="padding:4px 8px;text-align:right">Sefin</th></tr>'
            . $linhas
            . '</table></div>';
    }

    public static function enviarNFSePorEmail($param = null)
    {
        PermissaoService::verificarFuncionalidadePro('O envio da NFS-e por e-mail');

        $notaId = $param['key'] ?? $param['id'] ?? null;

        if (empty($notaId))
        {
            throw new Exception('Nota não informada.');
        }

        $param['somente_envio'] = 'T';

        if (
            empty($param['confirmar_emissao'])
            || $param['confirmar_emissao'] != 'T'
        )
        {
            self::exibirDialogoEmissao($param, $notaId);

            return [
                'emitida' => false,
                'dialogo' => true,
                'email_enviado' => false,
            ];
        }

        if (($param['canal_envio'] ?? 'email') === 'whatsapp')
        {
            return [
                'emitida' => false,
                'dialogo' => false,
                'email_enviado' => false,
            ];
        }

        $email = trim($param['email_tomador'] ?? '');

        if (empty($email))
        {
            throw new Exception(
                'Informe o e-mail para envio da NFS-e.'
            );
        }

        try
        {
            TTransaction::open('emissor');

            $nota = new NotaFiscalCabecalho($notaId);

            if (empty($nota->chave_acesso_nfse))
            {
                throw new Exception(
                    'Somente NFS-e autorizada pode ser enviada por e-mail.'
                );
            }

            $nota->email_tomador = $email;

            $nota->store();

            if (TTransaction::get())
            {
                TTransaction::close();
            }
        }
        catch (Exception $e)
        {
            if (TTransaction::get())
            {
                TTransaction::rollback();
            }

            throw $e;
        }

        try
        {
            self::enviarEmailNFSe($notaId, $email);

            return [
                'emitida' => false,
                'dialogo' => false,
                'email_enviado' => true,
            ];
        }
        catch (Exception $e)
        {
            return [
                'emitida' => false,
                'dialogo' => false,
                'email_enviado' => false,
                'email_erro' => $e->getMessage(),
            ];
        }
    }

    public static function exibirMensagemEnvio(array $resultado)
    {
        if (!empty($resultado['dialogo']))
        {
            return;
        }

        if (!empty($resultado['email_enviado']))
        {
            new TMessage(
                'info',
                'NFS-e enviada por e-mail com sucesso.'
            );
            return;
        }

        if (!empty($resultado['email_erro']))
        {
            new TMessage(
                'error',
                'Não foi possível enviar o e-mail: '
                . $resultado['email_erro']
            );
            return;
        }

        new TMessage(
            'warning',
            'O e-mail não foi enviado. Verifique o SMTP em Parâmetros Gerais.'
        );
    }

    public static function confirmarEnvioNFSe($param = null)
    {
        try
        {
            if (($param['canal_envio'] ?? '') === 'whatsapp')
            {
                TScript::create("
                    $('.modal').modal('hide');
                ");

                self::habilitarBotoesEmissao();

                new TMessage(
                    'info',
                    'O envio por WhatsApp estará disponível em breve. Utilize o e-mail por enquanto.'
                );

                return;
            }

            $resultado = self::enviarNFSePorEmail($param);

            if (!empty($resultado['dialogo']))
            {
                return;
            }

            self::processarResultadoEnvio($resultado, $param ?? []);
        }
        catch (Exception $e)
        {
            self::habilitarBotoesEmissao();

            new TMessage(
                'error',
                $e->getMessage()
            );
        }
    }

    private static function registrarFalhaEnvioEmail(array $param, array $resultado)
    {
        $notaId = $param['key'] ?? $param['id'] ?? null;

        if (empty($notaId))
        {
            return;
        }

        $detalhe = trim($resultado['email_erro'] ?? 'E-mail não enviado');

        try
        {
            LogNf::adicionar(
                $notaId,
                'E-MAIL',
                'Falha no envio: ' . $detalhe
            );
        }
        catch (Exception $e)
        {
            // ignora falha de log
        }
    }

    private static function fecharModalEmissao()
    {
        TScript::create("
            $('.modal').modal('hide');
        ");

        self::habilitarBotoesEmissao();
    }

    private static function agendarRecargaPagina(
        array $param,
        int $delayMs = 1500
    )
    {
        $reloadPage = $param['reload_page'] ?? null;
        $reloadMethod = $param['reload_method'] ?? 'onShow';
        $reloadParams = $param['reload_params'] ?? [];

        if (empty($reloadPage))
        {
            return;
        }

        $queryParams = array_merge(
            [
                'class' => $reloadPage,
                'method' => $reloadMethod,
            ],
            $reloadParams
        );

        $url = 'index.php?' . http_build_query($queryParams);

        TScript::create("
            setTimeout(function(){
                __adianti_load_page('{$url}');
            }, {$delayMs});
        ");
    }

    public static function processarResultadoEnvio(
        array $resultado,
        array $param = []
    )
    {
        self::fecharModalEmissao();
        self::exibirMensagemEnvio($resultado);

        if (!empty($resultado['email_enviado']))
        {
            self::agendarRecargaPagina($param, 2000);
            return;
        }

        self::registrarFalhaEnvioEmail($param, $resultado);
        self::agendarRecargaPagina($param, 4500);
    }

    private static function validarNotaParaEmissao(NotaFiscalCabecalho $nota)
    {
        if (
            (int) $nota->nota_fiscal_status_id !== (int) NotaFiscalStatus::PENDENTE
            && (int) $nota->nota_fiscal_status_id !== (int) NotaFiscalStatus::DIGITACAO
            && (int) $nota->nota_fiscal_status_id !== (int) NotaFiscalStatus::REJEITADA
        )
        {
            throw new Exception(
                'Somente notas pendentes ou em digitação podem ser emitidas.'
            );
        }

        if ((float) $nota->valor_servico <= 0)
        {
            throw new Exception('Valor da nota inválido para emissão');
        }

        $nota->validarTomadorQuandoIssRetido();
    }
        
    public static function exibirDialogoEmissao(array $param, $notaId)
    {
        $emailAtual = '';

        try
        {
            TTransaction::open('emissor');

            $nota = new NotaFiscalCabecalho($notaId);
            $emailAtual = trim($nota->email_tomador ?? '');

            TTransaction::close();
        }
        catch (Exception $e)
        {
            if (TTransaction::get())
            {
                TTransaction::rollback();
            }
        }

        $somenteEnvio =
            !empty($param['somente_envio'])
            && $param['somente_envio'] == 'T';

        $form = new BootstrapFormBuilder(
            'form_canal_nfse_emissao'
        );

        $form->setFormTitle(
            $somenteEnvio ? 'Enviar NFS-e' : 'Emitir NFS-e'
        );

        $form->setProperty('style', 'width:100%;margin:0');

        $instrucao = new TElement('p');

        $instrucao->add(
            $somenteEnvio
                ? 'Escolha como deseja enviar a NFS-e ao tomador e confirme o e-mail de destino.'
                : 'Escolha como deseja enviar a nota ao tomador e informe o e-mail, se necessário.'
        );

        $instrucao->style =
            'margin-bottom:15px;color:#555;font-size:14px;line-height:1.5';

        $canal_envio = new TCombo('canal_envio');

        $canal_envio->addItems([
            'email' => 'E-mail',
            'whatsapp' => 'WhatsApp',
        ]);

        $canal_envio->setValue('email');
        $canal_envio->setSize('100%');

        $email = new TEntry('email_tomador');

        $email->setSize('100%');
        $email->placeholder = 'exemplo@email.com';

        if (!empty($emailAtual))
        {
            $email->setValue($emailAtual);
        }

        $form->addContent([$instrucao]);

        $row1 = $form->addFields(
            [new TLabel('Enviar por')],
            [$canal_envio]
        );

        $row1->layout = ['col-sm-12'];

        $row2 = $form->addFields(
            [new TLabel('E-mail do tomador')],
            [$email]
        );

        $row2->layout = ['col-sm-12'];

        $paramSubmit = $param;

        $paramSubmit['key'] = $notaId;
        $paramSubmit['confirmar_emissao'] = 'T';

        if ($somenteEnvio)
        {
            $paramSubmit['somente_envio'] = 'T';
        }

        $actionClass =
            $param['reload_page']
            ?? 'NotaFiscalCabecalhoList';

        $actionMethod = $somenteEnvio
            ? 'onConfirmarEnvioNFSe'
            : 'onConfirmarEmissaoNFSe';

        if (!is_callable([$actionClass, $actionMethod]))
        {
            $actionClass = 'NotaFiscalCabecalhoList';
        }

        $form->addAction($somenteEnvio? 'Enviar': 'Emitir e enviar',

            new TAction( [$actionClass, $actionMethod], $paramSubmit ),

            $somenteEnvio ? 'fas:envelope #ffffff' : 'fas:paper-plane #ffffff'
        );

        new TInputDialog(
            $somenteEnvio
                ? 'Enviar NFS-e'
                : 'Emitir NFS-e',

            $form
        );

        TScript::create("
            setTimeout(function(){

                $('.modal-dialog').css({
                    'width': '95%',
                    'max-width': '600px',
                    'margin': '10px auto'
                });

                $('.modal-content').css({
                    'border-radius': '10px',
                    'overflow': 'hidden'
                });

                $('.modal-header').css({
                    'padding': '15px 20px'
                });

                $('.modal-title').css({
                    'font-size': '20px',
                    'font-weight': '600'
                });

                $('.modal-body').css({
                    'padding': '20px'
                });

                $('.tform').css({
                    'width': '100%',
                    'margin': '0'
                });

                $('.form-group').css({
                    'width': '100%',
                    'display': 'block',
                    'margin-bottom': '15px'
                });

                $('.control-label').css({
                    'display': 'block',
                    'width': '100%',
                    'margin-bottom': '6px',
                    'font-weight': '600'
                });

                $('.form-control').css({
                    'width': '100%',
                    'min-height': '42px',
                    'font-size': '14px'
                });

                $('.select2-container').css({
                    'width': '100%'
                });

                $('.modal-footer').css({
                    'padding': '15px 20px',
                    'display': 'block'
                });

                $('.modal-footer .btn').css({
                    'width': '100%',
                    'min-height': '45px',
                    'font-size': '15px',
                    'font-weight': '600',
                    'margin': '0'
                });

                // MOBILE
                if(window.innerWidth < 768){

                    $('.modal-dialog').css({
                        'width': '98%',
                        'margin': '5px auto'
                    });

                    $('.modal-body').css({
                        'padding': '15px'
                    });

                    $('.modal-header').css({
                        'padding': '12px 15px'
                    });

                    $('.modal-title').css({
                        'font-size': '18px'
                    });

                    $('.form-control').css({
                        'font-size': '16px'
                    });

                    $('.modal-footer').css({
                        'padding': '15px'
                    });
                }

            }, 100);
        ");

        self::habilitarBotoesEmissao();
    }
        
    public static function onConfirmarEmissaoNFSe($param = null)
    {
        $param['reload_page'] =
            $param['reload_page'] ?? __CLASS__;

        $param['reload_method'] =
            $param['reload_method'] ?? 'onShow';

        $param['reload_params'] =
            $param['reload_params']
            ?? ['key' => $param['key'] ?? null];

        self::confirmarCanalEnvioEmissao($param);
    }

    public static function confirmarCanalEnvioEmissao($param = null)
    {
        if (($param['canal_envio'] ?? '') === 'whatsapp')
        {
            TScript::create("
                $('.modal').modal('hide');
            ");

            self::habilitarBotoesEmissao();

            new TMessage(
                'info',
                'O envio por WhatsApp estará disponível em breve.'
            );

            return;
        }

        $resultado =
            self::emitirComEnvioEmail($param);

        if (!empty($resultado['dialogo']))
        {
            return;
        }

        self::processarResultadoEmissao(
            $resultado,
            $param ?? []
        );
    }

    private static function habilitarBotoesEmissao()
    {
        TScript::create("
            setTimeout(function(){
                $('button').prop('disabled', false);
                $('.btn').prop('disabled', false);
            }, 300);
        ");
    }

    public static function processarResultadoEmissao(
        array $resultado,
        array $param = []
    )
    {
        self::fecharModalEmissao();

        if (empty($resultado['emitida']))
        {
            self::exibirMensagemEmissao($resultado);
            return;
        }

        if (!empty($resultado['email_erro']))
        {
            self::registrarFalhaEnvioEmail($param, $resultado);
        }

        $notaId = $param['key'] ?? $param['id'] ?? null;

        // depois de emitir, vai para o resumo da nota (com DANFSe, XML e agendamento)
        if (!empty($notaId))
        {
            $param['reload_page'] = 'ResumoNotaForm';
            $param['reload_method'] = 'onShow';
            $param['reload_params'] = ['key' => $notaId, 'baixar_danfe' => 1];
        }

        // recarregar com timer fecharia a caixa antes de o usuário ler os valores alterados
        if (!empty($resultado['valores_atualizados']))
        {
            self::exibirMensagemEmissao($resultado, self::acaoRecargaPagina($param));
            return;
        }

        self::exibirMensagemEmissao($resultado);
        self::agendarRecargaPagina($param, !empty($resultado['email_enviado']) ? 1800 : 4500);
    }

    /**
     * Baixa o DANFSe (PDF) da nota emitida e abre no navegador.
     */
    public static function baixarDanfeNota($notaId, bool $automatico = false): void
    {
        TTransaction::open('emissor');

        try
        {
            $nota = new NotaFiscalCabecalho($notaId);

            if ((int) $nota->system_unit_id !== (int) TSession::getValue('userunitid'))
            {
                throw new Exception('Nota não encontrada.');
            }

            $empresa = Empresa::where('system_unit_id', '=', $nota->system_unit_id)->first();
            $cnpj = (string) self::somenteDigitos($empresa->cnpj ?? null);
            $chave = (string) self::somenteDigitos($nota->chave_acesso_nfse);
            $numero = $nota->numero ?: $nota->id_key_empresa;
            $cancelada = $nota->nota_fiscal_status_id == NotaFiscalStatus::CANCELADA;

            TTransaction::close();
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            throw $e;
        }

        if ($chave === '')
        {
            throw new Exception('A NFS-e ainda não foi emitida (sem chave de acesso).');
        }

        if ($cnpj === '')
        {
            throw new Exception('Empresa da unidade não encontrada. Verifique em Configurações > Dados da Empresa.');
        }

        if (!file_exists('tmp'))
        {
            mkdir('tmp', 0777, true);
        }

        if ($cancelada)
        {
            self::consultarEventoCancelamento($notaId);
        }

        $arquivo = 'tmp/danfse_' . (int) $notaId . '.pdf';
        file_put_contents($arquivo, self::baixarDANFEBinario($cnpj, $chave, $cancelada));

        $nome = $cancelada ? "NFSe_{$numero}_CANCELADA.pdf" : "NFSe_{$numero}.pdf";

        if (!$automatico)
        {
            TPage::openFile($arquivo, $nome);
            return;
        }

        // sem clique do usuário o navegador bloqueia window.open; o link com download não é bloqueado
        $url = json_encode('download.php?file=' . rawurlencode($arquivo) . '&basename=' . rawurlencode($nome));
        $nomeJs = json_encode($nome);

        TScript::create("
            (function(){
                var link = document.createElement('a');
                link.href = {$url};
                link.download = {$nomeJs};
                document.body.appendChild(link);
                link.click();
                link.remove();
            })();
        ");
    }

    private static function acaoRecargaPagina(array $param): ?TAction
    {
        $reloadPage = $param['reload_page'] ?? null;
        $reloadMethod = $param['reload_method'] ?? 'onShow';

        if (empty($reloadPage) || !class_exists($reloadPage) || !method_exists($reloadPage, $reloadMethod))
        {
            return null;
        }

        return new TAction([$reloadPage, $reloadMethod], $param['reload_params'] ?? []);
    }

    public static function confirmarEmissaoComEmail($param = null)
    {
        self::confirmarCanalEnvioEmissao($param);
    }

    private static function somenteDigitos($valor): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $valor);

        return $digitos !== '' ? $digitos : null;
    }

    private static function textoOuNulo($valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto !== '' ? $texto : null;
    }

    const OP_SIMPNAC_NAO_OPTANTE = 1;
    const OP_SIMPNAC_MEI = 2;
    const OP_SIMPNAC_ME_EPP = 3;

    /**
     * regime_tributario da empresa → opSimpNac da DPS (E0160 se divergir do cadastro do Simples).
     * 1 Simples Nacional / 2 Simples com excesso de sublimite → ME/EPP; 3 Regime Normal → não optante; 4 MEI.
     */
    private static function opcaoSimplesNacional($regimeTributarioId): ?int
    {
        switch ((int) $regimeTributarioId)
        {
            case 1:
            case 2:
                return self::OP_SIMPNAC_ME_EPP;
            case 3:
                return self::OP_SIMPNAC_NAO_OPTANTE;
            case 4:
                return self::OP_SIMPNAC_MEI;
            default:
                return null;
        }
    }

    private static function inscricaoMunicipal($valor): ?string
    {
        $im = preg_replace('/[^0-9A-Za-z]/', '', (string) $valor);

        if ($im === '') {
            return null;
        }

        // O CNC NFS-e localiza o registro pela IM com 15 posições; sem os zeros à esquerda retorna E0116
        if (ctype_digit($im) && strlen($im) < 15) {
            $im = str_pad($im, 15, '0', STR_PAD_LEFT);
        }

        return $im;
    }

    private static function valorNota($valor): float
    {
        return round((float) $valor, 2);
    }

    /**
     * Tributos aproximados (Lei 12.741). ME/EPP: pTotTribSN; demais: pTotTribFed/Est/Mun.
     */
    private static function montarTotTrib($nota, bool $optanteMeEpp): array
    {
        $fed = self::valorNota($nota->aliq_trib_fed);
        $est = self::valorNota($nota->aliq_trib_est);
        $mun = self::valorNota($nota->aliq_trib_mun);
        $total = round($fed + $est + $mun, 2);

        if ($optanteMeEpp)
        {
            return ["indTotTrib" => 1, "pTotTribSN" => $total > 0 ? $total : 1.00];
        }

        if ($total > 0)
        {
            return [
                "pTotTribFed" => $fed,
                "pTotTribEst" => $est,
                "pTotTribMun" => $mun
            ];
        }

        return ["indTotTrib" => 0];
    }

    const CST_IBSCBS_DIFERIMENTO = '510';

    /**
     * Grupo IBS/CBS da DPS (Reforma Tributária; E0855: obrigatório com competência a partir de 01/10/2026).
     * A classificação vem da natureza fiscal; os valores de IBS/CBS são calculados pela Sefin Nacional.
     * O CST são os 3 primeiros dígitos do cClassTrib.
     */
    private static function montarIbsCbs($nota): array
    {
        $cClassTrib = (string) self::somenteDigitos($nota->cclasstrib);
        $cIndOp = (string) self::somenteDigitos($nota->indop);
        $nbs = (string) self::somenteDigitos($nota->nbs);

        // A classificação é obrigatória neste emissor; não omitir em notas antigas.
        if ($cClassTrib === '' && $cIndOp === '' && $nbs === '')
        {
            throw new Exception('Informe IndOp, cClassTrib e NBS na natureza fiscal/nota. O emissor não envia NFS-e sem os dados de IBS/CBS.');
        }

        if (strlen($cClassTrib) !== 6 || strlen($cIndOp) !== 6 || strlen($nbs) !== 9)
        {
            throw new Exception(
                'Para enviar IBS/CBS (Reforma Tributária) a Natureza Fiscal do serviço (ou o Detalhe Fiscal da nota) precisa do Indop e do Cclasstrib com 6 dígitos cada e do Nbs com 9 dígitos. '
                . 'Na nota estão: Indop "' . $nota->indop . '", Cclasstrib "' . $nota->cclasstrib . '", Nbs "' . $nota->nbs . '".'
            );
        }

        $documentoTomador = (int) $nota->tipo_tomador_id !== 1
            ? (string) self::somenteDigitos($nota->cpf_cnpj_tomador)
            : '';

        $cst = substr($cClassTrib, 0, 3);

        $dados = [
            "IBSCBSDPS" => [
                "finNFSe" => 0,
                "indFinal" => strlen($documentoTomador) === 14 ? 0 : 1,
                "cIndOp" => $cIndOp,
                "indDest" => 0
            ],
            "gIBSCBS" => [
                "CST" => $cst,
                "cClassTrib" => $cClassTrib
            ]
        ];

        $pDif = self::valorNota($nota->aliq_difer);

        if ($cst === self::CST_IBSCBS_DIFERIMENTO && $nota->aliq_difer !== null && $nota->aliq_difer !== '')
        {
            $dados["gDif"] = [
                "pDifUF" => $pDif,
                "pDifMun" => $pDif,
                "pDifCBS" => $pDif
            ];
        }

        return $dados;
    }

    // tipo_retencao_piscofinsircsll.id → tpRetPisCofins da DPS é sempre id - 1 (0 a 9)
    // E0724: fora de 0 (nada retido) e 2 (PIS/COFINS não retidos) a Sefin exige vRetCSLL
    const TP_RET_PIS_COFINS_SEM_CSLL = [0, 2];
    const CST_PIS_COFINS_SEM_VALORES = ['00', '08', '09'];
    const CST_PIS_COFINS_ALIQUOTA_ZERO = ['04', '06'];

    private static function montarTribFederal($nota, float $baseCalculo): ?array
    {
        $trib = [];

        $tipoRetencao = (int) $nota->tipo_retencao_piscofinsircsll_id;

        $cst = null;

        if (!empty($nota->cst_pis_cofins_id))
        {
            $cstPisCofins = CstPisCofins::find($nota->cst_pis_cofins_id);
            $cst = $cstPisCofins ? self::textoOuNulo($cstPisCofins->codigo) : null;
        }

        if ($cst)
        {
            $aliqPis = self::valorNota($nota->aliq_pis);
            $aliqCofins = self::valorNota($nota->aliq_cofins);

            if (in_array($cst, self::CST_PIS_COFINS_SEM_VALORES, true))
            {
                $trib["CST"] = $cst;
            }
            elseif ($aliqPis > 0 || $aliqCofins > 0 || in_array($cst, self::CST_PIS_COFINS_ALIQUOTA_ZERO, true))
            {
                $baseCalculo = round($baseCalculo, 2);

                // A Sefin rejeita (E0694/E0695) se vPis/vCofins diferir de vBCPisCofins x alíquota
                $trib["CST"] = $cst;
                $trib["vBCPisCofins"] = $baseCalculo;
                $trib["pAliqPis"] = $aliqPis;
                $trib["pAliqCofins"] = $aliqCofins;
                $trib["vPis"] = round($baseCalculo * $aliqPis / 100, 2);
                $trib["vCofins"] = round($baseCalculo * $aliqCofins / 100, 2);
            }

            if (isset($trib["CST"]) && $tipoRetencao >= 1 && $tipoRetencao <= 10)
            {
                $trib["tpRetPisCofins"] = $tipoRetencao - 1;
            }
        }

        $valorIrrf = self::valorNota($nota->valor_irrf);
        $valorCsll = self::valorNota($nota->valor_csll);

        if ($valorIrrf > 0)
        {
            $trib["vRetIRRF"] = $valorIrrf;
        }

        $exigeCsll = isset($trib["tpRetPisCofins"])
            && !in_array($trib["tpRetPisCofins"], self::TP_RET_PIS_COFINS_SEM_CSLL, true);

        if ($exigeCsll && $valorCsll <= 0)
        {
            $tipo = TipoRetencaoPiscofinsircsll::find($tipoRetencao);

            throw new Exception(
                'Com a retenção "' . ($tipo->descricao ?? $tipoRetencao) . '" a Sefin exige o valor da CSLL retida (E0724). '
                . 'Informe a alíquota/valor da CSLL na nota ou na Natureza Fiscal, ou, se nada for retido, use "PIS, COFINS e CSLL não retidos" ou "PIS e COFINS não retidos".'
            );
        }

        if ($exigeCsll)
        {
            $trib["vRetCSLL"] = $valorCsll;
        }

        return $trib ?: null;
    }

    // campo da nota => [tag em infNFSe/valores, rótulo, é percentual]
    const VALORES_AUTORIZADOS = [
        'aliq_iss' => ['pAliqAplic', 'Alíquota do ISS', true],
        'valor_iss' => ['vISSQN', 'Valor do ISS', false],
        'valor_liquido' => ['vLiq', 'Valor líquido', false],
    ];

    /**
     * Grava na nota o ISS e o líquido calculados pela Sefin (podem divergir da tela, ex.: alíquota do município).
     * Retorna o que mudou: [['campo' => rótulo, 'antes' => texto, 'depois' => texto], ...].
     */
    private static function atualizarValoresAutorizados($nota, ?string $xml): array
    {
        if (!$xml)
        {
            return [];
        }

        $anterior = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_use_internal_errors($anterior);

        if (!$doc)
        {
            return [];
        }

        $doc->registerXPathNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');

        $alterados = [];

        foreach (self::VALORES_AUTORIZADOS as $campo => [$tag, $rotulo, $percentual])
        {
            $nos = $doc->xpath("//n:infNFSe/n:valores/n:{$tag}");

            if (!$nos)
            {
                continue;
            }

            $novo = round((float) $nos[0], 2);
            $atual = $nota->$campo;
            $vazio = $atual === null || $atual === '';

            if (abs(round((float) $atual, 2) - $novo) >= 0.01)
            {
                $alterados[] = [
                    'campo' => $rotulo,
                    'antes' => $vazio ? '(vazio)' : self::formatarValorAutorizado((float) $atual, $percentual),
                    'depois' => self::formatarValorAutorizado($novo, $percentual),
                ];
            }

            $nota->$campo = $novo;
        }

        return $alterados;
    }

    private static function formatarValorAutorizado(float $valor, bool $percentual): string
    {
        $texto = number_format($valor, 2, ',', '.');

        return $percentual ? $texto . '%' : 'R$ ' . $texto;
    }

    private static function registrarValoresAutorizados($notaId, array $alterados): void
    {
        if (!$alterados)
        {
            return;
        }

        LogNf::adicionar(
            $notaId,
            'VALORES SEFIN',
            'Valores atualizados automaticamente pelo retorno da NFS-e: ' . implode('; ', array_map(
                fn ($a) => "{$a['campo']} de {$a['antes']} para {$a['depois']}",
                $alterados
            ))
        );
    }

    /**
     * Valida o cadastro antes de enviar: o schema nacional rejeita (X800) CEP fora de 8 dígitos
     * e logradouro/número/bairro vazios; o CNC do município exige a IM do prestador (E0116).
     */
    private static function validarCadastrosEmissao($nota, $empresa, $pessoaTomador): void
    {
        if ((string) $nota->tipo_retencao_id === TipoRetencao::RETIDO_INTERMEDIARIO)
        {
            throw new Exception(
                'O tipo de retenção do ISS "Retido pelo Intermediário" exige os dados do intermediário, '
                . 'que o emissor não informa na NFS-e. Edite a nota e escolha "Retido pelo Tomador" ou "Não Retido".'
            );
        }

        $nota->validarTomadorQuandoIssRetido();

        $faltandoEmpresa = [];

        if (!self::inscricaoMunicipal($empresa->inscricao_municipal))
        {
            $faltandoEmpresa[] = 'Inscrição Municipal (a mesma cadastrada na prefeitura)';
        }

        if (self::opcaoSimplesNacional($empresa->regime_tributario_id) === null)
        {
            $faltandoEmpresa[] = 'Regime tributário (Simples Nacional, MEI ou Regime Normal, igual ao cadastro na Receita)';
        }

        $faltandoEmpresa = array_merge($faltandoEmpresa, self::camposEnderecoFaltando(
            $empresa->cep,
            $empresa->logradouro,
            $empresa->numero,
            $empresa->bairro
        ));

        $faltandoTomador = [];

        // Endereço do tomador é opcional; se começou a preencher, tem que estar completo
        if ((int) $nota->tipo_tomador_id === (int) TipoTomador::BRASIL && $pessoaTomador)
        {
            $temAlgumDado = self::textoOuNulo($pessoaTomador->cep)
                || self::textoOuNulo($pessoaTomador->logradouro)
                || self::textoOuNulo($pessoaTomador->numero)
                || self::textoOuNulo($pessoaTomador->bairro);

            if ($temAlgumDado)
            {
                $faltandoTomador = self::camposEnderecoFaltando(
                    self::cepTomador($pessoaTomador, $empresa),
                    $pessoaTomador->logradouro,
                    $pessoaTomador->numero,
                    $pessoaTomador->bairro
                );
            }
        }

        if (!$faltandoEmpresa && !$faltandoTomador)
        {
            return;
        }

        $mensagem = '<b>Complete os dados abaixo antes de emitir a NFS-e:</b><br><br>';

        if ($faltandoEmpresa)
        {
            $mensagem .= '<b>Sua empresa</b> (menu Configurações &gt; Dados da Empresa):<br>- '
                . implode('<br>- ', $faltandoEmpresa) . '<br><br>';
        }

        if ($faltandoTomador)
        {
            $nomeTomador = htmlspecialchars((string) ($pessoaTomador->razao_social ?: $nota->nome_tomador));
            $mensagem .= "<b>Cliente {$nomeTomador}</b> (menu Cadastros &gt; Clientes):<br>- "
                . implode('<br>- ', $faltandoTomador)
                . '<br><i>Ou deixe o endereço do cliente totalmente em branco.</i><br><br>';
        }

        $mensagem .= 'Depois de salvar o cadastro, emita a nota novamente.';

        throw new Exception($mensagem);
    }

    /**
     * CEP do cliente; sem CEP no cadastro, usa o da empresa.
     */
    private static function cepTomador($pessoaTomador, $empresa): string
    {
        $cep = (string) self::somenteDigitos($pessoaTomador->cep ?? null);

        return $cep;
    }

    private static function camposEnderecoFaltando($cep, $logradouro, $numero, $bairro): array
    {
        $faltando = [];
        $cepDigitos = (string) self::somenteDigitos($cep);

        if ($cepDigitos === '')
        {
            $faltando[] = 'CEP';
        }
        elseif (strlen($cepDigitos) !== 8)
        {
            $faltando[] = 'CEP válido (8 números; informado: ' . htmlspecialchars((string) $cep) . ')';
        }

        if (!self::textoOuNulo($logradouro))
        {
            $faltando[] = 'Logradouro (rua/avenida)';
        }

        if (!self::textoOuNulo($numero))
        {
            $faltando[] = 'Número (use S/N se não houver)';
        }

        if (!self::textoOuNulo($bairro))
        {
            $faltando[] = 'Bairro';
        }

        return $faltando;
    }

    public static function montarPayload($nota, $empresa)
    {
        $itens = NotaFiscalDetalhe::where(
            'nota_fiscal_cabecalho_id',
            '=',
            $nota->id
        )->load();

        if (!$itens)
        {
            throw new Exception(
                'Nenhum item encontrado na nota.'
            );
        }

        $cnpjEmpresa = preg_replace(
            '/\D/',
            '',
            $empresa->cnpj
        );

        // Dados de município/UF do prestador (Empresa)
        $ufPrestador = null;

        if (!empty($empresa->estado_id))
        {
            $estadoPrestador = new Estado($empresa->estado_id);
            $ufPrestador = $estadoPrestador->uf ?? null;
        }

        $localizacao = NfseApiConfiguracao::localizacao($empresa);
        $codigoMunicipioPrestador = $localizacao['municipio'];
        $municipioPrestador = $localizacao['cidade'];
        $ufPrestador = $localizacao['uf'];

        // Tipo de retenção do ISS definido na nota
        // (TipoRetencao: 1=Não retido, 2=Retido tomador, 3=Retido intermediário)
        $tpRetISSQN = (int) ($nota->tipo_retencao_id ?: TipoRetencao::NAO_RETIDO);

        // Dados do tomador a partir do cadastro de Pessoa vinculado à nota
        $pessoaTomador = null;
        $codigoMunicipioTomador = $codigoMunicipioPrestador;
        $municipioTomador = $municipioPrestador;
        $ufTomador = $ufPrestador;

        if (!empty($nota->pessoa_id))
        {
            $pessoaTomador = new Pessoa($nota->pessoa_id);

            if (!empty($pessoaTomador->estado_id))
            {
                $estadoTomador = new Estado($pessoaTomador->estado_id);
                $ufTomador = $estadoTomador->uf ?: $ufTomador;

                if (!empty($pessoaTomador->cidade))
                {
                    $municipioTomador = $pessoaTomador->cidade;

                    $cidadeTomador = Cidade::where('estado_id', '=', $pessoaTomador->estado_id)
                        ->where('nome', '=', $pessoaTomador->cidade)
                        ->first();

                    if ($cidadeTomador && !empty($cidadeTomador->codigo_ibge))
                    {
                        $codigoMunicipioTomador = $cidadeTomador->codigo_ibge;
                    }
                }
            }
        }

        self::validarCadastrosEmissao($nota, $empresa, $pessoaTomador);
        if((int)$nota->tipo_tomador_id===(int)TipoTomador::BRASIL && $pessoaTomador){
            $localTomador=NfseApiConfiguracao::localizacao($pessoaTomador);
            $codigoMunicipioTomador=$localTomador['municipio'];$municipioTomador=$localTomador['cidade'];$ufTomador=$localTomador['uf'];
        }

        $opSimpNac = self::opcaoSimplesNacional($empresa->regime_tributario_id);
        $optanteMeEpp = $opSimpNac === self::OP_SIMPNAC_ME_EPP;

        // valor_servico já vem líquido do desconto; a DPS espera o bruto + vDescIncond
        $valorDesconto = self::valorNota($nota->valor_desconto);
        $valorBruto = round(self::valorNota($nota->valor_servico) + $valorDesconto, 2);

        $dados = [

            "cnpj" => $cnpjEmpresa,

            "lote" => "1",

            "modoEnvio" => 0,

            "identificacaoNFSe" => [
                "tipoXML" => "RPS"
            ],

            "identificacaoRps" => [
                "numero" => (string) $nota->numero_rps,
                "serie" => (string) $nota->serie,
                "dataEmissao" => date(
                    'd/m/Y',
                    $nota->data_emissao ? strtotime($nota->data_emissao) : time()
                ),
                "competencia" => date(
                    'd/m/Y',
                    strtotime($nota->data_competencia)
                ),
                "verAplic" => "ACBrNFSeX",
                "tpEmit" => 1,
                "cLocEmi" => $codigoMunicipioPrestador
            ],

            "prestador" => [
                "CNPJ" => $cnpjEmpresa,
                "inscricaoMunicipal" => self::inscricaoMunicipal($empresa->inscricao_municipal),
                "razaoSocial" => $empresa->razao_social,
                "codigoMunicipio" => $codigoMunicipioPrestador,
                "cep" => self::somenteDigitos($empresa->cep),
                "codigoPais" => "1058",
                "xMunicipio" => $municipioPrestador,
                "uf" => $ufPrestador,
                "logradouro" => self::textoOuNulo($empresa->logradouro),
                "numero" => self::textoOuNulo($empresa->numero),
                "bairro" => self::textoOuNulo($empresa->bairro),
                "telefone" => $empresa->celular,
                "email" => $empresa->email,
                "opSimpNac" => $opSimpNac,
                "RegimeApuracaoSn" => $optanteMeEpp
                    ? ((int) $empresa->regime_apuracao_sn_id ?: 1)
                    : null,
                "regime" => 0
            ],

            "servico" => [
                "codigoMunicipio" => $codigoMunicipioPrestador,
                "codigoPais" => "1058",
                "itemListaServico" => $itens[0]->codigo_servico,
                "codigoTributacaoMunicipio" => "100",
                "discriminacao" => $itens[0]->descricao_servico ?? "NÃO INFORMADO",
                "codigoInterContr" => ""
            ],

            "valores" => [
                "valorServicos" => $valorBruto,
                "descontoIncondicionado" => $valorDesconto > 0 ? $valorDesconto : null
            ],

            "tribMun" => [
                "tribISSQN" => 1,
                "tpRetISSQN" => $tpRetISSQN
            ],

            "totTrib" => self::montarTotTrib($nota, $optanteMeEpp)
        ];

        $tipoSuspensao = (int) ($empresa->id_tipo_suspensao_exigibilidade ?: TipoSuspensaoExigibilidade::NENHUM);
        $processoSuspensao = preg_replace('/\D/', '', (string) $empresa->numero_processo_exibilidade);

        if ($tipoSuspensao > TipoSuspensaoExigibilidade::NENHUM)
        {
            if ($processoSuspensao === '' || strlen($processoSuspensao) > 30)
            {
                throw new Exception('O número do processo da suspensão da exigibilidade do ISS deve ter de 1 a 30 dígitos. Corrija em Configurações > Empresa.');
            }

            $processoSuspensao = str_pad($processoSuspensao, 30, '0', STR_PAD_LEFT);

            // TtpSusp do ACBr: 0=tsNenhum, 1=tsDecisaoJudicial, 2=tsProcessoAdm (mesmos códigos da DPS)
            $dados["tribMun"]["tpSusp"] = $tipoSuspensao - 1;
            $dados["tribMun"]["nProcesso"] = $processoSuspensao;
        }

        $observacao = self::textoOuNulo($nota->observacao_nota);

        if ($observacao)
        {
            $dados["informacoesComplementares"] = [
                "xInfComp" => mb_substr($observacao, 0, 2000)
            ];
        }

        $tribFederal = self::montarTribFederal($nota, $valorBruto - $valorDesconto);

        if ($tribFederal)
        {
            $dados["tribFederal"] = $tribFederal;
        }

        $ibsCbs = self::montarIbsCbs($nota);

        if ($ibsCbs)
        {
            $dados += $ibsCbs;
            $dados["servico"]["codigoNBS"] = self::somenteDigitos($nota->nbs);
        }

        // ==========================
        // TOMADOR (SÓ SE != 1)
        // ==========================

        if ((int)$nota->tipo_tomador_id !== 1)
        {
            $dados["tomador"] = [
                "CNPJCPF" => preg_replace(
                    '/\D/',
                    '',
                    $nota->cpf_cnpj_tomador
                ),
                "razaoSocial" => $nota->nome_tomador,
                "codigoMunicipio" => $codigoMunicipioTomador,
                "cep" => self::cepTomador($pessoaTomador, $empresa),
                "codigoPais" => "1058",
                "xMunicipio" => $municipioTomador,
                "uf" => $ufTomador,
                "logradouro" => self::textoOuNulo($pessoaTomador->logradouro ?? null),
                "numero" => self::textoOuNulo($pessoaTomador->numero ?? null),
                "bairro" => self::textoOuNulo($pessoaTomador->bairro ?? null),
                "telefone" => $nota->telefone_tomador,
                "email" => $nota->email_tomador
            ];
        }

        // ==========================
        // INTERMEDIADOR (SÓ SE != 1)
        // ==========================

        if ((int)$nota->tipo_intermediador_id !== 1)
        {
            $dados["intermediador"] = [
                "CNPJCPF" => preg_replace(
                    '/\D/',
                    '',
                    $nota->cnpj_intermediador ?? ''
                ),
                "razaoSocial" =>
                    $nota->razao_social_intermediador ?? '',
                "codigoMunicipio" => $codigoMunicipioPrestador,
                "uf" => $ufPrestador
            ];
        }

        $codigoMunicipal=trim((string)$itens[0]->codigo_tributacao_municipal);
        if($codigoMunicipal!==''){
            if(!preg_match('/^\d{3}$/',$codigoMunicipal)){throw new Exception('Código de tributação municipal do item deve ter 3 dígitos.');}
            $dados['_codigo_tributacao_municipal']=$codigoMunicipal;
        }
        $dados['_aliquota_iss'] = self::valorNota($nota->aliq_iss);
        $payload = NfseApiPayload::converter($dados);
        $payload['pedido_id'] = 'mad-' . (int) $nota->system_unit_id . '-' . (int) $nota->id;
        $payload['_cnpj'] = $cnpjEmpresa; // Metadado local removido pelo cliente HTTP.
        return $payload;
    }

    private static function extrairPdfBase64Api(array $retorno): ?string
    {
        if (!empty($retorno['erro']))
        {
            throw new Exception((string) $retorno['erro']);
        }

        if (
            isset($retorno['sucesso'])
            && $retorno['sucesso'] !== true
            && empty($retorno['pdfBase64'])
        )
        {
            throw new Exception(
                $retorno['mensagem']
                ?? $retorno['erro']
                ?? 'Erro ao obter PDF da NFS-e.'
            );
        }

        $pdfBase64 = $retorno['pdfBase64']
            ?? ($retorno['retorno']['pdfBase64'] ?? null);

        return !empty($pdfBase64) ? (string) $pdfBase64 : null;
    }

    private static function extrairXmlDaConsultaNFSe(array $retorno): ?string
    {
        if (empty($retorno))
        {
            throw new Exception('Resposta vazia ao consultar NFS-e.');
        }

        if (isset($retorno['sucesso']) && empty($retorno['sucesso']))
        {
            throw new Exception(
                $retorno['erro']
                ?? $retorno['mensagem']
                ?? 'Erro ao consultar NFS-e por chave.'
            );
        }

        $dados = $retorno['retorno'] ?? [];

        foreach (['xmlBase64', 'xml'] as $campo)
        {
            if (!empty($retorno[$campo]))
            {
                return self::decodificarConteudoBase64OuTexto(
                    (string) $retorno[$campo]
                );
            }

            if (!empty($dados[$campo]))
            {
                return self::decodificarConteudoBase64OuTexto(
                    (string) $dados[$campo]
                );
            }
        }

        $xml = $dados['xmlRetorno']
            ?? $retorno['xmlRetorno']
            ?? $retorno['xml']
            ?? null;

        if (!empty($xml))
        {
            return trim((string) $xml);
        }

        return null;
    }

    private static function decodificarConteudoBase64OuTexto(string $conteudo): string
    {
        $conteudo = trim($conteudo);

        if ($conteudo === '')
        {
            return '';
        }

        $decodificado = base64_decode($conteudo, true);

        if (
            $decodificado !== false
            && $decodificado !== ''
            && strncmp($decodificado, '<?xml', 5) === 0
        )
        {
            return $decodificado;
        }

        if (strncmp($conteudo, '<?xml', 5) === 0 || strncmp($conteudo, '<', 1) === 0)
        {
            return $conteudo;
        }

        if ($decodificado !== false && $decodificado !== '')
        {
            return $decodificado;
        }

        return $conteudo;
    }

    private static function codificarAnexoBase64(string $conteudo): string
    {
        $conteudo = trim($conteudo);
        $decodificado = base64_decode($conteudo, true);

        if (
            $decodificado !== false
            && $decodificado !== ''
            && (strncmp($decodificado, '<?xml', 5) === 0 || strncmp($decodificado, '<', 1) === 0)
        )
        {
            return preg_replace('/\s+/', '', $conteudo);
        }

        return base64_encode($conteudo);
    }

    /**
     * Consulta a NFS-e na API (XML) e gera o DANFE em PDF (base64).
     *
     * @return array{pdfBase64: string, xmlBase64: string}
     */
    public static function obterAnexosNFSePorChave(
        string $cnpj,
        string $chave,
        int $tentativasPdf = 5,
        int $intervaloSegundos = 2,
        bool $cancelada = false
    ): array
    {
        $cnpj = preg_replace('/\D/', '', $cnpj);
        $chave = preg_replace('/\D/', '', $chave);

        if ($cnpj === '' || $chave === '')
        {
            throw new Exception('CNPJ ou chave da NFS-e inválidos.');
        }

        $consulta = self::consultarNFSePorChave($cnpj, $chave);
        $xmlConteudo = self::extrairXmlDaConsultaNFSe($consulta);

        if (empty($xmlConteudo))
        {
            throw new Exception('XML da NFS-e não retornado pela API.');
        }

        $xmlBase64 = self::codificarAnexoBase64($xmlConteudo);
        $pdfBase64 = self::extrairPdfBase64Api($consulta);
        $ultimoErro = null;

        for ($i = 1; $i <= $tentativasPdf; $i++)
        {
            if (!empty($pdfBase64))
            {
                break;
            }

            try
            {
                $retornoPdf = self::baixarDANFE($cnpj, $chave, $cancelada);
                $pdfBase64 = self::extrairPdfBase64Api($retornoPdf ?? []);
            }
            catch (Exception $e)
            {
                $ultimoErro = $e;

                if ($i < $tentativasPdf)
                {
                    sleep($intervaloSegundos);
                }
            }
        }

        if (empty($pdfBase64))
        {
            throw new Exception(
                'Não foi possível gerar o PDF da NFS-e: '
                . ($ultimoErro ? $ultimoErro->getMessage() : 'PDF não retornado pela API.')
            );
        }

        return [
            'pdfBase64' => $pdfBase64,
            'xmlBase64' => $xmlBase64,
        ];
    }

    public static function baixarDANFEBinario(string $cnpj, string $chave, bool $cancelada = false): string
    {
        $anexos = self::obterAnexosNFSePorChave($cnpj, $chave, 5, 2, $cancelada);
        $pdf = base64_decode((string) $anexos['pdfBase64'], true);

        if ($pdf === false || $pdf === '')
        {
            throw new Exception('Erro ao decodificar PDF da NFS-e.');
        }

        return $pdf;
    }
    
    private static function obterEmpresaDaSessao()
    {
        $unitId = TSession::getValue('userunitid');

        $empresa = Empresa::where(
            'system_unit_id',
            '=',
            $unitId
        )->first();

        if (!$empresa)
        {
            throw new Exception('Empresa não encontrada para a unidade atual.');
        }

        return $empresa;
    }

    private static function validarConfiguracaoSmtp(?ParametrosGerais $config)
    {
        if (!$config)
        {
            throw new Exception(
                'Parâmetros gerais não encontrados. Configure o SMTP antes de enviar a NFS-e.'
            );
        }

        if (empty($config->host_smtp))
        {
            throw new Exception('Configure o host SMTP em Parâmetros Gerais.');
        }

        if (empty($config->porta_smtp))
        {
            throw new Exception('Configure a porta SMTP em Parâmetros Gerais.');
        }

        if (empty($config->usuario_smpt))
        {
            throw new Exception('Configure o usuário SMTP em Parâmetros Gerais.');
        }

        if (empty($config->senha_smpt))
        {
            throw new Exception('Configure a senha SMTP em Parâmetros Gerais.');
        }
    }

    private static function enviarEmailViaApi(array $payload)
    {
        $url = 'http://painel.lsisistemas.com.br/paglink/rest.php?class=EmailApiService&method=testar_email';
        //$url = 'http://127.0.0.1:5000/paglink/rest.php?class=EmailApiService&method=testar_email';
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($json),
                'Authorization: Basic_034bd030c93615992c2db7e7425c9de499e8292e976cc6d93efa7cc410c4',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch))
        {
            $erro = curl_error($ch);
            curl_close($ch);
            throw new Exception('Erro ao conectar na API de e-mail: ' . $erro);
        }

        curl_close($ch);

        if (empty($response))
        {
            throw new Exception('API de e-mail retornou resposta vazia.');
        }

        $retorno = json_decode($response, true);

        if (!$retorno)
        {
            throw new Exception('Resposta inválida da API de e-mail: ' . $response);
        }

        $enviado = !empty($retorno['sucesso'])
            || !empty($retorno['success'])
            || (($retorno['status'] ?? '') === 'ok');

        if ($httpCode != 200 || !$enviado)
        {
            $detalhe = $retorno['erro']
                ?? $retorno['mensagem']
                ?? $retorno['message']
                ?? null;

            if (empty($detalhe))
            {
                $detalhe = 'HTTP ' . $httpCode . ' - ' . substr((string) $response, 0, 300);
            }

            throw new Exception(
                'Erro ao enviar e-mail pela API: ' . $detalhe
            );
        }
    }

    public static function enviarEmailNFSe($notaId, $emailDestino = null)
    {
        try
        {
            TTransaction::open('emissor');

            $nota = new NotaFiscalCabecalho($notaId);

            if (!$nota)
            {
                throw new Exception('Nota não encontrada');
            }

            if (empty($nota->chave_acesso_nfse))
            {
                throw new Exception(
                    'A NFS-e ainda não possui chave de acesso para envio do e-mail.'
                );
            }

            $empresa = self::obterEmpresaDaSessao();

            $config = ParametrosGerais::where(
                'system_unit_id',
                '=',
                TSession::getValue('userunitid')
            )->first();

            self::validarConfiguracaoSmtp($config);

            $email = trim(
                $emailDestino
                ?: $nota->email_tomador
                ?: ''
            );

            if (empty($email))
            {
                throw new Exception('Nenhum e-mail informado para envio.');
            }

            $cnpjEmpresa = preg_replace('/\D/', '', $empresa->cnpj);
            $nomeTomador = $nota->nome_tomador ?? 'Cliente';
            $numeroNota = $nota->numero ?? $nota->numero_rps;

            TTransaction::close();

            $arquivos = self::obterAnexosNFSePorChave(
                $cnpjEmpresa,
                $nota->chave_acesso_nfse
            );

            $anexos = [
                [
                    'nome' => 'NFSE_' . $numeroNota . '.pdf',
                    'base64' => $arquivos['pdfBase64'],
                ],
                [
                    'nome' => 'NFSE_' . $numeroNota . '.xml',
                    'base64' => $arquivos['xmlBase64'],
                ],
            ];

            $nomeTomadorHtml = htmlspecialchars($nomeTomador, ENT_QUOTES, 'UTF-8');
            $nomeEmpresaHtml = htmlspecialchars(
                $empresa->razao_social ?? $empresa->nome_fantasia ?? 'Empresa',
                ENT_QUOTES,
                'UTF-8'
            );

            $payload = [
                'host' => trim($config->host_smtp),
                'porta' => (int) $config->porta_smtp,
                'usuario' => trim($config->usuario_smpt),
                'senha' => trim($config->senha_smpt),
                'nome_remetente' => $empresa->razao_social ?? $empresa->nome_fantasia,
                'destino' => [$email],
                'assunto' => 'NFS-e nº ' . $numeroNota . ' - ' . ($empresa->razao_social ?? ''),
                'mensagem' =>
                    '<p>Olá, <strong>' . $nomeTomadorHtml . '</strong>,</p>'
                    . '<p>Segue em anexo a NFS-e nº <strong>' . $numeroNota
                    . '</strong> emitida por <strong>' . $nomeEmpresaHtml . '</strong>.</p>'
                    . '<p>Atenciosamente,<br>' . $nomeEmpresaHtml . '</p>',
                'anexos' => $anexos,
            ];

            try
            {
                self::enviarEmailViaApi($payload);
            }
            catch (Exception $apiException)
            {
                if (
                    empty($config->email_origem_smpt)
                    || !class_exists('NotaFiscalEmailService')
                )
                {
                    throw new Exception(
                        $apiException->getMessage()
                        . ' Configure o e-mail de origem em Parâmetros Gerais ou corrija o SMTP.'
                    );
                }

                try
                {
                    NotaFiscalEmailService::enviarPorNotaId($notaId, $email);
                }
                catch (Exception $fallbackException)
                {
                    throw new Exception(
                        'API: ' . $apiException->getMessage()
                        . ' | SMTP local: ' . $fallbackException->getMessage()
                    );
                }
            }

            return true;
        }
        catch (Exception $e)
        {
            if (TTransaction::get())
            {
                TTransaction::rollback();
            }

            throw $e;
        }
    }

    /**
     * XMLs gravados pela API nas pastas do cliente: Notas (NFS-e autorizada) e Recibos (DPS enviada).
     * Sem o arquivo da NFS-e na API, usa o XML de retorno gravado na própria nota.
     */
    public static function obterXmlsNota($notaId): array
    {
        TTransaction::open('emissor');

        try
        {
            $nota = new NotaFiscalCabecalho($notaId);

            if (empty($nota->id))
            {
                throw new Exception('Nota não encontrada.');
            }

            $empresa = Empresa::where('system_unit_id', '=', $nota->system_unit_id)->first();

            if (!$empresa)
            {
                throw new Exception('Empresa da unidade não encontrada. Verifique em Configurações > Dados da Empresa.');
            }

            $cnpj = (string) self::somenteDigitos($empresa->cnpj);
            $chaveNfse = (string) self::somenteDigitos($nota->chave_acesso_nfse);
            $chaveDps = (string) (self::somenteDigitos($nota->chave_acesso_rps) ?: self::montarChaveDps($nota, $empresa));
            $retornoNota = json_decode((string) $nota->mensagem_retorno_nfse, true);
            $cancelada = $nota->nota_fiscal_status_id == NotaFiscalStatus::CANCELADA;
            $xmlCancelamentoSalvo = (string) $nota->xml_cancelamento;

            TTransaction::close();
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            throw $e;
        }

        if ($chaveNfse === '' && $chaveDps === '')
        {
            throw new Exception('Esta nota ainda não foi enviada: informe o número e a série do RPS para localizar a DPS.');
        }

        $resposta = null;

        try
        {
            $bruto = self::requestNfseApi('xml-arquivos', [
                'cnpj' => $cnpj,
                'chave' => $chaveNfse,
                'chaveDps' => $chaveDps,
            ], 60);

            $resposta = PainelApiService::decodificarJson($bruto) ?? json_decode((string) $bruto, true);
        }
        catch (Exception $e)
        {
            $resposta = ['sucesso' => false, 'erro' => $e->getMessage()];
        }

        $arquivos = (!empty($resposta['sucesso']) && is_array($resposta['retorno'] ?? null))
            ? $resposta['retorno']
            : [];

        $xmls = [
            'nfse' => !empty($arquivos['nfse']['xml']) ? $arquivos['nfse'] : null,
            'dps' => !empty($arquivos['dps']['xml']) ? $arquivos['dps'] : null,
            'erro' => empty($resposta['sucesso'])
                ? ($resposta['erro'] ?? 'Não foi possível consultar os XMLs na API.')
                : null,
        ];

        if ($cancelada)
        {
            try
            {
                $xmls['cancelamento'] = [
                    'arquivo' => $chaveNfse . '-cancelamento.xml',
                    'pasta' => 'Sefin Nacional (consulta do evento)',
                    'dataModificacao' => null,
                    'xml' => self::consultarEventoCancelamento($notaId),
                ];
            }
            catch (Exception $e)
            {
                $xmls['cancelamento'] = $xmlCancelamentoSalvo
                    ? [
                        'arquivo' => $chaveNfse . '-cancelamento.xml',
                        'pasta' => 'gravado na nota',
                        'dataModificacao' => null,
                        'xml' => $xmlCancelamentoSalvo,
                    ]
                    : null;
                $xmls['erro_cancelamento'] = $e->getMessage();
            }
        }

        $xmlRetorno = is_array($retornoNota)
            ? ($retornoNota['xmlRetorno'] ?? $retornoNota['retorno']['xmlRetorno'] ?? null)
            : null;

        if (!$xmls['nfse'] && $chaveNfse !== '' && is_string($xmlRetorno) && trim($xmlRetorno) !== '')
        {
            $xmls['nfse'] = [
                'arquivo' => $chaveNfse . '-nfse.xml',
                'pasta' => 'retorno gravado na nota',
                'dataModificacao' => null,
                'xml' => $xmlRetorno,
            ];
        }

        return $xmls;
    }

    /**
     * Id da infDPS (sem o prefixo "DPS"), igual ao gerado pelo ACBr: cLocEmi(7) + tipo inscrição(1)
     * + CPF/CNPJ(14) + série(5) + número(15). Nota rejeitada não grava chave_acesso_rps.
     */
    private static function montarChaveDps($nota, $empresa): ?string
    {
        $cnpj = (string) self::somenteDigitos($empresa->cnpj);
        $serie = (string) self::somenteDigitos($nota->serie);
        $numero = (string) self::somenteDigitos($nota->numero_rps);
        $municipio = (string) (self::somenteDigitos($empresa->codigo_ibge_cidade) ?: '1302603');

        if ($cnpj === '' || $serie === '' || $numero === '')
        {
            return null;
        }

        return str_pad($municipio, 7, '0', STR_PAD_LEFT)
            . (strlen($cnpj) === 14 ? '2' : '1')
            . str_pad($cnpj, 14, '0', STR_PAD_LEFT)
            . str_pad($serie, 5, '0', STR_PAD_LEFT)
            . str_pad($numero, 15, '0', STR_PAD_LEFT);
    }

    const CODIGO_DPS_JA_UTILIZADA = 'E0014';

    // RPS já usados fora do emissor (outro sistema, testes) que são pulados num mesmo clique
    const MAX_RPS_PULADOS = 10;

    const TENTATIVAS_EMISSAO = 3;

    // Falhas de comunicação/tempo (API, ACBr ou Sefin) que costumam passar ao reenviar a mesma DPS
    const FALHAS_TRANSITORIAS = [
        'Falha de comunicação',
        'Erro CURL',
        'timed out',
        'timeout',
        'tempo limite',
        'task was canceled',
        'Retorno vazio da ACBr',
        'ACBr não retornou',
        'Sefin Nacional não retornou a NFS-e',
    ];

    /**
     * Reenvia a DPS quando a falha é de comunicação. Antes de cada reenvio consulta a Sefin,
     * pois a DPS pode ter sido autorizada mesmo sem a resposta chegar.
     */
    private static function emitirComNovasTentativas(array $dados): ?array
    {
        $notaId = (int) $dados['nota_id'];
        set_time_limit(0);

        for ($tentativa = 1; ; $tentativa++)
        {
            $retorno = null;
            $falha = null;

            try
            {
                $retorno = self::emitirNFSe($dados);
            }
            catch (Exception $e)
            {
                $falha = $e;
            }

            $mensagem = $falha ? $falha->getMessage() : self::mensagemFalhaEmissao($retorno);

            if ($mensagem === null || !self::falhaTransitoria($mensagem) || $tentativa >= self::TENTATIVAS_EMISSAO)
            {
                if ($falha)
                {
                    throw $falha;
                }

                return $retorno;
            }

            sleep(5 * $tentativa);

            $existente = self::importarNFSeDaDpsExistente($notaId);

            if (!empty($existente['sucesso']))
            {
                return $existente;
            }

            LogNf::adicionar(
                $notaId,
                'NOVA TENTATIVA',
                "Falha de comunicação na tentativa {$tentativa} de " . self::TENTATIVAS_EMISSAO . '; reenviando a DPS',
                $mensagem !== '' ? $mensagem : '(API não informou o erro)'
            );
        }
    }

    /**
     * null quando a emissão deu certo; string vazia quando falhou sem dizer o motivo.
     */
    private static function mensagemFalhaEmissao(?array $retorno): ?string
    {
        if (!$retorno)
        {
            return '';
        }

        if (!empty($retorno['sucesso']))
        {
            return null;
        }

        if (!empty($retorno['errors']))
        {
            return json_encode($retorno['errors'], JSON_UNESCAPED_UNICODE);
        }

        return trim((string) ($retorno['erro'] ?? $retorno['mensagem'] ?? ''));
    }

    private static function falhaTransitoria(string $mensagem): bool
    {
        if ($mensagem === '')
        {
            return true;
        }

        foreach (self::FALHAS_TRANSITORIAS as $trecho)
        {
            if (stripos($mensagem, $trecho) !== false)
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Próximo RPS livre da unidade. Considera todas as notas (rascunhos já reservam número)
     * e o contador dos parâmetros, para não repetir série+número já enviado à Sefin (E0014).
     */
    public static function proximoNumeroRps($systemUnitId): int
    {
        $config = ParametrosGerais::where('system_unit_id', '=', $systemUnitId)->first();
        $serie = (int) ($config->serie_nfse ?? 1);
        $maiorNota = (int) NotaFiscalCabecalho::where('system_unit_id', '=', $systemUnitId)->where('serie', '=', $serie)->maxBy('numero_rps');

        return max((int) ($config->ultimo_numero_nfse ?? 0), $maiorNota) + 1;
    }

    /**
     * Série+RPS que já virou NFS-e em outra nota volta E0014 e não pode ser reaproveitado.
     * Retorna a mensagem para o log quando trocou o número.
     */
    private static function trocarRpsSeJaEnviadoPorOutraNota($nota): ?string
    {
        $dono = NotaFiscalCabecalho::outraNotaComRps(
            $nota->system_unit_id,
            $nota->serie,
            $nota->numero_rps,
            $nota->id,
            true
        );

        if (!$dono)
        {
            return null;
        }

        $rpsAnterior = $nota->numero_rps;
        $nota->numero_rps = self::proximoNumeroRps($nota->system_unit_id);
        $nota->chave_acesso_rps = null;
        $nota->store();

        return "RPS {$rpsAnterior} já usado pela nota #" . ($dono->id_key_empresa ?: $dono->id)
            . "; esta nota passou a usar o RPS {$nota->numero_rps}.";
    }

    private static function vezesRpsEnviado($nota): int
    {
        // mesmo formato do payload gravado em log_nf.retorno pelo emitirNFSe
        $trecho = '"identificacaoRps":{"numero":"' . $nota->numero_rps . '","serie":"' . $nota->serie . '"';

        return LogNf::where('nota_fiscal_cabecalho_id', '=', $nota->id)
            ->where('etapa', '=', 'ENVIO API')
            ->where('retorno', 'like', '%' . $trecho . '%')
            ->count();
    }

    private static function notaComChaveNfse(string $chaveNfse, $ignorarId): ?NotaFiscalCabecalho
    {
        if ($chaveNfse === '')
        {
            return null;
        }

        return NotaFiscalCabecalho::where('chave_acesso_nfse', '=', $chaveNfse)
            ->where('id', '<>', $ignorarId)
            ->first() ?: null;
    }

    /**
     * ultimo_numero_nfse é o contador de RPS/DPS (não o número da NFS-e devolvido pela Sefin).
     */
    private static function registrarUltimoRps($nota): void
    {
        $config = ParametrosGerais::where('system_unit_id', '=', $nota->system_unit_id)->first();

        if ($config && (int) $nota->numero_rps > (int) $config->ultimo_numero_nfse)
        {
            $config->ultimo_numero_nfse = (int) $nota->numero_rps;
            $config->store();
        }
    }

    private static function consultarNFSeDaDps(string $cnpj, string $chaveDps): ?array
    {
        foreach (['DPS' . $chaveDps, $chaveDps] as $idDps)
        {
            try
            {
                $resposta = self::consultarDPSPorChave($cnpj, $idDps);
            }
            catch (Exception $e)
            {
                continue;
            }

            if (!empty($resposta['sucesso']) && !empty($resposta['retorno']['chave']))
            {
                return $resposta['retorno'];
            }
        }

        return null;
    }

    private static function nfseCorrespondeNota(string $xml, $nota): bool
    {
        $anterior = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_use_internal_errors($anterior);

        if (!$doc)
        {
            return false;
        }

        $doc->registerXPathNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');

        $docToma = $doc->xpath('//n:infDPS/n:toma/n:CNPJ | //n:infDPS/n:toma/n:CPF');
        $vServ = $doc->xpath('//n:infDPS/n:valores/n:vServPrest/n:vServ');

        $documentoXml = $docToma ? (string) self::somenteDigitos((string) $docToma[0]) : '';
        $documentoNota = (int) $nota->tipo_tomador_id !== 1
            ? (string) self::somenteDigitos($nota->cpf_cnpj_tomador)
            : '';

        if ($documentoXml !== $documentoNota)
        {
            return false;
        }

        $valorXml = $vServ ? (float) $vServ[0] : 0.0;
        $liquido = self::valorNota($nota->valor_servico);
        $bruto = round($liquido + self::valorNota($nota->valor_desconto), 2);

        return abs($valorXml - $liquido) < 0.01 || abs($valorXml - $bruto) < 0.01;
    }

    /**
     * E0014: a DPS desta série/número já virou NFS-e (ex.: autorizou na Sefin mas o retorno não foi gravado).
     * Se a NFS-e for desta nota (mesmo tomador e valor), vincula em vez de emitir outra.
     * Retorna o mesmo formato do retorno de emissão; sem 'sucesso' quando não pôde importar.
     */
    private static function importarNFSeDaDpsExistente($notaId): array
    {
        TTransaction::open('emissor');

        try
        {
            $nota = new NotaFiscalCabecalho($notaId);
            $empresa = Empresa::where('system_unit_id', '=', $nota->system_unit_id)->first();
            $cnpj = $empresa ? (string) self::somenteDigitos($empresa->cnpj) : '';
            $chaveDps = $empresa ? self::montarChaveDps($nota, $empresa) : null;

            TTransaction::close();
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            return [];
        }

        if (!$chaveDps || $cnpj === '')
        {
            return [];
        }

        $dados = self::consultarNFSeDaDps($cnpj, $chaveDps);
        $xml = $dados['xmlRetorno'] ?? null;

        if (!$dados || !is_string($xml) || $xml === '')
        {
            return [];
        }

        if (!self::nfseCorrespondeNota($xml, $nota))
        {
            return ['numeroNota' => $dados['numero'] ?? null];
        }

        TTransaction::open('emissor');
        $outraNota = self::notaComChaveNfse((string) self::somenteDigitos($dados['chave'] ?? ''), $notaId);
        TTransaction::close();

        // Mesmo tomador e valor não basta: a NFS-e já pertence a outra nota (RPS repetido)
        if ($outraNota)
        {
            return [
                'numeroNota' => $dados['numero'] ?? null,
                'outraNota' => $outraNota->id_key_empresa ?: $outraNota->id,
            ];
        }

        $retorno = [
            'sucesso' => true,
            'importada' => true,
            'mensagem' => 'Esta DPS já tinha sido autorizada na Sefin; a NFS-e foi vinculada à nota.',
            'numeroNota' => $dados['numero'] ?? null,
            'chaveNFSe' => $dados['chave'] ?? null,
            'chaveDps' => $dados['chaveDps'] ?? null,
            'xmlRetorno' => $xml,
        ];

        TTransaction::open('emissor');

        try
        {
            $nota = new NotaFiscalCabecalho($notaId);
            $nota->chave_acesso_nfse = preg_replace('/\D/', '', (string) $retorno['chaveNFSe']);
            $nota->chave_acesso_rps = preg_replace('/\D/', '', (string) ($retorno['chaveDps'] ?: $chaveDps));
            $nota->numero = $retorno['numeroNota'];
            $nota->nota_fiscal_status_id = NotaFiscalStatus::AUTORIZADA;
            $nota->data_emissao = $nota->data_emissao ?: date('Y-m-d H:i:s');
            $nota->mensagem_retorno_nfse = json_encode($retorno);
            $retorno['valores_atualizados'] = self::atualizarValoresAutorizados($nota, $xml);
            $nota->store();

            self::registrarUltimoRps($nota);

            TTransaction::close();
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            throw $e;
        }

        LogNf::adicionar(
            $notaId,
            'RETORNO API',
            'DPS já autorizada na Sefin (E0014): NFS-e nº ' . $retorno['numeroNota'] . ' vinculada à nota',
            ['chave_nfse' => $retorno['chaveNFSe']]
        );

        self::registrarValoresAutorizados($notaId, $retorno['valores_atualizados']);

        return $retorno;
    }

    private static function formatarXml(string $xml): string
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        $anterior = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml);
        libxml_use_internal_errors($anterior);

        return $ok ? $dom->saveXML() : $xml;
    }

    /**
     * Janela com os XMLs da nota. $classe é a tela que recebe a ação onBaixarXml.
     */
    public static function exibirXmlsNota($notaId, string $classe): void
    {
        $avisoNota = null;

        try
        {
            $xmls = self::obterXmlsNota($notaId);
        }
        catch (Exception $e)
        {
            $xmls = ['nfse' => null, 'dps' => null, 'erro' => null];
            $avisoNota = $e->getMessage();
        }

        $secoes = [
            'nfse' => 'NFS-e autorizada',
            'dps' => 'DPS enviada (RPS)',
        ];

        if (array_key_exists('cancelamento', $xmls))
        {
            $secoes = ['cancelamento' => 'Evento de cancelamento'] + $secoes;
        }

        $conteudo = new TElement('div');
        $conteudo->style = 'padding:10px';

        if (!empty($xmls['erro_cancelamento']))
        {
            $aviso = new TElement('div');
            $aviso->class = 'alert alert-warning';
            $aviso->add(htmlspecialchars('Consulta do evento de cancelamento: ' . $xmls['erro_cancelamento'], ENT_QUOTES, 'UTF-8'));
            $conteudo->add($aviso);
        }

        if ($xmls['erro'])
        {
            $aviso = new TElement('div');
            $aviso->class = 'alert alert-warning';
            $aviso->add(htmlspecialchars('API: ' . $xmls['erro'], ENT_QUOTES, 'UTF-8'));
            $conteudo->add($aviso);
        }

        if (!$xmls['nfse'])
        {
            $previa = self::previaDps($notaId);

            $info = new TElement('div');
            $info->style = 'margin:12px 0 6px';
            $info->add('<b>Prévia da DPS (dados atuais da nota, ainda não enviada)</b>');
            $info->add('<br><small style="color:#666">Montada pela API sem assinatura e sem comunicar com a Sefin.</small>');
            $conteudo->add($info);

            if ($previa['xml'])
            {
                $pre = new TElement('pre');
                $pre->style = 'max-height:45vh;overflow:auto;background:#f7f7f9;border:1px solid #e1e1e8;'
                    . 'padding:10px;font-size:11px;white-space:pre;';
                $pre->add(htmlspecialchars(self::formatarXml((string) $previa['xml']), ENT_QUOTES, 'UTF-8'));
                $conteudo->add($pre);
            }
            else
            {
                $aviso = new TElement('div');
                $aviso->class = 'alert alert-warning';
                $aviso->add(htmlspecialchars('Não foi possível montar a prévia: ' . $previa['erro'], ENT_QUOTES, 'UTF-8'));
                $conteudo->add($aviso);
            }
        }

        if ($avisoNota)
        {
            $conteudo->add('<small style="color:#999">' . htmlspecialchars($avisoNota, ENT_QUOTES, 'UTF-8') . '</small>');

            $janela = TWindow::create('XML da nota #' . (int) $notaId, 0.85, 0.9);
            $janela->add($conteudo);
            $janela->show();
            return;
        }

        foreach ($secoes as $tipo => $titulo)
        {
            $arquivo = $xmls[$tipo];

            $cabecalho = new TElement('div');
            $cabecalho->style = 'display:flex;align-items:center;justify-content:space-between;gap:10px;margin:12px 0 6px';

            $info = new TElement('div');
            $info->add('<b>' . $titulo . '</b>');

            if ($arquivo)
            {
                $detalhe = ($arquivo['pasta'] ?? '') . ' / ' . ($arquivo['arquivo'] ?? '');

                if (!empty($arquivo['dataModificacao']))
                {
                    $detalhe .= ' — ' . date('d/m/Y H:i', strtotime($arquivo['dataModificacao']));
                }

                $info->add('<br><small style="color:#666">' . htmlspecialchars($detalhe, ENT_QUOTES, 'UTF-8') . '</small>');
                $cabecalho->add($info);

                $acao = new TAction([$classe, 'onBaixarXml'], [
                    'key' => $notaId,
                    'tipo' => $tipo,
                    'static' => '1',
                ]);
                $cabecalho->add(new TActionLink('Baixar XML', $acao, '#009688', '12px', '', 'fas:download #009688'));
            }
            else
            {
                $info->add('<br><small style="color:#999">Arquivo não encontrado nas pastas da API.</small>');
                $cabecalho->add($info);
            }

            $conteudo->add($cabecalho);

            if ($arquivo)
            {
                $pre = new TElement('pre');
                $pre->style = 'max-height:45vh;overflow:auto;background:#f7f7f9;border:1px solid #e1e1e8;'
                    . 'padding:10px;font-size:11px;white-space:pre;';
                $pre->add(htmlspecialchars(self::formatarXml((string) $arquivo['xml']), ENT_QUOTES, 'UTF-8'));
                $conteudo->add($pre);
            }
        }

        $janela = TWindow::create('XML da nota #' . (int) $notaId, 0.85, 0.9);
        $janela->add($conteudo);
        $janela->show();
    }

    /**
     * DPS montada pela API com os dados atuais da nota, sem assinar nem enviar à Sefin.
     */
    public static function previaDps($notaId): array
    {
        TTransaction::open('emissor');

        try
        {
            $nota = new NotaFiscalCabecalho($notaId);
            $empresa = Empresa::where('system_unit_id', '=', $nota->system_unit_id)->first();

            if (!$empresa)
            {
                throw new Exception('Empresa da unidade não encontrada. Verifique em Configurações > Dados da Empresa.');
            }

            $dados = self::montarPayload($nota, $empresa);

            TTransaction::close();
        }
        catch (Exception $e)
        {
            TTransaction::rollback();
            return ['xml' => null, 'erro' => $e->getMessage()];
        }

        try
        {
            $bruto = self::requestNfseApi('preview-xml', $dados, 60);
            $resposta = PainelApiService::decodificarJson($bruto) ?? json_decode((string) $bruto, true);
        }
        catch (Exception $e)
        {
            return ['xml' => null, 'erro' => $e->getMessage()];
        }

        if (empty($resposta['sucesso']) || empty($resposta['xml']))
        {
            return ['xml' => null, 'erro' => $resposta['erro'] ?? 'A API não devolveu a prévia da DPS.'];
        }

        return ['xml' => $resposta['xml'], 'erro' => null];
    }

    public static function baixarXmlNota($notaId, $tipo): void
    {
        $tipo = in_array($tipo, ['dps', 'cancelamento'], true) ? $tipo : 'nfse';
        $xmls = self::obterXmlsNota($notaId);

        if (empty($xmls[$tipo]['xml']))
        {
            throw new Exception('XML não encontrado' . ($xmls['erro'] ? ': ' . $xmls['erro'] : '.'));
        }

        if (!file_exists('tmp'))
        {
            mkdir('tmp', 0777, true);
        }

        $nome = basename((string) ($xmls[$tipo]['arquivo'] ?? ''));
        $arquivo = 'tmp/' . ($nome !== '' ? $nome : $tipo . '_' . (int) $notaId . '.xml');

        file_put_contents($arquivo, $xmls[$tipo]['xml']);

        TPage::openFile($arquivo);
    }

    private static function requestNfseApi($endpoint, $payload, $timeout = 120)
    {
        $result = PainelApiService::postNfse(ltrim($endpoint, '/'), $payload, $timeout);

        if (!empty($result['curl_error']))
        {
            throw new Exception('Erro CURL: ' . $result['curl_error']);
        }

        return $result['message'];
    }
}
