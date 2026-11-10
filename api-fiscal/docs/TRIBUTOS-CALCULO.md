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
| `operacao.data` | hoje (horário de Brasília) | `AAAA-MM-DD`: vigência do cClassTrib e se o `valor_item` soma IBS/CBS (a partir de 2027) |
| `operacao.aliquotas_ibscbs` | tabela da API | `{pIBSUF, pIBSMun, pCBS}`: se enviado, é usado no lugar da tabela de alíquotas da API |
| `operacao.municipio_destino` | município do emitente | código IBGE do destinatário: escolhe as alíquotas de IBS/CBS da tabela |
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
- `ibscbs` (Reforma Tributária, regime normal): `cClassTrib` (ou `itens[].cclasstrib`), `CST` opcional (vem da tabela), `pDif` (CST 510).

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

## Matriz de regras da empresa (`/api/v1/tributacao`)

Mesmo desenho da "Listagem de Tributação do ICMS" do ClickPDV. Cadastro no Portal Fiscal (no painel administrativo, botão **Abrir Portal Fiscal** da empresa) ou pela API; leitura com qualquer credencial da empresa, alteração só pelo proprietário (chaves de API contam como proprietário). Vale para homologação e produção.

| Cadastro | Chave | Conteúdo |
|---|---|---|
| Perfil (vale para ICMS e PIS/COFINS) | código automático (ex.: `1` Produto tributado, `2` Produto substituição) | nome |
| Operação | código (ex.: `VENDA`) | nome — vira a natureza da operação da nota quando ela não é informada |
| Regra de ICMS | perfil × operação × UF de destino × tipo de pessoa (F, J, E) | descrição (só identificação), CFOP, CST/CSOSN e parâmetros do CST, IPI (CST, enquadramento, alíquota) |
| Regra de PIS/COFINS | perfil × operação | descrição, CST e alíquota (ou valor por unidade no CST 03) de PIS e de COFINS |

A regra guarda só parâmetros. Os campos aceitos dependem do CST (`GET /tributacao` devolve `campos_por_cst` e `obrigatorios_por_cst`); campo que não se aplica é recusado. Ex.: CST 20 exige `pRedBC`; CST 10/70 exigem `pICMSST` (e `pMVAST` com modalidade 4); CST 51 aceita `pDif`; CSOSN 101/201 exigem `pCredSN`; CST 60/61/02 e CSOSN 500 não têm parâmetros (ST retido e ad rem vêm do produto, na venda).

| Rota | Uso |
|---|---|
| `GET /tributacao` | perfis, operações, campos por CST, UFs |
| `POST /tributacao/perfis` | `{nome}`: inclui com código automático (maior código numérico + 1); 201 com o perfil |
| `PUT`/`DELETE /tributacao/perfis/{codigo}` | `{nome}`; perfil com regras não é excluído (409). O mesmo perfil vale para ICMS e PIS/COFINS (`tipo` antigo é ignorado) |
| `POST /tributacao/operacoes` | `{nome}`: inclui com código automático; 201 |
| `PUT`/`DELETE /tributacao/operacoes/{codigo}` | `{nome}`; operação com regras não é excluída (409) |
| `POST /tributacao/regras-piscofins/copiar` | `{origem:{perfil, operacao}, destino:{perfis?, operacoes?}, sobrescrever?}` → `{criadas, sobrescritas, ignoradas}` |
| `POST /tributacao/regras-iss` | inclui regra de ISS com código automático; 201 |
| `GET /tributacao/regras-icms?perfil=&operacao=&uf=&tipo_pessoa=` | lista filtrada para a grade do ERP: `total` e cada regra com `perfil_nome`, `operacao_nome`, `tipo_pessoa_descricao`, `cst`, `cst_descricao` |
| `GET /tributacao/regras-icms/{perfil}/{operacao}/{uf}/{tipo_pessoa}` | detalhe: a regra, os nomes e `campos` (`grupo`, `campo`, `rotulo`, `valor`, `valor_descricao`) para mostrar ao usuário; 404 se não existir |
| `PUT`/`DELETE /tributacao/regras-icms/{perfil}/{operacao}/{uf}/{tipo_pessoa}` | `{descricao, cfop, icms:{CST, ...}, ipi?}` |
| `POST /tributacao/regras-icms/copiar` | `{origem:{perfil, operacao, uf, tipo_pessoa?}, destino:{ufs?, tipos_pessoa?, perfis?, operacoes?}, sobrescrever?}`; `["*"]` = todos; sem `tipo_pessoa` na origem copia as regras de todos os tipos daquela UF; até 3.000 regras; devolve criadas, substituídas e ignoradas (já existiam) |
| `GET /tributacao/regras-piscofins?perfil=&operacao=` | lista com `total`, nomes e `pis_cst_descricao` / `cofins_cst_descricao` |
| `GET /tributacao/regras-piscofins/{perfil}/{operacao}` | detalhe com `campos` rotulados |
| `PUT`/`DELETE /tributacao/regras-piscofins/{perfil}/{operacao}` | `{descricao, pis:{CST, pPIS|vAliqProd}, cofins:{...}}` |
| `GET /tributacao/cclasstrib?cst=&busca=` | tabela local de cClassTrib (somente leitura) |
| `POST /tributacao/importar` | carga inicial: `{perfis, operacoes, regras_icms, regras_piscofins, sobrescrever?}`; valida tudo antes de gravar (tudo ou nada), até 20.000 regras |

### IBS/CBS (Reforma Tributária)

A API calcula o IBS (UF e município) e a CBS de cada item da NF-e e da NFC-e (NT 2025.002-RTC) e monta os grupos no XML. Não se aplica à NFS-e. Não há regra de IBS/CBS na matriz: tudo vem do cClassTrib do produto e da tabela local.

O sistema informa:

- `itens[].cclasstrib`: o cClassTrib do produto (6 dígitos), escolhido pela aplicação a partir do NCM, dos anexos da LC 214/2025 e dos fatos da operação. O CST, a redução de alíquota (pRedIBS, pRedCBS), a vigência e se o código vale na NF-e/NFC-e vêm da **tabela local de cClassTrib**.
- `regra.ibscbs.pDif` só no CST 510 (diferimento); `regra.ibscbs.cClassTrib` também é aceito no lugar de `cclasstrib`.

As **alíquotas** (IBS UF, IBS município, CBS) vêm da **tabela de alíquotas da API**, mantida pelo administrador:

- Cada linha tem vigência (início e fim opcional) e abrangência nacional, por UF ou por município (código IBGE; a API traz a lista dos 5.571 municípios do IBGE).
- A busca usa a data da operação e o município de destino (destinatário da nota); sem destinatário com endereço, o município do emitente. Prioridade: município, depois UF, depois nacional; na mesma abrangência, o início mais recente.
- Sem linha vigente em 2026, valem as alíquotas de teste da LC 214/2025 (0,10% / 0% / 0,90%). Em outros anos sem linha, o cálculo é recusado.
- Se o JSON trouxer `aliquotas_ibscbs` (na emissão, `calculo_tributos.aliquotas_ibscbs`), elas são usadas no lugar da tabela.
- O resultado traz `ibscbs` com a fotografia do que foi usado: alíquotas, origem (`informada`, `tabela` com id e vigência, ou `lei`), município e versão da tabela de cClassTrib.

**Crédito presumido da operação** (grupo `gCredPresOper`): o ERP manda o `cCredPres` (tabela cCredPres, 1 a 99) e o percentual do IBS e/ou da CBS, que no ClickPDV ficam no cadastro da natureza. Envie em `operacao.credito_presumido` (na emissão, `calculo_tributos.credito_presumido`); `itens[].credito_presumido` troca ou anula (`null`) no item.

- Base (`vBCCredPres`) = a mesma base do IBS/CBS, também nos CST sem grupo de valores (ex.: 410 do produtor rural). Valor = base × pCredPres, com 2 casas; `gIBSCredPres` e `gCBSCredPres` só saem para o percentual informado.
- Os totais vão para `IBSCBSTot.gIBS.vCredPres` e `gCBS.vCredPres`. O crédito não altera vIBS, vCBS, vItem nem vNF.
- Se a tabela de cClassTrib diz que o código não admite o grupo (`ind_gCredPresOper` = 0): informado no item → 422; vindo da operação → o item fica sem o grupo e recebe alerta.
- A API não confere o cCredPres contra a tabela cCredPres (indicadores de IBS/CBS e vigência por tributo): a SEFAZ rejeita (regras 1050–1055). A condição suspensiva (`vCredPresCondSus`, só a partir de 2033) não é calculada.

Regras:

- **Quem:** regime normal (CRT 3). Simples Nacional e MEI (CRT 1, 2, 4) estão dispensados em 2026 e o leiaute deles ainda não foi publicado: o cClassTrib é ignorado com alerta. Na mesma nota, todos os itens têm cClassTrib ou nenhum.
- **Base (regra UB16-10):** vProd + frete + seguro + outras − desconto − PIS − COFINS − ICMS − FCP − ICMS monofásico.
- **CST 200:** alíquota efetiva = alíquota × (1 − redução da tabela), 4 casas, no grupo `gRed`. Quando a tabela muda a redução, o cálculo acompanha sem alterar nada no sistema.
- **CST 510:** `gDif`; o diferido é base × alíquota × pDif e o valor do tributo é o calculado menos o diferido.
- **CST 400 e 410:** só CST e cClassTrib, sem valores.
- **CST calculados:** 000, 200, 400, 410 e 510. Os demais (alíquota fixa, suspensão, monofasia, transferência de crédito, ajustes) vão no modo informado (`docs/RTC-INFORMADA-DELPHI.md`).
- **cClassTrib** inexistente na tabela, de outro CST, fora da vigência (`operacao.data`) ou não permitido no modelo (indNFe/indNFCe) é recusado com 422.
- **vItem e vNFTot:** vItem = vProd − desconto − desoneração deduzida + ICMS ST + FCP ST + frete + seguro + outras + IPI; em 2026 sem IBS/CBS, a partir de 2027 com IBS/CBS. vNFTot = soma dos vItem. O vNF nunca inclui IBS/CBS.
- Arredondamento: valores com 2 casas (ABNT). A SEFAZ aceita diferença de R$ 0,01.

```json
{
  "operacao": { "codigo": "VENDA", "municipio_destino": "1302603" },
  "itens": [ { "quantidade": 2, "valor_unitario": 5.49, "desconto": 0.50, "origem": 0,
               "perfil": "2", "cclasstrib": "000001" } ]
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

#### Tabelas globais (administrador da API)

No painel administrativo, página **Reforma tributária**, ou pelas rotas de administrador:

| Rota | Uso |
|---|---|
| `GET /api/v1/admin/rtc/aliquotas?data=&municipio=` | linhas da tabela; com `data` (e `municipio`) mostra também a vigente que o cálculo usaria |
| `POST /api/v1/admin/rtc/aliquotas` · `PUT`/`DELETE .../{id}` | `{abrangencia: nacional/uf/municipio, uf?, codigo_municipio?, inicio, fim?, pIBSUF, pIBSMun, pCBS, descricao?}`; vigência sobreposta para o mesmo local é recusada (409) |
| `GET /api/v1/admin/rtc/municipios?uf=&busca=` | municípios do IBGE |
| `GET`/`POST /api/v1/admin/rtc/cclasstrib` | consulta e importação da tabela de cClassTrib (`{conteudo, versao?}`, texto do CSV ou JSON) |

Tabela de cClassTrib pela linha de comando:

```bash
php bin/cclasstrib.php                                  # versão, data e total
php bin/cclasstrib.php importar cClassTrib.csv IT-1.70  # planilha oficial (aba cClassTrib salva como CSV) ou JSON
php bin/cclasstrib.php svrs certificado.pfx             # API Conformidade Fácil da SVRS (certificado ICP-Brasil)
```

A importação aceita as colunas da planilha do Informe Técnico 2025.002 (`CST-IBS/CBS`, `cClassTrib`, `Nome cClassTrib`, `Tipo de Alíquota`, `pRedIBS`, `pRedCBS`, `dIniVig`, `dFimVig`, `indNFe`, `indNFCe`), com `;` ou `,`, em UTF-8 ou Windows-1252. Substitui a tabela inteira (a anterior fica guardada) e é recusada por completo se alguma linha for inválida. Consulta: `GET /api/v1/tributacao/cclasstrib?cst=&busca=` (para o ERP montar a escolha do cClassTrib no cadastro do produto).

### Regras de ISS (NFS-e)

Equivalem ao IMPOSTO_ISS do ClickPDV: cada regra tem um código escolhido pela empresa e é usada na emissão da NFS-e (`regra_iss`, ver `docs/NFSE.md`). Não dependem de perfil nem de operação.

| Rota | Uso |
|---|---|
| `GET /tributacao/regras-iss?busca=` | lista (busca por código, descrição ou código de tributação nacional): `total` e cada regra com `servico_descricao`, `item_lc116`, `tipo_pessoa_descricao`, `retencao_federais_descricao` |
| `POST /tributacao/regras-iss/copiar` | `{origem, destino?, descricao?, sobrescrever?}` (sem destino: código automático): cria uma regra nova com os dados da origem (descrição padrão "Cópia de …"); 409 se o destino existir sem `sobrescrever: true`; 201 com a regra criada |
| `GET /tributacao/regras-iss/{codigo}` | detalhe com `campos` rotulados (descrição do serviço nacional e da NBS) |
| `PUT`/`DELETE /tributacao/regras-iss/{codigo}` | `{descricao, tipo_pessoa?, servico:{codigo_tributacao_nacional, nbs?, codigo_tributacao_municipal?, codigo_interno?}, iss?:{aliquota_iss?}, federais?:{cst_pis_cofins, aliquota_pis?, aliquota_cofins?, aliquota_irrf?, aliquota_csll?, tipo_retencao}, ibs_cbs?:{indicador_operacao?, classificacao_tributaria?, percentual_diferimento?, operacao_onerosa, servico_exterior}}` |

Campos que vieram da tela do ClickPDV (`frmCadImpostoIss`, tabela `IMPOSTO_ISS`):

| Regra (API) | ClickPDV | Uso na NFS-e |
|---|---|---|
| `tipo_pessoa` (F/J) | `ID_TIPO_PESSOA_TRIBUTACAO` | identificação: o ERP escolhe a regra pelo tomador |
| `federais.cst_pis_cofins`, `aliquota_pis`, `aliquota_cofins` | `ID_CST_ISS`, `ALIQ_PIS`, `ALIQ_COFINS` | grupo `piscofins`: base = serviço − desconto incondicionado; vPis e vCofins com 2 casas |
| `federais.aliquota_irrf` | `ALIQ_IRRF` | `vRetIRRF` = base × alíquota |
| `federais.aliquota_csll`, `tipo_retencao` (0–9) | `ALIQ_CSLL`, `ID_TIPO_RETENCAO_PISCOFINS_CSLL` | `tpRetPisCofins`; `vRetCSLL` = PIS + COFINS + CSLL **retidos** conforme o tipo (NT 007) |
| `ibs_cbs.indicador_operacao`, `classificacao_tributaria`, `percentual_diferimento` | `INDOP`, `CCLASSTRIB`, `ALIQ_DIFER` | completam o `ibs_cbs` informado na nota (CST = 3 primeiros dígitos do cClassTrib; diferimento igual para UF, município e CBS) |
| `ibs_cbs.operacao_onerosa`, `servico_exterior` | `PS_ONEROSA`, `ADQ_EXTERIOR` | cadastro para o ERP escolher o indicador da operação; não vão para a DPS |

O CST segue a NFS-e Nacional (00 a 09, 49, 50–56, 60–67, 70–75, 98, 99), com as descrições oficiais. A tabela `CST_ISS` do ClickPDV usa os mesmos códigos 00–09, mas com descrições diferentes em três deles: 00 e 07 aparecem como "sem incidência" e 09 como "outras operações de saída". Na NFS-e, 00 = nenhum, 07 = isenta, 08 = sem incidência, 09 = suspensão e 49 = outras operações de saída. Na importação das regras do ClickPDV, conferir esses códigos.

Se a nota trouxer `tributos_federais`, vale o informado. O Delphi somava PIS e COFINS no `vRetCSLL` mesmo quando o tipo de retenção dizia que não eram retidos; a API soma só os retidos. Crédito presumido não existe na NFS-e (decisão de 10/11).

- Tributação, retenção, imunidade e suspensão do ISSQN **não** ficam na regra: vêm do cadastro da empresa (`PUT /nfse/config`). Enviá-los na regra dá 422.
- `aliquota_iss`: 0 a 5%, só em operação tributável. Se o regime do prestador ou o convênio do município não permitir informar a alíquota, a emissão a omite na DPS (com aviso).

```json
{
  "descricao": "Licenciamento com retenção",
  "servico": { "codigo_tributacao_nacional": "010501", "nbs": "115022000" },
  "iss": { "aliquota_iss": 5 }
}
```

### Usando os perfis no cálculo

O item informa `perfil` (um só, vale para ICMS e PIS/COFINS) e `origem` (do produto); `perfil_icms`/`perfil_piscofins` ainda são aceitos para trocar o perfil de um dos dois; a operação informa `codigo` e `tipo_pessoa`. A API busca a regra de ICMS pela UF de destino (na NFC-e, a UF do emitente) e devolve em cada item `regra_aplicada` (qual regra foi usada e o que foi sobreposto).

```json
{
  "operacao": { "codigo": "VENDA", "tipo_pessoa": "F" },
  "itens": [ { "quantidade": 2, "valor_unitario": 5.49, "origem": 0, "perfil": "2" } ]
}
```

- `regra` no item continua aceita e sobrepõe campo a campo a regra da matriz (ex.: `"regra": {"icms": {"pICMS": 18}}`). Informar outro CST/CSOSN substitui o grupo inteiro.
- Sem regra para a combinação: 422 em `itens[i].perfil_icms`, com perfil, operação, UF e pessoa na mensagem.
- `valor_pauta_st` no item (por unidade, do produto) calcula o ST nas modalidades 0 (preço tabelado) e 5 (pauta): base = quantidade × pauta.
- Na emissão, use `calculo_tributos.operacao`; o tipo de pessoa vem do destinatário (CNPJ → J, CPF ou sem destinatário → F), ou de `calculo_tributos.tipo_pessoa`.

## Cálculo na emissão (`POST /nfe` e `/pdv/nfce`)

Envie o perfil (`perfil`, `origem`) ou a `regra` no item, no lugar de `impostos`. A API calcula com este mesmo motor, preenche `impostos` e `valor_aproximado_tributos` de cada item e segue a emissão normal (validação, numeração, assinatura, transmissão).

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

DIFAL na NF-e, tabela IBPT na API, `modBCST` 1–3 (listas: informe `vBCST`), CST 15 e 53. IBS/CBS: CST além de 000, 200, 400, 410 e 510, crédito presumido da ZFM e em condição suspensiva, compras governamentais, Imposto Seletivo e o leiaute do Simples Nacional (use o modo informado). 
