<?php

declare(strict_types=1);

namespace App\Nfse;

use App\Nfe\ConfigService;

/**
 * v2.9.3: orientação em linguagem simples para rejeições da Sefin Nacional que NÃO se resolvem só reenviando a nota.
 *
 * Fonte: ANEXO_I-SEFIN_ADN-DPS_NFSe-SNNFSe (aba "RN DPS_NFS-e"), em gov.br/nfse > Documentação atual.
 *  - E0037: município emissor não conveniado / convênio não ativo no Sistema Nacional;
 *  - E0039: município emissor não parametrizado para usar os emissores públicos nacionais;
 *  - E0312: código de tributação nacional (cTribNac) não administrado pelo município de incidência;
 *  - E0314: código de tributação MUNICIPAL (serv/cServ/cTribMun) não existe ou não é administrado pelo município de
 *    incidência do ISSQN na data de competência (não vale para MEI). Não é o código IBGE do município.
 *
 * Mesma interface da classe homônima da 2.10 (orientacoes()), para facilitar a junção das versões.
 */
final class Rejeicoes
{
    /** Códigos que dependem do convênio/habilitação do município, não do conteúdo da nota. */
    public const MUNICIPIO_NAO_HABILITADO = ['E0037', 'E0039'];

    /**
     * @param list<array{codigo:string, descricao:string}> $erros
     * @param array<string,mixed>|null $ctx ['municipio' => 'Brasília/DF', 'uf' => 53, 'ctribmun' => '123'] (opcional)
     * @return list<array{codigo:string, quem_resolve:string, orientacao:string}>
     */
    public static function orientacoes(array $erros, ?array $ctx = null): array
    {
        $ctx ??= self::contexto();
        $out = [];
        foreach ($erros as $e) {
            $c = strtoupper((string) ($e['codigo'] ?? ''));
            if (isset($out[$c])) {
                continue;
            }
            $o = self::orientacao($c, $ctx);
            if ($o !== null) {
                $out[$c] = ['codigo' => $c, 'quem_resolve' => $o[0], 'orientacao' => $o[1]];
            }
        }
        return array_values($out);
    }

    /** @param array<string,mixed> $ctx @return array{0:string,1:string}|null */
    private static function orientacao(string $codigo, array $ctx): ?array
    {
        $mun = self::nomeMunicipio($ctx);
        return match ($codigo) {
            'E0037', 'E0039' => ['prefeitura', self::mensagemNaoHabilitado($ctx)],
            'E0314' => ['configuracao', 'O código de tributação municipal'
                . (isset($ctx['ctribmun']) && $ctx['ctribmun'] !== '' ? ' "' . $ctx['ctribmun'] . '"' : '')
                . ' (campo "Cód. municipal" do serviço) não existe na tabela do município ' . $mun . ' no Sistema Nacional para a data de competência. '
                . 'Esse campo é opcional: deixe em branco, a não ser que a prefeitura exija um código da lista dela. '
                . 'Ele não é o código IBGE do município nem o item da lista de serviços. Corrija em NFS-e (prestador) › Serviços e emita de novo.'],
            'E0312' => ['configuracao', 'O código de tributação nacional do serviço não é aceito pelo município ' . $mun . ' para a data de competência. '
                . 'Confira com o contador ou com a prefeitura qual código da lista nacional o município usa para esse serviço.'],
            default => null,
        };
    }

    /** Mensagem para município que ainda não usa o Emissor Nacional da NFS-e. @param array<string,mixed>|null $ctx */
    public static function mensagemNaoHabilitado(?array $ctx = null): string
    {
        $ctx ??= self::contexto();
        $df = (int) ($ctx['uf'] ?? 0) === 53;
        return 'O município ' . self::nomeMunicipio($ctx) . ' ainda não está habilitado no Emissor Nacional da NFS-e. '
            . 'Isso depende do município, não do Emissor. A partir de 1º/11/2026 o Simples Nacional passa a usar o padrão nacional; '
            . 'até o município ser habilitado, emita pelo sistema ' . ($df ? 'do Distrito Federal (Secretaria de Economia do DF)' : 'da prefeitura') . '.';
    }

    /** True se algum dos erros indica município não habilitado (E0037/E0039). @param list<array{codigo:string}> $erros */
    public static function municipioNaoHabilitado(array $erros): bool
    {
        foreach ($erros as $e) {
            if (in_array(strtoupper((string) ($e['codigo'] ?? '')), self::MUNICIPIO_NAO_HABILITADO, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Contexto a partir da configuração do tenant: nome do município emissor (quando o código coincide com o do
     * endereço do emitente) e UF pelo código IBGE.
     * @return array<string,mixed>
     */
    public static function contexto(): array
    {
        $cfg = NfseConfig::get() ?? [];
        $emit = ConfigService::get() ?? [];
        $cMun = (string) ($cfg['codigo_municipio'] ?? ($emit['endereco']['codigo_municipio'] ?? ''));
        $ctx = ['codigo_municipio' => $cMun, 'uf' => (int) substr($cMun, 0, 2)];
        $end = $emit['endereco'] ?? [];
        if ($cMun !== '' && ($end['codigo_municipio'] ?? null) === $cMun && is_string($end['municipio'] ?? null) && $end['municipio'] !== '') {
            $uf = array_search($ctx['uf'], \App\Support\Validate::UFS, true);
            $ctx['municipio'] = $end['municipio'] . ($uf !== false ? '/' . $uf : '');
        } elseif ($cMun === \App\Support\Validate::IBGE_BRASILIA) {
            $ctx['municipio'] = 'Brasília/DF';
        }
        return $ctx;
    }

    /** @param array<string,mixed> $ctx */
    private static function nomeMunicipio(array $ctx): string
    {
        if (!empty($ctx['municipio'])) {
            return mb_substr((string) $ctx['municipio'], 0, 80);
        }
        return !empty($ctx['codigo_municipio']) ? '(código IBGE ' . $ctx['codigo_municipio'] . ')' : 'emissor';
    }
}
