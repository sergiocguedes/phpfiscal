<?php
/** Cliente da API Fiscal. Não usa as credenciais de Horse/pagamentos do PainelApiService. */
class NfseApiPhpService
{
    public static $transport;

    public static function config(string $cnpj): array
    {
        $path = getenv('NFSE_API_CONFIG') ?: dirname(__DIR__) . '/config/nfse-api.local.php';
        if (!is_file($path)) { throw new RuntimeException('Configure app/config/nfse-api.local.php para usar a API PHP.'); }
        $all = require $path;
        $company = $all['empresas'][$cnpj] ?? null;
        if (!is_array($company) || empty($company['chave'])) { throw new RuntimeException('Chave da API PHP não configurada para este CNPJ.'); }
        $cfg = array_replace($all, $company); unset($cfg['empresas']);
        $cfg['url'] = rtrim($cfg['url'], '/');
        $url = parse_url($cfg['url']);
        if (empty($url['host']) || isset($url['user']) || isset($url['query']) || isset($url['fragment']) ||
            (($url['scheme'] ?? '') !== 'https' && !(($url['scheme'] ?? '') === 'http' && in_array($url['host'], ['localhost','127.0.0.1','host.docker.internal'], true)))) {
            throw new RuntimeException('Use HTTPS na API remota ou HTTP apenas no Docker/localhost.');
        }
        foreach (['chave', 'empresa_id'] as $key) { if (strpbrk((string) ($cfg[$key] ?? ''), "\r\n") !== false) { throw new RuntimeException('Cabeçalho de integração inválido.'); } }
        return $cfg;
    }

    public static function request(array $cfg, string $method, string $path, ?array $body = null, int $timeout = 120, ?string $idempotency = null): array
    {
        if (is_callable(self::$transport)) { return call_user_func(self::$transport, $cfg, $method, $path, $body, $idempotency); }
        $headers = ['Authorization: Bearer ' . $cfg['chave'], 'Accept: application/json'];
        if (!empty($cfg['empresa_id'])) { $headers[] = 'X-Empresa-Id: ' . $cfg['empresa_id']; }
        if ($idempotency !== null) { $headers[] = 'Idempotency-Key: ' . $idempotency; }
        $ch = curl_init($cfg['url'] . $path);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
        if ($body !== null) { $headers[] = 'Content-Type: application/json'; $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
        $opts[CURLOPT_HTTPHEADER] = $headers; curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $error = curl_errno($ch); curl_close($ch);
        if ($raw === false) { throw new RuntimeException('Falha de comunicação com a API PHP (cURL ' . $error . '). Consulte a DPS antes de reenviar; mantenha a numeração.'); }
        $json = json_decode($raw, true);
        $fiscalRecord = $method === 'POST' && preg_match('~^/nfse(?:$|/[^/]+/(?:sincronizar|reenviar-horario|corrigir)$)~', $path) && is_array($json) && isset($json['id'], $json['status']);
        if ($code >= 400 && !$fiscalRecord && !(str_contains($path, 'dry_run=1') && is_array($json) && ($json['dry_run'] ?? false) === true)) {
            $msg = is_array($json['erro'] ?? null) ? ($json['erro']['mensagem'] ?? 'Erro na API PHP') : 'Erro na API PHP';
            $fields = $json['erro']['detalhes'] ?? $json['erro']['campos'] ?? [];
            foreach ((array) $fields as $field) { if (is_array($field)) { $msg .= ' | ' . ($field['campo'] ?? '') . ': ' . ($field['mensagem'] ?? ''); } }
            throw new RuntimeException('HTTP ' . $code . ': ' . $msg, $code);
        }
        return ['raw' => $raw, 'json' => $json, 'http_code' => $code];
    }

    public static function dispatch(string $endpoint, array $data, int $timeout = 120): array
    {
        if (trim($endpoint, '/') === 'configurar-cliente') {
            return ['message' => json_encode(self::sincronizar($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'http_code' => 200, 'curl_error' => ''];
        }
        $cnpj = preg_replace('/\D/', '', (string) ($data['_cnpj'] ?? $data['cnpj'] ?? $data['Cnpj'] ?? ''));
        $cfg = self::config($cnpj);
        $identity = self::request($cfg, 'GET', '/config')['json'];
        if (($identity['cnpj'] ?? '') !== $cnpj) { throw new RuntimeException('A chave selecionada pertence a outro CNPJ ou os dados fiscais ainda não estão cadastrados na API PHP.'); }
        $ref = (string) ($data['chave'] ?? $data['ChaveNFSe'] ?? $data['chaveDps'] ?? '');
        // IDs de DPS da API incluem o prefixo DPS; chaves de NFS-e têm 50 dígitos.
        if (preg_match('/^\d{42}$/', $ref)) { $ref = 'DPS' . $ref; }
        $path = '/nfse/' . rawurlencode($ref);
        switch (trim($endpoint, '/')) {
            case 'emitir':
                $native = $data;
                unset($native['_cnpj'], $native['nota_id']);
                $key = 'mad-nfse-' . $cnpj . '-' . ($native['serie'] ?? 0) . '-' . ($native['numero'] ?? 0);
                $municipio=(string)($identity['endereco']['codigo_municipio']??'');
                if(!preg_match('/^\d{7}$/',$municipio)){throw new RuntimeException('Município do prestador ausente na API. Sincronize o cadastro.');}
                $id='DPS'.$municipio.'2'.$cnpj.str_pad((string)$native['serie'],5,'0',STR_PAD_LEFT).str_pad((string)$native['numero'],15,'0',STR_PAD_LEFT);
                $existing=null;
                try{$existing=self::request($cfg,'GET','/nfse/'.$id)['json'];}
                catch(RuntimeException $e){if($e->getCode()!==404){throw $e;}}
                if(($existing['status']??'')==='autorizada'){$result=self::record($cfg,$existing);break;}
                if(($existing['status']??'')==='cancelada'){throw new RuntimeException('Esta DPS está cancelada na API; não será reemitida.');}
                if(in_array($existing['status']??'',['erro','processando'],true)){
                    $rec=self::request($cfg,'POST','/nfse/'.$id.'/sincronizar',[],$timeout)['json'];$result=self::record($cfg,$rec);break;
                }
                // A mesma nota conserva exatamente o mesmo corpo entre tentativas; emissão não leva dhEmi local.
                $path='/nfse';
                if(($existing['status']??'')==='rejeitada'){
                    $path='/nfse/'.$id.'/corrigir';
                    $providerContext=self::request($cfg,'GET','/nfse/config')['json'];
                    $fiscalContext=array_intersect_key($providerContext,array_flip(['inscricao_municipal','codigo_municipio','opcao_simples_nacional','regime_apuracao_sn','regime_especial','ambiente','municipio_convenio_ativo']));
                    $key.='.corr.'.substr(hash('sha256',json_encode(['payload'=>$native,'prestador'=>$fiscalContext],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),0,16);
                }
                $rec = self::request($cfg, 'POST', $path, $native, $timeout, $key)['json'];
                if (($rec['status']??'')==='rejeitada' && !empty($rec['id'])) {
                    $latest=self::request($cfg,'GET','/nfse/'.rawurlencode($rec['id']))['json'];
                    if(($latest['id']??null)===$rec['id']){$rec=$latest;}
                }
                if (in_array($rec['status'] ?? '', ['erro', 'processando'], true) && !empty($rec['id'])) {
                    $rec = self::request($cfg, 'POST', '/nfse/' . rawurlencode($rec['id']) . '/sincronizar', [], $timeout)['json'];
                }
                $result = self::record($cfg, $rec);
                break;
            case 'consultar-dps-por-chave':
            case 'consultar-por-chave':
                $rec = self::request($cfg, 'POST', $path . '/sincronizar', [], $timeout)['json'];
                $result = self::record($cfg, $rec);
                $result['retorno'] = ['chave' => $result['chaveNFSe'], 'numero' => $result['numeroNota'],
                    'chaveDps' => $result['chaveDps'], 'xmlRetorno' => $result['xmlRetorno'], 'status' => $rec['status'] ?? null];
                break;
            case 'imprimir-pdf':
                $pdf = self::request($cfg, 'GET', $path . '/danfse', null, $timeout)['raw'];
                if (strncmp($pdf, '%PDF-', 5) !== 0) { throw new RuntimeException('A API não retornou um PDF válido.'); }
                $result = ['sucesso' => true, 'pdfBase64' => base64_encode($pdf)];
                break;
            case 'cancelar':
                $r = self::request($cfg, 'POST', $path . '/cancelar', ['codigo_motivo' => (int) $data['CodCancelamento'], 'motivo' => $data['MotCancelamento']], $timeout)['json'];
                $result = ['sucesso' => (bool) ($r['sucesso'] ?? false), 'nfse' => $r['nfse'] ?? null];
                if ($result['sucesso']) { $result['xmlEvento'] = self::request($cfg, 'GET', $path . '/xml?tipo=evento')['raw']; }
                else { $result['erro'] = json_encode($r['erros'] ?? [], JSON_UNESCAPED_UNICODE); }
                break;
            case 'consultar-evento':
                self::request($cfg, 'POST', $path . '/sincronizar', [], $timeout);
                $result = ['sucesso' => true, 'xmlEvento' => self::request($cfg, 'GET', $path . '/xml?tipo=evento')['raw']];
                break;
            case 'xml-arquivos':
                $files = [];
                foreach (['nfse', 'dps'] as $type) {
                    $xml = self::request($cfg, 'GET', $path . '/xml?tipo=' . $type)['raw'];
                    $files[$type] = ['xml' => $xml, 'arquivo' => $ref . '-' . $type . '.xml', 'pasta' => 'API Fiscal', 'dataModificacao' => null];
                }
                $result = ['sucesso' => true, 'retorno' => $files];
                break;
            default: throw new RuntimeException('Operação não suportada pela integração API PHP: ' . $endpoint);
        }
        return ['message' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'http_code' => 200, 'curl_error' => ''];
    }

    public static function sincronizar(array $data): array
    {
        foreach (['cnpj', 'empresa', 'emitente', 'prestador'] as $key) {
            if (empty($data[$key])) { throw new RuntimeException('Configuração incompleta enviada pelo emissor: ' . $key); }
        }
        $cfg = self::config($data['cnpj']);
        $tenant = self::request($cfg, 'GET', '/tenant')['json'];
        if (($tenant['cnpj'] ?? '') !== $data['cnpj'] || ($data['emitente']['cnpj'] ?? '') !== $data['cnpj']) {
            throw new RuntimeException('Chave da integração não corresponde ao CNPJ do emissor.');
        }
        $tenant = self::request($cfg, 'PUT', '/tenant', $data['empresa'])['json'];
        $current = self::request($cfg, 'GET', '/config')['json'];
        $preserve = array_intersect_key($current, array_flip(['serie', 'proximo_numero', 'serie_nfce', 'proximo_numero_nfce', 'csc_id']));
        self::request($cfg, 'PUT', '/config', array_replace($preserve, $data['emitente']));
        $providerInput = $data['prestador'];
        $avisos = $data['avisos'] ?? [];
        if (isset($providerInput['proximo_numero'])) {
            $existing = self::request($cfg, 'GET', '/nfse/config')['json'];
            $serie = (int) $providerInput['serie'];
            $floor = (int) (($existing['serie'] ?? null) === $serie ? ($existing['proximo_numero'] ?? 1) : ($existing['numeracao'][(string) $serie] ?? 1));
            if ($providerInput['proximo_numero'] < $floor) { $avisos[] = 'A API já possui numeração maior nesta série/ambiente; mantido próximo número ' . $floor . '.'; }
            $providerInput['proximo_numero'] = max($providerInput['proximo_numero'], $floor);
        }
        $provider = self::request($cfg, 'PUT', '/nfse/config', $providerInput)['json'];
        $certificate = null;
        if (!empty($data['certificado'])) {
            $certificate = self::request($cfg, 'POST', '/certificado', $data['certificado'])['json'];
            if (($certificate['cnpj_confere'] ?? null) === false) { throw new RuntimeException('CNPJ do certificado enviado difere da empresa. Corrija o A1 antes de emitir.'); }
        }
        return ['sucesso' => true, 'mensagem' => 'Empresa, ambiente NFS-e e parâmetros do prestador sincronizados pela API.',
            'empresa_id' => $tenant['id'], 'ambiente_nfse' => $tenant['ambiente_nfse'],
            'serie' => $provider['serie'], 'proximo_numero' => $provider['proximo_numero'],
            'certificado_sincronizado' => $certificate !== null,
            'certificado_validade' => $certificate['valido_ate'] ?? $certificate['validade'] ?? null,
            'avisos' => $avisos];
    }

    private static function record(array $cfg, array $rec): array
    {
        $ok = in_array($rec['status'] ?? '', ['autorizada', 'cancelada'], true);
        $xml = null;
        if ($ok) { $xml = self::request($cfg, 'GET', '/nfse/' . rawurlencode($rec['id']) . '/xml')['raw']; }
        return ['sucesso' => $ok, 'numeroNota' => $rec['numero_nfse'] ?? null, 'chaveNFSe' => $rec['chave'] ?? null,
            'chaveDps' => $rec['id'] ?? null, 'xmlRetorno' => $xml, 'api_php' => $rec,
            'erro' => $ok ? null : ($rec['mensagem'] ?? json_encode($rec['erros'] ?? [], JSON_UNESCAPED_UNICODE)),
            'mensagem' => $ok ? 'NFS-e autorizada.' : 'NFS-e não autorizada; consulte a DPS antes de reenviar.'];
    }
}
