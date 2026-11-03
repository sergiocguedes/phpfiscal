<?php
/** Exercita o onSave real com os valores atuais; não transmite nota. Executar no diretório do emissor. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require getcwd() . '/init.php';
require_once getcwd() . '/app/service/NfseApiPhpService.php';
TSession::setValue('userunitid', 1);
TSession::setValue('config_steps', false);
TTransaction::open('emissor');
$empresa = Empresa::where('system_unit_id', '=', 1)->first();
if (preg_replace('/\D/', '', $empresa->cnpj) !== '11229442000120') { throw new RuntimeException('Empresa do teste divergente.'); }
$params = ParametrosGerais::where('system_unit_id', '=', 1)->first();
$_POST = $params->toArray();
$beforeNotes = hash('sha256', json_encode(TTransaction::get()->query('SELECT * FROM nota_fiscal_cabecalho ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)));
TTransaction::rollback();
$requests = [];
$transport = function ($cfg, $method, $path, $body, $key) use (&$transport, &$requests) {
    $requests[] = $method . ' ' . $path;
    NfseApiPhpService::$transport = null;
    try { return NfseApiPhpService::request($cfg, $method, $path, $body, 120, $key); }
    finally { NfseApiPhpService::$transport = $transport; }
};
NfseApiPhpService::$transport = $transport;
ob_start();
try { $form = new ParametrosGeraisForm([]); $form->onSave(); }
finally { $output = ob_get_clean(); NfseApiPhpService::$transport = null; }
TTransaction::open('emissor');
$after = ParametrosGerais::where('system_unit_id', '=', 1)->first();
$afterNotes = hash('sha256', json_encode(TTransaction::get()->query('SELECT * FROM nota_fiscal_cabecalho ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)));
$source = ['ambiente' => (int) $after->ambiente_fiscal_id, 'serie' => (int) $after->serie_nfse, 'ultimo' => (int) $after->ultimo_numero_nfse];
TTransaction::rollback();
$cfg = NfseApiPhpService::config('11229442000120');
$provider = NfseApiPhpService::request($cfg, 'GET', '/nfse/config')['json'];
$seriesIsoladas = false;
TTransaction::open('emissor');
try {
    $db = TTransaction::get();
    $db->exec('UPDATE parametros_gerais SET serie_nfse=49999,ultimo_numero_nfse=0 WHERE system_unit_id=1');
    $esperado = (int) $db->query('SELECT COALESCE(MAX(numero_rps),0)+1 FROM nota_fiscal_cabecalho WHERE system_unit_id=1 AND serie=49999')->fetchColumn();
    $seriesIsoladas = NFSeService::proximoNumeroRps(1) === $esperado;
} finally { TTransaction::rollback(); }
$ok = in_array('PUT /nfse/config', $requests, true) && $source['ambiente'] === (int) $provider['ambiente'] && $source['serie'] === (int) $provider['serie'] && $source['ultimo'] + 1 === (int) $provider['proximo_numero'] && $beforeNotes === $afterNotes && $seriesIsoladas;
echo json_encode(['tela' => 'ParametrosGeraisForm::onSave', 'sucesso' => $ok, 'emissor' => $source,
    'api' => ['ambiente' => $provider['ambiente'], 'serie' => $provider['serie'], 'proximo' => $provider['proximo_numero']],
    'notas_preservadas' => $beforeNotes === $afterNotes, 'series_isoladas' => $seriesIsoladas, 'chamadas' => $requests,
    'certificado_reenviado' => in_array('POST /certificado', $requests, true),
    'emissao_chamada' => in_array('POST /nfse', $requests, true)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
if (!$ok) { echo 'Diagnóstico da tela: ' . mb_substr(strip_tags($output), 0, 1500) . "\n"; }
exit($ok ? 0 : 1);
