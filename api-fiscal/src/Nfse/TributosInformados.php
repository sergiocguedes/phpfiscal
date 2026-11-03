<?php

declare(strict_types=1);

namespace App\Nfse;

use App\Support\Errors;

/** Dados declarados pelo integrador: valida e serializa, sem calcular tributos. */
final class TributosInformados
{
    public static function validar(array $in, Errors $e): array
    {
        $out = [];
        if (array_key_exists('tributos_federais', $in)) {
            $f = self::obj($in['tributos_federais'], 'tributos_federais', ['pis_cofins', 'retencao_cp', 'retencao_irrf', 'retencao_csll'], $e);
            $r = [];
            foreach (['retencao_cp', 'retencao_irrf', 'retencao_csll'] as $k) {
                if (array_key_exists($k, $f)) { $r[$k] = self::decimal($f[$k], "tributos_federais.$k", 15, $e); }
            }
            if (array_key_exists('pis_cofins', $f)) {
                $p = self::obj($f['pis_cofins'], 'tributos_federais.pis_cofins', ['cst', 'base_calculo', 'aliquota_pis', 'aliquota_cofins', 'valor_pis', 'valor_cofins', 'tipo_retencao'], $e);
                $csts = ['00','01','02','03','04','05','06','07','08','09','49','50','51','52','53','54','55','56','60','61','62','63','64','65','66','67','70','71','72','73','74','75','98','99'];
                if (!is_string($p['cst'] ?? null) || !in_array($p['cst'], $csts, true)) {
                    $e->add('tributos_federais.pis_cofins.cst', 'CST válido com dois dígitos, como string');
                }
                $pc = ['cst' => $p['cst'] ?? ''];
                foreach (['base_calculo', 'aliquota_pis', 'aliquota_cofins', 'valor_pis', 'valor_cofins'] as $k) {
                    if (array_key_exists($k, $p)) { $pc[$k] = self::decimal($p[$k], "tributos_federais.pis_cofins.$k", str_starts_with($k, 'aliquota') ? 2 : 15, $e); }
                }
                if (array_key_exists('tipo_retencao', $p)) {
                    if (!is_int($p['tipo_retencao']) || $p['tipo_retencao'] < 0 || $p['tipo_retencao'] > 9) {
                        $e->add('tributos_federais.pis_cofins.tipo_retencao', 'Inteiro de 0 a 9');
                    }
                    $pc['tipo_retencao'] = $p['tipo_retencao'];
                }
                $r['pis_cofins'] = $pc;
            }
            $out['tributos_federais'] = $r;
        }
        $v = is_array($in['valores'] ?? null) ? $in['valores'] : [];
        if (array_key_exists('suspensao', $v)) {
            $s = self::obj($v['suspensao'], 'valores.suspensao', ['tipo', 'numero_processo'], $e);
            if (!in_array($s['tipo'] ?? null, [1, 2], true)) { $e->add('valores.suspensao.tipo', '1 judicial ou 2 administrativo'); }
            if (!is_string($s['numero_processo'] ?? null) || !preg_match('/^[0-9]{30}$/D', $s['numero_processo'])) {
                $e->add('valores.suspensao.numero_processo', 'String com exatamente 30 dígitos');
            }
            if (($v['tributacao_iss'] ?? 1) !== 1) { $e->add('valores.suspensao', 'Suspensão exige operação tributável'); }
            $out['suspensao'] = $s;
        }
        if (array_key_exists('deducoes', $v)) {
            $d = self::obj($v['deducoes'], 'valores.deducoes', ['valor', 'percentual'], $e);
            if (count($d) !== 1) { $e->add('valores.deducoes', 'Informe exclusivamente valor ou percentual; documentos ainda não suportados'); }
            $r = [];
            foreach (['valor', 'percentual'] as $k) {
                if (array_key_exists($k, $d)) {
                    $r[$k] = self::decimal($d[$k], "valores.deducoes.$k", $k === 'valor' ? 15 : 3, $e);
                    if ($k === 'percentual' && (float) $r[$k] > 100) { $e->add('valores.deducoes.percentual', 'Percentual até 100'); }
                    if ($k === 'valor' && (float) $r[$k] > (float) ($v['servico'] ?? 0)) { $e->add('valores.deducoes.valor', 'Dedução não pode superar o serviço'); }
                }
            }
            $out['deducoes'] = $r;
        }
        return $out;
    }

    private static function obj(mixed $v, string $path, array $keys, Errors $e): array
    {
        if (!is_array($v) || array_is_list($v) || $v === []) { $e->add($path, 'Objeto não vazio esperado'); return []; }
        foreach (array_keys($v) as $key) {
            if (!in_array($key, $keys, true)) { $e->add("$path.$key", 'Campo não suportado'); }
        }
        return $v;
    }

    private static function decimal(mixed $v, string $path, int $digits, Errors $e): string
    {
        // Strings preservam exatidão mesmo acima do limite inteiro de double.
        $s = is_string($v) || is_int($v) || is_float($v) ? (string) $v : '';
        if (!preg_match('/^(0|[1-9][0-9]{0,' . ($digits - 1) . '})(?:\.([0-9]{1,2}))?$/D', $s, $m)) {
            $e->add($path, "Decimal não negativo, até $digits dígitos inteiros e 2 casas, sem arredondamento");
            return '0.00';
        }
        return $m[1] . '.' . str_pad($m[2] ?? '', 2, '0');
    }

    public static function deducoes(\DOMDocument $dom, \DOMElement $val, array $d, bool $novo): void
    {
        $g = self::el($dom, $val, $novo ? 'vAjusteBC' : 'vDedRed');
        foreach ($d as $key => $value) {
            self::el($dom, $g, $novo ? ($key === 'valor' ? 'vAjusteBCISSQN' : 'pAjusteBCISSQN') : ($key === 'valor' ? 'vDR' : 'pDR'), $value);
        }
    }

    public static function suspensao(\DOMDocument $dom, \DOMElement $tm, array $s): void
    {
        $g = self::el($dom, $tm, 'exigSusp');
        self::el($dom, $g, 'tpSusp', (string) $s['tipo']);
        self::el($dom, $g, 'nProcesso', $s['numero_processo']);
    }

    public static function federais(\DOMDocument $dom, \DOMElement $trib, array $f): void
    {
        $g = self::el($dom, $trib, 'tribFed');
        if (isset($f['pis_cofins'])) {
            $p = self::el($dom, $g, 'piscofins');
            foreach (['cst'=>'CST','base_calculo'=>'vBCPisCofins','aliquota_pis'=>'pAliqPis','aliquota_cofins'=>'pAliqCofins','valor_pis'=>'vPis','valor_cofins'=>'vCofins','tipo_retencao'=>'tpRetPisCofins'] as $k=>$tag) {
                if (array_key_exists($k, $f['pis_cofins'])) { self::el($dom, $p, $tag, (string) $f['pis_cofins'][$k]); }
            }
        }
        foreach (['retencao_cp'=>'vRetCP','retencao_irrf'=>'vRetIRRF','retencao_csll'=>'vRetCSLL'] as $k=>$tag) {
            if (array_key_exists($k, $f)) { self::el($dom, $g, $tag, $f[$k]); }
        }
    }

    private static function el(\DOMDocument $dom, \DOMElement $parent, string $name, ?string $value = null): \DOMElement
    {
        $el = $dom->createElementNS(DpsBuilder::NS, $name);
        if ($value !== null) { $el->appendChild($dom->createTextNode($value)); }
        $parent->appendChild($el);
        return $el;
    }
}
