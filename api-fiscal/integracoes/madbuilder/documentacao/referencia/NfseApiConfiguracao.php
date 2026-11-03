<?php
/** Monta configuração e A1 a partir do cadastro do emissor; nunca registra segredos em log. */
class NfseApiConfiguracao
{
    public static function inscricaoMunicipal($valor): ?string
    {
        $im=preg_replace('/[^0-9A-Za-z]/','',(string)$valor);
        if($im===''){return null;}
        return ctype_digit($im)&&strlen($im)<15?str_pad($im,15,'0',STR_PAD_LEFT):$im;
    }
    public static function localizacao($empresa): array
    {
        $uf = strtoupper((string) $empresa->estado->uf);
        $municipio = preg_replace('/\D/', '', (string) $empresa->codigo_ibge_cidade);
        $cidade = trim((string) $empresa->cidade);
        $cep = preg_replace('/\D/', '', (string) $empresa->cep);
        $avisos = [];
        if (strlen($municipio) !== 7 || $cidade === '') {
            $ch = curl_init('https://viacep.com.br/ws/' . rawurlencode($cep) . '/json/');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
            $j = json_decode((string) curl_exec($ch), true); curl_close($ch);
            if (!is_array($j) || ($j['uf'] ?? '') !== $uf || !preg_match('/^\d{7}$/', (string) ($j['ibge'] ?? ''))) {
                throw new RuntimeException('Preencha cidade e código IBGE no cadastro da empresa; não foi possível resolver o CEP com segurança.');
            }
            if ($municipio !== '' && $municipio !== $j['ibge']) { throw new RuntimeException('Código IBGE do cadastro difere do município do CEP. Revise o cadastro.'); }
            $municipio = $j['ibge']; $cidade = $j['localidade'];
            $avisos[] = 'Município completado pelo CEP cadastrado (ViaCEP); revise e grave cidade/código IBGE no emissor.';
        }
        return compact('uf', 'municipio', 'cidade', 'cep', 'avisos');
    }

    public static function montar($empresa, $parametros, bool $incluirCertificado = true): array
    {
        $cnpj = preg_replace('/\D/', '', (string) $empresa->cnpj);
        $loc = self::localizacao($empresa);
        $uf=$loc['uf'];$municipio=$loc['municipio'];$cidade=$loc['cidade'];$cep=$loc['cep'];$avisos=$loc['avisos'];
        $ie = trim((string) $empresa->inscricao_estadual);
        $ambiente = (int) $parametros->ambiente_fiscal_id;
        if (!in_array($ambiente, [1, 2], true)) { throw new RuntimeException('Ambiente fiscal deve ser 1 ou 2.'); }
        $crt = (int) $empresa->regime_tributario_id;
        $op = [1 => 3, 2 => 3, 3 => 1, 4 => 2][$crt] ?? null;
        if ($op === null) { throw new RuntimeException('Regime tributário não mapeado.'); }
        $reg = $empresa->regime_especial_tributacao_id ? $empresa->regime_especial_tributacao->codigo : 'retNenhum';
        $map = ['retNenhum' => 0, 'retCooperativa' => 1, 'retEstimativa' => 2, 'retMicroempresaMunicipal' => 3,
            'retSociedadeProfissionais' => 6, 'retOutros' => 9];
        if (!array_key_exists($reg, $map)) { throw new RuntimeException('Regime especial ACBr sem equivalência segura no Padrão Nacional: ' . $reg); }
        $certificate = null;
        if ($incluirCertificado) {
        $b64 = (string) $parametros->certificado_digital;
        $pfx = base64_decode($b64, true);
        if (!$pfx) { throw new RuntimeException('Certificado A1 base64 ausente ou inválido no emissor.'); }
        $password = UtilService::obterSenhaCertificado($parametros->senha_certificado, $pfx);
        $certs = [];
        if (!openssl_pkcs12_read($pfx, $certs, $password)) { throw new RuntimeException('Não foi possível abrir o A1 com a senha cadastrada no emissor.'); }
        $parsed = openssl_x509_parse($certs['cert']);
        if (!$parsed || $parsed['validTo_time_t'] < time()) { throw new RuntimeException('Certificado A1 expirado ou inválido.'); }
        $certificate = ['certificado_base64' => $b64, 'senha' => $password];
        }
        $emit = ['cnpj' => $cnpj, 'ie' => $ie === '' ? null : strtoupper($ie), 'razao_social' => $empresa->razao_social,
            'uf' => $uf, 'crt' => $crt, 'endereco' => ['logradouro' => $empresa->logradouro,
                'numero' => (string) $empresa->numero, 'bairro' => $empresa->bairro, 'municipio' => $cidade,
                'codigo_municipio' => $municipio, 'uf' => $uf, 'cep' => $cep]];
        if (!empty($empresa->nome_fantasia)) { $emit['nome_fantasia'] = $empresa->nome_fantasia; }
        if (!empty($empresa->complemento)) { $emit['endereco']['complemento'] = $empresa->complemento; }
        $provider = ['inscricao_municipal' => self::inscricaoMunicipal($empresa->inscricao_municipal), 'codigo_municipio' => $municipio,
            'opcao_simples_nacional' => $op, 'regime_especial' => $map[$reg], 'serie' => (int) $parametros->serie_nfse];
        $last = (string) $parametros->ultimo_numero_nfse;
        if (!preg_match('/^\d{1,15}$/', $last) || (int) $last >= 999999999999999) { throw new RuntimeException('Último número RPS/DPS inválido; informe de 0 a 999999999999998.'); }
        $largest = (int) NotaFiscalCabecalho::where('system_unit_id', '=', $empresa->system_unit_id)->where('serie', '=', $provider['serie'])->maxBy('numero_rps');
        $provider['proximo_numero'] = max((int) $last, $largest) + 1;
        if ($provider['proximo_numero'] > (int) $last + 1) { $avisos[] = 'Próximo RPS/DPS ajustado para superar os números já atribuídos às notas desta série no emissor.'; }
        if ($op === 3) { $provider['regime_apuracao_sn'] = (int) ($empresa->regime_apuracao_sn_id ?: 1); }
        return ['cnpj' => $cnpj, 'empresa' => ['nome' => $empresa->razao_social, 'ambiente_nfse' => $ambiente],
            'emitente' => $emit, 'prestador' => $provider,
            'certificado' => $certificate, 'avisos' => $avisos];
    }
}
