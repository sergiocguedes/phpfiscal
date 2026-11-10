<?php

declare(strict_types=1);

namespace App\Tributos;

use App\Http\ApiException;
use App\Storage\JsonStore;

/**
 * Tabela local de classificação tributária do IBS/CBS (cClassTrib, Informe Técnico 2025.002), global para todas
 * as empresas. Dela vêm o CST de cada cClassTrib, os percentuais de redução (pRedIBS, pRedCBS) e a vigência.
 *
 * Atualização (administrador da API): `php bin/cclasstrib.php importar <arquivo.csv|json>` com a planilha oficial
 * (aba cClassTrib salva em CSV) ou o JSON da API Conformidade Fácil, ou `php bin/cclasstrib.php svrs` para baixar
 * da API da SVRS com certificado ICP-Brasil. A importação substitui a tabela inteira e guarda a anterior.
 */
final class TabelaCClassTrib
{
    private const ARQUIVO = 'rtc/cclasstrib.json';
    private const ANTERIOR = 'rtc/cclasstrib-anterior.json';
    public const URL_SVRS = 'https://cff.svrs.rs.gov.br/api/v1/consultas/classTrib';

    /** @return array{versao:?string, atualizado_em:string, origem:string, total:int, itens:array<string,array<string,mixed>>}|null */
    public static function tabela(): ?array
    {
        return JsonStore::read(self::ARQUIVO);
    }

    /** @return array<string,mixed>|null */
    public static function item(string $cClassTrib): ?array
    {
        return self::tabela()['itens'][$cClassTrib] ?? null;
    }

    /** @return list<array<string,mixed>> */
    public static function listar(?string $cst = null, ?string $busca = null, int $limite = 500): array
    {
        $b = $busca === null ? '' : mb_strtolower(trim($busca));
        $out = [];
        foreach (self::tabela()['itens'] ?? [] as $i) {
            if (($cst === null || $i['CST'] === $cst)
                && ($b === '' || str_contains(mb_strtolower($i['cClassTrib'] . ' ' . $i['nome']), $b))) {
                $out[] = $i;
                if (count($out) >= $limite) {
                    break;
                }
            }
        }
        return $out;
    }

    /** Resumo sem os itens. @return array<string,mixed> */
    public static function info(): array
    {
        $t = self::tabela();
        return $t === null ? ['carregada' => false]
            : ['carregada' => true, 'versao' => $t['versao'], 'atualizado_em' => $t['atualizado_em'], 'origem' => $t['origem'], 'total' => $t['total']];
    }

    /**
     * Valida o cClassTrib contra a tabela local (existência, CST e vigência na data).
     * @return array<string,mixed> o item da tabela
     */
    public static function exigir(string $cst, string $cClassTrib, ?string $data = null, ?array $tabela = null, ?int $modelo = null): array
    {
        $tabela ??= self::tabela();
        if ($tabela === null) {
            throw new ApiException(422, 'tabela_cclasstrib_ausente',
                'Tabela local de cClassTrib não carregada. O administrador da API deve importá-la com bin/cclasstrib.php');
        }
        $i = $tabela['itens'][$cClassTrib] ?? throw ApiException::validation([['campo' => 'cClassTrib',
            'mensagem' => "cClassTrib $cClassTrib não existe na tabela local (versão " . ($tabela['versao'] ?? '?') . '). Atualize a tabela ou corrija a regra']]);
        if ($i['CST'] !== $cst) {
            throw ApiException::validation([['campo' => 'cClassTrib', 'mensagem' => "cClassTrib $cClassTrib é do CST {$i['CST']}, não do CST $cst"]]);
        }
        if ($data !== null && (($i['dIniVig'] ?? null) !== null && $data < $i['dIniVig'] || ($i['dFimVig'] ?? null) !== null && $data > $i['dFimVig'])) {
            throw ApiException::validation([['campo' => 'cClassTrib', 'mensagem' => "cClassTrib $cClassTrib fora da vigência em $data ("
                . ($i['dIniVig'] ?? '…') . ' a ' . ($i['dFimVig'] ?? '…') . ')']]);
        }
        $ind = $modelo === 65 ? 'indNFCe' : ($modelo === 55 ? 'indNFe' : null);
        if ($ind !== null && ($i[$ind] ?? null) === false) {
            throw ApiException::validation([['campo' => 'cClassTrib', 'mensagem' => "cClassTrib $cClassTrib não é permitido na " . ($modelo === 65 ? 'NFC-e' : 'NF-e') . ' (tabela cClassTrib)']]);
        }
        return $i;
    }

    // ------------------------------------------------------------------ atualização

    /**
     * Substitui a tabela a partir de linhas já lidas (CSV ou JSON). Aceita os nomes de coluna da planilha oficial
     * ("CST-IBS/CBS", "cClassTrib", "Nome cClassTrib", "pRedIBS", "pRedCBS", "dIniVig", "dFimVig") ou do JSON da SVRS.
     * @param list<array<string,mixed>> $linhas
     * @return array<string,mixed> resumo
     */
    public static function importarLinhas(array $linhas, string $origem, ?string $versao = null): array
    {
        $itens = [];
        $erros = [];
        foreach ($linhas as $n => $l) {
            $k = [];
            foreach ($l as $campo => $v) {
                $k[self::chave((string) $campo)] = is_string($v) ? trim($v) : $v;
            }
            $cc = preg_replace('/\D/', '', (string) ($k['cclasstrib'] ?? ''));
            if ($cc === '') {
                continue; // linha de título ou em branco
            }
            if (strlen($cc) !== 6) {
                $erros[] = 'linha ' . ($n + 1) . ": cClassTrib inválido ($cc)";
                continue;
            }
            $cst = preg_replace('/\D/', '', (string) ($k['cstibscbs'] ?? $k['cst'] ?? substr($cc, 0, 3)));
            $item = [
                'cClassTrib' => $cc,
                'CST' => str_pad($cst, 3, '0', STR_PAD_LEFT),
                'nome' => mb_substr((string) ($k['nomecclasstrib'] ?? $k['nome'] ?? $k['descricaocclasstrib'] ?? ''), 0, 300),
                'tipo_aliquota' => ($t = (string) ($k['tipodealiquota'] ?? $k['tipoaliquota'] ?? '')) === '' ? null : mb_substr($t, 0, 60),
                'pRedIBS' => self::pct($k['predibs'] ?? null),
                'pRedCBS' => self::pct($k['predcbs'] ?? null),
                'dIniVig' => self::data($k['dinivig'] ?? null),
                'dFimVig' => self::data($k['dfimvig'] ?? null),
                // indicadores de uso por documento (null = coluna ausente no arquivo: não restringe)
                'indNFe' => self::ind($k['indnfe'] ?? null),
                'indNFCe' => self::ind($k['indnfce'] ?? null),
                // ind_gCredPresOper: o cClassTrib admite o grupo de crédito presumido da operação
                'indCredPres' => self::ind($k['indgcredpresoper'] ?? $k['gcredpresoper'] ?? null),
            ];
            if ($item['pRedIBS'] === false || $item['pRedCBS'] === false) {
                $erros[] = "cClassTrib $cc: percentual de redução inválido";
                continue;
            }
            if (substr($cc, 0, 3) !== $item['CST']) {
                $erros[] = "cClassTrib $cc: CST {$item['CST']} diferente dos 3 primeiros dígitos";
                continue;
            }
            $itens[$cc] = $item;
        }
        if ($erros !== []) {
            throw new \InvalidArgumentException("Tabela recusada (nada foi alterado):\n- " . implode("\n- ", array_slice($erros, 0, 30)));
        }
        if (count($itens) < 1) {
            throw new \InvalidArgumentException('Nenhum cClassTrib encontrado. Confira se o arquivo tem as colunas cClassTrib, CST-IBS/CBS, pRedIBS e pRedCBS');
        }
        ksort($itens);
        $atual = JsonStore::read(self::ARQUIVO);
        if ($atual !== null) {
            JsonStore::write(self::ANTERIOR, $atual);
        }
        $nova = ['versao' => $versao, 'atualizado_em' => date('c'), 'origem' => $origem, 'total' => count($itens), 'itens' => $itens];
        JsonStore::write(self::ARQUIVO, $nova);
        $antes = $atual['itens'] ?? [];
        return ['total' => count($itens), 'incluidos' => count(array_diff_key($itens, $antes)), 'removidos' => count(array_diff_key($antes, $itens)),
            'alterados' => count(array_filter($itens, fn($i) => isset($antes[$i['cClassTrib']]) && $antes[$i['cClassTrib']] != $i))];
    }

    /** Lê CSV (separador ; ou , ; UTF-8 ou Windows-1252) ou JSON. @return list<array<string,mixed>> */
    public static function lerArquivo(string $caminho): array
    {
        $txt = @file_get_contents($caminho);
        if ($txt === false) {
            throw new \InvalidArgumentException("Não foi possível ler $caminho");
        }
        if (!mb_check_encoding($txt, 'UTF-8')) {
            $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
        }
        $txt = preg_replace('/^\xEF\xBB\xBF/', '', $txt);
        $json = json_decode($txt, true);
        if (is_array($json)) {
            return self::linhasJson($json);
        }
        $linhas = preg_split('/\r\n|\n|\r/', $txt);
        // cabeçalho: primeira linha que cita cClassTrib (a planilha pode ter títulos antes)
        $sep = ';';
        $cab = null;
        $out = [];
        foreach ($linhas as $linha) {
            if (trim($linha) === '') {
                continue;
            }
            if ($cab === null) {
                if (stripos($linha, 'cclasstrib') === false) {
                    continue;
                }
                $sep = substr_count($linha, ';') >= substr_count($linha, ',') ? ';' : ',';
                $cab = str_getcsv($linha, $sep, '"', '');
                continue;
            }
            $v = str_getcsv($linha, $sep, '"', '');
            $out[] = array_combine($cab, array_pad(array_slice($v, 0, count($cab)), count($cab), ''));
        }
        return $out;
    }

    /** Baixa a tabela da API Conformidade Fácil (SVRS) com certificado ICP-Brasil (mTLS). @return list<array<string,mixed>> */
    public static function baixarSvrs(string $pfx, string $senha): array
    {
        $bin = @file_get_contents($pfx);
        $certs = [];
        if ($bin === false || !openssl_pkcs12_read($bin, $certs, $senha)) {
            throw new \InvalidArgumentException('Certificado PFX ilegível ou senha incorreta');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'cff');
        file_put_contents($tmp, $certs['cert'] . $certs['pkey'] . implode('', $certs['extracerts'] ?? []));
        chmod($tmp, 0600);
        try {
            $ch = curl_init(self::URL_SVRS);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSLCERT => $tmp, CURLOPT_SSLCERTTYPE => 'PEM',
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 120,
                CURLOPT_HTTPHEADER => ['Accept: application/json']]);
            $corpo = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $erro = curl_error($ch);
            curl_close($ch);
        } finally {
            @unlink($tmp);
        }
        if (!is_string($corpo) || $status !== 200) {
            throw new \RuntimeException("SVRS respondeu HTTP $status" . ($erro !== '' ? " ($erro)" : ''));
        }
        $json = json_decode($corpo, true);
        if (!is_array($json)) {
            throw new \RuntimeException('Resposta da SVRS não é JSON');
        }
        return self::linhasJson($json);
    }

    /**
     * Achata o JSON (lista simples ou CST com lista de classificações): cada objeto que tem cClassTrib vira uma linha,
     * herdando o CST do objeto pai quando não tiver.
     * @param array<mixed> $json
     * @return list<array<string,mixed>>
     */
    private static function linhasJson(array $json, array $herdado = []): array
    {
        $out = [];
        $escalares = array_filter($json, fn($v) => !is_array($v));
        $temCc = false;
        foreach (array_keys($escalares) as $k) {
            $temCc = $temCc || self::chave((string) $k) === 'cclasstrib';
        }
        if ($temCc) {
            $out[] = $escalares + $herdado;
        }
        $cst = [];
        foreach ($escalares as $k => $v) {
            if (in_array(self::chave((string) $k), ['cst', 'cstibscbs'], true)) {
                $cst = ['CST' => $v];
            }
        }
        foreach ($json as $v) {
            if (is_array($v)) {
                $out = array_merge($out, self::linhasJson($v, $cst + $herdado));
            }
        }
        return $out;
    }

    private static function chave(string $k): string
    {
        $k = strtr(mb_strtolower($k), ['ç' => 'c', 'ã' => 'a', 'á' => 'a', 'í' => 'i', 'é' => 'e', 'ó' => 'o', 'ê' => 'e']);
        return preg_replace('/[^a-z0-9]/', '', $k);
    }

    private static function pct(mixed $v): string|int|float|false
    {
        if ($v === null || $v === '') {
            return 0;
        }
        if (is_int($v) || is_float($v)) {
            $s = (string) $v;
        } else {
            $s = str_replace([' ', '%'], '', (string) $v);
            $s = str_contains($s, ',') ? str_replace(['.', ','], ['', '.'], $s) : $s;
        }
        $d = Dec::de(is_numeric($s) ? (string) (0 + $s) : null);
        if ($d === null || Dec::cmp($d, '0') < 0 || Dec::cmp($d, '100') > 0) {
            return false;
        }
        return Dec::num($d);
    }

    private static function ind(mixed $v): ?bool
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = mb_strtolower(trim((string) $v));
        return in_array($s, ['1', 's', 'sim', 'true', 'x'], true) ? true : (in_array($s, ['0', 'n', 'nao', 'não', 'false'], true) ? false : null);
    }

    private static function data(mixed $v): ?string
    {
        $s = is_string($v) ? trim($v) : '';
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})#', $s, $m)) {
            return "$m[3]-$m[2]-$m[1]";
        }
        return preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m) ? $m[1] : null;
    }
}
