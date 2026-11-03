<?php

declare(strict_types=1);

namespace App\Nfse;

use Com\Tecnick\Barcode\Barcode;
use NFePHP\DA\Legacy\Pdf;

/**
 * DANFSe (Documento Auxiliar da NFS-e, padrão nacional) gerado localmente a partir do XML da NFS-e autorizada
 * (leiaute NFSe_v1.01). Geração padrão local conforme a NT 008 v1.02, Anexo I.
 *
 * Layout DANFSe v2.0 — NT 008 versão 1.02: cabeçalho, chave de acesso, número, competência, emitente, tomador,
 * intermediário, serviço (código de tributação nacional e descrição), tributação municipal e federal, valor total,
 * totalização dos tributos, QR code de consulta pela chave e informações complementares.
 *
 * Segurança: o XML é lido com NfseXml::load (sem DTD/entidades, sem rede); todo texto vindo do XML passa por
 * self::t() (remove caracteres de controle e limita o tamanho). O FPDF escapa os delimitadores do PDF.
 * Logotipo nacional fixo; fontes licenciadas Arial e Microsoft Sans Serif fornecidas pela instalação.
 */
final class DanfseRenderer
{
    /** Consulta pública oficial pela chave de acesso (é o destino do QR code do DANFSe do ADN). */
    public const URL_CONSULTA = 'https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave=';

    private const X = 2.0;
    private const W = 206.0;
    private const F = 'danfsebody';

    private const UF = [
        '11' => 'RO', '12' => 'AC', '13' => 'AM', '14' => 'RR', '15' => 'PA', '16' => 'AP', '17' => 'TO', '21' => 'MA',
        '22' => 'PI', '23' => 'CE', '24' => 'RN', '25' => 'PB', '26' => 'PE', '27' => 'AL', '28' => 'SE', '29' => 'BA',
        '31' => 'MG', '32' => 'ES', '33' => 'RJ', '35' => 'SP', '41' => 'PR', '42' => 'SC', '43' => 'RS', '50' => 'MS',
        '51' => 'MT', '52' => 'GO', '53' => 'DF',
    ];
    private const TP_EMIT = ['1' => 'Prestador do serviço', '2' => 'Tomador do serviço', '3' => 'Intermediário do serviço'];
    private const OP_SIMP = [
        '1' => 'Não optante',
        '2' => 'Optante - Microempreendedor Individual (MEI)',
        '3' => 'Optante - Microempresa ou Empresa de Pequeno Porte (ME/EPP)',
    ];
    private const REG_AP_SN = [
        '1' => 'Tributos federais e municipal apurados pelo Simples Nacional',
        '2' => 'Tributos federais pelo SN e ISSQN pela NFS-e conforme legislação municipal',
        '3' => 'Tributos federais e municipal pela NFS-e conforme respectivas legislações',
    ];
    private const REG_ESP = [
        '0' => 'Nenhum', '1' => 'Ato cooperado (cooperativa)', '2' => 'Estimativa', '3' => 'Microempresa municipal',
        '4' => 'Notário ou registrador', '5' => 'Profissional autônomo', '6' => 'Sociedade de profissionais', '9' => 'Outros',
    ];
    private const TRIB_ISSQN = ['1' => 'Operação tributável', '2' => 'Imunidade', '3' => 'Exportação de serviço', '4' => 'Não incidência'];
    private const RET_ISSQN = ['1' => 'Não retido', '2' => 'Retido pelo tomador', '3' => 'Retido pelo intermediário'];
    private const IMUNIDADE = [
        '0' => 'Imunidade (tipo não informado)', '1' => 'Patrimônio, renda ou serviços uns dos outros',
        '2' => 'Templos de qualquer culto', '3' => 'Partidos, sindicatos, educação e assistência social',
        '4' => 'Livros, jornais, periódicos e papel', '5' => 'Fonogramas e videofonogramas musicais',
    ];
    private const SUSPENSAO = ['1' => 'Decisão judicial', '2' => 'Processo administrativo'];
    private const RET_PISCOFINS = [
        '0' => 'PIS/COFINS/CSLL não retidos', '1' => 'PIS/COFINS retidos', '2' => 'PIS/COFINS não retidos',
        '3' => 'PIS/COFINS/CSLL retidos', '4' => 'PIS/COFINS retidos, CSLL não retido', '5' => 'PIS retido, COFINS/CSLL não retidos',
        '6' => 'COFINS retido, PIS/CSLL não retidos', '7' => 'PIS não retido, COFINS/CSLL retidos',
        '8' => 'PIS/COFINS não retidos, CSLL retido', '9' => 'COFINS não retido, PIS/CSLL retidos',
    ];

    private Pdf $pdf;
    private \DOMXPath $xp;
    private \DOMElement $inf;
    private ?\DOMElement $dps;
    private float $y = 0.0;
    private bool $cancelada = false;

    /**
     * @param array{logo?: ?string, cancelada?: bool, substituida?: bool, rodape?: ?string} $opt
     *        logo/rodape legados são ignorados para preservar o modelo nacional.
     * @throws \UnexpectedValueException XML inválido ou que não é uma NFS-e
     */
    public static function render(string $xml, array $opt = []): string
    {
        $r = new self($xml);
        return $r->montar($opt);
    }

    private function __construct(string $xml)
    {
        $dom = NfseXml::load($xml);
        $this->xp = new \DOMXPath($dom);
        $this->xp->registerNamespace('n', DpsBuilder::NS);
        $inf = $this->xp->query('/n:NFSe/n:infNFSe')->item(0);
        if (!$inf instanceof \DOMElement) {
            throw new \UnexpectedValueException('infNFSe ausente');
        }
        $this->inf = $inf;
        $dps = $this->xp->query('n:DPS/n:infDPS', $inf)->item(0);
        $this->dps = $dps instanceof \DOMElement ? $dps : null;
        if ($this->chave() === '') {
            throw new \UnexpectedValueException('chave de acesso ausente');
        }
    }

    /** Modelo do Anexo I da NT 008 v1.02. Uma única página, sem fontes reduzidas automaticamente. */
    private function montar(array $opt): string
    {
        foreach (['arial', 'arialbd', 'micross'] as $font) {
            if (!is_file(APP_ROOT . '/secrets/danfse-fonts/' . $font . '.php') || !is_file(APP_ROOT . '/secrets/danfse-fonts/' . $font . '.z')) {
                throw new \RuntimeException('Instale as fontes do DANFSe conforme docs/DANFSE-NACIONAL.md');
            }
        }
        $this->pdf = new DanfsePdf('P', 'mm', 'A4');
        $p = $this->pdf;
        $p->addFont(self::F, '', 'micross.php');
        $p->addFont('danfselabel', '', 'arial.php');
        $p->addFont('danfselabel', 'B', 'arialbd.php');
        $p->setMargins(self::X, self::X);
        $p->setAutoPageBreak(false);
        $p->setTitle('DANFSe v2.0 - ' . $this->chave());
        $p->setCreator('API Fiscal - NT 008 v1.02');
        $p->addPage();
        $p->setDrawColor(0, 0, 0);
        $p->setLineWidth(0.353); // borda de um ponto
        $p->rect(self::X, self::X, self::W, 293);
        $p->setLineWidth(0.176); // divisões de meio ponto
        $this->cancelada = !empty($opt['cancelada']);
        if ($this->cancelada || !empty($opt['substituida'])) {
            $this->cor([166, 166, 166]);
            $p->setFont('danfselabel', '', 60);
            $texto = self::iso($this->cancelada ? 'CANCELADA' : 'SUBSTITUÍDA');
            $p->rotate(45, 105, 150);
            $p->text(105 - $p->getStringWidth($texto) / 2, 150, $texto);
            $p->rotate(0);
            $this->cor([0, 0, 0]);
        }
        $p->setFillColor(242, 242, 242);
        $p->rect(self::X, self::X, self::W, 12, 'F');
        $logo = (string) file_get_contents(APP_ROOT . '/resources/nfse/logo-nacional.png');
        if (!$this->logo($logo, 4, 3.5, 43, 8.5)) { throw new \RuntimeException('Logo nacional indisponível'); }
        $this->texto(53, 2.6, 102, 3.3, 'DANFSe v2.0', 9, 'B', true, 'C');
        $this->texto(53, 5.8, 102, 3.3, 'Documento Auxiliar da NFS-e', 9, 'B', true, 'C');
        if ($this->tpAmb() === '2') {
            $this->cor([255, 0, 0]);
            $this->texto(53, 9, 102, 3.3, 'NFS-e SEM VALIDADE JURÍDICA', 9, 'B', true, 'C');
            $this->cor([0, 0, 0]);
        }
        $cEmi = $this->v('n:DPS/n:infDPS/n:cLocEmi');
        $this->texto(157, 2.8, 49, 4.5, 'Município: ' . $this->municipio($cEmi), 8);
        $this->texto(157, 7.7, 49, 2.8, 'Ambiente Gerador: ' . (['1'=>'Município', '2'=>'Sistema Nacional NFS-e'][$this->v('n:ambGer')] ?? '-'), 6);
        $this->texto(157, 10.5, 49, 2.7, 'Tipo de Ambiente: ' . ($this->tpAmb() === '2' ? 'Homologação' : 'Produção'), 6);
        $this->y = 14;
        $this->divisao();
        $left = self::W * .75;
        $this->campoNacional(0, 3, 'CHAVE DE ACESSO DA NFS-E', $this->chave(), 7, false, true);
        $this->y += 7;
        $this->linhaNacional([
            ['NÚMERO DA NFS-E', $this->v('n:nNFSe')],
            ['COMPETÊNCIA DA NFS-E', self::data($this->v('n:DPS/n:infDPS/n:dCompet'))],
            ['DATA E HORA DA EMISSÃO DA NFS-E', self::dataHora($this->v('n:dhProc'))],
        ], 7, true);
        $this->linhaNacional([
            ['NÚMERO DA DPS', $this->v('n:DPS/n:infDPS/n:nDPS')],
            ['SÉRIE DA DPS', $this->v('n:DPS/n:infDPS/n:serie')],
            ['DATA E HORA DA EMISSÃO DA DPS', self::dataHora($this->v('n:DPS/n:infDPS/n:dhEmi'))],
        ], 7, true);
        $fin = $this->v('n:DPS/n:infDPS/n:finNFSe');
        if ($fin === '') { $fin = $this->v('n:DPS/n:infDPS/n:IBSCBS/n:finNFSe'); }
        $status = $this->cancelada ? 'Cancelada' : (['100'=>'NFS-e Gerada','102'=>'NFS-e de Decisão Judicial','103'=>'NFS-e Avulsa','107'=>'NFS-e MEI'][$this->v('n:cStat')] ?? $this->v('n:cStat'));
        $this->linhaNacional([
            ['EMITENTE DA NFS-E', ['1'=>'Prestador','2'=>'Tomador','3'=>'Intermediário'][$this->v('n:DPS/n:infDPS/n:tpEmit')] ?? '-', 1, true],
            ['SITUAÇÃO DA NFS-E', $status],
            ['FINALIDADE', ['0'=>'NFS-e regular','1'=>'Nota de crédito','2'=>'Nota de débito'][$fin] ?? '-'],
        ], 7, true);
        $this->qr(self::URL_CONSULTA . $this->chave(), 174.8, 16.7, 15.2);
        $this->texto(self::X + $left + 1, 33, self::W - $left - 2, 8.4,
            'A autenticidade desta NFS-e pode ser verificada pela leitura deste código QR ou pela consulta da chave de acesso no portal nacional da NFS-e', 6);
        $this->pessoaNacional('PRESTADOR / FORNECEDOR', 'n:emit', true);
        $this->pessoaNacional('TOMADOR / ADQUIRENTE', 'n:DPS/n:infDPS/n:toma');
        if ($this->v('n:DPS/n:infDPS/n:IBSCBS/n:indDest') === '0') {
            $this->divisao(); $this->texto(self::X + 1, $this->y + .2, self::W - 2, 3.2, 'O DESTINATÁRIO É O PRÓPRIO TOMADOR/ADQUIRENTE DA OPERAÇÃO', 7, 'B', true); $this->y += 3.2;
        } else { $this->pessoaNacional('DESTINATÁRIO DA OPERAÇÃO', 'n:DPS/n:infDPS/n:IBSCBS/n:dest'); }
        $this->pessoaNacional('INTERMEDIÁRIO DA OPERAÇÃO', 'n:DPS/n:infDPS/n:interm');
        $this->servicoNacional();
        $this->tributosNacionais();
        $this->totaisNacionais();
        $this->informacoesNacionais();
        return $p->getPdf();
    }

    private function pessoaNacional(string $titulo, string $path, bool $prestador = false): void
    {
        $this->divisao();
        if ($this->xp->query($path, $this->inf)->length === 0) {
            $this->texto(self::X + 1, $this->y + .2, self::W - 2, 3.2, $titulo . ' NÃO IDENTIFICADO NA NFS-e', 7, 'B', true); $this->y += 3.2; return;
        }
        $im = $this->v("$path/n:IM") ?: ($prestador ? $this->v('n:DPS/n:infDPS/n:prest/n:IM') : '');
        $this->linhaNacional([['@'.$titulo, ''], ['CNPJ / CPF / NIF', self::doc($this->docDe($path))], ['Indicador Municipal (Inscrição)', $im], ['Telefone', self::fone($this->v("$path/n:fone"))]]);
        $endPath = $prestador ? "$path/n:enderNac" : "$path/n:end";
        $end = $this->endereco($endPath);
        $mun = $end['cMun'];
        $local = $mun !== '' ? $this->municipio($mun) : $this->v("$endPath/n:endExt/n:xCidade");
        $cep = $end['cep'] !== '' ? self::cep($end['cep']) : $this->v("$endPath/n:endExt/n:cEndPost");
        $this->linhaNacional([['Nome / Nome Empresarial', $this->v("$path/n:xNome"), 2], ['Município / Sigla UF', $local], ['Código IBGE / CEP', trim($mun . ' / ' . $cep, ' /')]]);
        $this->linhaNacional([['Endereço', $end['endereco'], 2], ['E-mail', $this->v("$path/n:email"), 2]]);
        if ($prestador) {
            $base = 'n:DPS/n:infDPS/n:prest/n:regTrib';
            $this->linhaNacional([['Simples Nacional na Data de Competência', self::OP_SIMP[$this->v("$base/n:opSimpNac")] ?? '-', 1], ['Regime de Apuração Tributária pelo SN', self::REG_AP_SN[$this->v("$base/n:regApTribSN")] ?? '-', 3]]);
        }
    }

    private function servicoNacional(): void
    {
        $this->divisao();
        $s = 'n:DPS/n:infDPS/n:serv';
        $this->linhaNacional([['@SERVIÇO PRESTADO', ''], ['Código de Tributação Nacional / Municipal', trim($this->v("$s/n:cServ/n:cTribNac") . ' / ' . $this->v("$s/n:cServ/n:cTribMun"), ' /')], ['Código da NBS', $this->v("$s/n:cServ/n:cNBS")], ['Local da Prestação / Sigla UF / País', $this->municipio($this->v("$s/n:locPrest/n:cLocPrestacao"))]]);
        $desc = $this->v('n:xTribMun') ?: $this->v('n:xTribNac');
        $this->texto(self::X + 1, $this->y + .3, self::W - 2, 5, $desc, 7);
        $this->texto(self::X + 1, $this->y + 5.5, self::W - 2, 3, 'Descrição do Serviço', 6, 'B', true);
        $this->texto(self::X + 1, $this->y + 8.3, self::W - 2, 41.2, $this->v("$s/n:cServ/n:xDescServ"), 7);
        $this->y += 50;
    }

    private function tributosNacionais(): void
    {
        $this->divisao();
        $v = 'n:DPS/n:infDPS/n:valores'; $m = "$v/n:trib/n:tribMun"; $f = "$v/n:trib/n:tribFed"; $pc = "$f/n:piscofins";
        $esp = $this->v('n:DPS/n:infDPS/n:prest/n:regTrib/n:regEspTrib');
        $susp = $this->v("$m/n:exigSusp/n:tpSusp");
        $this->linhaNacional([['@TRIBUTAÇÃO MUNICIPAL (ISSQN)', ''], ['Tipo de Tributação do ISSQN', self::TRIB_ISSQN[$this->v("$m/n:tribISSQN")] ?? '-'], ['Município / Sigla UF / País de Incidência do ISSQN', $this->municipio($this->v('n:cLocIncid')), 2]]);
        $this->linhaNacional([['Regime Especial de Tributação do ISSQN', self::REG_ESP[$esp] ?? '-'], ['Tipo de Imunidade do ISSQN', self::IMUNIDADE[$this->v("$m/n:tpImunidade")] ?? '-'], ['Suspensão da Exigibilidade do ISSQN', self::SUSPENSAO[$susp] ?? '-'], ['Número Processo Suspensão', $this->v("$m/n:exigSusp/n:nProcesso")]]);
        $this->linhaNacional([['Benefício Municipal', ['1'=>'Isenção','2'=>'Redução percentual da BC','3'=>'Redução da BC em valor','4'=>'Alíquota diferenciada'][$this->v('n:valores/n:tpBM')] ?? '-'], ['Cálculo do BM', self::money($this->v('n:valores/n:vCalcBM') ?: $this->v("$m/n:BM/n:vRedBCBM"))], ['Total Deduções/Reduções', self::money($this->soma(['n:valores/n:vCalcDR', 'n:IBSCBS/n:valores/n:vCalcReeRepRes'], $this->v("$v/n:vDedRed/n:vDR")))], ['Desconto Incondicionado', self::money($this->v("$v/n:vDescCondIncond/n:vDescIncond"))]]);
        $this->linhaNacional([['BC ISSQN', self::money($this->v('n:valores/n:vBC'))], ['Alíquota Aplicada', self::pct($this->v('n:valores/n:pAliqAplic'))], ['Retenção do ISSQN', self::RET_ISSQN[$this->v("$m/n:tpRetISSQN")] ?? '-'], ['ISSQN Apurado', self::money($this->v('n:valores/n:vISSQN'))]]);
        $this->divisao();
        $retPc = $this->v("$pc/n:tpRetPisCofins");
        $retSociais = $this->v("$f/n:vRetCSLL");
        if ($retPc === '1') { $retSociais = $this->soma(["$f/n:vRetCSLL", "$pc/n:vPis", "$pc/n:vCofins"]); }
        $this->linhaNacional([['@TRIBUTAÇÃO FEDERAL (EXCETO CBS)', ''], ['IRRF', self::money($this->v("$f/n:vRetIRRF"))], ['Contribuição Previdenciária - Retida', self::money($this->v("$f/n:vRetCP"))], ['Contribuições Sociais - Retidas', self::money($retSociais)]]);
        if (substr($this->v('n:DPS/n:infDPS/n:dCompet'), 0, 4) <= '2026') {
            $this->linhaNacional([['PIS - Débito Apuração Própria', self::money($retPc === '1' ? '0.00' : $this->v("$pc/n:vPis"))], ['COFINS - Débito Apuração Própria', self::money($retPc === '1' ? '0.00' : $this->v("$pc/n:vCofins"))], ['Descrição Contrib. Sociais - Retidas', self::RET_PISCOFINS[$retPc] ?? '-', 2]]);
        }
        $this->divisao();
        $g = 'n:IBSCBS'; $t = 'n:DPS/n:infDPS/n:IBSCBS';
        $cst = $this->v("$t/n:valores/n:trib/n:CST") ?: $this->v("$t/n:valores/n:trib/n:gIBSCBS/n:CST");
        $classe = $this->v("$t/n:valores/n:trib/n:cClassTrib") ?: $this->v("$t/n:valores/n:trib/n:gIBSCBS/n:cClassTrib");
        $ci = $this->v("$g/n:cLocalidadeIncid");
        $loc = implode(' / ', array_filter([$this->v("$t/n:cIndOp"), $ci, $this->v("$g/n:xLocalidadeIncid"), self::UF[substr($ci, 0, 2)] ?? '']));
        $this->linhaNacional([['@TRIBUTAÇÃO IBS / CBS', ''], ['CST / cClassTrib', trim($cst . ' / ' . $classe, ' /')], ['Indicador de Operação / Código IBGE Incidência / Município Incidência / Sigla UF', $loc, 2]]);
        $this->linhaNacional([['Exclusões e Reduções da Base de Cálculo', self::money($this->soma(["$v/n:vDescCondIncond/n:vDescIncond", "$g/n:valores/n:vCalcReeRepRes", 'n:valores/n:vISSQN', "$pc/n:vPis", "$pc/n:vCofins"]))], ['Base de Cálculo Após Exclusões e Reduções', self::money($this->v("$g/n:valores/n:vBC"))], ['Red. Alíquota IBS / Red. Alíquota CBS', implode(' / ', [self::pct($this->v("$g/n:valores/n:uf/n:pRedAliqUF")), self::pct($this->v("$g/n:valores/n:mun/n:pRedAliqMun")), self::pct($this->v("$g/n:valores/n:fed/n:pRedAliqCBS"))])], ['Alíquota - IBS UF / IBS Mun', self::pct($this->v("$g/n:valores/n:uf/n:pIBSUF")) . ' / ' . self::pct($this->v("$g/n:valores/n:mun/n:pIBSMun"))]]);
        $this->linhaNacional([['Alíq. Efetiva Municipal - IBS', self::pct($this->v("$g/n:valores/n:mun/n:pAliqEfetMun"))], ['Valor Apurado Municipal - IBS', self::money($this->v("$g/n:totCIBS/n:gIBS/n:gIBSMunTot/n:vIBSMun"))], ['Alíq. Efetiva Estadual - IBS', self::pct($this->v("$g/n:valores/n:uf/n:pAliqEfetUF"))], ['Valor Apurado Estadual - IBS', self::money($this->v("$g/n:totCIBS/n:gIBS/n:gIBSUFTot/n:vIBSUF"))]]);
        $this->linhaNacional([['Valor Total Apurado - IBS', self::money($this->v("$g/n:totCIBS/n:gIBS/n:vIBSTot"))], ['Alíquota - CBS', self::pct($this->v("$g/n:valores/n:fed/n:pCBS"))], ['Alíquota Efetiva - CBS', self::pct($this->v("$g/n:valores/n:fed/n:pAliqEfetCBS"))], ['Valor Total Apurado - CBS', self::money($this->v("$g/n:totCIBS/n:gCBS/n:vCBS"))]]);
    }

    private function totaisNacionais(): void
    {
        $this->divisao();
        $v = 'n:DPS/n:infDPS/n:valores';
        $this->linhaNacional([['@VALOR TOTAL DA NFS-E', ''], ['VALOR DA OPERAÇÃO / SERVIÇO', self::money($this->v("$v/n:vServPrest/n:vServ"))], ['Desconto Incondicionado', self::money($this->v("$v/n:vDescCondIncond/n:vDescIncond"))], ['Desconto Condicionado', self::money($this->v("$v/n:vDescCondIncond/n:vDescCond"))]]);
        $this->linhaNacional([['Total das Retenções (ISSQN / Federais)', self::money($this->v('n:valores/n:vTotalRet'))], ['VALOR LÍQUIDO DA NFS-e', self::money($this->v('n:valores/n:vLiq'))], ['Total do IBS/CBS', self::money($this->soma(['n:IBSCBS/n:totCIBS/n:gIBS/n:vIBSTot', 'n:IBSCBS/n:totCIBS/n:gCBS/n:vCBS']))], ['VALOR LÍQUIDO DA NFS-e + IBS/CBS', self::money($this->v('n:IBSCBS/n:totCIBS/n:vTotNF')), 1, true]]);
    }

    private function informacoesNacionais(): void
    {
        $this->divisao();
        $this->texto(self::X + 1, $this->y + .3, self::W - 2, 3, 'INFORMAÇÕES COMPLEMENTARES', 7, 'B', true);
        $this->y += 3.5;
        $s = 'n:DPS/n:infDPS/n:serv/n:infoCompl';
        $parts = [];
        foreach (['Inf. Cont.'=>"$s/n:xInfComp", 'NFS-e Subst.'=>'n:DPS/n:infDPS/n:subst/n:chSubstda', 'Doc. Ref.'=>"$s/n:docRef", 'Cod. Obra'=>'n:DPS/n:infDPS/n:serv/n:obra/n:cObra', 'Insc. Imob.'=>'n:DPS/n:infDPS/n:IBSCBS/n:imovel/n:inscImobFisc', 'Cod. Evt.'=>'n:DPS/n:infDPS/n:serv/n:atvEvento/n:idAtvEvt', 'Doc. Tec.'=>"$s/n:idDocTec", 'Núm. Ped.'=>"$s/n:xPed", 'Inf. A. T. Mun.'=>'n:xOutInf'] as $rotulo=>$path) {
            if (($txt = $this->v($path)) !== '') { $parts[] = $rotulo . ': ' . $txt; }
        }
        foreach ($this->xp->query("$s/n:gItemPed/n:xItemPed", $this->inf) as $node) { $parts[] = 'Item Ped.: ' . $node->textContent; }
        $t = 'n:DPS/n:infDPS/n:valores/n:trib/n:totTrib';
        $valores = []; foreach (['Fed','Est','Mun'] as $suf) {
            $value = $this->v("$t/n:vTotTrib/n:vTotTrib$suf"); $perc = $this->v("$t/n:pTotTrib/n:pTotTrib$suf");
            $valores[] = $value !== '' ? self::money($value) : ($perc !== '' ? self::pct($perc) : '-');
        }
        $totais = 'Totais Aproximados dos Tributos cfe. Lei nº 12.741/2012: Federais: ' . $valores[0] . '; Estaduais: ' . $valores[1] . '; Municipais: ' . $valores[2] . '.';
        if (($sn = $this->v("$t/n:pTotTribSN")) !== '') { $totais .= ' Simples Nacional: ' . self::pct($sn) . '.'; }
        $this->texto(self::X + 1, $this->y, self::W - 2, 284 - $this->y, implode(' | ', $parts), 7);
        $this->texto(self::X + 1, 285, self::W - 2, 8, $totais, 7);
    }

    /** Somatórios de apresentação previstos na NT, sem alterar/calcular tributos do XML. */
    private function soma(array $paths, string $fallback = ''): string
    {
        $centavos = 0; $encontrou = false;
        foreach ($paths as $path) { $v = $this->v($path); if ($v !== '' && is_numeric($v)) { $centavos += (int) round((float) $v * 100); $encontrou = true; } }
        return $encontrou ? number_format($centavos / 100, 2, '.', '') : $fallback;
    }

    private function divisao(): void { $this->pdf->line(self::X, $this->y, self::X + self::W, $this->y); }

    private function linhaNacional(array $campos, float $h = 6.4, bool $identificacao = false): void
    {
        $col = 0;
        foreach ($campos as $c) { $span = $c[2] ?? 1; $this->campoNacional($col, $span, $c[0], $c[1], $h, !empty($c[3]), $identificacao); $col += $span; }
        $this->y += $h;
    }

    private function campoNacional(float $col, float $span, string $rotulo, string $valor, float $h, bool $fundo = false, bool $identificacao = false): void
    {
        $x = self::X + self::W / 4 * $col; $w = self::W / 4 * $span;
        $titulo = str_starts_with($rotulo, '@');
        if ($titulo || $fundo) { $this->pdf->setFillColor(242, 242, 242); $this->pdf->rect($x, $this->y, $w, $h, 'F'); }
        $this->texto($x + 1, $this->y + .15, $w - 2, $titulo ? $h - .3 : 4.4, ltrim($rotulo, '@'), $titulo || $identificacao ? 7 : 6, 'B', true);
        if (!$titulo) { $this->texto($x + 1, $this->y + $h - 2.8, $w - 2, 2.8, $valor ?: '-', 7); }
    }

    /** Quebra e reticências explícitas: nunca reduz a fonte abaixo do mínimo nacional. */
    private function texto(float $x, float $y, float $w, float $h, string $text, float $size, string $style = '', bool $label = false, string $align = 'L'): void
    {
        $p = $this->pdf; $p->setFont($label ? 'danfselabel' : self::F, $style, $size);
        $text = self::iso(html_entity_decode(self::t($text, 10000), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $lines = []; $line = '';
        foreach (preg_split('/\r?\n/', $text) as $paragraph) {
            foreach (preg_split('/\s+/', trim($paragraph)) as $word) {
                if ($line !== '' && $p->getStringWidth($line . ' ' . $word) > $w) { $lines[] = $line; $line = ''; }
                while ($p->getStringWidth($word) > $w && strlen($word) > 1) {
                    $part = ''; while ($word !== '' && $p->getStringWidth($part . $word[0]) <= $w) { $part .= $word[0]; $word = substr($word, 1); } $lines[] = $part;
                }
                $line = $line === '' ? $word : $line . ' ' . $word;
            }
            $lines[] = $line; $line = '';
        }
        $step = $size * .35278; $max = max(1, (int) floor($h / $step));
        if (count($lines) > $max) {
            $lines = array_slice($lines, 0, $max); $last = array_pop($lines);
            while ($last !== '' && $p->getStringWidth($last . '...') > $w) { $last = substr($last, 0, -1); } $lines[] = $last . '...';
        }
        foreach ($lines as $i=>$line) { $dx = $align === 'C' ? ($w - $p->getStringWidth($line)) / 2 : 0; $p->text($x + $dx, $y + $step * ($i + .85), $line); }
    }

    private function cor(array $rgb): void
    {
        $this->pdf->textColor = sprintf('%.3F %.3F %.3F rg', $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255);
        $this->pdf->colorFlag = true;
    }

    private function qr(string $conteudo, float $x, float $y, float $lado): void
    {
        $png = (new Barcode())->getBarcodeObj('QRCODE,M', $conteudo, -4, -4, 'black', [-2,-2,-2,-2])->setBackgroundColor('white')->getPngData();
        $this->pdf->image('data://text/plain;base64,' . base64_encode($png), $x, $y, $lado, $lado, 'PNG');
    }

    /** Desenha o logo (PNG/JPEG) proporcional dentro da caixa. false = não desenhou (sem quebrar o DANFSe). */
    private function logo(string $bin, float $x, float $y, float $wMax, float $hMax): bool
    {
        if (strlen($bin) > 1024 * 1024) {
            return false;
        }
        $tipo = str_starts_with($bin, "\x89PNG\r\n\x1a\n") ? 'PNG' : (str_starts_with($bin, "\xFF\xD8\xFF") ? 'JPG' : null);
        $info = $tipo !== null ? @getimagesizefromstring($bin) : false;
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            return false;
        }
        try {
            if ($tipo === 'PNG') {
                // o parser PNG do FPDF não aceita canal alfa/entrelaçado: regrava em PNG RGB simples via GD
                $img = @imagecreatefromstring($bin);
                if ($img === false) {
                    return false;
                }
                $fundo = imagecreatetruecolor($info[0], $info[1]);
                imagefill($fundo, 0, 0, imagecolorallocate($fundo, 255, 255, 255));
                imagecopy($fundo, $img, 0, 0, 0, 0, $info[0], $info[1]);
                imageinterlace($fundo, false);
                ob_start();
                imagepng($fundo);
                $bin = (string) ob_get_clean();
                imagedestroy($img);
                imagedestroy($fundo);
            }
            $r = min($wMax / $info[0], $hMax / $info[1]);
            $w = $info[0] * $r;
            $h = $info[1] * $r;
            $this->pdf->image('data://text/plain;base64,' . base64_encode($bin), $x + ($wMax - $w) / 2, $y + ($hMax - $h) / 2, $w, $h, $tipo);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    // ------------------------------------------------------------ leitura do XML

    private function v(string $path): string
    {
        $n = $this->xp->query($path, $this->inf)->item(0);
        return $n !== null ? trim((string) $n->textContent) : '';
    }

    private function chave(): string
    {
        return preg_match('/^NFS(\d{50})$/', $this->inf->getAttribute('Id'), $m) ? $m[1] : '';
    }

    private function tpAmb(): string
    {
        return $this->v('n:DPS/n:infDPS/n:tpAmb');
    }

    private function docDe(string $path): string
    {
        foreach (['CNPJ', 'CPF', 'NIF'] as $t) {
            if (($d = $this->v("$path/n:$t")) !== '') {
                return $d;
            }
        }
        $nao = $this->v("$path/n:cNaoNIF");
        return $nao !== '' ? 'NIF não informado' : '';
    }

    /** @return array{endereco:string,cMun:string,cep:string} */
    private function endereco(string $path): array
    {
        $partes = array_filter([
            $this->v("$path/n:xLgr"),
            $this->v("$path/n:nro"),
            $this->v("$path/n:xCpl"),
            $this->v("$path/n:xBairro"),
        ], fn($s) => $s !== '');
        $cMun = $this->v("$path/n:cMun") ?: $this->v("$path/n:endNac/n:cMun");
        $cep = $this->v("$path/n:CEP") ?: $this->v("$path/n:endNac/n:CEP");
        return ['endereco' => $partes === [] ? '-' : implode(', ', $partes), 'cMun' => $cMun, 'cep' => $cep];
    }

    /** Nome do município quando a própria NFS-e o traz (emissão, prestação, incidência); senão, código IBGE e UF. */
    private function municipio(string $cMun): string
    {
        if ($cMun === '') {
            return '-';
        }
        $uf = self::UF[substr($cMun, 0, 2)] ?? '';
        $nomes = [
            $this->v('n:DPS/n:infDPS/n:cLocEmi') => $this->v('n:xLocEmi'),
            $this->v('n:emit/n:enderNac/n:cMun') => $this->v('n:xLocEmi'),
            $this->v('n:DPS/n:infDPS/n:serv/n:locPrest/n:cLocPrestacao') => $this->v('n:xLocPrestacao'),
            $this->v('n:cLocIncid') => $this->v('n:xLocIncid'),
        ];
        $nome = $nomes[$cMun] ?? '';
        if ($nome !== '') {
            return $nome . ($uf !== '' ? " - $uf" : '');
        }
        return 'IBGE ' . $cMun . ($uf !== '' ? " - $uf" : '');
    }

    // ------------------------------------------------------------ formatação e escape

    /**
     * Texto seguro para o PDF: sem caracteres de controle (exceto quebra de linha), UTF-8 válido, tamanho limitado
     * e "&" neutralizado (o textBox do sped-da aplica html_entity_decode; assim "&lt;" do XML sai literal).
     */
    public static function t(string $s, int $max = 2000): string
    {
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        $s = (string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', $s);
        $s = (string) preg_replace('/\r\n?/', "\n", $s);
        if (mb_strlen($s) > $max) {
            $s = mb_substr($s, 0, $max - 3) . '...';
        }
        return str_replace('&', '&amp;', $s);
    }

    private static function iso(string $s): string
    {
        return (string) mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    }

    private static function money(string $v): string
    {
        if ($v === '' || !is_numeric($v)) {
            return '-';
        }
        return 'R$ ' . number_format((float) $v, 2, ',', '.');
    }

    private static function pct(string $v): string
    {
        if ($v === '' || !is_numeric($v)) {
            return '-';
        }
        return number_format((float) $v, 2, ',', '.') . '%';
    }

    private static function data(string $v): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) ? "$m[3]/$m[2]/$m[1]" : ($v ?: '-');
    }

    private static function dataHora(string $v): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}:\d{2}:\d{2})/', $v, $m) ? "$m[3]/$m[2]/$m[1] $m[4]" : ($v ?: '-');
    }

    private static function doc(string $d): string
    {
        if (preg_match('/^\d{14}$/', $d)) {
            return substr($d, 0, 2) . '.' . substr($d, 2, 3) . '.' . substr($d, 5, 3) . '/' . substr($d, 8, 4) . '-' . substr($d, 12, 2);
        }
        if (preg_match('/^\d{11}$/', $d)) {
            return substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9, 2);
        }
        return $d !== '' ? $d : '-';
    }

    private static function cep(string $c): string
    {
        return preg_match('/^\d{8}$/', $c) ? substr($c, 0, 5) . '-' . substr($c, 5) : ($c ?: '-');
    }

    private static function fone(string $f): string
    {
        if (preg_match('/^(\d{2})(\d{4,5})(\d{4})$/', $f, $m)) {
            return "($m[1]) $m[2]-$m[3]";
        }
        return $f !== '' ? $f : '-';
    }
}
