<?php

declare(strict_types=1);

namespace App\Tributos;

use App\Http\ApiException;
use App\Storage\JsonStore;
use App\Support\Errors;
use App\Tenancy\Tenant;

/**
 * Matriz de regras tributárias da empresa (cadastro no painel), no mesmo desenho da "Listagem de Tributação
 * do ICMS" do ClickPDV:
 *  - perfis de ICMS e de PIS/COFINS (a "Tributação" do produto: ex. 1 Produto tributado, 2 Produto substituição);
 *  - operações (o "Imposto ICMS" da natureza: venda, devolução, entrada...);
 *  - regra de ICMS por perfil × operação × UF de destino × tipo de pessoa (F, J, E): CFOP, CST/CSOSN, alíquotas, IPI;
 *  - regra de PIS/COFINS por perfil × operação.
 * A regra guarda só parâmetros (CST, alíquotas, reduções, MVA); os campos aceitos dependem do CST.
 * Dados da empresa (iguais em homologação e produção): tenants/{id}/tributacao/...
 */
final class Matriz
{
    public const TIPOS_PESSOA = ['F', 'J', 'E'];
    public const TIPOS_PERFIL = ['icms', 'piscofins'];
    private const NOMES_TIPO = ['icms' => 'ICMS', 'piscofins' => 'PIS/COFINS'];
    public const UFS = ['AC', 'AL', 'AM', 'AP', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MG', 'MS', 'MT', 'PA', 'PB', 'PE', 'PI',
        'PR', 'RJ', 'RN', 'RO', 'RR', 'RS', 'SC', 'SE', 'SP', 'TO', 'EX'];
    private const CODIGO = '/^[A-Za-z0-9_-]{1,30}$/D';
    public const MAX_COPIAS = 3000;
    public const MAX_IMPORTACAO = 20000;

    private const ST = ['modBCST', 'pMVAST', 'pICMSST', 'pRedBCST', 'pFCPST'];
    private const DESON = ['motDesICMS', 'indDeduzDeson'];
    /** Desoneração calculada sobre a base (CST 30/40/41/50/90): o método segue a base legal do benefício. */
    private const DESON_BASE = [...self::DESON, 'metodo_desoneracao'];
    /** Parâmetros que cada CST/CSOSN aceita na regra (além do próprio CST). */
    public const CAMPOS_POR_CST = [
        '00' => ['modBC', 'pICMS', 'pFCP'],
        '10' => ['modBC', 'pICMS', 'pFCP', ...self::ST],
        '20' => ['modBC', 'pICMS', 'pRedBC', 'pFCP', ...self::DESON],
        '30' => ['pICMS', ...self::ST, ...self::DESON_BASE],
        '40' => ['pICMS', ...self::DESON_BASE],
        '41' => ['pICMS', ...self::DESON_BASE],
        '50' => ['pICMS', ...self::DESON_BASE],
        '51' => ['modBC', 'pICMS', 'pRedBC', 'pDif', 'pFCP'],
        '60' => [],
        '02' => [],
        '61' => [],
        '70' => ['modBC', 'pICMS', 'pRedBC', 'pFCP', ...self::ST, ...self::DESON],
        '90' => ['modBC', 'pICMS', 'pRedBC', 'pFCP', ...self::ST, ...self::DESON_BASE],
        '101' => ['pCredSN'],
        '102' => [], '103' => [], '300' => [], '400' => [], '500' => [],
        '201' => ['pCredSN', 'pICMS', ...self::ST],
        '202' => ['pICMS', ...self::ST],
        '203' => ['pICMS', ...self::ST],
        '900' => ['modBC', 'pICMS', 'pRedBC', 'pCredSN', ...self::ST],
    ];
    /** Obrigatórios por CST/CSOSN. */
    public const OBRIGATORIOS_POR_CST = [
        '10' => ['pICMSST'], '20' => ['pRedBC'], '30' => ['pICMSST'], '51' => ['pICMS'], '70' => ['pRedBC', 'pICMSST'],
        '101' => ['pCredSN'], '201' => ['pCredSN', 'pICMS', 'pICMSST'], '202' => ['pICMS', 'pICMSST'], '203' => ['pICMS', 'pICMSST'],
    ];
    private const IPI = ['CST', 'cEnq', 'pIPI', 'vUnid'];

    // ------------------------------------------------------------------ cadastro (perfis e operações)

    /** @return array{perfis: list<array<string,mixed>>, operacoes: list<array<string,mixed>>} */
    public static function cadastro(): array
    {
        $c = JsonStore::read(Tenant::path('tributacao/cadastro.json')) ?? [];
        $ord = fn(array $l) => array_values(array_map(fn($x) => array_diff_key($x, ['tipo' => 1]), self::ordenar($l)));
        return ['perfis' => $ord($c['perfis'] ?? []), 'operacoes' => $ord($c['operacoes'] ?? [])];
    }

    /** @param array<mixed> $in @return array<string,mixed> */
    public static function salvarPerfil(string $codigo, array $in): array
    {
        self::codigo($codigo, 'codigo');
        $e = new Errors();
        $nome = self::nome($in['nome'] ?? null, $e);
        // o perfil vale para ICMS e PIS/COFINS ao mesmo tempo; "tipo" antigo é aceito e ignorado
        if (isset($in['tipo']) && !in_array($in['tipo'], self::TIPOS_PERFIL, true)) {
            $e->add('tipo', 'Não é mais usado: o perfil vale para ICMS e PIS/COFINS');
        }
        $e->throwIfAny();
        return self::atualizarCadastro(function (array $c) use ($codigo, $nome) {
            $c['perfis'][$codigo] = ['codigo' => $codigo, 'nome' => $nome, 'atualizado_em' => date('c')];
            return [$c, $c['perfis'][$codigo]];
        });
    }

    /**
     * Inclusão com código automático (sequencial numérico: maior código numérico + 1).
     * @param 'perfis'|'operacoes' $tipo @param array<mixed> $in @return array<string,mixed>
     */
    public static function criar(string $tipo, array $in): array
    {
        $codigo = self::proximoCodigo(array_column(self::cadastro()[$tipo], 'codigo'));
        return $tipo === 'perfis' ? self::salvarPerfil($codigo, $in) : self::salvarOperacao($codigo, $in);
    }

    /** @param list<int|string> $codigos */
    public static function proximoCodigo(array $codigos): string
    {
        $max = 0;
        foreach ($codigos as $c) {
            if (preg_match('/^\d{1,9}$/D', (string) $c)) {
                $max = max($max, (int) $c);
            }
        }
        return (string) ($max + 1);
    }

    /** @param array<mixed> $in @return array<string,mixed> */
    public static function salvarOperacao(string $codigo, array $in): array
    {
        self::codigo($codigo, 'codigo');
        $e = new Errors();
        $nome = self::nome($in['nome'] ?? null, $e);
        $e->throwIfAny();
        return self::atualizarCadastro(function (array $c) use ($codigo, $nome) {
            $c['operacoes'][$codigo] = ['codigo' => $codigo, 'nome' => $nome, 'atualizado_em' => date('c')];
            return [$c, $c['operacoes'][$codigo]];
        });
    }

    public static function excluirPerfil(string $codigo): void
    {
        self::atualizarCadastro(function (array $c) use ($codigo) {
            isset($c['perfis'][$codigo]) ?: throw Tenant::notFound('Perfil');
            if (self::temRegras('icms', $codigo, null) || self::temRegras('piscofins', $codigo, null)) {
                throw new ApiException(409, 'perfil_em_uso', 'Exclua antes as regras deste perfil');
            }
            unset($c['perfis'][$codigo]);
            return [$c, null];
        });
    }

    public static function excluirOperacao(string $codigo): void
    {
        self::atualizarCadastro(function (array $c) use ($codigo) {
            isset($c['operacoes'][$codigo]) ?: throw Tenant::notFound('Operação');
            if (self::temRegras('icms', null, $codigo) || self::temRegras('piscofins', null, $codigo)) {
                throw new ApiException(409, 'operacao_em_uso', 'Exclua antes as regras desta operação');
            }
            unset($c['operacoes'][$codigo]);
            return [$c, null];
        });
    }

    // ------------------------------------------------------------------ regras de ICMS

    /** @return list<array<string,mixed>> */
    public static function listarIcms(?string $perfil, ?string $operacao, ?string $uf): array
    {
        $p = $perfil ?? '*';
        $o = $operacao ?? '*';
        $u = $uf ?? '*';
        foreach ([$p, $o] as $v) {
            if ($v !== '*' && !preg_match(self::CODIGO, $v)) {
                return [];
            }
        }
        if ($u !== '*' && !in_array($u, self::UFS, true)) {
            return [];
        }
        $out = [];
        foreach (JsonStore::files(Tenant::path("tributacao/icms/$p/$o/$u-*.json")) as $f) {
            $r = JsonStore::read($f);
            if ($r !== null) {
                $out[] = $r;
            }
        }
        usort($out, fn($a, $b) => [$a['perfil'], $a['operacao'], $a['uf'], $a['tipo_pessoa']] <=> [$b['perfil'], $b['operacao'], $b['uf'], $b['tipo_pessoa']]);
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function regraIcms(string $perfil, string $operacao, string $uf, string $tipo): ?array
    {
        if (!preg_match(self::CODIGO, $perfil) || !preg_match(self::CODIGO, $operacao) || !in_array($uf, self::UFS, true)
            || !in_array($tipo, self::TIPOS_PESSOA, true)) {
            return null;
        }
        return JsonStore::read(self::arqIcms($perfil, $operacao, $uf, $tipo));
    }

    /** @param array<mixed> $in @return array<string,mixed> */
    public static function salvarIcms(string $perfil, string $operacao, string $uf, string $tipo, array $in): array
    {
        $r = self::validarIcms($perfil, $operacao, $uf, $tipo, $in, self::cadastro());
        JsonStore::write(self::arqIcms($perfil, $operacao, $uf, $tipo), $r);
        return $r;
    }

    public static function excluirIcms(string $perfil, string $operacao, string $uf, string $tipo): void
    {
        self::regraIcms($perfil, $operacao, $uf, $tipo) ?? throw Tenant::notFound('Regra de ICMS');
        JsonStore::delete(self::arqIcms($perfil, $operacao, $uf, $tipo));
    }

    /**
     * Copia uma regra (ou todas as regras de um perfil/operação/UF, sem tipo_pessoa) para outras combinações.
     * @param array<mixed> $in {origem:{perfil,operacao,uf,tipo_pessoa?}, destino:{perfis?,operacoes?,ufs?,tipos_pessoa?}, sobrescrever?}
     * @return array{criadas:int, sobrescritas:int, ignoradas:list<string>}
     */
    public static function copiarIcms(array $in): array
    {
        $e = new Errors();
        $str = fn($v) => is_int($v) ? (string) $v : $v;
        $o = array_map($str, is_array($in['origem'] ?? null) ? $in['origem'] : []);
        $d = array_map(fn($l) => is_array($l) ? array_map($str, $l) : $l, is_array($in['destino'] ?? null) ? $in['destino'] : []);
        $sobrescrever = ($in['sobrescrever'] ?? false) === true;
        foreach (['perfil', 'operacao', 'uf'] as $k) {
            if (!is_string($o[$k] ?? null)) {
                $e->add("origem.$k", 'Obrigatório');
            }
        }
        $e->throwIfAny();
        $origens = array_values(array_filter(self::listarIcms($o['perfil'], $o['operacao'], strtoupper($o['uf'])),
            fn($r) => !isset($o['tipo_pessoa']) || $r['tipo_pessoa'] === $o['tipo_pessoa']));
        if ($origens === []) {
            throw Tenant::notFound('Regra de origem');
        }
        $cad = self::cadastro();
        $lista = function (string $k, array $todos, array $padrao) use ($d, $e): array {
            $v = $d[$k] ?? $padrao;
            if ($v === ['*']) {
                return $todos;
            }
            if (!is_array($v) || !array_is_list($v) || $v === [] || array_diff($v, $todos) !== []) {
                $e->add("destino.$k", 'Lista com valores cadastrados (ou ["*"] para todos)');
                return [];
            }
            return array_values(array_unique($v));
        };
        $perfis = $lista('perfis', array_column($cad['perfis'], 'codigo'), [$o['perfil']]);
        $ops = $lista('operacoes', array_column($cad['operacoes'], 'codigo'), [$o['operacao']]);
        $ufs = $lista('ufs', self::UFS, [strtoupper($o['uf'])]);
        $tipos = isset($d['tipos_pessoa']) ? $lista('tipos_pessoa', self::TIPOS_PESSOA, []) : null;
        $e->throwIfAny();
        $total = count($perfis) * count($ops) * count($ufs) * ($tipos === null ? count($origens) : count($tipos));
        if ($total > self::MAX_COPIAS) {
            throw ApiException::validation([['campo' => 'destino', 'mensagem' => "Cópia de $total regras excede o limite de " . self::MAX_COPIAS]]);
        }
        $res = ['criadas' => 0, 'sobrescritas' => 0, 'ignoradas' => []];
        foreach ($origens as $src) {
            foreach ($perfis as $p) {
                foreach ($ops as $op) {
                    foreach ($ufs as $uf) {
                        foreach ($tipos ?? [$src['tipo_pessoa']] as $tp) {
                            $chave = "$p/$op/$uf/$tp";
                            if ($p === $src['perfil'] && $op === $src['operacao'] && $uf === $src['uf'] && $tp === $src['tipo_pessoa']) {
                                continue;
                            }
                            $existe = self::regraIcms($p, $op, $uf, $tp) !== null;
                            if ($existe && !$sobrescrever) {
                                $res['ignoradas'][] = $chave;
                                continue;
                            }
                            self::salvarIcms($p, $op, $uf, $tp, array_intersect_key($src, array_flip(['descricao', 'cfop', 'icms', 'ipi'])));
                            $res[$existe ? 'sobrescritas' : 'criadas']++;
                        }
                    }
                }
            }
        }
        return $res;
    }

    // ------------------------------------------------------------------ regras de PIS/COFINS

    /** @return list<array<string,mixed>> */
    public static function listarPisCofins(?string $perfil, ?string $operacao): array
    {
        $p = $perfil ?? '*';
        $o = $operacao ?? '*';
        foreach ([$p, $o] as $v) {
            if ($v !== '*' && !preg_match(self::CODIGO, $v)) {
                return [];
            }
        }
        $out = [];
        foreach (JsonStore::files(Tenant::path("tributacao/piscofins/$p/$o.json")) as $f) {
            $r = JsonStore::read($f);
            if ($r !== null) {
                $out[] = $r;
            }
        }
        usort($out, fn($a, $b) => [$a['perfil'], $a['operacao']] <=> [$b['perfil'], $b['operacao']]);
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function regraPisCofins(string $perfil, string $operacao): ?array
    {
        if (!preg_match(self::CODIGO, $perfil) || !preg_match(self::CODIGO, $operacao)) {
            return null;
        }
        return JsonStore::read(Tenant::path("tributacao/piscofins/$perfil/$operacao.json"));
    }

    /** @param array<mixed> $in @return array<string,mixed> */
    public static function salvarPisCofins(string $perfil, string $operacao, array $in): array
    {
        $r = self::validarPisCofins($perfil, $operacao, $in, self::cadastro());
        JsonStore::write(Tenant::path("tributacao/piscofins/$perfil/$operacao.json"), $r);
        return $r;
    }

    public static function excluirPisCofins(string $perfil, string $operacao): void
    {
        self::regraPisCofins($perfil, $operacao) ?? throw Tenant::notFound('Regra de PIS/COFINS');
        JsonStore::delete(Tenant::path("tributacao/piscofins/$perfil/$operacao.json"));
    }

    /**
     * Copia uma regra de PIS/COFINS para outros perfis e/ou operações.
     * @param array<mixed> $in {origem:{perfil,operacao}, destino:{perfis?,operacoes?}, sobrescrever?}
     * @return array{criadas:int, sobrescritas:int, ignoradas:list<string>}
     */
    public static function copiarPisCofins(array $in): array
    {
        $e = new Errors();
        $str = fn($v) => is_int($v) ? (string) $v : $v;
        $o = array_map($str, is_array($in['origem'] ?? null) ? $in['origem'] : []);
        $d = array_map(fn($l) => is_array($l) ? array_map($str, $l) : $l, is_array($in['destino'] ?? null) ? $in['destino'] : []);
        foreach (['perfil', 'operacao'] as $k) {
            is_string($o[$k] ?? null) ?: $e->add("origem.$k", 'Obrigatório');
        }
        $e->throwIfAny();
        $src = self::regraPisCofins($o['perfil'], $o['operacao']) ?? throw Tenant::notFound('Regra de origem');
        $cad = self::cadastro();
        $lista = function (string $k, array $todos, array $padrao) use ($d, $e): array {
            $v = $d[$k] ?? $padrao;
            if ($v === ['*']) {
                return $todos;
            }
            if (!is_array($v) || !array_is_list($v) || $v === [] || array_diff($v, $todos) !== []) {
                $e->add("destino.$k", 'Lista com valores cadastrados (ou ["*"] para todos)');
                return [];
            }
            return array_values(array_unique($v));
        };
        $perfis = $lista('perfis', array_column($cad['perfis'], 'codigo'), [$o['perfil']]);
        $ops = $lista('operacoes', array_column($cad['operacoes'], 'codigo'), [$o['operacao']]);
        $e->throwIfAny();
        if (count($perfis) * count($ops) > self::MAX_COPIAS) {
            throw ApiException::validation([['campo' => 'destino', 'mensagem' => 'Cópia excede o limite de ' . self::MAX_COPIAS . ' regras']]);
        }
        $res = ['criadas' => 0, 'sobrescritas' => 0, 'ignoradas' => []];
        foreach ($perfis as $p) {
            foreach ($ops as $op) {
                if ($p === $src['perfil'] && $op === $src['operacao']) {
                    continue;
                }
                $existe = self::regraPisCofins($p, $op) !== null;
                if ($existe && ($in['sobrescrever'] ?? false) !== true) {
                    $res['ignoradas'][] = "$p/$op";
                    continue;
                }
                self::salvarPisCofins($p, $op, array_intersect_key($src, array_flip(['descricao', 'pis', 'cofins'])));
                $res[$existe ? 'sobrescritas' : 'criadas']++;
            }
        }
        return $res;
    }

    // ------------------------------------------------------------------ importação em lote

    /**
     * Carga inicial (ex.: a partir das tabelas do ClickPDV). Valida tudo antes de gravar (tudo ou nada).
     * @param array<mixed> $in {perfis, operacoes, regras_icms, regras_piscofins, sobrescrever?}
     * @return array<string,int>
     */
    public static function importar(array $in): array
    {
        $e = new Errors();
        $sobrescrever = ($in['sobrescrever'] ?? true) === true;
        $lista = function (string $k) use ($in, $e): array {
            $v = $in[$k] ?? [];
            if (!is_array($v) || ($v !== [] && !array_is_list($v))) {
                $e->add($k, 'Lista');
                return [];
            }
            return $v;
        };
        $perfis = $lista('perfis');
        $ops = $lista('operacoes');
        $ricms = $lista('regras_icms');
        $rpc = $lista('regras_piscofins');
        if (count($ricms) + count($rpc) > self::MAX_IMPORTACAO) {
            $e->add('regras_icms', 'Máximo de ' . self::MAX_IMPORTACAO . ' regras por importação');
        }
        $e->throwIfAny();

        $cad = self::cadastro();
        $novoCad = ['perfis' => array_column($cad['perfis'], null, 'codigo'), 'operacoes' => array_column($cad['operacoes'], null, 'codigo')];
        foreach ($perfis as $i => $p) {
            $cod = is_array($p) ? (string) ($p['codigo'] ?? '') : '';
            preg_match(self::CODIGO, $cod) ?: $e->add("perfis[$i].codigo", 'Código com 1 a 30 caracteres [A-Za-z0-9_-]');
            $nome = self::nome(is_array($p) ? ($p['nome'] ?? null) : null, $e, "perfis[$i].nome");
            $novoCad['perfis'][$cod] = ['codigo' => $cod, 'nome' => $nome, 'atualizado_em' => date('c')];
        }
        foreach ($ops as $i => $o) {
            $cod = is_array($o) ? (string) ($o['codigo'] ?? '') : '';
            preg_match(self::CODIGO, $cod) ?: $e->add("operacoes[$i].codigo", 'Código com 1 a 30 caracteres [A-Za-z0-9_-]');
            $nome = self::nome(is_array($o) ? ($o['nome'] ?? null) : null, $e, "operacoes[$i].nome");
            $novoCad['operacoes'][$cod] = ['codigo' => $cod, 'nome' => $nome, 'atualizado_em' => date('c')];
        }
        $e->throwIfAny();
        $cadLista = ['perfis' => array_values($novoCad['perfis']), 'operacoes' => array_values($novoCad['operacoes'])];

        $gravar = [];
        foreach ($ricms as $i => $r) {
            $r = is_array($r) ? $r : [];
            try {
                $v = self::validarIcms((string) ($r['perfil'] ?? ''), (string) ($r['operacao'] ?? ''), strtoupper((string) ($r['uf'] ?? '')),
                    strtoupper((string) ($r['tipo_pessoa'] ?? '')), $r, $cadLista);
                $gravar[self::arqIcms($v['perfil'], $v['operacao'], $v['uf'], $v['tipo_pessoa'])] = $v;
            } catch (ApiException $x) {
                foreach ($x->details as $det) {
                    $e->add("regras_icms[$i]." . ($det['campo'] ?? ''), (string) ($det['mensagem'] ?? $x->getMessage()));
                }
            }
        }
        foreach ($rpc as $i => $r) {
            $r = is_array($r) ? $r : [];
            try {
                $v = self::validarPisCofins((string) ($r['perfil'] ?? ''), (string) ($r['operacao'] ?? ''), $r, $cadLista);
                $gravar[Tenant::path("tributacao/piscofins/{$v['perfil']}/{$v['operacao']}.json")] = $v;
            } catch (ApiException $x) {
                foreach ($x->details as $det) {
                    $e->add("regras_piscofins[$i]." . ($det['campo'] ?? ''), (string) ($det['mensagem'] ?? $x->getMessage()));
                }
            }
        }
        if (count($e->all()) > 200) {
            throw ApiException::validation(array_slice($e->all(), 0, 200), 'Importação recusada: mais de 200 erros (mostrando os 200 primeiros)');
        }
        $e->throwIfAny();

        JsonStore::write(Tenant::path('tributacao/cadastro.json'), $novoCad);
        $cont = ['perfis' => count($perfis), 'operacoes' => count($ops), 'regras_gravadas' => 0, 'regras_ignoradas' => 0];
        foreach ($gravar as $arq => $v) {
            if (!$sobrescrever && JsonStore::read($arq) !== null) {
                $cont['regras_ignoradas']++;
                continue;
            }
            JsonStore::write($arq, $v);
            $cont['regras_gravadas']++;
        }
        return $cont;
    }

    // ------------------------------------------------------------------ validação

    /**
     * @param array<mixed> $in
     * @param array{perfis: list<array<string,mixed>>, operacoes: list<array<string,mixed>>} $cad
     * @return array<string,mixed>
     */
    private static function validarIcms(string $perfil, string $operacao, string $uf, string $tipo, array $in, array $cad): array
    {
        $e = new Errors();
        self::chaves($perfil, 'icms', $operacao, $cad, $e);
        in_array($uf, self::UFS, true) ?: $e->add('uf', 'UF de destino inválida');
        in_array($tipo, self::TIPOS_PESSOA, true) ?: $e->add('tipo_pessoa', 'F (física), J (jurídica) ou E (estrangeiro)');
        $descricao = self::nome($in['descricao'] ?? null, $e, 'descricao', 120);
        $cfop = (string) ($in['cfop'] ?? '');
        preg_match('/^[1-35-7]\d{3}$/D', $cfop) ?: $e->add('cfop', 'CFOP com 4 dígitos');
        $icms = is_array($in['icms'] ?? null) && !array_is_list($in['icms']) ? $in['icms'] : null;
        $out = ['perfil' => $perfil, 'operacao' => $operacao, 'uf' => $uf, 'tipo_pessoa' => $tipo, 'descricao' => $descricao, 'cfop' => $cfop];
        if ($icms === null) {
            $e->add('icms', 'Objeto com CST (ou CSOSN) e os parâmetros do CST');
        } else {
            $cst = isset($icms['CST']) ? (string) $icms['CST'] : null;
            $csosn = isset($icms['CSOSN']) ? (string) $icms['CSOSN'] : null;
            $codigo = $cst ?? $csosn;
            if (($cst === null) === ($csosn === null) || !isset(self::CAMPOS_POR_CST[$codigo])
                || ($cst !== null && strlen($cst) !== 2) || ($csosn !== null && strlen($csosn) !== 3)) {
                $e->add('icms.CST', 'Informe CST (00, 02, 10, 20, 30, 40, 41, 50, 51, 60, 61, 70, 90) ou CSOSN (101–103, 201–203, 300, 400, 500, 900)');
            } else {
                $g = [$cst !== null ? 'CST' : 'CSOSN' => $codigo];
                foreach ($icms as $k => $v) {
                    if ($k === 'CST' || $k === 'CSOSN' || $v === null) {
                        continue;
                    }
                    if (!in_array($k, self::CAMPOS_POR_CST[$codigo], true)) {
                        $e->add("icms.$k", "Não se aplica ao " . ($cst !== null ? 'CST' : 'CSOSN') . " $codigo");
                        continue;
                    }
                    $x = self::param('icms', (string) $k, $v);
                    $x === null ? $e->add("icms.$k", 'Valor inválido') : $g[$k] = $x;
                }
                foreach (self::OBRIGATORIOS_POR_CST[$codigo] ?? [] as $k) {
                    isset($g[$k]) ?: $e->add("icms.$k", "Obrigatório no " . ($cst !== null ? 'CST' : 'CSOSN') . " $codigo");
                }
                if (in_array('pMVAST', self::CAMPOS_POR_CST[$codigo], true) && isset($g['pICMSST']) && !isset($g['pMVAST'])
                    && (int) ($g['modBCST'] ?? 4) === 4) {
                    $e->add('icms.pMVAST', 'MVA obrigatória com modalidade BC ST 4 (MVA)');
                }
                $out['icms'] = $g;
            }
        }
        if (isset($in['ipi']) && $in['ipi'] !== null) {
            $ipi = is_array($in['ipi']) && !array_is_list($in['ipi']) ? $in['ipi'] : [];
            $g = [];
            foreach ($ipi as $k => $v) {
                if (!in_array($k, self::IPI, true)) {
                    $e->add("ipi.$k", 'Campo não permitido');
                    continue;
                }
                $x = self::param('ipi', (string) $k, $v);
                $x === null ? $e->add("ipi.$k", 'Valor inválido') : $g[$k] = $x;
            }
            isset($g['CST']) ?: $e->add('ipi.CST', 'Obrigatório');
            $out['ipi'] = $g;
        }
        $e->throwIfAny();
        $out['atualizado_em'] = date('c');
        return $out;
    }

    /**
     * @param array<mixed> $in
     * @param array{perfis: list<array<string,mixed>>, operacoes: list<array<string,mixed>>} $cad
     * @return array<string,mixed>
     */
    private static function validarPisCofins(string $perfil, string $operacao, array $in, array $cad): array
    {
        $e = new Errors();
        self::chaves($perfil, 'piscofins', $operacao, $cad, $e);
        $out = ['perfil' => $perfil, 'operacao' => $operacao, 'descricao' => self::nome($in['descricao'] ?? null, $e, 'descricao', 120)];
        foreach (['pis' => 'pPIS', 'cofins' => 'pCOFINS'] as $grupo => $aliq) {
            $g = is_array($in[$grupo] ?? null) && !array_is_list($in[$grupo]) ? $in[$grupo] : null;
            if ($g === null) {
                $e->add($grupo, 'Objeto com CST e alíquota');
                continue;
            }
            $o = [];
            foreach ($g as $k => $v) {
                if (!in_array($k, ['CST', $aliq, 'vAliqProd'], true)) {
                    $e->add("$grupo.$k", 'Campo não permitido');
                    continue;
                }
                if ($v === null) {
                    continue;
                }
                $x = self::param($grupo, (string) $k, $v);
                $x === null ? $e->add("$grupo.$k", 'Valor inválido') : $o[$k] = $x;
            }
            $cst = (string) ($o['CST'] ?? '');
            if (!preg_match('/^(0[1-9]|49|5[0-6]|6[0-7]|7[0-5]|98|99)$/D', $cst)) {
                $e->add("$grupo.CST", 'CST de PIS/COFINS inválido');
            } elseif (in_array($cst, ['01', '02'], true) && !isset($o[$aliq])) {
                $e->add("$grupo.$aliq", "Obrigatória no CST $cst");
            } elseif ($cst === '03' && !isset($o['vAliqProd'])) {
                $e->add("$grupo.vAliqProd", 'Alíquota em reais obrigatória no CST 03');
            } elseif (in_array($cst, ['04', '05', '06', '07', '08', '09'], true) && count($o) > 1) {
                $e->add($grupo, "CST $cst não tem alíquota");
            }
            $out[$grupo] = $o;
        }
        $e->throwIfAny();
        $out['atualizado_em'] = date('c');
        return $out;
    }

    /** Valida e normaliza um parâmetro usando os tipos de Entrada::CAMPOS. */
    private static function param(string $grupo, string $k, mixed $v): string|int|float|null
    {
        $tipo = Entrada::CAMPOS[$grupo][$k] ?? null;
        if ($tipo === null) {
            return null;
        }
        if (is_int($tipo)) {
            $d = Entrada::valor($v, $tipo);
            if ($d === null || (str_starts_with($k, 'p') && !in_array($k, Entrada::SEM_TETO, true) && Dec::cmp($d, '100') > 0)) {
                return null;
            }
            return Dec::num($d);
        }
        $s = is_int($v) ? (string) $v : $v;
        if (!is_string($s) || !preg_match($tipo, $s)) {
            return null;
        }
        return in_array($k, ['modBC', 'modBCST', 'motDesICMS', 'indDeduzDeson'], true) ? (int) $s : $s;
    }

    /** @param array{perfis: list<array<string,mixed>>, operacoes: list<array<string,mixed>>} $cad */
    private static function chaves(string $perfil, string $tipo, string $operacao, array $cad, Errors $e): void
    {
        $p = array_values(array_filter($cad['perfis'], fn($x) => $x['codigo'] === $perfil))[0] ?? null;
        if ($p === null) {
            $e->add('perfil', 'Perfil não cadastrado');
        }
        in_array($operacao, array_column($cad['operacoes'], 'codigo'), true) ?: $e->add('operacao', 'Operação não cadastrada');
    }

    private static function temRegras(string $tipo, ?string $perfil, ?string $operacao): bool
    {
        return match ($tipo) {
            'icms' => self::listarIcms($perfil, $operacao, null) !== [],
            default => self::listarPisCofins($perfil, $operacao) !== [],
        };
    }

    private static function arqIcms(string $perfil, string $operacao, string $uf, string $tipo): string
    {
        return Tenant::path("tributacao/icms/$perfil/$operacao/$uf-$tipo.json");
    }

    /** @param callable(array<string,mixed>): array{0: array<string,mixed>, 1: mixed} $fn */
    private static function atualizarCadastro(callable $fn): mixed
    {
        return JsonStore::update(Tenant::path('tributacao/cadastro.json'), fn(?array $c) => $fn($c ?? ['perfis' => [], 'operacoes' => []]));
    }

    private static function codigo(string $v, string $campo): void
    {
        if (!preg_match(self::CODIGO, $v)) {
            throw ApiException::validation([['campo' => $campo, 'mensagem' => 'Código com 1 a 30 caracteres [A-Za-z0-9_-]']]);
        }
    }

    private static function nome(mixed $v, Errors $e, string $campo = 'nome', int $max = 60): string
    {
        $s = \App\Support\Validate::text($v, 1, $max);
        if ($s === null) {
            $e->add($campo, "1 a $max caracteres");
            return '';
        }
        return $s;
    }

    /** @param array<string,array<string,mixed>> $l @return array<string,array<string,mixed>> */
    private static function ordenar(array $l): array
    {
        uksort($l, fn($a, $b) => strnatcmp((string) $a, (string) $b));
        return $l;
    }
}
