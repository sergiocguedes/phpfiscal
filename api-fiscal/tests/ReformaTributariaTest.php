<?php
declare(strict_types=1);
namespace Tests;
use App\Storage\JsonStore;
use App\Tenancy\Tenant;

final class ReformaTributariaTest extends TestCase
{
    public function testDefaultsDaEmpresaNaoGeramTributosNemClassificacoes(): void
    {
        $this->configure();JsonStore::write(Tenant::path('reforma-tributaria.json'),['habilitado'=>true,'cst'=>'200','cClassTrib'=>'200034']);
        $r=$this->authed('POST','/api/v1/nfe?dry_run=1',$this->samplePayload());
        self::assertSame(200,$r->status,$r->body);self::assertStringNotContainsString('<IBSCBS>',$this->json($r)['xml']);
        self::assertSame(404,$this->authed('GET','/api/v1/reforma-tributaria/classificacoes')->status);
        self::assertSame(404,$this->authed('PUT','/api/v1/reforma-tributaria','{}')->status);
    }
    public function testRtcSemModoInformadoNaoEIgnoradaNemCalculada(): void
    {
        $this->configure();$p=json_decode($this->samplePayload(),true);
        $p['itens'][0]['impostos']['ibscbs']=['CST'=>'000','cClassTrib'=>'000001'];
        $r=$this->authed('POST','/api/v1/nfe?dry_run=1',json_encode($p));
        self::assertSame(422,$r->status);self::assertStringContainsString('modo=informado',$r->body);
    }
}
