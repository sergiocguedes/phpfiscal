# Reforma tributária (IBS, CBS e IS) na NF-e e na NFC-e

A API aceita dois modos. Use um deles por nota.

## 1. Calculado pela API (regime normal)

O sistema informa o cClassTrib de cada produto (`itens[].cclasstrib`), escolhido pela aplicação a partir do NCM e dos anexos. A API busca o CST, a redução e a vigência na tabela local de cClassTrib e as alíquotas (IBS UF, IBS municipal e CBS) na tabela de alíquotas da API, pela data e pelo município de destino (ou do emitente); alíquotas enviadas no JSON têm prioridade. O crédito presumido (`credito_presumido`: cCredPres e percentuais, da natureza da operação no ERP) gera o grupo gCredPresOper sobre a mesma base. Depois calcula IBS e CBS por item, preenche `vItem`, `IBSCBSTot` e `vNFTot` e valida o XML no XSD. Detalhes em [TRIBUTOS-CALCULO.md](TRIBUTOS-CALCULO.md), seção "IBS/CBS".

- Regime normal (CRT 3). Simples Nacional e MEI: dispensados em 2026; o cálculo é ignorado com alerta até o leiaute deles ser publicado.
- CST calculados: 000, 200, 400, 410 e 510.
- Tabelas globais mantidas pelo administrador no painel (página Reforma tributária): alíquotas por vigência e local (nacional, UF, município do IBGE) e cClassTrib (planilha oficial ou API Conformidade Fácil da SVRS; também por `php bin/cclasstrib.php`).
- Não se aplica à NFS-e (o IBS/CBS da NFS-e continua informado, ver [NFSE-IBS-CBS.md](NFSE-IBS-CBS.md)).

## 2. Informado pelo sistema

Para os casos que a API não calcula (alíquota fixa, suspensão, monofasia, crédito presumido da ZFM, Imposto Seletivo, compras governamentais...) o sistema envia códigos, bases, alíquotas, valores e totais. Use [RTC-INFORMADA-DELPHI.md](RTC-INFORMADA-DELPHI.md) e o [mapa de campos do XSD](RTC-CAMPOS-INFORMADOS.md). O comando `php bin/rtc-contrato.php` gera o mapa estrutural a partir dos XSD.

A API preserva a precisão informada, recusa campos desconhecidos e valida a estrutura XML com os schemas locais. Se a nota trouxer a RTC informada, ela prevalece sobre a calculada. A autorização e as regras de negócio externas continuam a cargo da SEFAZ.
