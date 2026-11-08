# Cálculo de tributos — `POST /api/v1/tributos/calcular`

Calcula ICMS (CST e CSOSN, ST, FCP, desoneração, diferimento, monofásico), IPI, PIS, COFINS, ISSQN e os tributos aproximados da Lei 12.741/2012 de uma venda, item a item, com os totais da nota. **Só calcula**: não grava, não numera e não transmite. Usa a mesma autenticação das demais rotas da empresa (chave de API ou sessão; credencial central com `X-Empresa-Id`).

A regra tributária de cada item vem da matriz de regras da empresa (perfis cadastrados no painel) ou informada no JSON. O mesmo cálculo roda dentro de `POST /api/v1/nfe` e `/api/v1/pdv/nfce` (seção "Cálculo na emissão").

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

### Regra do item (`regra`)

Os nomes seguem as tags do leiaute da NF-e.

- `icms`: `orig`, `CST` ou `CSOSN`, `modBC`, `pICMS`, `pRedBC`, `modBCST`, `pMVAST`, `pICMSST`, `pRedBCST`, `pDif`, `pCredSN`, `pFCP`, `pFCPST`, `adRemICMS`, `adRemICMSRet`, `motDesICMS`, `indDeduzDeson`, `metodo_desoneracao` (CST 30/40/41/50/90: `por_fora`, padrão, ou `por_dentro`, conforme a base legal do benefício); no CST 60 / CSOSN 500, os valores retidos (`vBCSTRet`, `pST`, `vICMSSubstituto`, `vICMSSTRet`, `vBCFCPSTRet`, `pFCPSTRet`, `vFCPSTRet`) são repassados.
- `pis` / `cofins`: `CST`, `pPIS`/`pCOFINS` ou `vAliqProd` (CST 03).
- `ipi` (só NF-e): `CST`, `cEnq` (padrão 999), `pIPI` ou `vUnid`.
- `iss` (serviço): `aliquota`, `cMunFG`, `cListServ`, `indISS`, `indIncentivo`.
- `ibpt`: `federal`, `estadual`, `municipal` (%).

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
- `calculo_id` é determinístico: a mesma entrada gera o mesmo id; não depende de `?detalhar` nem dos alertas.
- `?detalhar=1` acrescenta `memoria_calculo` (fórmulas e valores intermediários) a cada item.
- Erros de regra retornam 422 no formato padrão (`erro.detalhes[].campo`, ex.: `itens[0].regra.icms.pICMSST`).

## Matriz de regras da empresa (`/api/v1/tributacao`)

Mesmo desenho da "Listagem de Tributação do ICMS" do ClickPDV. Cadastro no painel (aba **Tributação**) ou pela API; leitura com qualquer credencial da empresa, alteração só pelo proprietário (chaves de API contam como proprietário). Vale para homologação e produção.

| Cadastro | Chave | Conteúdo |
|---|---|---|
| Perfil (`tipo` icms ou piscofins) | código (ex.: `1` Produto tributado, `2` Produto substituição) | nome |
| Operação | código (ex.: `VENDA`) | nome — vira a natureza da operação da nota quando ela não é informada |
| Regra de ICMS | perfil × operação × UF de destino × tipo de pessoa (F, J, E) | descrição (só identificação), CFOP, CST/CSOSN e parâmetros do CST, IPI (CST, enquadramento, alíquota) |
| Regra de PIS/COFINS | perfil × operação | descrição, CST e alíquota (ou valor por unidade no CST 03) de PIS e de COFINS |

A regra guarda só parâmetros. Os campos aceitos dependem do CST (`GET /tributacao` devolve `campos_por_cst` e `obrigatorios_por_cst`); campo que não se aplica é recusado. Ex.: CST 20 exige `pRedBC`; CST 10/70 exigem `pICMSST` (e `pMVAST` com modalidade 4); CST 51 aceita `pDif`; CSOSN 101/201 exigem `pCredSN`; CST 60/61/02 e CSOSN 500 não têm parâmetros (ST retido e ad rem vêm do produto, na venda).

| Rota | Uso |
|---|---|
| `GET /tributacao` | perfis, operações, campos por CST, UFs |
| `PUT`/`DELETE /tributacao/perfis/{codigo}` | `{nome, tipo}`; perfil com regras não é excluído (409) |
| `PUT`/`DELETE /tributacao/operacoes/{codigo}` | `{nome}`; operação com regras não é excluída (409) |
| `GET /tributacao/regras-icms?perfil=&operacao=&uf=&tipo_pessoa=` | lista filtrada para a grade do ERP: `total` e cada regra com `perfil_nome`, `operacao_nome`, `tipo_pessoa_descricao`, `cst`, `cst_descricao` |
| `GET /tributacao/regras-icms/{perfil}/{operacao}/{uf}/{tipo_pessoa}` | detalhe: a regra, os nomes e `campos` (`grupo`, `campo`, `rotulo`, `valor`, `valor_descricao`) para mostrar ao usuário; 404 se não existir |
| `PUT`/`DELETE /tributacao/regras-icms/{perfil}/{operacao}/{uf}/{tipo_pessoa}` | `{descricao, cfop, icms:{CST, ...}, ipi?}` |
| `POST /tributacao/regras-icms/copiar` | `{origem:{perfil, operacao, uf, tipo_pessoa?}, destino:{ufs?, tipos_pessoa?, perfis?, operacoes?}, sobrescrever?}`; `["*"]` = todos; sem `tipo_pessoa` na origem copia as regras de todos os tipos daquela UF; até 3.000 regras; devolve criadas, substituídas e ignoradas (já existiam) |
| `GET /tributacao/regras-piscofins?perfil=&operacao=` | lista com `total`, nomes e `pis_cst_descricao` / `cofins_cst_descricao` |
| `GET /tributacao/regras-piscofins/{perfil}/{operacao}` | detalhe com `campos` rotulados |
| `PUT`/`DELETE /tributacao/regras-piscofins/{perfil}/{operacao}` | `{descricao, pis:{CST, pPIS|vAliqProd}, cofins:{...}}` |
| `POST /tributacao/importar` | carga inicial: `{perfis, operacoes, regras_icms, regras_piscofins, sobrescrever?}`; valida tudo antes de gravar (tudo ou nada), até 20.000 regras |

### Regras de ISS (NFS-e)

Equivalem ao IMPOSTO_ISS do ClickPDV: cada regra tem um código escolhido pela empresa e é usada na emissão da NFS-e (`regra_iss`, ver `docs/NFSE.md`). Não dependem de perfil nem de operação.

| Rota | Uso |
|---|---|
| `GET /tributacao/regras-iss?busca=` | lista (busca por código, descrição ou código de tributação nacional): `total` e cada regra com `servico_descricao`, `item_lc116`, `tributacao_iss_descricao`, `retencao_iss_descricao` |
| `GET /tributacao/regras-iss/{codigo}` | detalhe com `campos` rotulados (descrição do serviço nacional e da NBS) |
| `PUT`/`DELETE /tributacao/regras-iss/{codigo}` | `{descricao, servico:{codigo_tributacao_nacional, nbs?, codigo_tributacao_municipal?, codigo_interno?}, iss:{tributacao_iss, tipo_imunidade?, retencao_iss, aliquota_iss?}}` |

- `tributacao_iss`: 1 tributável (padrão), 2 imunidade (com `tipo_imunidade` 0 a 5), 4 não incidência.
- `retencao_iss`: 1 não retido (padrão), 2 retido pelo tomador; só em operação tributável.
- `aliquota_iss`: 0 a 5%, só em operação tributável. Se o regime do prestador ou o convênio do município não permitir informar a alíquota, a emissão a omite na DPS (com aviso).

```json
{
  "descricao": "Licenciamento com retenção",
  "servico": { "codigo_tributacao_nacional": "010501", "nbs": "115022000" },
  "iss": { "tributacao_iss": 1, "retencao_iss": 2, "aliquota_iss": 5 }
}
```

### Usando os perfis no cálculo

O item informa `perfil_icms`, `perfil_piscofins` e `origem` (do produto); a operação informa `codigo` e `tipo_pessoa`. A API busca a regra de ICMS pela UF de destino (na NFC-e, a UF do emitente) e devolve em cada item `regra_aplicada` (qual regra foi usada e o que foi sobreposto).

```json
{
  "operacao": { "codigo": "VENDA", "tipo_pessoa": "F" },
  "itens": [ { "quantidade": 2, "valor_unitario": 5.49, "origem": 0, "perfil_icms": "2", "perfil_piscofins": "PC1" } ]
}
```

- `regra` no item continua aceita e sobrepõe campo a campo a regra da matriz (ex.: `"regra": {"icms": {"pICMS": 18}}`). Informar outro CST/CSOSN substitui o grupo inteiro.
- Sem regra para a combinação: 422 em `itens[i].perfil_icms`, com perfil, operação, UF e pessoa na mensagem.
- `valor_pauta_st` no item (por unidade, do produto) calcula o ST nas modalidades 0 (preço tabelado) e 5 (pauta): base = quantidade × pauta.
- Na emissão, use `calculo_tributos.operacao`; o tipo de pessoa vem do destinatário (CNPJ → J, CPF ou sem destinatário → F), ou de `calculo_tributos.tipo_pessoa`.

## Cálculo na emissão (`POST /nfe` e `/pdv/nfce`)

Envie os perfis (`perfil_icms`, `perfil_piscofins`, `origem`) ou a `regra` no item, no lugar de `impostos`. A API calcula com este mesmo motor, preenche `impostos` e `valor_aproximado_tributos` de cada item e segue a emissão normal (validação, numeração, assinatura, transmissão).

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
- **`calculo_tributos`**: `operacao` (código da matriz, obrigatório com perfis), `tipo_pessoa`, `calculo_id`, `excluir_icms_base_piscofins`. Sem `natureza_operacao` na nota, vale o nome da operação.
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

DIFAL na NF-e, tabela IBPT na API, IBS/CBS no cálculo, `modBCST` 1–3 (listas: informe `vBCST`), CST 15 e 53, regra de ISS na matriz (serviço usa `regra.iss`).
