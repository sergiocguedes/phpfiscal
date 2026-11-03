<?php
/** Executar no container PHP MadBuilder: php /var/www/html/emissor/api-fiscal/integracoes/madbuilder/testar-homologacao.php 60 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require getcwd() . '/init.php';
require_once getcwd() . '/app/service/NfseApiPhpService.php';
$cnpj = '11229442000120'; $id = (int) ($argv[1] ?? 60);
$snapshot = static function () use ($id): array {
    TTransaction::open('emissor');
    try {
        $q = TTransaction::get()->prepare('SELECT * FROM nota_fiscal_cabecalho WHERE id=?');
        $q->execute([$id]); $row = $q->fetch(PDO::FETCH_ASSOC);
        return ['nota' => hash('sha256', json_encode($row)), 'parametros' => hash('sha256', json_encode(TTransaction::get()->query('SELECT id,ultimo_numero_nfse,serie_nfse FROM parametros_gerais ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)))];
    } finally { TTransaction::rollback(); }
};
$before = $snapshot();
TTransaction::open('emissor');
try {
    $nota = new NotaFiscalCabecalho($id);
    $empresa = Empresa::where('system_unit_id', '=', $nota->system_unit_id)->first();
    if (!$empresa || preg_replace('/\D/', '', $empresa->cnpj) !== $cnpj) { throw new RuntimeException('Nota não pertence ao CNPJ do teste.'); }
    TSession::setValue('userunitid', $empresa->system_unit_id);
    $payload = NFSeService::montarPayload($nota, $empresa);
} finally { TTransaction::rollback(); }
$cfg = NfseApiPhpService::config($cnpj);
$tenant = NfseApiPhpService::request($cfg, 'GET', '/tenant')['json'];
$providerBefore = NfseApiPhpService::request($cfg, 'GET', '/nfse/config')['json'];
if ((int) ($tenant['ambiente_nfse'] ?? 0) !== 2 || (int) ($providerBefore['ambiente'] ?? 0) !== 2) { throw new RuntimeException('O teste exige homologação, ambiente 2.'); }
$recordsBefore = NfseApiPhpService::request($cfg, 'GET', '/nfse?limite=200')['json'];
unset($payload['_cnpj'], $payload['nota_id']);
$r = NfseApiPhpService::request($cfg, 'POST', '/nfse?dry_run=1', $payload);
$j = $r['json'];
$providerAfter = NfseApiPhpService::request($cfg, 'GET', '/nfse/config')['json'];
$recordsAfter = NfseApiPhpService::request($cfg, 'GET', '/nfse?limite=200')['json'];
$after = $snapshot();
$folder = dirname(__DIR__, 3) . '/.migration-backups/homologacao-madbuilder-' . date('Ymd-His');
mkdir($folder, 0700, true);
file_put_contents($folder . '/payload-nfse.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
if (isset($j['xml'])) { file_put_contents($folder . '/dps-homologacao.xml', $j['xml']); }
$doc = new DOMDocument(); $xmlOk = isset($j['xml']) && !str_contains($j['xml'], '<!DOCTYPE') && $doc->loadXML($j['xml'], LIBXML_NONET);
$xp = $xmlOk ? new DOMXPath($doc) : null;
$report = ['cnpj' => $cnpj, 'nota_origem' => $id, 'http' => $r['http_code'], 'dry_run' => $j['dry_run'] ?? null,
    'valida' => $j['valida'] ?? false, 'erros_schema' => $j['erros_schema'] ?? [], 'avisos' => $j['avisos'] ?? [],
    'ambiente' => $j['ambiente'] ?? null, 'id_dps' => $j['id_dps'] ?? null, 'serie' => $j['serie'] ?? null, 'numero_dps' => $j['numero_dps'] ?? null,
    'leiaute_xsd' => $j['leiaute_xsd'] ?? null,
    'xml_tpAmb' => $xp ? $xp->evaluate('string(//*[local-name()="tpAmb"])') : null,
    'xml_cnpj_prestador' => $xp ? $xp->evaluate('string(//*[local-name()="prest"]/*[local-name()="CNPJ"])') : null,
    'xml_im' => $xp ? $xp->evaluate('string(//*[local-name()="prest"]/*[local-name()="IM"])') : null,
    'ibs_cbs_json' => $payload['ibs_cbs'] ?? null,
    'nbs_json' => $payload['servico']['nbs'] ?? null,
    'xml_ibs_cbs' => $xp ? $xp->evaluate('count(//*[local-name()="infDPS"]/*[local-name()="IBSCBS"])') === 1.0 : false,
    'xml_cst' => $xp ? $xp->evaluate('string(//*[local-name()="IBSCBS"]//*[local-name()="CST"])') : null,
    'xml_cclass_trib' => $xp ? $xp->evaluate('string(//*[local-name()="IBSCBS"]//*[local-name()="cClassTrib"])') : null,
    'xml_indop' => $xp ? $xp->evaluate('string(//*[local-name()="IBSCBS"]/*[local-name()="cIndOp"])') : null,
    'xml_nbs' => $xp ? $xp->evaluate('string(//*[local-name()="cServ"]/*[local-name()="cNBS"])') : null,
    'origem_preservada' => $before === $after,
    'numeracao_api_preservada' => ($providerBefore['proximo_numero'] ?? null) === ($providerAfter['proximo_numero'] ?? null) && ($providerBefore['numeracao'] ?? []) === ($providerAfter['numeracao'] ?? []),
    'nenhuma_nfse_criada' => $recordsBefore === $recordsAfter,
    'certificado_e_senha_nos_artefatos' => false];
file_put_contents($folder . '/resultado.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\nArtefatos: $folder\n";
exit(($report['valida'] && $report['xml_tpAmb'] === '2' && $report['origem_preservada'] && $report['numeracao_api_preservada'] && $report['nenhuma_nfse_criada'] && $report['xml_ibs_cbs'] && $report['xml_cst']===($payload['ibs_cbs']['cst']??null) && $report['xml_cclass_trib']===($payload['ibs_cbs']['classificacao_tributaria']??null) && $report['xml_indop']===($payload['ibs_cbs']['indicador_operacao']??null) && $report['xml_nbs']===($payload['servico']['nbs']??null)) ? 0 : 1);
