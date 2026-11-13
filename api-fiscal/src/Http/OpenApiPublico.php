<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Documentação pública: o que /openapi.yaml (e o Swagger em /docs) mostra.
 *
 * O openapi.yaml do projeto continua completo. Operações marcadas com "x-interno": true
 * são usadas só pelas telas da própria API (painel administrativo, /configuracao e
 * Portal do Contador): continuam existindo, protegidas pela autenticação de sempre,
 * mas saem da documentação pública. Tags e componentes que ficam sem uso também saem.
 */
final class OpenApiPublico
{
    private const METODOS = ['get', 'put', 'post', 'delete', 'patch', 'head', 'options', 'trace'];

    public static function gerar(string $json): string
    {
        // Objetos (não arrays associativos) para preservar {} vazios do documento.
        $doc = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        $tagsUsadas = [];
        foreach (get_object_vars($doc->paths ?? new \stdClass()) as $path => $item) {
            $temOperacao = false;
            foreach (self::METODOS as $m) {
                if (!isset($item->$m)) {
                    continue;
                }
                if (!empty($item->$m->{'x-interno'})) {
                    unset($item->$m);
                    continue;
                }
                $temOperacao = true;
                foreach ($item->$m->tags ?? [] as $t) {
                    $tagsUsadas[$t] = true;
                }
            }
            if (!$temOperacao) {
                unset($doc->paths->$path);
            }
        }
        if (isset($doc->tags)) {
            $doc->tags = array_values(array_filter($doc->tags, fn($t) => isset($tagsUsadas[$t->name])));
        }
        self::podarComponentes($doc);
        return json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /** Retira schemas, parâmetros, respostas etc. que só as operações internas usavam. */
    private static function podarComponentes(\stdClass $doc): void
    {
        if (!isset($doc->components)) {
            return;
        }
        $usados = [];
        $fila = self::refs($doc->paths ?? null);
        while ($fila !== []) {
            $ref = array_pop($fila);
            if (isset($usados[$ref])) {
                continue;
            }
            $usados[$ref] = true;
            [$tipo, $nome] = explode('/', $ref, 2);
            if (isset($doc->components->$tipo->$nome)) {
                array_push($fila, ...self::refs($doc->components->$tipo->$nome));
            }
        }
        foreach (get_object_vars($doc->components) as $tipo => $itens) {
            if ($tipo === 'securitySchemes' || !is_object($itens)) {
                continue;
            }
            foreach (array_keys(get_object_vars($itens)) as $nome) {
                if (!isset($usados["$tipo/$nome"])) {
                    unset($doc->components->$tipo->$nome);
                }
            }
        }
    }

    /** @return list<string> referências "tipo/nome" encontradas em $no */
    private static function refs(mixed $no): array
    {
        $out = [];
        if (is_object($no) || is_array($no)) {
            foreach ($no as $k => $v) {
                if ($k === '$ref' && is_string($v) && str_starts_with($v, '#/components/')) {
                    $out[] = substr($v, 13);
                } elseif (is_object($v) || is_array($v)) {
                    array_push($out, ...self::refs($v));
                }
            }
        }
        return $out;
    }
}
