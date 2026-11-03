<?php
declare(strict_types=1);
namespace Tests;

final class NfseIbsCbsTest extends TestCase
{
    private function configurar(int $ambiente): void
    {
        $this->configure();
        self::assertSame(200,$this->authed('PUT','/api/v1/tenant',json_encode(['ambiente_nfse'=>$ambiente]))->status);
        $r=$this->authed('PUT','/api/v1/nfse/config',json_encode(['inscricao_municipal'=>'12345','opcao_simples_nacional'=>3,'regime_apuracao_sn'=>1,'regime_especial'=>0,'serie'=>1]));
        self::assertSame(200,$r->status,$r->body);
    }

    private function payload(): array
    {
        return ['servico'=>['codigo_tributacao_nacional'=>'080201','nbs'=>'122051900','descricao'=>'Curso teste IBS CBS'],
            'tomador'=>['cpf'=>'52998224725','nome'=>'Cliente de teste'], 'valores'=>['servico'=>100],
            'ibs_cbs'=>['finalidade'=>0,'consumidor_final'=>1,'indicador_operacao'=>'100301','indicador_destinatario'=>0,'cst'=>'000','classificacao_tributaria'=>'000001']];
    }

    public function testClassificacaoNosDoisLeiautesSemPerderZeros(): void
    {
        foreach([1,2] as $ambiente){
            $this->configurar($ambiente);
            $r=$this->authed('POST','/api/v1/nfse?dry_run=1',json_encode($this->payload()));
            self::assertSame(200,$r->status,$r->body);$j=$this->json($r);self::assertTrue($j['valida']);self::assertSame([],$j['erros_schema']);
            $d=new \DOMDocument();$d->loadXML($j['xml']);$x=new \DOMXPath($d);$x->registerNamespace('n','http://www.sped.fazenda.gov.br/nfse');
            $root='/n:DPS/n:infDPS';$ibs=$root.'/n:IBSCBS';$classification=$ibs.'/n:valores/n:trib'.($ambiente===1?'/n:gIBSCBS':'');
            self::assertSame(1.0,$x->evaluate('count('.$ibs.')'));
            self::assertSame('000',$x->evaluate('string('.$classification.'/n:CST)'));
            self::assertSame('000001',$x->evaluate('string('.$classification.'/n:cClassTrib)'));
            self::assertSame('100301',$x->evaluate('string('.$ibs.'/n:cIndOp)'));
            self::assertSame('0',$x->evaluate('string('.($ambiente===1?$ibs:$root).'/n:finNFSe)'));
            self::assertSame('122051900',$x->evaluate('string('.$root.'/n:serv/n:cServ/n:cNBS)'));
            self::assertSame(0.0,$x->evaluate('count(//n:vCBS | //n:vIBSUF)'),'Não calcular IBS/CBS na DPS');
        }
    }

    public function testDiferimentoInformadoCompletoNosDoisLeiautes(): void
    {
        foreach([1,2] as $ambiente){
            $this->configurar($ambiente);$p=$this->payload();$p['ibs_cbs']['cst']='510';$p['ibs_cbs']['classificacao_tributaria']='510001';
            $p['ibs_cbs']['diferimento']=['percentual_uf'=>'0.00','percentual_municipal'=>'12.50','percentual_cbs'=>100];
            $r=$this->authed('POST','/api/v1/nfse?dry_run=1',json_encode($p));self::assertSame(200,$r->status,$r->body);
            $xml=$this->json($r)['xml'];self::assertStringContainsString('<pDifUF>0.00</pDifUF>',$xml);self::assertStringContainsString('<pDifMun>12.50</pDifMun>',$xml);self::assertStringContainsString('<pDifCBS>100.00</pDifCBS>',$xml);
        }
    }

    public function testCamposInvalidosNaoSaoIgnorados(): void
    {
        $this->configurar(2);$base=$this->payload();$invalid=[];
        foreach([null,[],['cst'=>'000']] as $value){$p=$base;$p['ibs_cbs']=$value;$invalid[]=$p;}
        foreach(['finalidade'=>1,'consumidor_final'=>true,'indicador_destinatario'=>1,'cst'=>0,'classificacao_tributaria'=>'1','indicador_operacao'=>'10030','campo_desconhecido'=>1] as $k=>$v){$p=$base;$p['ibs_cbs'][$k]=$v;$invalid[]=$p;}
        $p=$base;unset($p['servico']['nbs']);$invalid[]=$p;
        foreach(['1.234','100.01','-1','1e1',true,[]] as $value){$p=$base;$p['ibs_cbs']['diferimento']=['percentual_uf'=>$value,'percentual_municipal'=>'0.00','percentual_cbs'=>'0.00'];$invalid[]=$p;}
        $p=$base;$p['ibs_cbs']['diferimento']=['percentual_uf'=>'0.00'];$invalid[]=$p;
        $p=$base;$p['gIBSCBS']=['CST'=>'000','cClassTrib'=>'000001'];$invalid[]=$p;
        foreach($invalid as $p){$r=$this->authed('POST','/api/v1/nfse?dry_run=1',json_encode($p));self::assertSame(422,$r->status,$r->body);}
    }
}
