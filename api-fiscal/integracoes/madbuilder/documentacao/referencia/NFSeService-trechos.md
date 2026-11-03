# Trechos de app/control/NFSeService.php

Métodos completos extraídos do fonte atual. No editor de ação que já contém a assinatura, copie apenas o corpo. Não duplique declarações.

## configurarCliente — linha de referência 12

```php
    public static function configurarCliente()
    {
        return self::sincronizarConfiguracao(true);
    }
```

## sincronizarParametros — linha de referência 17

```php
    public static function sincronizarParametros(): array
    {
        return self::sincronizarConfiguracao(false);
    }
```

## sincronizarConfiguracao — linha de referência 22

```php
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
```

## emitirNFSe — linha de referência 244

```php
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
```

## cancelarNotaFiscalCabecalho — linha de referência 472

```php
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
```

## extrairEventoCancelamento — linha de referência 583

```php
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
```

## emitirNFSePorId — linha de referência 628

```php
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
```

## inscricaoMunicipal — linha de referência 1785

```php
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
```

## montarIbsCbs — linha de referência 1840

```php
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
```

## cepTomador — linha de referência 2138

```php
    private static function cepTomador($pessoaTomador, $empresa): string
    {
        $cep = (string) self::somenteDigitos($pessoaTomador->cep ?? null);

        return $cep;
    }
```

## montarPayload — linha de referência 2177

```php
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
```

## proximoNumeroRps — linha de referência 3142

```php
    public static function proximoNumeroRps($systemUnitId): int
    {
        $config = ParametrosGerais::where('system_unit_id', '=', $systemUnitId)->first();
        $serie = (int) ($config->serie_nfse ?? 1);
        $maiorNota = (int) NotaFiscalCabecalho::where('system_unit_id', '=', $systemUnitId)->where('serie', '=', $serie)->maxBy('numero_rps');

        return max((int) ($config->ultimo_numero_nfse ?? 0), $maiorNota) + 1;
    }
```

## requestNfseApi — linha de referência 3588

```php
    private static function requestNfseApi($endpoint, $payload, $timeout = 120)
    {
        $result = PainelApiService::postNfse(ltrim($endpoint, '/'), $payload, $timeout);

        if (!empty($result['curl_error']))
        {
            throw new Exception('Erro CURL: ' . $result['curl_error']);
        }

        return $result['message'];
    }
```

