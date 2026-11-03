<?php
declare(strict_types=1);

namespace App\Fiscal;

use App\Http\ApiException;
use App\Nfe\XsdValidator;
use App\Support\Errors;

/** RTC calculada no PDV: contrato espelha o XSD, sem cálculo, arredondamento ou totalização. */
final class RtcInformada
{
    private const NS = 'http://www.portalfiscal.inf.br/nfe';
    private static array $types = [];
    private static array $trees = [];

    public static function ativa(array $p): bool
    {
        return ($p['reforma_tributaria']['modo'] ?? null) === 'informado';
    }

    /** Descritor de campos derivado apenas dos XSD locais confiáveis, nunca de entrada do cliente. */
    public static function campos(string $type): array
    {
        if (isset(self::$trees[$type])) {
            return self::$trees[$type];
        }
        if (self::$types === []) {
            foreach (['DFeTiposBasicos_v1.00.xsd', 'tiposBasico_v4.00.xsd'] as $file) {
                $dom = new \DOMDocument();
                $dom->load(XsdValidator::schemaDir() . $file, LIBXML_NONET);
                $xp = new \DOMXPath($dom);
                $xp->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
                foreach ($xp->query('/xs:schema/xs:complexType | /xs:schema/xs:simpleType') as $node) {
                    self::$types[$node->getAttribute('name')] = $node;
                }
            }
        }
        $node = self::$types[$type] ?? null;
        return self::$trees[$type] = $node instanceof \DOMElement && $node->localName === 'complexType' ? self::filhos($node) : [];
    }

    private static function filhos(\DOMElement $node): array
    {
        $out = [];
        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            if ($child->localName === 'element') {
                $children = self::campos($child->getAttribute('type'));
                foreach ($child->childNodes as $inline) {
                    if ($inline instanceof \DOMElement && $inline->localName === 'complexType') {
                        $children = self::filhos($inline);
                    }
                }
                $out[$child->getAttribute('name')] = $children;
            } elseif (in_array($child->localName, ['sequence', 'choice', 'all'], true)) {
                $out += self::filhos($child);
            }
        }
        return $out;
    }

    /** Todos os valores escalares são strings: conserva escala decimal e zeros dos códigos. */
    public static function validarGrupo(mixed $input, string $type, string $path, Errors $e): array
    {
        return self::validarObjeto($input, self::campos($type), $path, $e);
    }

    private static function validarObjeto(mixed $input, array $tree, string $path, Errors $e): array
    {
        if (!is_array($input) || array_is_list($input)) {
            $e->add($path, 'Informe um objeto com os nomes das tags do XML');
            return [];
        }
        $out = [];
        foreach ($input as $name => $value) {
            if (!array_key_exists($name, $tree)) {
                $e->add("$path.$name", 'Campo desconhecido no schema ' . XsdValidator::PACOTE);
                continue;
            }
            if ($tree[$name] !== []) {
                $out[$name] = self::validarObjeto($value, $tree[$name], "$path.$name", $e);
            } elseif (!is_string($value) || strlen($value) > 100 || preg_match('/[\x00-\x1f]/', $value)) {
                $e->add("$path.$name", 'Informe texto (códigos e decimais com ponto), sem conversão/arredondamento pela API');
            } else {
                $out[$name] = $value;
            }
        }
        return $out;
    }

    public static function validarConfiguracao(mixed $input, Errors $e): array
    {
        if (!is_array($input) || ($input['modo'] ?? null) !== 'informado') {
            $e->add('reforma_tributaria', 'Use {"modo":"informado","totais":{...}} para receber valores do Delphi');
            return [];
        }
        foreach (array_diff(array_keys($input), ['modo', 'totais']) as $field) {
            $e->add("reforma_tributaria.$field", 'Campo desconhecido');
        }
        $tree = ['ISTot' => self::campos('TISTot'), 'IBSCBSTot' => self::campos('TIBSCBSMonoTot'), 'vNFTot' => []];
        $totais = self::validarObjeto($input['totais'] ?? null, $tree, 'reforma_tributaria.totais', $e);
        if (!isset($totais['vNFTot'])) {
            $e->add('reforma_tributaria.totais.vNFTot', 'Total calculado pelo Delphi obrigatório');
        }
        return ['modo' => 'informado', 'totais' => $totais];
    }

    public static function preparar(array $p): array
    {
        $e = new Errors();
        $ibscbs = false;
        $is = false;
        foreach ($p['itens'] as $i => $item) {
            $ibscbs = $ibscbs || isset($item['impostos']['ibscbs']);
            $is = $is || isset($item['impostos']['is']);
            if (!isset($item['valor_item'])) {
                $e->add("itens[$i].valor_item", 'vItem calculado pelo Delphi obrigatório no modo informado');
            }
        }
        foreach (['IBSCBSTot' => $ibscbs, 'ISTot' => $is] as $group => $present) {
            if ($present && !isset($p['reforma_tributaria']['totais'][$group])) {
                $e->add("reforma_tributaria.totais.$group", 'Informe os totais calculados no Delphi');
            }
        }
        $total = $p['reforma_tributaria']['totais']['vNFTot'] ?? '';
        if (preg_match('/^\d{1,13}\.\d{2}$/D', $total)) {
            $somaCentavos = 0;
            foreach ($p['itens'] as $item) {
                $somaCentavos += (int) str_replace('.', '', $item['valor_item'] ?? '0');
            }
            if ($somaCentavos !== (int) str_replace('.', '', $total)) {
                $e->add('reforma_tributaria.totais.vNFTot', 'vNFTot diverge da soma dos vItem informados; corrija no Delphi');
            }
        }
        $e->throwIfAny();
        return [
            'itens' => array_fill(0, count($p['itens']), null), 'is' => array_fill(0, count($p['itens']), null),
            'usar_pl010' => false, 'avisos' => [],
            'resumo' => ['modo' => 'informado', 'grupo_informado' => $ibscbs, 'totais' => $p['reforma_tributaria']['totais'], 'schema' => XsdValidator::PACOTE],
        ];
    }

    private static function elemento(\DOMDocument $dom, string $name, mixed $value, array $tree): \DOMElement
    {
        $node = $dom->createElementNS(self::NS, $name);
        if ($tree === []) {
            $node->appendChild($dom->createTextNode($value));
        } else {
            // Ordem do JSON é irrelevante: XML sempre segue a sequência do XSD.
            foreach ($tree as $field => $children) {
                if (array_key_exists($field, $value)) {
                    $node->appendChild(self::elemento($dom, $field, $value[$field], $children));
                }
            }
        }
        return $node;
    }

    public static function aplicar(string $xml, array $p): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml, LIBXML_NONET);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('n', self::NS);
        foreach ($xp->query('//n:infNFe/n:det') as $i => $det) {
            $it = $p['itens'][$i];
            $imposto = $xp->query('n:imposto', $det)->item(0);
            foreach (['is' => ['IS', 'TIS'], 'ibscbs' => ['IBSCBS', 'TTribNFe']] as $key => [$tag, $type]) {
                if (isset($it['impostos'][$key])) {
                    $imposto->appendChild(self::elemento($dom, $tag, $it['impostos'][$key], self::campos($type)));
                }
            }
            $old = $xp->query('n:vItem', $det)->item(0);
            if ($old !== null) {
                $det->removeChild($old);
            }
            // vItem sucede obsItem e antecede DFeReferenciado na sequência do leiaute.
            $before = $xp->query('n:DFeReferenciado', $det)->item(0);
            $det->insertBefore(self::elemento($dom, 'vItem', $it['valor_item'], []), $before);
        }
        $total = $xp->query('//n:infNFe/n:total')->item(0);
        foreach (['ISTot' => 'TISTot', 'IBSCBSTot' => 'TIBSCBSMonoTot', 'vNFTot' => ''] as $tag => $type) {
            foreach (iterator_to_array($xp->query('n:' . $tag, $total)) as $old) {
                $total->removeChild($old);
            }
            if (isset($p['reforma_tributaria']['totais'][$tag])) {
                $total->appendChild(self::elemento($dom, $tag, $p['reforma_tributaria']['totais'][$tag], self::campos($type)));
            }
        }
        $xml = $dom->saveXML();
        $errors = XsdValidator::validateUnsignedNFe($xml);
        if ($errors !== []) {
            throw ApiException::validation(array_map(fn($m) => ['campo' => 'reforma_tributaria', 'mensagem' => mb_substr($m, 0, 500)], array_slice($errors, 0, 20)), 'RTC informada não passou no XSD; nenhum valor foi recalculado');
        }
        return $xml;
    }
}
