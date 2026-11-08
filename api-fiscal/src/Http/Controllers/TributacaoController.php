<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Support\Logger;
use App\Tributos\Matriz;
use App\Tributos\RegraDetalhe;
use App\Tributos\RegrasIss;
use App\Tenancy\Tenant;

/**
 * /api/v1/tributacao — matriz de regras tributárias da empresa (perfis, operações, regras de ICMS e PIS/COFINS).
 * Leitura: qualquer credencial da empresa. Alteração: proprietário (chaves de API contam como proprietário).
 */
final class TributacaoController
{
    public static function cadastro(): Response
    {
        return Response::json(Matriz::cadastro() + [
            'campos_por_cst' => Matriz::CAMPOS_POR_CST,
            'obrigatorios_por_cst' => Matriz::OBRIGATORIOS_POR_CST,
            'ufs' => Matriz::UFS,
            'tipos_pessoa' => Matriz::TIPOS_PESSOA,
        ]);
    }

    public static function salvarPerfil(Request $req): Response
    {
        $r = Matriz::salvarPerfil($req->params['codigo'], $req->json());
        Logger::audit('tributacao.perfil', 'salvo', ['codigo' => $r['codigo'], 'tipo' => $r['tipo']]);
        return Response::json($r);
    }

    public static function excluirPerfil(Request $req): Response
    {
        Matriz::excluirPerfil($req->params['codigo']);
        Logger::audit('tributacao.perfil', 'excluido', ['codigo' => $req->params['codigo']]);
        return Response::noContent();
    }

    public static function salvarOperacao(Request $req): Response
    {
        $r = Matriz::salvarOperacao($req->params['codigo'], $req->json());
        Logger::audit('tributacao.operacao', 'salva', ['codigo' => $r['codigo']]);
        return Response::json($r);
    }

    public static function excluirOperacao(Request $req): Response
    {
        Matriz::excluirOperacao($req->params['codigo']);
        Logger::audit('tributacao.operacao', 'excluida', ['codigo' => $req->params['codigo']]);
        return Response::noContent();
    }

    /** Lista com nomes de perfil/operação e descrição do CST, para o ERP montar a grade. Filtros: perfil, operacao, uf, tipo_pessoa. */
    public static function listarIcms(Request $req): Response
    {
        $q = fn(string $k) => is_string($req->query[$k] ?? null) && $req->query[$k] !== '' ? $req->query[$k] : null;
        $tp = ($t = $q('tipo_pessoa')) === null ? null : strtoupper($t);
        $cad = Matriz::cadastro();
        $regras = Matriz::listarIcms($q('perfil'), $q('operacao'), ($u = $q('uf')) === null ? null : strtoupper($u));
        $regras = array_values(array_filter($regras, fn($r) => $tp === null || $r['tipo_pessoa'] === $tp));
        return Response::json(['total' => count($regras), 'regras' => array_map(fn($r) => RegraDetalhe::resumoIcms($r, $cad), $regras)]);
    }

    /** Regra de ICMS com cada campo rotulado e o significado dos códigos. */
    public static function detalheIcms(Request $req): Response
    {
        $r = Matriz::regraIcms(...self::chaveIcms($req)) ?? throw Tenant::notFound('Regra de ICMS');
        return Response::json(RegraDetalhe::detalheIcms($r, Matriz::cadastro()));
    }

    public static function salvarIcms(Request $req): Response
    {
        $k = self::chaveIcms($req);
        $r = Matriz::salvarIcms($k[0], $k[1], $k[2], $k[3], $req->json());
        Logger::audit('tributacao.regra_icms', 'salva', ['chave' => implode('/', $k)]);
        return Response::json($r);
    }

    public static function excluirIcms(Request $req): Response
    {
        $k = self::chaveIcms($req);
        Matriz::excluirIcms(...$k);
        Logger::audit('tributacao.regra_icms', 'excluida', ['chave' => implode('/', $k)]);
        return Response::noContent();
    }

    public static function copiarIcms(Request $req): Response
    {
        $r = Matriz::copiarIcms($req->json());
        Logger::audit('tributacao.regra_icms', 'copiada', ['criadas' => $r['criadas'], 'sobrescritas' => $r['sobrescritas'], 'ignoradas' => count($r['ignoradas'])]);
        return Response::json($r);
    }

    public static function listarPisCofins(Request $req): Response
    {
        $q = fn(string $k) => is_string($req->query[$k] ?? null) && $req->query[$k] !== '' ? $req->query[$k] : null;
        $cad = Matriz::cadastro();
        $regras = Matriz::listarPisCofins($q('perfil'), $q('operacao'));
        return Response::json(['total' => count($regras), 'regras' => array_map(fn($r) => RegraDetalhe::resumoPisCofins($r, $cad), $regras)]);
    }

    public static function detalhePisCofins(Request $req): Response
    {
        $r = Matriz::regraPisCofins($req->params['perfil'], $req->params['operacao']) ?? throw Tenant::notFound('Regra de PIS/COFINS');
        return Response::json(RegraDetalhe::detalhePisCofins($r, Matriz::cadastro()));
    }

    // ------------------------------------------------------------------ regras de ISS (NFS-e)

    /** Filtros opcionais: busca (código, descrição ou código de tributação nacional). */
    public static function listarIss(Request $req): Response
    {
        $b = is_string($req->query['busca'] ?? null) ? mb_strtolower(trim($req->query['busca'])) : '';
        $regras = array_values(array_filter(RegrasIss::listar(), fn($r) => $b === ''
            || str_contains(mb_strtolower($r['codigo'] . ' ' . $r['descricao'] . ' ' . ($r['servico']['codigo_tributacao_nacional'] ?? '')), $b)));
        return Response::json(['total' => count($regras), 'regras' => array_map([RegraDetalhe::class, 'resumoIss'], $regras)]);
    }

    public static function detalheIss(Request $req): Response
    {
        $r = RegrasIss::obter($req->params['codigo']) ?? throw Tenant::notFound('Regra de ISS');
        return Response::json(RegraDetalhe::detalheIss($r));
    }

    public static function salvarIss(Request $req): Response
    {
        $r = RegrasIss::salvar($req->params['codigo'], $req->json());
        Logger::audit('tributacao.regra_iss', 'salva', ['codigo' => $r['codigo']]);
        return Response::json(RegraDetalhe::resumoIss($r));
    }

    public static function excluirIss(Request $req): Response
    {
        RegrasIss::excluir($req->params['codigo']);
        Logger::audit('tributacao.regra_iss', 'excluida', ['codigo' => $req->params['codigo']]);
        return Response::noContent();
    }

    public static function salvarPisCofins(Request $req): Response
    {
        $r = Matriz::salvarPisCofins($req->params['perfil'], $req->params['operacao'], $req->json());
        Logger::audit('tributacao.regra_piscofins', 'salva', ['chave' => $r['perfil'] . '/' . $r['operacao']]);
        return Response::json($r);
    }

    public static function excluirPisCofins(Request $req): Response
    {
        Matriz::excluirPisCofins($req->params['perfil'], $req->params['operacao']);
        Logger::audit('tributacao.regra_piscofins', 'excluida', ['chave' => $req->params['perfil'] . '/' . $req->params['operacao']]);
        return Response::noContent();
    }

    public static function importar(Request $req): Response
    {
        $r = Matriz::importar($req->json());
        Logger::audit('tributacao.importacao', 'sucesso', $r);
        return Response::json($r);
    }

    /** @return array{0:string,1:string,2:string,3:string} */
    private static function chaveIcms(Request $req): array
    {
        return [$req->params['perfil'], $req->params['operacao'], strtoupper($req->params['uf']), strtoupper($req->params['tipo_pessoa'])];
    }
}
