<?php
declare(strict_types=1);
namespace App\Nfse;

use App\Support\Errors;

/** Classificação e diferimento informados pelo consumidor, sem tabelas fiscais ou cálculos. */
final class IbsCbsInformados
{
    public static function validar(array $in, Errors $e): ?array
    {
        foreach (['IBSCBSDPS','gIBSCBS','gDif'] as $old) {
            if (array_key_exists($old,$in)) { $e->add($old,'Use o objeto ibs_cbs do contrato da API; grupos ACBr precisam ser convertidos pelo consumidor'); }
        }
        if (!array_key_exists('ibs_cbs',$in)) { return null; }
        $r=self::obj($in['ibs_cbs'],'ibs_cbs',['finalidade','consumidor_final','indicador_operacao','indicador_destinatario','cst','classificacao_tributaria','diferimento'],$e);
        foreach (['finalidade'=>[0],'consumidor_final'=>[0,1],'indicador_destinatario'=>[0]] as $key=>$values) {
            if (!isset($r[$key]) || !is_int($r[$key]) || !in_array($r[$key],$values,true)) {
                $e->add('ibs_cbs.'.$key,$key==='indicador_destinatario'?'Somente 0 (destinatário igual ao tomador); destinatário distinto ainda não implementado':'Inteiro: '.implode(' ou ',$values));
            }
        }
        foreach (['indicador_operacao'=>6,'cst'=>3,'classificacao_tributaria'=>6] as $key=>$digits) {
            if (!is_string($r[$key]??null) || !preg_match('/^[0-9]{'.$digits.'}$/D',$r[$key])) { $e->add('ibs_cbs.'.$key,'String com exatamente '.$digits.' dígitos; preserve os zeros à esquerda'); }
        }
        $nbs=$in['servico']['nbs']??null;
        if (!is_string($nbs) || !preg_match('/^[0-9]{9}$/D',$nbs)) { $e->add('servico.nbs','NBS de 9 dígitos é obrigatório ao informar IBS/CBS'); }
        if (array_key_exists('diferimento',$r)) {
            $d=self::obj($r['diferimento'],'ibs_cbs.diferimento',['percentual_uf','percentual_municipal','percentual_cbs'],$e);
            foreach (['percentual_uf','percentual_municipal','percentual_cbs'] as $key) {
                $value=$d[$key]??null;
                if ((!is_string($value)&&!is_int($value)&&!is_float($value)) || !preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,2})?$/D',(string)$value) || (float)$value>100) {
                    $e->add('ibs_cbs.diferimento.'.$key,'Percentual informado de 0 a 100, com no máximo duas casas decimais');
                } else { $d[$key]=number_format((float)$value,2,'.',''); }
            }
            $r['diferimento']=$d;
        }
        return $r;
    }

    private static function obj(mixed $value,string $path,array $allowed,Errors $e): array
    {
        if (!is_array($value) || $value===[] || array_is_list($value)) { $e->add($path,'Objeto não vazio esperado');return []; }
        foreach (array_diff(array_keys($value),$allowed) as $key) { $e->add($path.'.'.$key,'Campo não implementado; não será ignorado'); }
        return $value;
    }

    public static function montar(\DOMDocument $dom,\DOMElement $inf,array $r,bool $novo): void
    {
        $ibs=self::el($dom,$inf,'IBSCBS');
        if (!$novo) { self::el($dom,$ibs,'finNFSe',(string)$r['finalidade']); }
        self::el($dom,$ibs,'indFinal',(string)$r['consumidor_final']);
        self::el($dom,$ibs,'cIndOp',$r['indicador_operacao']);
        self::el($dom,$ibs,'indDest',(string)$r['indicador_destinatario']);
        $values=self::el($dom,$ibs,'valores');$trib=self::el($dom,$values,'trib');
        $classification=$novo?$trib:self::el($dom,$trib,'gIBSCBS');
        self::el($dom,$classification,'CST',$r['cst']);
        self::el($dom,$classification,'cClassTrib',$r['classificacao_tributaria']);
        if (isset($r['diferimento'])) {
            $group=$novo?self::el($dom,$trib,'gIBSCBS'):$classification;
            $diff=self::el($dom,$group,'gDif');
            foreach (['percentual_uf'=>'pDifUF','percentual_municipal'=>'pDifMun','percentual_cbs'=>'pDifCBS'] as $key=>$tag) { self::el($dom,$diff,$tag,$r['diferimento'][$key]); }
        }
    }

    private static function el(\DOMDocument $dom,\DOMElement $parent,string $name,?string $text=null): \DOMElement
    {
        $node=$dom->createElementNS(DpsBuilder::NS,$name);if($text!==null){$node->appendChild($dom->createTextNode($text));}$parent->appendChild($node);return $node;
    }
}
