<?php

declare(strict_types=1);

namespace Tests;

use App\Nfse\DpsBuilder;
use App\Nfse\NfseConfig;
use App\Nfse\NfseXml;
use App\Nfse\SefinClient;
use App\Storage\JsonStore;
use App\Support\Env;
use App\Support\Paths;
use App\Tenancy\Tenant;
use Tests\Support\FakeSefin;
use Tests\Support\TestCert;

/**
 * NFS-e Padrão Nacional: configuração do prestador, DPS v1.01 validada no XSD oficial, regras de negócio da Sefin
 * reproduzidas localmente, assinatura XMLDSig, emissão/sincronização/cancelamento/DANFSe contra o dublê HTTP
 * (FakeSefin — nenhuma chamada de rede), idempotência, travas (transmissão/produção), isolamento entre empresas
 * e leitura segura de XML.
 */
final class NfseTest extends TestCase
{
    public function testCorrecaoDeRejeitadaMantemNumeroERecuperaAutorizacao(): void
    {
        $this->configure();$this->configNfse();$this->uploadCert();$this->ligar();
        $this->sefin->modo='rejeitar_com';$this->sefin->errosRejeicao=[['Codigo'=>'E0239','Descricao'=>'Tomador obrigatório']];
        $p=$this->payload();$first=$this->emitir($p);self::assertSame(422,$first->status);$rec=$this->json($first);
        $p['numero']=$rec['numero_dps'];$p['serie']=$rec['serie'];$p['tomador']['nome']='Cliente com cadastro corrigido';
        $path='/api/v1/nfse/'.$rec['id'].'/corrigir';$invalid=$p;$invalid['numero']++;
        self::assertSame(422,$this->authed('POST',$path,json_encode($invalid),['Idempotency-Key'=>'correcao-numero-invalido'])->status);
        $before=$this->json($this->authed('GET','/api/v1/nfse/config'))['proximo_numero'];$this->sefin->modo='autorizar';
        $r=$this->authed('POST',$path,json_encode($p),['Idempotency-Key'=>'correcao-cadastro-001']);self::assertSame(201,$r->status,$r->body);$d=$this->json($r);
        self::assertSame('autorizada',$d['status']);self::assertSame($rec['id'],$d['id']);self::assertCount(2,$this->sefin->dpsRecebidas);
        self::assertSame($before,$this->json($this->authed('GET','/api/v1/nfse/config'))['proximo_numero']);
        self::assertSame(201,$this->authed('POST',$path,json_encode($p),['Idempotency-Key'=>'correcao-cadastro-001'])->status);self::assertCount(2,$this->sefin->dpsRecebidas);
    }

    public function testReenvioE0008MantemDpsEConsultaAntesDeTransmitir(): void
    {
        $this->configure();$this->configNfse();$this->uploadCert();$this->ligar();
        $this->sefin->modo='rejeitar_com';$this->sefin->errosRejeicao=[['Codigo'=>'E0008','Descricao'=>'Data posterior ao processamento']];
        $first=$this->emitir($this->payload());self::assertSame(422,$first->status,$first->body);$old=$this->json($first);
        $this->sefin->modo='autorizar';
        $path='/api/v1/nfse/'.$old['id'].'/reenviar-horario';$body='{"recuo_segundos":180}';$headers=['Idempotency-Key'=>'teste-reenvio-horario-001'];
        $r=$this->authed('POST',$path,$body,$headers);self::assertSame(200,$r->status,$r->body);$d=$this->json($r);
        self::assertSame('autorizada',$d['status']);self::assertSame($old['id'],$d['id']);self::assertSame($old['numero_dps'],$d['numero_dps']);
        self::assertCount(2,$this->sefin->dpsRecebidas);$xml=$this->sefin->dpsRecebidas[1];$dom=NfseXml::load($xml);$xp=new \DOMXPath($dom);
        $dh=$xp->evaluate('string(//*[local-name()="dhEmi"])');self::assertLessThanOrEqual(185,time()-strtotime($dh));self::assertGreaterThanOrEqual(180,time()-strtotime($dh));
        $calls=$this->sefin->calls;$lastPost=array_key_last(array_filter($calls,fn($c)=>$c['metodo']==='POST'&&str_ends_with($c['url'],'/nfse')));
        self::assertSame('GET',$calls[$lastPost-1]['metodo']);self::assertStringContainsString('/dps/'.$old['id'],$calls[$lastPost-1]['url']);
        self::assertSame(200,$this->authed('POST',$path,$body,$headers)->status);self::assertCount(2,$this->sefin->dpsRecebidas);
    }

    public function testReenvioHorarioNaoPermiteProducaoNemOutroErro(): void
    {
        $this->configure();$this->configNfse();$this->uploadCert();$this->ligar();$this->sefin->modo='rejeitar';
        $r=$this->emitir($this->payload());self::assertSame(422,$r->status);$rec=$this->json($r);$path='/api/v1/nfse/'.$rec['id'].'/reenviar-horario';
        self::assertSame(409,$this->authed('POST',$path,'{}',['Idempotency-Key'=>'outro-erro-reenvio-001'])->status);
        Tenant::set(Tenant::DEFAULT_ID);\App\Tenancy\FiscalScope::setModelo('nfse');$rec['ambiente']=1;$rec['erros']=[['codigo'=>'E0008','descricao'=>'data']];\App\Nfse\NfseRepository::save($rec);
        self::assertSame(422,$this->authed('POST',$path,'{}',['Idempotency-Key'=>'producao-bloqueada-001'])->status);
        self::assertSame(422,$this->authed('POST',$path,'{"recuo_segundos":301}',['Idempotency-Key'=>'recuo-invalido-001'])->status);
        self::assertCount(1,$this->sefin->dpsRecebidas);
    }

    private const CERT_PASS = 'senha-de-teste-gerada';
    private FakeSefin $sefin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sefin = new FakeSefin();
    }

    // ------------------------------------------------------------ helpers

    /** @param array<string,mixed> $over */
    private function configNfse(array $over = [], ?callable $req = null): array
    {
        $req ??= fn($m, $u, $b = '') => $this->authed($m, $u, $b);
        // a opção pelo Simples da NFS-e vem do regime tributário (CRT) da empresa
        $this->regime($over['opcao_simples_nacional'] ?? 3, $req);
        $r = $req('PUT', '/api/v1/nfse/config', (string) json_encode($over + [
            'inscricao_municipal' => '12345678',
            'opcao_simples_nacional' => 3,
            'regime_apuracao_sn' => 1,
            'regime_especial' => 0,
            'serie' => 1,
            'proximo_numero' => 1,
            'municipio_convenio_ativo' => true,
        ]));
        $this->assertSame(200, $r->status, $r->body);
        return $this->json($r);
    }

    /** Ajusta o CRT da empresa para a opção pelo Simples desejada (1 não optante, 2 MEI, 3 ME/EPP). */
    private function regime(int $opcao, ?callable $req = null): void
    {
        $req ??= fn($m, $u, $b = '') => $this->authed($m, $u, $b);
        $r = $req('PUT', '/api/v1/config', (string) json_encode([
            'cnpj' => '11222333000181', 'ie' => '111111111111', 'razao_social' => 'EMPRESA TESTE LTDA', 'uf' => 'SP', 'ambiente' => 2,
            'crt' => [1 => 3, 2 => 4, 3 => 1][$opcao],
            'endereco' => ['logradouro' => 'Rua das Flores', 'numero' => '100', 'bairro' => 'Centro', 'codigo_municipio' => '3550308',
                'municipio' => 'Sao Paulo', 'cep' => '01001000'],
            'serie' => 1, 'proximo_numero' => 1,
        ]));
        $this->assertSame(200, $r->status, $r->body);
    }

    private function ligar(): void
    {
        $this->sefin->install();
    }

    private function uploadCert(?callable $req = null): void
    {
        $req ??= fn($m, $u, $b = '') => $this->authed($m, $u, $b);
        $r = $req('POST', '/api/v1/certificado', (string) json_encode(['certificado_base64' => base64_encode(TestCert::pfx(self::CERT_PASS)), 'senha' => self::CERT_PASS]));
        $this->assertSame(201, $r->status, $r->body);
    }

    /** @param array<string,mixed> $over @return array<string,mixed> */
    private function payload(array $over = []): array
    {
        return array_replace_recursive([
            'servico' => ['codigo_tributacao_nacional'=>'080201','nbs'=>'122051900','descricao'=>'Curso online'],
            'tomador' => ['cpf' => '52998224725', 'nome' => 'Maria da Silva', 'email' => 'maria@example.com'],
            'valores' => ['servico' => 1500.00,'aliquota_iss'=>2],
            'pedido_id' => 'pedido-77',
        ], $over);
    }

    private function emitir(array $payload, string $key = 'nfse-pedido-0001'): \App\Http\Response
    {
        return $this->authed('POST', '/api/v1/nfse', (string) json_encode($payload), ['Idempotency-Key' => $key]);
    }

    private function dry(array $payload): \App\Http\Response
    {
        return $this->authed('POST', '/api/v1/nfse?dry_run=1', (string) json_encode($payload));
    }

    private function prontoParaEmitir(): void
    {
        $this->configure();
        $this->configNfse();
        $this->uploadCert();
        $this->ligar();
    }

    private function semRede(): int
    {
        return count($this->sefin->calls);
    }

    // ------------------------------------------------------------ configuração

    public function testConfigExigeEmitenteEValidaRegimes(): void
    {
        $r = $this->authed('PUT', '/api/v1/nfse/config', (string) json_encode(['opcao_simples_nacional' => 2]));
        $this->assertSame(409, $r->status, 'sem config do emitente (CNPJ)');

        $this->configure();
        $view = $this->json($this->authed('GET', '/api/v1/nfse/config'));
        $this->assertFalse($view['configurado']);
        $this->assertSame('3550308', $view['codigo_municipio'], 'sugere o município do emitente');
        $this->assertArrayNotHasKey('transmissao_habilitada', $view);

        // a opção pelo Simples vem do CRT: CRT 1 (Simples) → 3; outra opção no corpo é recusada
        $this->assertSame(3, $view['opcao_simples_nacional']);
        $r = $this->authed('PUT', '/api/v1/nfse/config', (string) json_encode(['opcao_simples_nacional' => 1, 'regime_apuracao_sn' => 1]));
        $this->assertSame(422, $r->status);
        $this->assertContains('opcao_simples_nacional', array_column($this->json($r)['erro']['detalhes'], 'campo'));
        // MEI com regime de apuração e regime especial: E0162/E0174
        $this->regime(2);
        $r = $this->authed('PUT', '/api/v1/nfse/config', (string) json_encode(['opcao_simples_nacional' => 2, 'regime_apuracao_sn' => 1, 'regime_especial' => 5]));
        $this->assertSame(422, $r->status);
        $campos = array_column($this->json($r)['erro']['detalhes'], 'campo');
        $this->assertContains('regime_apuracao_sn', $campos);
        $this->assertContains('regime_especial', $campos);
        // ME/EPP sem regime de apuração: E0166
        $this->regime(3);
        $r = $this->authed('PUT', '/api/v1/nfse/config', (string) json_encode(['opcao_simples_nacional' => 3]));
        $this->assertContains('regime_apuracao_sn', array_column($this->json($r)['erro']['detalhes'], 'campo'));
        // o município emissor é o do endereço nos dados fiscais (um só código IBGE)
        $r = $this->authed('PUT', '/api/v1/nfse/config', (string) json_encode(['codigo_municipio' => '1302603', 'regime_apuracao_sn' => 1,
            'inscricao_municipal' => '12345678', 'serie' => 1, 'proximo_numero' => 1]));
        $this->assertSame(422, $r->status);
        $this->assertContains('codigo_municipio', array_column($this->json($r)['erro']['detalhes'], 'campo'));
        $c = $this->configNfse();
        $this->assertSame('3550308', $c['codigo_municipio']);
        $this->assertTrue($c['configurado']);
        $this->assertSame(2, $c['ambiente'], 'padrão: produção restrita (homologação)');
        $this->assertStringContainsString('nfse.config', $this->auditTrail());
    }

    public function testConfigSoProprietarioMasTodosLeem(): void
    {
        $owner = $this->signup('dono@a.com');
        $this->configureSession($owner);
        $member = $this->memberOf($owner, 'membro@a.com');
        $r = $this->sess($member, 'PUT', '/api/v1/nfse/config', (string) json_encode(['opcao_simples_nacional' => 2]));
        $this->assertSame(403, $r->status);
        $this->configNfse(['opcao_simples_nacional' => 2, 'regime_apuracao_sn' => null], fn($m, $u, $b = '') => $this->sess($owner, $m, $u, $b));
        $this->assertSame(200, $this->sess($member, 'GET', '/api/v1/nfse/config')->status);
    }

    public function testBuscaNaListaNacionalDeServicos(): void
    {
        $this->configure();
        $d = $this->json($this->authed('GET', '/api/v1/nfse/servicos-nacionais?busca=0101'));
        $this->assertSame('010101', $d['servicos'][0]['codigo']);
        $d = $this->json($this->authed('GET', '/api/v1/nfse/servicos-nacionais?busca=' . rawurlencode('ensino')));
        $this->assertNotEmpty($d['servicos']);
    }

    // ------------------------------------------------------------ DPS (dry-run)

    public function testDryRunMontaDpsValidaNoXsdOficialSemConsumirNumero(): void
    {
        $this->configure();
        $this->configNfse();
        $r = $this->dry($this->payload());
        $this->assertSame(200, $r->status, $r->body);
        $d = $this->json($r);
        $this->assertTrue($d['valida'], implode("\n", $d['erros_schema']));
        $this->assertSame('1.01', $d['versao_leiaute']);
        // Id: DPS + cMun(7) + 2 (CNPJ) + CNPJ(14) + série(5) + número(15)
        $this->assertSame('DPS3550308211222333000181' . '00001' . '000000000000001', $d['id_dps']);
        $xml = $d['xml'];
        $this->assertStringContainsString('<DPS xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01">', $xml);
        $this->assertStringContainsString('<tpAmb>2</tpAmb>', $xml);
        $this->assertStringContainsString('<prest><CNPJ>11222333000181</CNPJ><IM>12345678</IM><regTrib><opSimpNac>3</opSimpNac><regApTribSN>1</regApTribSN><regEspTrib>0</regEspTrib></regTrib></prest>', $xml, 'prestador emitente sem xNome/end (E0121/E0128)');
        $this->assertStringContainsString('<toma><CPF>52998224725</CPF><xNome>Maria da Silva</xNome><email>maria@example.com</email></toma>', $xml);
        $this->assertStringContainsString('<cTribNac>080201</cTribNac>', $xml);
        $this->assertStringContainsString('<cNBS>122051900</cNBS>', $xml);
        $this->assertStringContainsString('<xPed>pedido-77</xPed>', $xml);
        $this->assertStringContainsString('<vServ>1500.00</vServ>', $xml);
        $this->assertStringNotContainsString('<pAliq>', $xml, 'ME/EPP pelo SN sem retenção: alíquota proibida (E0625)');
        $this->assertStringContainsString('<pTotTrib><pTotTribFed>0.00</pTotTribFed>', $xml);
        $this->assertStringNotContainsString('Signature', $xml);
        $this->assertEquals(1500.0, $d['estimativa']['base_calculo']);
        // numeração intacta
        $this->assertSame(1, $this->json($this->authed('GET', '/api/v1/nfse/config'))['proximo_numero']);
        $this->assertSame(0, $this->semRede());
    }

    public function testRegraDeIssPreencheServicoEValores(): void
    {
        $this->configure();
        $this->configNfse(['retencao_iss' => 2]); // retenção vem da empresa
        $r = $this->authed('PUT', '/api/v1/tributacao/regras-iss/CURSO', (string) json_encode(['descricao' => 'Curso retido',
            'servico' => ['codigo_tributacao_nacional' => '080201', 'nbs' => '122051900'],
            'iss' => ['aliquota_iss' => 2.5]]));
        $this->assertSame(200, $r->status, $r->body);

        $p = ['regra_iss' => 'CURSO', 'servico' => ['descricao' => 'Curso online'],
            'tomador' => ['cpf' => '52998224725', 'nome' => 'Maria da Silva'], 'valores' => ['servico' => 1000]];
        $r = $this->dry($p);
        $this->assertSame(200, $r->status, $r->body);
        $d = $this->json($r);
        $this->assertTrue($d['valida'], implode("\n", $d['erros_schema']));
        $this->assertStringContainsString('<cTribNac>080201</cTribNac>', $d['xml']);
        $this->assertStringContainsString('<cNBS>122051900</cNBS>', $d['xml']);
        $this->assertStringContainsString('<tpRetISSQN>2</tpRetISSQN>', $d['xml']);
        $this->assertStringContainsString('<pAliq>2.50</pAliq>', $d['xml']);
        $this->assertSame(['codigo' => 'CURSO', 'descricao' => 'Curso retido'], $d['regra_iss']);

        // o que vem no JSON prevalece sobre a regra
        $p['valores']['retencao_iss'] = 1;
        $d = $this->json($this->dry($p));
        $this->assertStringContainsString('<tpRetISSQN>1</tpRetISSQN>', $d['xml']);
        $this->assertStringNotContainsString('<pAliq>', $d['xml'], 'ME/EPP sem retenção: alíquota não vai na DPS');

        $p['regra_iss'] = 'NAO-EXISTE';
        $r = $this->dry($p);
        $this->assertSame(422, $r->status);
        $this->assertContains('regra_iss', array_column($this->json($r)['erro']['detalhes'], 'campo'));
        $this->assertSame(1, $this->json($this->authed('GET', '/api/v1/nfse/config'))['proximo_numero']);
    }

    public function testTributacaoDeServicosDaEmpresaPreencheIssqn(): void
    {
        $this->configure();
        // não optante: retenção e suspensão vêm do cadastro da empresa
        $this->configNfse(['opcao_simples_nacional' => 1, 'regime_apuracao_sn' => null, 'retencao_iss' => 2,
            'suspensao' => ['tipo' => 1, 'numero_processo' => str_repeat('1', 30)]]);
        $c = $this->json($this->authed('GET', '/api/v1/nfse/config'));
        $this->assertSame(2, $c['retencao_iss']);
        $this->assertSame(['tipo' => 1, 'numero_processo' => str_repeat('1', 30)], $c['suspensao']);
        $this->authed('PUT', '/api/v1/tributacao/regras-iss/CURSO', (string) json_encode(['descricao' => 'Curso',
            'servico' => ['codigo_tributacao_nacional' => '080201', 'nbs' => '122051900'], 'iss' => ['aliquota_iss' => 2]]));
        $p = ['regra_iss' => 'CURSO', 'servico' => ['descricao' => 'Curso'], 'tomador' => ['cpf' => '52998224725', 'nome' => 'Maria'],
            'valores' => ['servico' => 100]];
        $d = $this->json($this->dry($p));
        $this->assertTrue($d['valida'], implode("\n", $d['erros_schema']));
        $this->assertStringContainsString('<tpRetISSQN>2</tpRetISSQN>', $d['xml']);
        $this->assertStringContainsString('<tpSusp>1</tpSusp>', $d['xml']);
        // a nota prevalece sobre a empresa
        $p['valores']['retencao_iss'] = 1;
        $this->assertStringContainsString('<tpRetISSQN>1</tpRetISSQN>', $this->json($this->dry($p))['xml']);
        // empresa imune: tipo de imunidade do cadastro
        $this->configNfse(['opcao_simples_nacional' => 1, 'regime_apuracao_sn' => null, 'tributacao_iss' => 2, 'tipo_imunidade' => 2]);
        unset($p['valores']['retencao_iss']);
        $d = $this->json($this->dry($p));
        $this->assertStringContainsString('<tribISSQN>2</tribISSQN>', $d['xml']);
        $this->assertStringContainsString('<tpImunidade>2</tpImunidade>', $d['xml']);
        // validações do cadastro
        foreach ([['retencao_iss' => 2, 'tributacao_iss' => 4], ['tipo_imunidade' => 1], ['suspensao' => ['tipo' => 3, 'numero_processo' => '1']]] as $over) {
            $r = $this->authed('PUT', '/api/v1/nfse/config', (string) json_encode($over + ['inscricao_municipal' => '12345678',
                'opcao_simples_nacional' => 1, 'regime_especial' => 0, 'serie' => 1, 'proximo_numero' => 1]));
            $this->assertSame(422, $r->status, $r->body);
        }
    }

    public function testRegraDeIssCalculaTributosFederaisComoNoClickPdv(): void
    {
        $this->configure();
        $this->configNfse(['opcao_simples_nacional' => 1, 'regime_apuracao_sn' => null]);
        $r = $this->authed('PUT', '/api/v1/tributacao/regras-iss/CONSULT', (string) json_encode(['descricao' => 'Consultoria PJ',
            'tipo_pessoa' => 'J', 'servico' => ['codigo_tributacao_nacional' => '080201', 'nbs' => '122051900'],
            'iss' => ['aliquota_iss' => 2],
            'federais' => ['cst_pis_cofins' => '01', 'aliquota_pis' => 0.65, 'aliquota_cofins' => 3, 'aliquota_irrf' => 1.5,
                'aliquota_csll' => 1, 'tipo_retencao' => 3],
            'ibs_cbs' => ['indicador_operacao' => '100301', 'classificacao_tributaria' => '000001', 'operacao_onerosa' => true, 'servico_exterior' => false]]));
        $this->assertSame(200, $r->status, $r->body);
        $p = ['regra_iss' => 'CONSULT', 'servico' => ['descricao' => 'Consultoria'],
            'tomador' => ['cnpj' => '11444777000161', 'nome' => 'Cliente PJ'], 'valores' => ['servico' => 1000, 'desconto_incondicionado' => 100]];
        $r = $this->dry($p);
        $this->assertSame(200, $r->status, $r->body);
        $d = $this->json($r);
        $this->assertTrue($d['valida'], implode("\n", $d['erros_schema']));
        // base 900: PIS 5,85; COFINS 27,00; IRRF 13,50; CSLL 9,00 → vRetCSLL (tipo 3) = 5,85 + 27,00 + 9,00 = 41,85
        foreach (['<CST>01</CST>', '<vBCPisCofins>900.00</vBCPisCofins>', '<pAliqPis>0.65</pAliqPis>', '<pAliqCofins>3.00</pAliqCofins>',
            '<vPis>5.85</vPis>', '<vCofins>27.00</vCofins>', '<tpRetPisCofins>3</tpRetPisCofins>', '<vRetIRRF>13.50</vRetIRRF>',
            '<vRetCSLL>41.85</vRetCSLL>'] as $tag) {
            $this->assertStringContainsString($tag, $d['xml']);
        }
        // tipo 8 (só CSLL retido) e tributos_federais do JSON prevalecem
        $this->authed('PUT', '/api/v1/tributacao/regras-iss/CONSULT', (string) json_encode(['descricao' => 'Consultoria PJ',
            'servico' => ['codigo_tributacao_nacional' => '080201', 'nbs' => '122051900'],
            'federais' => ['cst_pis_cofins' => '01', 'aliquota_pis' => 0.65, 'aliquota_cofins' => 3, 'aliquota_csll' => 1, 'tipo_retencao' => 8]]));
        $d = $this->json($this->dry($p));
        $this->assertStringContainsString('<vRetCSLL>9.00</vRetCSLL>', $d['xml']);
        $this->assertStringNotContainsString('<vRetIRRF>', $d['xml']);
        $p['tributos_federais'] = ['pis_cofins' => ['cst' => '08', 'tipo_retencao' => 0]];
        $d = $this->json($this->dry($p));
        $this->assertStringContainsString('<CST>08</CST>', $d['xml']);
        $this->assertStringNotContainsString('<vRetCSLL>', $d['xml']);
        $this->assertSame(1, $this->json($this->authed('GET', '/api/v1/nfse/config'))['proximo_numero']);
    }

    public function testRegrasDaSefinRecusadasAntesDoEnvio(): void
    {
        $this->configure();
        // MEI: não retém ISS (E0583); usa indTotTrib; não informa alíquota (E0600)
        $this->configNfse(['opcao_simples_nacional' => 2, 'regime_apuracao_sn' => null]);
        $r = $this->dry($this->payload(['valores' => ['retencao_iss' => 2]]));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('E0583', $r->body);
        $d = $this->json($this->dry($this->payload(['valores' => ['aliquota_iss' => 3]])));
        $this->assertTrue($d['valida'], implode("\n", $d['erros_schema']));
        $this->assertStringNotContainsString('<pAliq>', $d['xml']);
        $this->assertStringContainsString('<indTotTrib>0</indTotTrib>', $d['xml']);
        $this->assertStringNotContainsString('regApTribSN', $d['xml']);
        $this->assertNotEmpty($d['avisos']);
        // MEI não usa pTotTribSN (E0710)
        $r = $this->dry($this->payload(['tributos_aproximados' => ['tipo' => 'percentual_sn', 'percentual_sn' => 6]]));
        $this->assertSame(422, $r->status);

        // ME/EPP pelo SN com retenção pelo tomador: alíquota obrigatória e mínima 1,8% (E0621)
        $this->configNfse();
        $semAliq = ['servico' => ['codigo_tributacao_nacional' => '010101', 'descricao' => 'Desenvolvimento de site'], 'tomador' => ['cnpj' => '11444777000161', 'nome' => 'Cliente PJ'], 'valores' => ['servico' => 100, 'retencao_iss' => 2]];
        $r = $this->dry($semAliq);
        $this->assertSame(422, $r->status, $r->body);
        $this->assertContains('valores.aliquota_iss', array_column($this->json($r)['erro']['detalhes'], 'campo'));
        $semAliq['valores']['aliquota_iss'] = 1.5;
        $this->assertStringContainsString('1,8%', $this->dry($semAliq)->body);
        $semAliq['valores']['aliquota_iss'] = 2.5;
        $d = $this->json($this->dry($semAliq));
        $this->assertTrue($d['valida'], implode("\n", $d['erros_schema']));
        $this->assertStringContainsString('<tpRetISSQN>2</tpRetISSQN><pAliq>2.50</pAliq>', $d['xml']);
        $this->assertEquals(2.5, $d['estimativa']['iss_retido']);
        $this->assertEquals(97.5, $d['estimativa']['valor_liquido']);
        // retenção exige tomador (E0204); tomador = prestador (E0202); CPF inválido; alíquota > 5% (E0595)
        $this->assertStringContainsString('E0204', $this->dry(['servico' => ['codigo_tributacao_nacional'=>'080201','descricao'=>'Curso online'], 'valores' => ['servico' => 10, 'retencao_iss' => 2, 'aliquota_iss' => 2]])->body);
        $this->assertStringContainsString('E0202', $this->dry($this->payload(['tomador' => ['cpf' => null, 'cnpj' => '11222333000181', 'nome' => 'Eu mesmo']]))->body);
        $this->assertStringContainsString('tomador.cpf', $this->dry($this->payload(['tomador' => ['cpf' => '12345678900']]))->body);
        $this->assertStringContainsString('E0595', $this->dry($this->payload(['valores' => ['aliquota_iss' => 5.5]]))->body);
        // exportação não suportada; competência futura (E0015); descontos >= valor; código inexistente
        $this->assertStringContainsString('Exportação', $this->dry($this->payload(['valores' => ['tributacao_iss' => 3]]))->body);
        $this->assertStringContainsString('E0015', $this->dry($this->payload(['competencia' => date('Y-m-d', strtotime('+2 days'))]))->body);
        $this->assertStringContainsString('descontos', $this->dry($this->payload(['valores' => ['desconto_incondicionado' => 1500]]))->body);
        $this->assertStringContainsString('lista de serviços nacional', $this->dry($this->payload(['servico' => ['codigo_tributacao_nacional' => '123456']]))->body);

        // não optante, convênio ativo: alíquota proibida (E0617); convênio inativo: obrigatória (E0619)
        $this->configNfse(['opcao_simples_nacional' => 1, 'regime_apuracao_sn' => null]);
        $d = $this->json($this->dry($this->payload(['valores' => ['aliquota_iss' => 3]])));
        $this->assertStringNotContainsString('<pAliq>', $d['xml']);
        $this->configNfse(['opcao_simples_nacional' => 1, 'regime_apuracao_sn' => null, 'municipio_convenio_ativo' => false]);
        $r = $this->dry(['servico' => ['codigo_tributacao_nacional' => '010101', 'descricao' => 'Site'], 'valores' => ['servico' => 100]]);
        $this->assertSame(422, $r->status);
        $d = $this->json($this->dry(['servico' => ['codigo_tributacao_nacional' => '010101', 'descricao' => 'Site'], 'valores' => ['servico' => 100, 'aliquota_iss' => 3, 'desconto_incondicionado' => 10, 'desconto_condicionado' => 5],
            'tomador' => ['cnpj' => '11444777000161', 'nome' => 'Cliente PJ', 'endereco' => ['logradouro' => 'Rua A', 'numero' => '1', 'bairro' => 'Centro', 'codigo_municipio' => '3304557', 'cep' => '20000000']],
            'tributos_aproximados' => ['tipo' => 'valor', 'federal' => 10, 'estadual' => 0, 'municipal' => 3]]));
        $this->assertTrue($d['valida'], implode("\n", $d['erros_schema']));
        $this->assertStringContainsString('<pAliq>3.00</pAliq>', $d['xml']);
        $this->assertStringContainsString('<vDescCondIncond><vDescIncond>10.00</vDescIncond><vDescCond>5.00</vDescCond></vDescCondIncond>', $d['xml']);
        $this->assertStringContainsString('<end><endNac><cMun>3304557</cMun><CEP>20000000</CEP></endNac><xLgr>Rua A</xLgr>', $d['xml']);
        $this->assertStringContainsString('<vTotTrib><vTotTribFed>10.00</vTotTribFed>', $d['xml']);
        // imunidade: tpImunidade, sem alíquota e sem retenção (E0580/E0602)
        $d = $this->json($this->dry(['servico' => ['codigo_tributacao_nacional' => '010101', 'descricao' => 'Site'], 'valores' => ['servico' => 100, 'tributacao_iss' => 2, 'tipo_imunidade' => 3]]));
        $this->assertTrue($d['valida'], implode("\n", $d['erros_schema']));
        $this->assertStringContainsString('<tribISSQN>2</tribISSQN><tpImunidade>3</tpImunidade><tpRetISSQN>1</tpRetISSQN>', $d['xml']);
        $this->assertSame(0, $this->semRede());
    }

    public function testRegraDaAliquotaCobreOsCenariosDoAnexoI(): void
    {
        $this->assertSame('proibida', DpsBuilder::regraAliquota(2, null, 0, 1, 1, true)); // E0600 MEI
        $this->assertSame('proibida', DpsBuilder::regraAliquota(3, 1, 0, 4, 1, false)); // E0602 não incidência
        $this->assertSame('proibida', DpsBuilder::regraAliquota(1, null, 6, 1, 1, false)); // E0604 regime especial
        $this->assertSame('proibida', DpsBuilder::regraAliquota(1, null, 0, 1, 1, true)); // E0617
        $this->assertSame('obrigatoria', DpsBuilder::regraAliquota(1, null, 0, 1, 1, false)); // E0619
        $this->assertSame('obrigatoria', DpsBuilder::regraAliquota(3, 1, 0, 1, 2, true)); // E0621
        $this->assertSame('proibida', DpsBuilder::regraAliquota(3, 1, 0, 1, 1, true)); // E0625
        $this->assertSame('obrigatoria', DpsBuilder::regraAliquota(3, 1, 0, 1, 3, false)); // E0628
        $this->assertSame('proibida', DpsBuilder::regraAliquota(3, 2, 0, 1, 1, true)); // E0635
        $this->assertSame('obrigatoria', DpsBuilder::regraAliquota(3, 3, 0, 1, 1, false)); // E0640
    }

    public function testDpsComCaracteresEspeciaisEhEscapadaEValida(): void
    {
        $this->configure();
        $this->configNfse();
        $d = $this->json($this->dry($this->payload(['tomador' => ['nome' => 'Ação & Cia "Ltda"'], 'informacoes_complementares' => 'Turma A & B — 2026'])));
        $this->assertTrue($d['valida'], implode("\n", $d['erros_schema']));
        $this->assertStringContainsString('Ação &amp; Cia "Ltda"', $d['xml']);
        $this->assertStringContainsString('<xInfComp>Turma A &amp; B - 2026</xInfComp>', $d['xml'], 'travessão convertido (leiaute só aceita U+0020..U+00FF)');
        $r = $this->dry($this->payload(['servico' => ['descricao' => 'Mentoria 🚀']]));
        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Caractere não aceito', $r->body);
        // < > recusados na validação de texto
        $this->assertSame(422, $this->dry($this->payload(['servico' => ['descricao' => '<script>x</script>']]))->status);
    }

    // ------------------------------------------------------------ travas

    public function testEmissaoExigeChaveECertificadoSemTravaDeTransporte(): void
    {
        $this->configure();
        $this->configNfse();
        $this->sefin->install();
        $p = $this->payload();
        $r = $this->authed('POST', '/api/v1/nfse', (string) json_encode($p));
        $this->assertSame(400, $r->status);
        $this->assertStringContainsString('idempotency_key_obrigatoria', $r->body);
        $r = $this->emitir($p);
        $this->assertSame(412, $r->status);
        $this->assertStringContainsString('certificado_ausente', $r->body);
        $this->assertSame(0, $this->semRede());
        // erros de pré-validação não consumiram número nem a Idempotency-Key
        $this->assertSame(1, $this->json($this->authed('GET', '/api/v1/nfse/config'))['proximo_numero']);
        $this->uploadCert();
        $this->assertSame(201, $this->emitir($p)->status);
    }

    public function testProducaoNaoDependeDeTravaGlobalDoServidor(): void
    {
        $this->ligar();
        SefinClient::validarAmbiente(1);
        $this->assertSame(0,$this->semRede());
    }

    public function testUrlDaSefinSoNoDominioDoGoverno(): void
    {
        $this->assertSame('https://sefin.producaorestrita.nfse.gov.br/SefinNacional', SefinClient::baseUrl('sefin', 2));
        $this->assertSame('https://adn.nfse.gov.br', SefinClient::baseUrl('adn', 1));
        Env::set('NFSE_SEFIN_URL_HOMOLOGACAO', 'https://atacante.example.com/nfse');
        try {
            SefinClient::baseUrl('sefin', 2);
            $this->fail('URL fora de *.nfse.gov.br aceita');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        } finally {
            Env::set('NFSE_SEFIN_URL_HOMOLOGACAO', '');
        }
    }

    // ------------------------------------------------------------ emissão (dublê)

    public function testEmissaoAutorizadaAssinadaIdempotente(): void
    {
        $this->prontoParaEmitir();
        $p = $this->payload();
        $r = $this->emitir($p);
        $this->assertSame(201, $r->status, $r->body);
        $d = $this->json($r);
        $this->assertSame('autorizada', $d['status']);
        $this->assertMatchesRegularExpression('/^\d{50}$/', $d['chave']);
        $this->assertSame('1', $d['numero_nfse']);
        $this->assertSame('30.00', $d['valores_nfse']['valor_iss']);
        $this->assertSame('/api/v1/nfse/' . $d['chave'] . '/danfse', $d['links']['danfse']);
        $this->assertCount(1, $this->sefin->calls);
        $this->assertSame('POST', $this->sefin->calls[0]['metodo']);
        $this->assertSame('https://sefin.producaorestrita.nfse.gov.br/SefinNacional/nfse', $this->sefin->calls[0]['url']);

        // assinatura: RSA-SHA256 sobre infDPS, verificável com o certificado embutido
        $dps = $this->sefin->dpsRecebidas[0];
        $dom = NfseXml::load($dps);
        $inf = $dom->getElementsByTagName('infDPS')->item(0);
        $this->assertSame(base64_encode(hash('sha256', $inf->C14N(true, false), true)), $dom->getElementsByTagName('DigestValue')->item(0)->nodeValue);
        $this->assertStringContainsString('xmldsig-more#rsa-sha256', $dps);
        $this->assertStringContainsString('URI="#' . $d['id'] . '"', $dps);
        $si = $dom->getElementsByTagName('SignedInfo')->item(0)->C14N(true, false);
        $sig = base64_decode($dom->getElementsByTagName('SignatureValue')->item(0)->nodeValue);
        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(trim($dom->getElementsByTagName('X509Certificate')->item(0)->nodeValue), 64, "\n") . "-----END CERTIFICATE-----\n";
        $this->assertSame(1, openssl_verify($si, $sig, $pem, OPENSSL_ALGO_SHA256));
        $this->assertTrue(\NFePHP\Common\Signer::isSigned($dps, 'infDPS'));

        // repetição idempotente: mesma resposta, sem nova chamada nem novo número
        $r2 = $this->emitir($p);
        $this->assertSame('true', $r2->headers['Idempotent-Replayed'] ?? null);
        $this->assertSame($r->body, $r2->body);
        $this->assertCount(1, $this->sefin->calls);
        $this->assertSame(422, $this->emitir($this->payload(['valores' => ['servico' => 1]]))->status, 'mesma chave, outro corpo');
        $this->assertSame(2, $this->json($this->authed('GET', '/api/v1/nfse/config'))['proximo_numero']);

        // arquivos e consultas por chave e por id da DPS
        $this->assertSame(200, $this->authed('GET', '/api/v1/nfse/' . $d['id'])->status);
        $x = $this->authed('GET', '/api/v1/nfse/' . $d['chave'] . '/xml');
        $this->assertSame(200, $x->status);
        $this->assertStringContainsString('<NFSe xmlns', $x->body);
        $this->assertStringContainsString('<Signature', $this->authed('GET', '/api/v1/nfse/' . $d['chave'] . '/xml?tipo=dps')->body);
        $this->assertSame(404, $this->authed('GET', '/api/v1/nfse/' . $d['chave'] . '/xml?tipo=evento')->status);
        $lista = $this->json($this->authed('GET', '/api/v1/nfse?status=autorizada'))['nfses'];
        $this->assertCount(1, $lista);
        $this->assertArrayNotHasKey('historico', $lista[0]);

        // auditoria sem segredos
        $audit = $this->auditTrail();
        $this->assertStringContainsString('"event":"nfse.emissao","result":"autorizada"', $audit);
        $this->assertStringNotContainsString(self::CERT_PASS, $audit);
        $this->assertStringNotContainsString(self::CERT_PASS, (string) @file_get_contents(Paths::storage('logs/app.log')));
        // nada de PEM temporário sobrando
        $this->assertSame([], glob(Paths::storage('tmp/nfse/*/*.pem')) ?: []);
    }

    public function testRejeicaoDevolveErrosDaSefin(): void
    {
        $this->prontoParaEmitir();
        $this->sefin->modo = 'rejeitar';
        $r = $this->emitir($this->payload());
        $this->assertSame(422, $r->status);
        $d = $this->json($r);
        $this->assertSame('rejeitada', $d['status']);
        $this->assertSame('E0310', $d['erros'][0]['codigo']);
        $this->assertNull($d['chave']);
        $this->assertSame(409, $this->authed('GET', '/api/v1/nfse/' . $d['id'] . '/danfse')->status, 'sem DANFSe para rejeitada');
    }

    public function testTimeoutViraErroESincronizarRecuperaANota(): void
    {
        $this->prontoParaEmitir();
        $this->sefin->modo = 'timeout';
        $r = $this->emitir($this->payload());
        $this->assertSame(502, $r->status);
        $d = $this->json($r);
        $this->assertSame('erro', $d['status']);
        $this->assertStringContainsString('sincronizar', $d['mensagem']);
        $this->assertStringNotContainsString('timed out', $r->body, 'detalhe técnico só no log');

        $s = $this->authed('POST', '/api/v1/nfse/' . $d['id'] . '/sincronizar');
        $this->assertSame(200, $s->status, $s->body);
        $s = $this->json($s);
        $this->assertSame('autorizada', $s['status']);
        $this->assertMatchesRegularExpression('/^\d{50}$/', $s['chave']);
        $this->assertSame(['POST', 'GET', 'GET'], array_column($this->sefin->calls, 'metodo'));
        $this->assertStringContainsString('/dps/' . $d['id'], $this->sefin->calls[1]['url']);
    }

    public function testRespostaHtmlOuDuplicidadeE0014(): void
    {
        $this->prontoParaEmitir();
        $this->sefin->modo = 'html';
        $d = $this->json($this->emitir($this->payload(), 'chave-html-001'));
        $this->assertSame('erro', $d['status']);
        $this->assertStringContainsString('HTTP 502', $d['mensagem']);
        // sincronizar sem NFS-e gerada: continua erro e avisa que pode emitir de novo
        $s = $this->json($this->authed('POST', '/api/v1/nfse/' . $d['id'] . '/sincronizar'));
        $this->assertSame('erro', $s['status']);
        $this->assertStringContainsString('Nenhuma NFS-e', $s['mensagem']);

        $this->sefin->modo = 'e0014';
        $d = $this->json($this->emitir($this->payload(), 'chave-e0014-01'));
        $this->assertSame('autorizada', $d['status'], 'E0014: a DPS já virou NFS-e, recuperada pela consulta');
    }

    public function testRespostaComDtdNaoEhProcessada(): void
    {
        $this->assertSame(['XML com DTD/entidades não é aceito'], NfseXml::validate('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>', 'DPS_v1.01.xsd'));
        $this->prontoParaEmitir();
        SefinClient::$transport = function (string $m, string $url) {
            $xxe = '<?xml version="1.0"?><!DOCTYPE NFSe [<!ENTITY e SYSTEM "file:///etc/passwd">]><NFSe xmlns="http://www.sped.fazenda.gov.br/nfse"><infNFSe Id="NFS' . str_repeat('1', 50) . '"><nNFSe>&e;</nNFSe></infNFSe></NFSe>';
            return ['status' => 201, 'body' => (string) json_encode(['chaveAcesso' => str_repeat('1', 50), 'nfseXmlGZipB64' => NfseXml::gzB64($xxe)]), 'content_type' => 'application/json'];
        };
        $r = $this->emitir($this->payload());
        $d = $this->json($r);
        $this->assertSame('erro', $d['status']);
        $this->assertStringNotContainsString('root:', $r->body);
        // gzip bomb: limite de tamanho na descompactação
        $this->expectException(\UnexpectedValueException::class);
        NfseXml::unGzB64(base64_encode((string) gzencode(str_repeat('A', NfseXml::MAX_BYTES + 10))));
    }

    // ------------------------------------------------------------ cancelamento e DANFSe

    public function testCancelamentoDanfseEPapelFiscal(): void
    {
        $owner = $this->signup('dono@a.com');
        $req = fn($m, $u, $b = '', $h = []) => $this->sess($owner, $m, $u, $b, $h);
        $this->configureSession($owner);
        $this->configNfse([], $req);
        $this->uploadCert($req);
        $this->ligar();
        $r = $req('POST', '/api/v1/nfse', (string) json_encode(['servico' => ['codigo_tributacao_nacional'=>'080201','descricao'=>'Curso online'], 'valores' => ['servico' => 97]]), ['Idempotency-Key' => 'painel-0000001']);
        $this->assertSame(201, $r->status, $r->body);
        $nota = $this->json($r);
        $this->assertNull($nota['tomador'], 'consumidor não identificado');

        // Padrão atual: geração local, sem acessar ADN e sem reutilizar PDF legado.
        $antesPdf = count($this->sefin->calls);
        $pdfLocal = $req('GET', '/api/v1/nfse/' . $nota['chave'] . '/danfse');
        $this->assertSame(200, $pdfLocal->status, $pdfLocal->body);
        $this->assertSame('local', $pdfLocal->headers['X-Danfse-Origem']);
        $this->assertCount($antesPdf, $this->sefin->calls);
        $base64 = $req('GET', '/api/v1/nfse/' . $nota['chave'] . '/danfse?formato=base64');
        $this->assertSame(200, $base64->status, $base64->body);
        $jsonPdf = $this->json($base64);
        $this->assertStringStartsWith('%PDF-', base64_decode($jsonPdf['pdf_base64'], true));
        $this->assertSame('application/pdf', $jsonPdf['tipo_conteudo']);
        $this->assertSame('DANFSe v2.0', $jsonPdf['modelo']);
        $this->assertSame('local', $jsonPdf['origem']);
        $this->assertCount($antesPdf, $this->sefin->calls);
        $this->assertSame(422, $req('GET', '/api/v1/nfse/' . $nota['chave'] . '/danfse?formato=invalido')->status);
        // ADN legado somente quando solicitado explicitamente, com cache.
        $pdf = $req('GET', '/api/v1/nfse/' . $nota['chave'] . '/danfse?origem=adn');
        $this->assertSame(200, $pdf->status);
        $this->assertSame('application/pdf', $pdf->headers['Content-Type']);
        $this->assertStringStartsWith('%PDF-', $pdf->body);
        $n = count($this->sefin->calls);
        $req('GET', '/api/v1/nfse/' . $nota['chave'] . '/danfse?origem=adn');
        $this->assertCount($n, $this->sefin->calls, 'segunda vez vem do cache');
        $this->assertStringContainsString('https://adn.producaorestrita.nfse.gov.br/danfse/' . $nota['chave'], $this->sefin->calls[$n - 1]['url']);

        // membro comum não cancela
        $member = $this->memberOf($owner, 'membro@a.com');
        $body = (string) json_encode(['codigo_motivo' => 1, 'motivo' => 'Valor do serviço informado errado']);
        $this->assertSame(403, $this->sess($member, 'POST', '/api/v1/nfse/' . $nota['chave'] . '/cancelar', $body)->status);
        // validação
        $this->assertSame(422, $req('POST', '/api/v1/nfse/' . $nota['chave'] . '/cancelar', (string) json_encode(['codigo_motivo' => 5, 'motivo' => 'curto']))->status);
        // rejeitado pela Sefin: continua autorizada
        $this->sefin->cancelarOk = false;
        $c = $req('POST', '/api/v1/nfse/' . $nota['chave'] . '/cancelar', $body);
        $this->assertSame(422, $c->status);
        $this->assertSame('E0822', $this->json($c)['erros'][0]['codigo']);
        $this->assertSame('autorizada', $this->json($c)['nfse']['status']);
        // aceito
        $this->sefin->cancelarOk = true;
        $c = $req('POST', '/api/v1/nfse/' . $nota['chave'] . '/cancelar', $body);
        $this->assertSame(200, $c->status, $c->body);
        $c = $this->json($c);
        $this->assertTrue($c['sucesso']);
        $this->assertSame('cancelada', $c['nfse']['status']);
        $ped = end($this->sefin->eventosRecebidos);
        $this->assertStringContainsString('<CNPJAutor>11222333000181</CNPJAutor>', $ped);
        $this->assertStringContainsString('<e101101><xDesc>Cancelamento de NFS-e</xDesc><cMotivo>1</cMotivo>', $ped);
        $this->assertStringContainsString('<infEvento', $req('GET', '/api/v1/nfse/' . $nota['chave'] . '/xml?tipo=evento')->body);
        $this->assertSame(409, $req('POST', '/api/v1/nfse/' . $nota['chave'] . '/cancelar', $body)->status, 'já cancelada');
        $this->assertStringContainsString('"event":"nfse.cancelamento","result":"sucesso"', $this->auditTrail());
    }

    // ------------------------------------------------------------ isolamento

    public function testOutraEmpresaNaoVeNemCancelaANota(): void
    {
        $a = $this->signup('a@a.com', 'Empresa A LTDA', '11222333000181', '10.0.0.1');
        $reqA = fn($m, $u, $b = '', $h = []) => $this->sess($a, $m, $u, $b, $h);
        $this->configureSession($a);
        $this->configNfse([], $reqA);
        $this->uploadCert($reqA);
        $this->ligar();
        $nota = $this->json($reqA('POST', '/api/v1/nfse', (string) json_encode(['servico' => ['codigo_tributacao_nacional'=>'080201','descricao'=>'Curso online'], 'valores' => ['servico' => 50]]), ['Idempotency-Key' => 'empresa-a-0001']));
        $this->assertSame('autorizada', $nota['status']);

        $b = $this->signup('b@b.com', 'Empresa B LTDA', '11444777000161', '10.0.0.2');
        foreach ([$nota['chave'], $nota['id']] as $ref) {
            $this->assertSame(404, $this->sess($b, 'GET', '/api/v1/nfse/' . $ref)->status);
            $this->assertSame(404, $this->sess($b, 'GET', '/api/v1/nfse/' . $ref . '/xml')->status);
            $this->assertSame(404, $this->sess($b, 'GET', '/api/v1/nfse/' . $ref . '/danfse')->status);
            $this->assertSame(404, $this->sess($b, 'POST', '/api/v1/nfse/' . $ref . '/cancelar', (string) json_encode(['codigo_motivo' => 1, 'motivo' => 'Tentativa de outra empresa']))->status);
        }
        $this->assertSame([], $this->json($this->sess($b, 'GET', '/api/v1/nfse'))['nfses']);
        $this->assertSame(400, $this->sess($b, 'GET', '/api/v1/nfse/..%2F..%2Fetc')->status);
        $this->assertSame(400, $this->sess($b, 'GET', '/api/v1/nfse/DPS123')->status);
        // a chave de idempotência também é por empresa
        $this->assertSame(409, $this->sess($b, 'POST', '/api/v1/nfse', (string) json_encode(['servico' => ['codigo_tributacao_nacional'=>'080201','descricao'=>'Curso online'], 'valores' => ['servico' => 50]]), ['Idempotency-Key' => 'empresa-a-0001'])->status, 'B sem config NFS-e: 409 (não a resposta de A)');
    }

    // ------------------------------------------------------------ parâmetros municipais

    public function testDryRunNaoConsultaServicosExternos(): void
    {
        $this->configure();
        $this->configNfse(['municipio_convenio_ativo' => null]);
        $this->ligar();
        $resposta = $this->dry($this->payload());
        $this->assertSame(200, $resposta->status);
        $this->assertCount(0, $this->sefin->calls);
    }

    public function testParametrosMunicipaisComCache(): void
    {
        $this->configure();
        $this->configNfse(['municipio_convenio_ativo' => null]);
        $this->ligar();
        $p = $this->json($this->authed('GET', '/api/v1/nfse/parametros'));
        $this->assertTrue($p['conveniado']);
        $this->assertSame('https://adn.producaorestrita.nfse.gov.br/parametrizacao/3550308/convenio', $this->sefin->calls[0]['url']);
        $this->assertTrue($p['emissor_nacional']);
        $this->assertTrue($this->json($this->authed('GET', '/api/v1/nfse/parametros'))['cache']);
        $this->assertCount(1, $this->sefin->calls);
        $this->assertTrue($this->json($this->authed('GET', '/api/v1/nfse/config'))['municipio_convenio_ativo']);

        // 404 de negócio do ADN (JSON com parametrosConvenio null): convênio não ativo neste ambiente
        $this->sefin->convenioStatus = 404;
        $p = $this->json($this->authed('GET', '/api/v1/nfse/parametros?atualizar=1'));
        $this->assertFalse($p['conveniado']);
        $this->assertStringContainsString('ainda não está ativo', $p['mensagem']);
        $this->assertStringContainsString('produção restrita', $p['mensagem']);
        $this->assertFalse($this->json($this->authed('GET', '/api/v1/nfse/config'))['municipio_convenio_ativo']);
        $d = $this->json($this->dry(['servico' => ['codigo_tributacao_nacional'=>'080201','descricao'=>'Curso online'], 'valores' => ['servico' => 10, 'aliquota_iss' => 2]]));
        $this->assertStringContainsString('E0037', implode(' ', $d['avisos']), 'dry-run avisa que a Sefin vai recusar');
        $this->sefin->convenioStatus = 200;
        $this->sefin->convenioJson = ['parametrosConvenio' => ['aderenteAmbienteNacional' => 0, 'aderenteEmissorNacional' => 0]];
        $this->assertFalse($this->json($this->authed('GET', '/api/v1/nfse/parametros?atualizar=1'))['conveniado']);
        $this->sefin->convenioJson = ['parametrosConvenio' => ['aderenteAmbienteNacional' => 1, 'aderenteEmissorNacional' => 0], 'mensagem' => 'ok'];
        $p = $this->json($this->authed('GET', '/api/v1/nfse/parametros?atualizar=1'));
        $this->assertTrue($p['conveniado']);
        $this->assertFalse($p['emissor_nacional']);
        $this->assertStringContainsString('Emissor Nacional', $p['mensagem']);
    }

    public function testConvenio404SemJsonDoAdnNaoViraSemConvenio(): void
    {
        $this->configure();
        $this->configNfse(['municipio_convenio_ativo' => true]);
        $this->ligar();
        foreach ([[404, '<!DOCTYPE html><html>The resource cannot be found.</html>', 'text/html'], [404, '{"erro":"x"}', 'application/json'], [503, '', 'text/plain']] as [$st, $body, $ct]) {
            SefinClient::$transport = fn() => ['status' => $st, 'body' => $body, 'content_type' => $ct];
            $p = $this->json($this->authed('GET', '/api/v1/nfse/parametros?atualizar=1'));
            $this->assertNull($p['conveniado'], "HTTP $st sem o JSON do ADN não permite concluir");
            $this->assertStringContainsString('Não foi possível confirmar', $p['mensagem']);
            $this->assertTrue($this->json($this->authed('GET', '/api/v1/nfse/config'))['municipio_convenio_ativo'], 'mantém o último valor conhecido');
        }
    }

    public function testCacheDeConvenioDa291EDescartadoEValorErradoZerado(): void
    {
        $this->configure();
        // estado deixado pela 2.9.1: 404 HTML da Sefin lido como "sem convênio"
        $this->configNfse(['municipio_convenio_ativo' => false]);
        \App\Nfse\NfseConfig::salvarParametros(['codigo_municipio' => '3550308', 'http' => 404, 'consultado_em_ts' => time(), 'conveniado' => false]);
        $this->ligar();
        SefinClient::$transport = fn() => ['status' => 502, 'body' => '', 'content_type' => 'text/html'];
        $p = $this->json($this->authed('GET', '/api/v1/nfse/parametros'));
        $this->assertArrayNotHasKey('cache', $p, 'cache antigo (sem ambiente) não é reaproveitado');
        $this->assertNull($p['conveniado']);
        $this->assertNull($this->json($this->authed('GET', '/api/v1/nfse/config'))['municipio_convenio_ativo'], 'false da 2.9.1 vira desconhecido');
    }

    public function testTributosInformadosChegamAssinadosESaoPreservadosNoXml(): void
    {
        $this->prontoParaEmitir();
        $this->configNfse(['opcao_simples_nacional'=>1, 'regime_apuracao_sn'=>null]);
        $p=$this->payload([
            'valores'=>['deducoes'=>['valor'=>'100.00'], 'suspensao'=>['tipo'=>2,'numero_processo'=>str_repeat('0',29).'1']],
            'tributos_aproximados'=>['tipo'=>'percentual','federal'=>10,'estadual'=>0,'municipal'=>2],
            'tributos_federais'=>['pis_cofins'=>['cst'=>'01','base_calculo'=>'1400.00','aliquota_pis'=>'0.65',
                'aliquota_cofins'=>'3.00','valor_pis'=>'9.09','valor_cofins'=>'42.01','tipo_retencao'=>3],
                'retencao_irrf'=>'15.00','retencao_csll'=>'10.00'],
        ]);
        $dry=$this->dry($p);
        $this->assertSame(200,$dry->status,$dry->body);
        $this->assertNull($this->json($dry)['estimativa']['valor_liquido']);
        $r=$this->emitir($p,'nfse-tributos-informados-1');
        $this->assertSame(201,$r->status,$r->body);
        $this->assertCount(1,$this->sefin->dpsRecebidas);
        $this->assertStringContainsString('<vPis>9.09</vPis>',$this->sefin->dpsRecebidas[0]);
        $this->assertStringContainsString('<vRetCSLL>10.00</vRetCSLL>',$this->sefin->dpsRecebidas[0]);
        $this->assertStringContainsString('<tpSusp>2</tpSusp>',$this->sefin->dpsRecebidas[0]);
        $this->assertSame('autorizada',$this->json($r)['status']);
        $xml=$this->authed('GET',$this->json($r)['links']['xml']);
        $this->assertSame(200,$xml->status);
        $this->assertStringContainsString('<vPis>9.09</vPis>',$xml->body);
        $again=$this->emitir($p,'nfse-tributos-informados-1');
        $this->assertSame(201,$again->status,$again->body);
        $this->assertCount(1,$this->sefin->dpsRecebidas);
    }

    public function testLimiteDeChamadasASefinPorEmpresa(): void
    {
        $this->prontoParaEmitir();
        Env::set('NFSE_RATE_MAX', '1');
        try {
            $this->assertSame(200, $this->authed('GET', '/api/v1/nfse/parametros')->status);
            $r = $this->authed('GET', '/api/v1/nfse/parametros?atualizar=1');
            $this->assertSame(429, $r->status);
            $this->assertArrayHasKey('Retry-After', $r->headers);
        } finally {
            Env::set('NFSE_RATE_MAX', '60');
        }
    }
}
