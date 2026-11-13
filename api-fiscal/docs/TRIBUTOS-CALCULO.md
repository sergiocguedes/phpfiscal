# Cálculo de tributos — `POST /api/v1/tributos/calcular`

Calcula ICMS (CST e CSOSN, ST, FCP, desoneração, diferimento, monofásico), IPI, PIS, COFINS, ISSQN e os tributos aproximados da Lei 12.741/2012 de uma venda, item a item, com os totais da nota. **Só calcula**: não grava, não numera e não transmite. Usa a mesma autenticação das demais rotas da empresa (chave de API ou sessão; credencial central com `X-Empresa-Id`).

Os códigos fiscais de cada item (CFOP, CST/CSOSN, alíquotas, cClassTrib) vêm **sempre no JSON**, enviados pelo sistema que integra (ERP, ex.: sistema feito no MadBuilder). A API não mantém cadastro de regras, perfis, operações nem tabelas de alíquotas ou de cClassTrib. O mesmo cálculo roda dentro de `POST /api/v1/nfe` e `/api/v1/pdv/nfce`, no momento do envio (seção "Cálculo na emissão"); lá também é possível enviar as tags já calculadas pelo ERP (`calculo_tributos.modo = erp`).

## Entrada

```json
{
  "modelo": 55,
  "emitente": { "crt": 3, "uf": "SP" },
  "operacao": { "uf_destino": "SP", "consumidor_final": false, "contribuinte_icms": true },
  "rateio": { "desconto": 0, "acrescimo": 0, "frete": 0, "seguro": 0 },
  "itens": [
    {
      "item": 1,
      "quantidade": 2,
      "valor_unitario": 5.49,
      "desconto": 0.50,
      "regra": {
        "cfop": "5405",
        "icms": { "orig": 0, "CST": "10", "pICMS": 20, "pMVAST": 40, "pICMSST": 20 },
        "pis": { "CST": "01", "pPIS": 1.65 },
        "cofins": { "CST": "01", "pCOFINS": 7.6 },
        "ibpt": { "federal": 13.45, "estadual": 18, "municipal": 0 }
      }
    }
  ]
}
```

| Campo | Padrão | Observação |
|---|---|---|
| `modelo` | 55 | 65 (NFC-e) ignora IPI e uf_destino |
| `emitente.crt` / `emitente.uf` | configuração da empresa | CRT 1 e 4 usam CSOSN; 2 e 3 usam CST |
| `operacao.uf_destino` | UF do emitente | diferente da UF do emitente = interestadual (NF-e) |
| `operacao.consumidor_final` | true na NFC-e, false na NF-e | IPI entra na base do ICMS próprio |
| `operacao.desconto_iss_condicionado` | false | true: base do ISS sem o desconto |
| `operacao.excluir_icms_base_piscofins` | false | true: base do PIS/COFINS sem o ICMS destacado |
| `rateio.*` | 0 | rateado pelo vProd; a sobra de centavos vai para o maior item |
| `itens[].valor_total` | quantidade × valor_unitario | informe quando o PDV já tem o total do item |
| `itens[].servico` | false | true: calcula ISSQN e não ICMS/IPI |
| `itens[].qtd_tributavel_unidade` | 1 | combustível (monofásico): quantidade tributada por unidade vendida |
| `operacao.data` | hoje (horário de Brasília) | `AAAA-MM-DD`: se o `valor_item` soma IBS/CBS (a partir de 2027) |
| `operacao.aliquotas_ibscbs` | — | `{pIBSUF, pIBSMun, pCBS}` em %: obrigatório quando algum item calcula IBS/CBS |
| `operacao.municipio_destino` | município do emitente | código IBGE do destinatário: registrado na fotografia do IBS/CBS |
| `operacao.credito_presumido` | — | `{cCredPres, pCredPresIBS?, pCredPresCBS?}` da natureza da operação no ERP; vale para todos os itens com IBS/CBS (o item pode trocar em `itens[].credito_presumido` ou anular com `null`) |
| `emitente.codigo_municipio` | configuração da empresa | município do emitente (usado quando não há destino) |
| `itens[].cclasstrib` | — | cClassTrib do produto: calcula IBS/CBS (ver "IBS/CBS") |

### Regra do item (`regra`)

Os nomes seguem as tags do leiaute da NF-e.

- `icms`: `orig`, `CST` ou `CSOSN`, `modBC`, `pICMS`, `pRedBC`, `modBCST`, `pMVAST`, `pICMSST`, `pRedBCST`, `pDif`, `pCredSN`, `pFCP`, `pFCPST`, `adRemICMS`, `adRemICMSRet`, `motDesICMS`, `indDeduzDeson`, `metodo_desoneracao` (CST 30/40/41/50/90: `por_fora`, padrão, ou `por_dentro`, conforme a base legal do benefício); no CST 60 / CSOSN 500, os valores retidos (`vBCSTRet`, `pST`, `vICMSSubstituto`, `vICMSSTRet`, `vBCFCPSTRet`, `pFCPSTRet`, `vFCPSTRet`) são repassados.
- `pis` / `cofins`: `CST`, `pPIS`/`pCOFINS` ou `vAliqProd` (CST 03).
- `ipi` (só NF-e): `CST`, `cEnq` (padrão 999), `pIPI` ou `vUnid`.
- `iss` (serviço): `aliquota`, `cMunFG`, `cListServ`, `indISS`, `indIncentivo`.
- `ibpt`: `federal`, `estadual`, `municipal` (%).
- `ibscbs` (Reforma Tributária, regime normal): `cClassTrib` (ou `itens[].cclasstrib`), `CST` opcional (3 primeiros dígitos do cClassTrib), `pRedIBS` e `pRedCBS` (CST 200: redução do cClassTrib), `pDif` (CST 510).

No item, `origem` completa `regra.icms.orig` e `valor_pauta_st` (por unidade, do produto) calcula o ST nas modalidades 0 (preço tabelado) e 5 (pauta): base = quantidade × pauta. Os campos `perfil`, `perfil_icms`, `perfil_piscofins`, `operacao.codigo` e `operacao.tipo_pessoa` da antiga matriz de tributação foram retirados e são recusados com 422.

Suportados: CST 00, 02, 10, 20, 30, 40, 41, 50, 51, 60, 61, 70, 90 e CSOSN 101, 102, 103, 201, 202, 203, 300, 400, 500, 900. `modBCST` 4 (MVA) e 6 (valor da operação) são calculados; nas demais modalidades informe `vBCST`.

### Ajuste manual

Qualquer valor final informado na regra (`vBC`, `vICMS`, `vBCST`, `vICMSST`, `vICMSDeson`, `vCredICMSSN`, `vFCP`, `vFCPST`, `vICMSOp`, `vICMSDif`, `qBCMono`, `vICMSMono`, `vBC`/`vPIS`/`vCOFINS`/`qBCProd`, `vBC`/`vIPI`/`qUnid`, `vBC`/`vISSQN`) é usado no lugar do calculado. Os campos que dependem dele são recalculados a partir do valor informado: `vBC` manual gera `vICMS = vBC × pICMS`; `vICMS` manual é o valor deduzido do ST. O campo aparece em `ajustes_manuais` e, se a diferença para o calculado passar de R$ 0,01, em `alertas`. Combinações impossíveis são recusadas (ex.: `vICMS` no CST 40, `vICMSDeson` sem `motDesICMS`).

## Retorno

```json
{
  "calculo_id": "calc_…",
  "versao_calculo": "1.0",
  "modelo": 55,
  "regime": "normal",
  "operacao": { "uf": "SP", "uf_destino": "SP", "interestadual": false, "consumidor_final": false },
  "itens": [
    {
      "item": 1, "cfop": "5405", "servico": false,
      "valores": { "vProd": 10.98, "vDesc": 0.5, "vFrete": 0, "vSeg": 0, "vOutro": 0, "valor_liquido": 10.48 },
      "impostos": {
        "icms": { "orig": 0, "CST": "10", "modBC": 3, "vBC": 10.48, "pICMS": 20, "vICMS": 2.1,
                  "modBCST": 4, "pMVAST": 40, "vBCST": 14.67, "pICMSST": 20, "vICMSST": 0.83 },
        "pis": { "CST": "01", "vBC": 10.48, "pPIS": 1.65, "vPIS": 0.17 },
        "cofins": { "CST": "01", "vBC": 10.48, "pCOFINS": 7.6, "vCOFINS": 0.8 }
      },
      "valor_aproximado_tributos": 3.3,
      "tributos_aproximados": { "federal": 1.41, "estadual": 1.89, "municipal": 0, "total": 3.3 },
      "ajustes_manuais": [],
      "alertas": []
    }
  ],
  "totais": {
    "ICMSTot": { "vBC": 10.48, "vICMS": 2.1, "vICMSDeson": 0, "vFCP": 0, "vBCST": 14.67, "vST": 0.83, "vFCPST": 0,
                 "qBCMono": 0, "vICMSMono": 0, "qBCMonoRet": 0, "vICMSMonoRet": 0, "vProd": 10.98, "vFrete": 0, "vSeg": 0,
                 "vDesc": 0.5, "vII": 0, "vIPI": 0, "vIPIDevol": 0, "vPIS": 0.17, "vCOFINS": 0.8, "vOutro": 0,
                 "vNF": 11.31, "vTotTrib": 3.3 },
    "vCredICMSSN": 0
  },
  "alertas": []
}
```

- `impostos` usa as chaves que `itens[].impostos` do `POST /api/v1/nfe` e do `/pdv/nfce` aceitam: o bloco pode ser copiado para a emissão sem conversão. `valor_aproximado_tributos` também.
- `ISSQNtot` só aparece quando há item de serviço.
- Com IBS/CBS: cada item traz `impostos.ibscbs` (tags do grupo `IBSCBS`) e `valor_item` (vItem); os totais trazem `IBSCBSTot` e `vNFTot`.
- `calculo_id` é determinístico: a mesma entrada gera o mesmo id; não depende de `?detalhar` nem dos alertas.
- `?detalhar=1` acrescenta `memoria_calculo` (fórmulas e valores intermediários) a cada item.
- Erros de regra retornam 422 no formato padrão (`erro.detalhes[].campo`, ex.: `itens[0].regra.icms.pICMSST`).

## IBS/CBS (Reforma Tributária)

A API calcula o IBS (UF e município) e a CBS de cada item da NF-e e da NFC-e (NT 2025.002-RTC) e monta os grupos no XML. Não se aplica à NFS-e. Tudo vem do ERP no JSON; a API não mantém tabela de cClassTrib nem de alíquotas.

O sistema informa:

- `itens[].cclasstrib` (ou `regra.ibscbs.cClassTrib`): o cClassTrib do produto (6 dígitos), escolhido pela aplicação a partir do NCM, dos anexos da LC 214/2025 e dos fatos da operação. O CST são os 3 primeiros dígitos.
- `regra.ibscbs.pRedIBS` e `pRedCBS` no CST 200 (percentual de redução do cClassTrib, da tabela oficial) e `regra.ibscbs.pDif` no CST 510 (diferimento).
- As **alíquotas** (IBS UF, IBS município, CBS) em `operacao.aliquotas_ibscbs` (na emissão, `calculo_tributos.aliquotas_ibscbs`). Sem elas, o cálculo do IBS/CBS é recusado com 422.
- O resultado traz `ibscbs` com a fotografia do que foi usado: alíquotas (origem `informada`) e município.

**Crédito presumido da operação** (grupo `gCredPresOper`): o ERP manda o `cCredPres` (tabela cCredPres, 1 a 99) e o percentual do IBS e/ou da CBS, que no ClickPDV ficam no cadastro da natureza. Envie em `operacao.credito_presumido` (na emissão, `calculo_tributos.credito_presumido`); `itens[].credito_presumido` troca ou anula (`null`) no item.

- Base (`vBCCredPres`) = a mesma base do IBS/CBS, também nos CST sem grupo de valores (ex.: 410 do produtor rural). Valor = base × pCredPres, com 2 casas; `gIBSCredPres` e `gCBSCredPres` só saem para o percentual informado.
- Os totais vão para `IBSCBSTot.gIBS.vCredPres` e `gCBS.vCredPres`. O crédito não altera vIBS, vCBS, vItem nem vNF.
- A API não confere se o cClassTrib admite o grupo (`ind_gCredPresOper`): o ERP só envia o crédito presumido nos itens que o admitem.
- A API não confere o cCredPres contra a tabela cCredPres (indicadores de IBS/CBS e vigência por tributo): a SEFAZ rejeita (regras 1050–1055). A condição suspensiva (`vCredPresCondSus`, só a partir de 2033) não é calculada.

Regras:

- **Quem:** regime normal (CRT 3). Simples Nacional e MEI (CRT 1, 2, 4) estão dispensados em 2026 e o leiaute deles ainda não foi publicado: o cClassTrib é ignorado com alerta. Na mesma nota, todos os itens têm cClassTrib ou nenhum.
- **Base (regra UB16-10):** vProd + frete + seguro + outras − desconto − PIS − COFINS − ICMS − FCP − ICMS monofásico.
- **CST 200:** alíquota efetiva = alíquota × (1 − redução informada em `pRedIBS`/`pRedCBS`), 4 casas, no grupo `gRed`.
- **CST 510:** `gDif`; o diferido é base × alíquota × pDif e o valor do tributo é o calculado menos o diferido.
- **CST 400 e 410:** só CST e cClassTrib, sem valores.
- **CST calculados:** 000, 200, 400, 410 e 510. Os demais (alíquota fixa, suspensão, monofasia, transferência de crédito, ajustes) vão no modo informado (`docs/RTC-INFORMADA-DELPHI.md`).
- **cClassTrib** sem 6 dígitos ou de outro CST é recusado com 422. Existência, vigência e permissão no modelo (indNFe/indNFCe) são conferidas pela SEFAZ.
- **vItem e vNFTot:** vItem = vProd − desconto − desoneração deduzida + ICMS ST + FCP ST + frete + seguro + outras + IPI; em 2026 sem IBS/CBS, a partir de 2027 com IBS/CBS. vNFTot = soma dos vItem. O vNF nunca inclui IBS/CBS.
- Arredondamento: valores com 2 casas (ABNT). A SEFAZ aceita diferença de R$ 0,01.

```json
{
  "operacao": { "municipio_destino": "1302603", "aliquotas_ibscbs": { "pIBSUF": 0.1, "pIBSMun": 0, "pCBS": 0.9 } },
  "itens": [ { "quantidade": 2, "valor_unitario": 5.49, "desconto": 0.50, "origem": 0, "cclasstrib": "000001",
               "regra": { "cfop": "5405", "icms": { "CST": "10", "pICMS": 20, "pMVAST": 40, "pICMSST": 20 },
                          "pis": { "CST": "01", "pPIS": 1.65 }, "cofins": { "CST": "01", "pCOFINS": 7.6 } } } ]
}
```

Resultado do item (ICMS 2,10, ST 0,83, PIS 0,17, COFINS 0,80):

```json
"ibscbs": { "CST": "000", "cClassTrib": "000001", "gIBSCBS": {
  "vBC": 7.41, "gIBSUF": { "pIBSUF": 0.1, "vIBSUF": 0.01 }, "gIBSMun": { "pIBSMun": 0, "vIBSMun": 0 },
  "vIBS": 0.01, "gCBS": { "pCBS": 0.9, "vCBS": 0.07 } } },
"valor_item": 11.31
```

Na emissão, o cálculo vira `itens[].impostos.ibscbs`, `valor_item` e `reforma_tributaria.totais` (`IBSCBSTot`, `vNFTot`) e passa pelo mesmo caminho do modo informado: XML montado e validado no XSD PL_010f. Se a nota já trouxer a RTC informada pelo sistema, ela prevalece.

## NFS-e

A NFS-e não usa este motor. Os códigos do serviço, a alíquota do ISS e os tributos federais vêm no JSON de `POST /nfse`: `federais` com os percentuais (a API calcula o grupo `tributos_federais` no envio) ou `tributos_federais` com os valores calculados pelo ERP. Ver `docs/NFSE.md`.

## Cálculo na emissão (`POST /nfe` e `/pdv/nfce`)

Os códigos fiscais vêm no JSON da nota, em um de dois modos (`calculo_tributos.modo`):

| Modo | Itens trazem | O que a API faz |
|---|---|---|
| `api` | `regra` (cfop, icms, pis, cofins, ipi, ibscbs, ibpt) | calcula no envio com este mesmo motor, preenche `impostos` e `valor_aproximado_tributos` de cada item e segue a emissão normal (validação, numeração, assinatura, transmissão) |
| `erp` | `impostos` com as tags já calculadas pelo ERP | não recalcula nada: as tags vão para o XML como vieram |

Sem `modo`, ele é deduzido dos itens (com `regra` → `api`; só com `impostos` → `erp`). Informar o modo deixa explícito para a API o que o ERP mandou: `erp` com algum item com `regra`, ou `api` com algum item sem `regra`, é recusado com 422.

```json
{
  "natureza_operacao": "VENDA DE MERCADORIA",
  "consumidor_final": 1,
  "destinatario": { "cpf": "12345678909", "nome": "Cliente", "endereco": { "uf": "SP", "...": "..." } },
  "itens": [
    {
      "codigo": "SKU-1", "descricao": "Produto", "ncm": "22030000", "unidade": "UN",
      "quantidade": 2, "valor_unitario": 49.90,
      "regra": {
        "cfop": "5405",
        "icms": { "orig": 0, "CST": "10", "pICMS": 18, "pMVAST": 40, "pICMSST": 18 },
        "pis": { "CST": "01", "pPIS": 1.65 }, "cofins": { "CST": "01", "pCOFINS": 7.6 }
      }
    }
  ],
  "pagamentos": [ { "forma": "01" } ],
  "calculo_tributos": { "calculo_id": "calc_…" }
}
```

- **Operação**: tirada da própria nota. `uf_destino` = UF do endereço do destinatário (só NF-e); `consumidor_final` = campo da nota; `contribuinte_icms` = `destinatario.indicador_ie` 1. CRT e UF do emitente vêm da configuração da empresa.
- **CFOP**: pode ficar só na regra; se vier no item e na regra, precisam ser iguais.
- **Pagamento**: o total só é conhecido depois do cálculo (ST, IPI, desoneração). Um pagamento único sem `valor` recebe o `vNF` calculado. Com mais de um pagamento, informe os valores (use a pré-visualização).
- **Não misture**: com cálculo pela API, todos os itens levam `regra`. Em `impostos` só podem ir junto `ibscbs`/`is` (Reforma Tributária informada).
- **`calculo_tributos`**: `modo` (`api` ou `erp`), `calculo_id`, `excluir_icms_base_piscofins`, `aliquotas_ibscbs`, `credito_presumido`. No modo `erp` só vale `modo`. `natureza_operacao` é obrigatória na nota.
- **Pré-visualização**: `POST /nfe?dry_run=1` devolve o cálculo completo em `calculo_tributos` (mesmo formato de `/tributos/calcular`), com o XML montado. Para a NFC-e do PDV, use `/tributos/calcular` com `"modelo": 65`.
- **`calculo_id` na emissão**: se o cálculo de agora for diferente do pré-visualizado (regra, valores ou alíquotas mudaram), a emissão é recusada com **409 `calculo_divergente`** antes de numerar, e a `Idempotency-Key` não é consumida. A mensagem traz o id calculado agora.
- **Ajuste manual na emissão**: alterar parâmetro da regra (CST, alíquota, redução, MVA) é livre. Informar valor final (base ou valor de imposto) exige papel **proprietário ou fiscal**; caso contrário, **403 `ajuste_manual_negado`**. Chaves de API contam como proprietário. Todo ajuste vai para o audit log (`tributos.ajuste_manual`).
- **Registro**: a nota (`GET /nfe/{chave}`) e a operação do PDV guardam `calculo_tributos` com `calculo_id`, regime, `ajustes_manuais` por item, `ajustado_por`, alertas e totais. Os valores por item ficam no XML.
- **PDV**: o replay da mesma `operacao_id` devolve o mesmo resultado sem recalcular nem retransmitir. Erros de regra apontam `nota.itens[i].regra...`.

## Regras de cálculo

1. Valores em aritmética decimal exata (bcmath). Tributos arredondados para 2 casas pela ABNT NBR 5891, o mesmo `RoundABNT` do ACBr usado no ClickPDV. `vProd = quantidade × valor_unitario` usa o arredondamento comum, igual ao montador da NF-e.
2. Ordem por item: IPI → ICMS → PIS/COFINS → ISSQN → tributos aproximados. Totais = soma dos itens arredondados.
3. Base do ICMS próprio: `vProd − vDesc + vFrete + vSeg + vOutro`, mais o IPI quando `consumidor_final`; depois a redução (`pRedBC`).
4. ST: `(vProd − vDesc + vFrete + vSeg + vOutro + vIPI) × (1 + MVA) × (1 − pRedBCST)`; `vICMSST = vBCST × pICMSST − ICMS próprio`, nunca negativo (alerta quando zerado). No Simples (201–203) o ICMS deduzido é o presumido `base × pICMS`.
5. Desoneração só com `motDesICMS`. CST 20/70: ICMS sem redução − ICMS com redução. CST 30/40/41/50/90: `base × pICMS` (por fora, padrão) ou `base ÷ (1 − pICMS) × pICMS` (por dentro), conforme `regra.icms.metodo_desoneracao`. O método é da regra de ICMS porque depende da base legal de cada benefício; não é mais informado na operação. `indDeduzDeson = 1` abate o valor do `vNF`.
6. Interestadual (NF-e, `uf_destino` ≠ UF): sem `pICMS` informado aplica 4% (origem 1, 2, 3, 8), 7% (Sul/Sudeste exceto ES para N/NE/CO/ES) ou 12%. Com `pICMS` informado diferente, alerta. DIFAL ainda não é calculado (alerta).
7. PIS/COFINS: base `vProd − vDesc`. CST 01/02 por alíquota; 03 por quantidade (`vAliqProd`); 04–09 sem valores; 49–99 com alíquota quando informada, senão grupo zerado.
8. ISSQN: base `vProd` (desconto condicionado) ou `vProd − vDesc`.

## Diferenças em relação ao ClickPDV (`UclCalcula_Imposto.pas`)

O cálculo da API corrige comportamentos do Delphi que alteravam o valor das notas. Na homologação, compare nota a nota antes de desligar o cálculo do PDV.

| ClickPDV | API |
|---|---|
| Alíquota ST nunca preenchida (CST 10 com ST negativo, 70 zerado) | `pICMSST` obrigatório |
| MVA só carregada na licença Free | MVA sempre aplicada |
| Frete, seguro e outras despesas fora da base do ICMS | Incluídos na base |
| Simples: CSOSN nunca calculado (crédito sempre 0) | CSOSN calculado |
| CST 70: redução da base ST aplicada sobre o próprio percentual | Redução aplicada sobre a base ST |
| Desoneração sem motivo, por dentro/por fora conforme o CST, sobre vProd bruto | Só com motivo; método único configurável; sobre a base líquida |
| Interestadual grava 4% e calcula com a alíquota interna | Mesma alíquota no cálculo e na saída |
| Monofásico do CST 61 nas tags do CST 02 | CST 61 em `qBCMonoRet`/`adRemICMSRet`/`vICMSMonoRet` |
| PIS/COFINS CST 03 sem arredondamento | Arredondado |

## Ainda não disponível

DIFAL na NF-e, tabela IBPT na API, `modBCST` 1–3 (listas: informe `vBCST`), CST 15 e 53. IBS/CBS: CST além de 000, 200, 400, 410 e 510, crédito presumido da ZFM e em condição suspensiva, compras governamentais, Imposto Seletivo e o leiaute do Simples Nacional (use o modo informado). 
