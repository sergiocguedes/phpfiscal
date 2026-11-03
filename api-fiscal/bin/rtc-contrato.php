<?php
declare(strict_types=1);

/** Regenera apenas a documentação dos campos RTC a partir do XSD local confiável. */
require dirname(__DIR__) . '/vendor/autoload.php';
define('APP_ROOT', dirname(__DIR__));

use App\Fiscal\RtcInformada;

function rtcYaml(array $tree, int $indent): string
{
    $pad = str_repeat(' ', $indent);
    if ($tree === []) {
        return $pad . "type: string\n" . $pad . "maxLength: 100\n";
    }
    $out = $pad . "type: object\n" . $pad . "additionalProperties: false\n" . $pad . "properties:\n";
    foreach ($tree as $name => $children) {
        $out .= $pad . "  $name:\n" . rtcYaml($children, $indent + 4);
    }
    return $out;
}

function rtcCamposMd(array $tree, string $prefix = ''): string
{
    $out = '';
    foreach ($tree as $name => $children) {
        $path = $prefix . $name;
        $out .= $children === [] ? "| `$path` | string |\n" : rtcCamposMd($children, $path . '.');
    }
    return $out;
}

$groups = [
    'RtcIbsCbsInformado' => RtcInformada::campos('TTribNFe'),
    'RtcIsInformado' => RtcInformada::campos('TIS'),
    'RtcTotaisInformados' => ['ISTot' => RtcInformada::campos('TISTot'), 'IBSCBSTot' => RtcInformada::campos('TIBSCBSMonoTot'), 'vNFTot' => []],
];
$yaml = "    # BEGIN RTC INFORMADA GERADA\n";
$md = "# Campos RTC informados pelo Delphi\n\nGerado por `php bin/rtc-contrato.php` a partir de " . App\Nfe\XsdValidator::PACOTE . ". Todos os valores escalares são strings. Campos opcionais devem ser omitidos, sem null. A presença obrigatória, as alternativas exclusivas e os limites decimais são validados pelo XSD completo.\n\n";
foreach ($groups as $name => $tree) {
    $yaml .= "    $name:\n" . rtcYaml($tree, 6);
    $md .= "## $name\n\n| Campo dentro do grupo | Formato |\n|---|---|\n" . rtcCamposMd($tree) . "\n";
}
$yaml .= "    # END RTC INFORMADA GERADA\n";
$file = APP_ROOT . '/openapi.yaml';
$old = file_get_contents($file);
$json = json_decode($old, true);
if (is_array($json)) {
    $schema = function (array $tree) use (&$schema): array {
        if ($tree === []) { return ['type' => 'string', 'maxLength' => 100]; }
        $properties = [];
        foreach ($tree as $name => $children) { $properties[$name] = $schema($children); }
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties];
    };
    foreach ($groups as $name => $tree) { $json['components']['schemas'][$name] = $schema($tree); }
    $new = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} elseif (str_contains($old, '    # BEGIN RTC INFORMADA GERADA')) {
    $new = preg_replace('/    # BEGIN RTC INFORMADA GERADA.*?    # END RTC INFORMADA GERADA\r?\n/s', $yaml, $old);
} else {
    $new = str_replace('    PdvNfceRecord:', $yaml . '    PdvNfceRecord:', $old);
}
file_put_contents($file, $new);
file_put_contents(APP_ROOT . '/docs/RTC-CAMPOS-INFORMADOS.md', $md);
echo "OpenAPI e mapa de campos RTC atualizados.\n";
