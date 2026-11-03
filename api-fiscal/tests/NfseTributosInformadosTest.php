<?php
declare(strict_types=1);
namespace Tests;

use App\Nfse\DpsBuilder;
use App\Nfse\NfseXml;
use App\Http\ApiException;

final class NfseTributosInformadosTest extends TestCase
{
    private function cfg(int $amb): array
    {
        return ['ambiente'=>$amb, 'codigo_municipio'=>'1302603', 'opcao_simples_nacional'=>1,
            'regime_especial'=>0, 'municipio_convenio_ativo'=>true];
    }

    private function payload(): array
    {
        return ['servico'=>['codigo_tributacao_nacional'=>'080201', 'descricao'=>'Serviço de teste'],
            'valores'=>['servico'=>1000, 'deducoes'=>['valor'=>'100.00'],
                'suspensao'=>['tipo'=>1, 'numero_processo'=>'000000000000437834420248040001']],
            'tributos_aproximados'=>['tipo'=>'percentual','federal'=>10,'estadual'=>0,'municipal'=>2],
            'tributos_federais'=>['pis_cofins'=>['cst'=>'01', 'base_calculo'=>'1000.00', 'aliquota_pis'=>'0.65',
                'aliquota_cofins'=>'3.00','valor_pis'=>'6.49','valor_cofins'=>'30.01','tipo_retencao'=>3],
                'retencao_cp'=>'0.00', 'retencao_irrf'=>'15.00', 'retencao_csll'=>'10.00']];
    }

    public function testBothSchemasPreserveInformedValuesWithoutCalculating(): void
    {
        foreach ([1,2] as $amb) {
            $cfg=$this->cfg($amb);
            $p=DpsBuilder::validatePayload($this->payload(),$cfg,['cnpj'=>'11222333000181']);
            $b=DpsBuilder::build($p,$cfg,['cnpj'=>'11222333000181'],1,1);
            $this->assertSame([],NfseXml::validate($b['xml'],'DPS_v1.01.xsd',$b['leiaute']));
            foreach (['<vPis>6.49</vPis>','<vCofins>30.01</vCofins>','<vRetCSLL>10.00</vRetCSLL>',
                '<vRetIRRF>15.00</vRetIRRF>','<vRetCP>0.00</vRetCP>','<tpSusp>1</tpSusp>',
                $amb===1?'<vDR>100.00</vDR>':'<vAjusteBCISSQN>100.00</vAjusteBCISSQN>'] as $tag) {
                $this->assertStringContainsString($tag,$b['xml']);
            }
            $this->assertNull($p['estimativa']['valor_liquido']);
            $this->assertStringNotContainsString('<IBSCBS>',$b['xml']);
        }
    }

    public function testPercentageAndAdministrativeSuspensionInBothSchemas(): void
    {
        foreach ([1,2] as $amb) {
            $in=$this->payload(); $in['valores']['deducoes']=['percentual'=>'10.50'];
            $in['valores']['suspensao']['tipo']=2;
            $p=DpsBuilder::validatePayload($in,$this->cfg($amb),['cnpj'=>'11222333000181']);
            $b=DpsBuilder::build($p,$this->cfg($amb),['cnpj'=>'11222333000181'],1,1);
            $this->assertSame([],NfseXml::validate($b['xml'],'DPS_v1.01.xsd',$b['leiaute']));
            $this->assertStringContainsString($amb===1?'<pDR>10.50</pDR>':'<pAjusteBCISSQN>10.50</pAjusteBCISSQN>',$b['xml']);
        }
    }

    public function testRejectsInvalidOrUnsupportedInformedFields(): void
    {
        $cases=[
            ['tributos_federais'=>null],
            ['tributos_federais'=>['pis_cofins'=>['cst'=>1]]],
            ['tributos_federais'=>['retencao_csll'=>'1.234']],
            ['tributos_federais'=>['retencao_irrf'=>-1]],
            ['tributos_federais'=>['pis_cofins'=>['tipo_retencao'=>10]]],
            ['tributos_federais'=>['retencao_pis'=>'1.00']],
            ['valores'=>['deducoes'=>['valor'=>1,'percentual'=>1]]],
            ['valores'=>['deducoes'=>['documentos'=>[]]]],
            ['valores'=>['deducoes'=>['percentual'=>'100.01']]],
            ['valores'=>['deducoes'=>['valor'=>'1001.00']]],
            ['valores'=>['suspensao'=>['tipo'=>3]]],
            ['valores'=>['suspensao'=>['numero_processo'=>'123']]],
            ['valores'=>['tributacao_iss'=>4]],
        ];
        foreach ($cases as $case) {
            $in=array_replace_recursive($this->payload(),$case);
            try {
                DpsBuilder::validatePayload($in,$this->cfg(2),['cnpj'=>'11222333000181']);
                $this->fail('Payload inválido aceito: '.json_encode($case));
            } catch (ApiException $e) { $this->assertSame(422,$e->status()); }
        }
    }
}
