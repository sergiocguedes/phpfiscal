# NFS-e Padrão Nacional — API

O painel configura somente o prestador, a série e a numeração. Os serviços vêm sempre no JSON de emissão. A emissão é realizada pela integração. O certificado A1 e os dados básicos da empresa são compartilhados com a configuração fiscal da NF-e/NFC-e.

## Cadastro do prestador pelo sistema consumidor

O consumidor pode enviar toda a configuração por API usando sua chave restrita, sem preencher o painel:

1. `GET /api/v1/tenant`: confirmar o CNPJ da credencial.
2. `PUT /api/v1/tenant`: enviar nome e `ambiente_nfse` (1 produção, 2 homologação). Os ambientes NF-e/NFC-e não mudam quando omitidos.
3. `PUT /api/v1/config`: enviar identidade, endereço e regime do prestador. **IE é opcional para NFS-e**: omita ou envie `null`; não transforme ausência em `ISENTO`. NF-e/NFC-e exigem IE antes de gerar seus XML.
4. `PUT /api/v1/nfse/config`: enviar IM, município, regime nacional, série e demais configurações do prestador. Não reinicie o próximo número em cada sincronização.
5. `POST /api/v1/certificado`: enviar `certificado_base64` e `senha`, nunca em URL ou logs.
6. `POST /api/v1/nfse?dry_run=1`: validar o JSON de serviço e gerar a DPS não assinada, sem transmitir nem consumir número.

A chave já precisa pertencer à empresa cadastrada. Para provisionar uma nova empresa, a integração administrativa usa `POST /api/v1/empresas` e credencial central; não exponha uma chave administrativa aos caixas/consumidores restritos. Ativação e mudança de CNPJ continuam na gestão central. Uma sincronização usa chamadas independentes; se alguma falhar, corrija o erro e repita antes de emitir. O painel reflete os dados recebidos por essas rotas.

| Método | Rota | Uso |
|---|---|---|
| GET/PUT | `/api/v1/nfse/config` | Configuração do prestador, série e próximo número |
| GET | `/api/v1/nfse/servicos-nacionais` | Consulta de códigos de serviços |
| GET | `/api/v1/nfse/parametros` | Parâmetros do município na Sefin/ADN |
| POST | `/api/v1/nfse?dry_run=1` | Montar e validar DPS sem transmitir |
| POST | `/api/v1/nfse` | Emitir; exige `Idempotency-Key` |
| GET | `/api/v1/nfse` | Listar documentos da empresa |
| GET | `/api/v1/nfse/{ref}` | Consultar registro local |
| GET | `/api/v1/nfse/{ref}/xml` | Baixar XML |
| GET | `/api/v1/nfse/{ref}/danfse` | DANFSe nacional v2.0 gerado na API; `?formato=base64` retorna JSON com o PDF |
| POST | `/api/v1/nfse/{ref}/sincronizar` | Recuperar situação junto à Sefin/ADN |
| POST | `/api/v1/nfse/{ref}/reenviar-horario` | Recuperação explícita de E0008, somente homologação, mesma DPS |
| POST | `/api/v1/nfse/{ref}/corrigir` | JSON corrigido de DPS definitivamente rejeitada, somente homologação |
| POST | `/api/v1/nfse/{ref}/cancelar` | Solicitar cancelamento |

Todas as rotas usam credenciais restritas à empresa ou acesso central com `X-Empresa-Id`. O ambiente vem de `ambiente_nfse` na gestão da empresa, independentemente da NF-e e NFC-e. `{ref}` é o ID da DPS ou a chave de NFS-e retornada pela emissão. Cancelamento exige proprietário/fiscal; configuração exige proprietário. O OpenAPI em `/docs` descreve os campos e retornos.

Exemplo com serviço informado pela integração:

```json
{
  "servico": {"codigo_tributacao_nacional":"080201","nbs":"122051900","descricao":"Curso de exemplo"},
  "tomador": { "cpf": "52998224725", "nome": "Cliente de teste" },
  "valores": { "servico": 150.00 },
  "ibs_cbs": {"finalidade":0,"consumidor_final":1,"indicador_operacao":"100301","indicador_destinatario":0,"cst":"000","classificacao_tributaria":"000001"},
  "pedido_id": "servico-0001"
}
```

### Regra de ISS cadastrada (`regra_iss`)

Com regras de ISS cadastradas (`/api/v1/tributacao/regras-iss`, mantidas no Portal do Contador), a emissão pode informar só o código da regra. A API preenche `servico.codigo_tributacao_nacional`, `nbs`, `codigo_tributacao_municipal`, `codigo_interno` e `valores.tributacao_iss`, `tipo_imunidade`, `retencao_iss`, `aliquota_iss`. O que vier no JSON prevalece sobre a regra. A descrição do serviço continua vindo da nota.

```json
{
  "regra_iss": "SOFTWARE-RET",
  "servico": { "descricao": "Licença mensal do sistema" },
  "tomador": { "cnpj": "11444777000161", "nome": "Cliente Ltda" },
  "valores": { "servico": 1500.00 }
}
```

Código inexistente: 422 no campo `regra_iss`. A resposta e o registro trazem `regra_iss` (código e descrição) para conferência. Listagem e detalhe para o ERP: `GET /api/v1/tributacao/regras-iss` e `GET /api/v1/tributacao/regras-iss/{codigo}` (ver `docs/TRIBUTOS-CALCULO.md`).

O objeto `servico` é obrigatório em cada emissão (com `regra_iss`, basta a descrição). `servico_id` não é aceito; não há cadastro de serviços. Informe o tomador diretamente; não existe catálogo de clientes nesta edição.

## Tributos informados pelo Delphi

Exemplo completo: [nfse-tributos-informados.json](../examples/nfse-tributos-informados.json). Ajuste a competência, códigos, regime e valores à operação. O exemplo demonstra campos; não determina o tratamento fiscal de um serviço.

A API recebe os valores já calculados e monta a DPS. Não calcula bases, alíquotas, PIS/COFINS ou retenções destes novos grupos. Não soma PIS/COFINS à CSLL, não preenche valores omitidos e não arredonda excesso de casas. Prefira strings decimais com ponto, sem separador de milhar e com até duas casas. Também são aceitos números JSON dentro desses limites; strings preservam valores grandes sem perda de precisão.

| Campo JSON | Tag da DPS | Informação |
|---|---|---|
| `tributos_federais.pis_cofins.cst` | `tribFed/piscofins/CST` | String de dois dígitos, obrigatória quando há `pis_cofins` |
| `...base_calculo` | `vBCPisCofins` | Base de apuração própria informada |
| `...aliquota_pis`, `...aliquota_cofins` | `pAliqPis`, `pAliqCofins` | Alíquotas informadas, até dois dígitos inteiros e duas casas |
| `...valor_pis`, `...valor_cofins` | `vPis`, `vCofins` | Valores de apuração própria informados, sem cálculo automático |
| `...tipo_retencao` | `tpRetPisCofins` | Inteiro de 0 a 9; código nacional, não ID do cadastro Delphi |
| `tributos_federais.retencao_cp` | `tribFed/vRetCP` | Valor informado de CP |
| `tributos_federais.retencao_irrf` | `tribFed/vRetIRRF` | Valor informado de IRRF |
| `tributos_federais.retencao_csll` | `tribFed/vRetCSLL` | Valor informado de CSLL, sem soma automática de outros tributos |
| `valores.suspensao.tipo` | `tribMun/exigSusp/tpSusp` | 1 judicial; 2 administrativo |
| `valores.suspensao.numero_processo` | `tribMun/exigSusp/nProcesso` | String com exatamente 30 dígitos |
| `valores.deducoes.valor` | `vDedRed/vDR` ou `vAjusteBC/vAjusteBCISSQN` | Valor monetário informado, não superior ao serviço |
| `valores.deducoes.percentual` | `vDedRed/pDR` ou `vAjusteBC/pAjusteBCISSQN` | Alternativa ao valor, de 0 a 100; sem conversão em valor |

Os caminhos abreviados de PIS/COFINS começam em `tributos_federais.pis_cofins`. Os grupos são opcionais. Omitir um campo é diferente de enviar zero: zero informado é serializado. Objetos vazios, propriedades desconhecidas nesses novos grupos, números negativos ou com mais de duas casas retornam erro 422. Suspensão exige `tributacao_iss=1` e ambos os seus campos.

Tipos de retenção PIS/COFINS/CSLL do XSD local: 0 nenhum retido; 1 PIS/COFINS retidos; 2 PIS/COFINS não retidos; 3 PIS/COFINS/CSLL retidos; 4 PIS/COFINS retidos e CSLL não retido; 5 somente PIS; 6 somente COFINS; 7 COFINS/CSLL; 8 somente CSLL; 9 PIS/CSLL. O integrador deve informar o código e os valores compatíveis com a operação; a API não deduz retenções a partir de valores de apuração própria.

Em `deducoes`, envie exclusivamente `valor` ou `percentual`. O serializador usa `vDedRed` no pacote `1.01-20260209` e `vAjusteBC` no pacote `1.01-20261020`. Deduções com documentos comprobatórios ainda não estão implementadas e são recusadas, em vez de ignoradas.

Quando qualquer um desses novos grupos é informado, os campos de `estimativa` ficam nulos: a fórmula antiga não representa esses cenários. Antes da autorização, `valor_liquido` também pode ser nulo. Após a autorização, prevalecem `valores_nfse` e o líquido retornado pela Sefin. O XML autorizado continua disponível pela rota `/xml`, preservando os cálculos e totais retornados pelo serviço nacional.

IBS/CBS NFS-e é recebido em `ibs_cbs`, com NBS em `servico.nbs`. A API monta classificação, indicadores e diferimento sem calcular tributos nem consultar tabelas padrão de CST/cClassTrib. Consulte [contrato e diferenças dos leiautes](NFSE-IBS-CBS.md) e [exemplo JSON](../examples/nfse-ibs-cbs-informados.json). O emissor MadBuilder exige esses dados e não omite o grupo em homologação. O XML completo devolvido pelo serviço nacional permanece preservado. A RTC de NF-e/NFC-e possui contrato separado.

O ambiente inicial é produção restrita (`ambiente=2`). O envio à Sefin/ADN usa diretamente o ambiente fiscal da empresa; não há opção global para habilitar o transporte. Produção é selecionada por `ambiente_nfse=1`; não existe liberação global adicional de produção. O município precisa atender ao Padrão Nacional. Esta edição não envia notas recorrentes automaticamente.

Em falha de comunicação, preserve a referência e a chave de idempotência e sincronize antes de nova emissão. A API mantém os XML e o histórico. Não há envio comercial de e-mail ou webhooks nesta edição.

As rotas de correção exigem proprietário, nova Idempotency-Key da tentativa e consulta negativa da DPS no governo antes do reenvio. Nunca use correção para estado incerto ou para trocar a série/número. O retorno de uma chave antiga representa aquela tentativa; consulte o registro atual para obter a situação após uma correção. `reenviar-horario` recebe `{"recuo_segundos":180}` (30..300); `corrigir` recebe o payload completo com `numero` e `serie` originais. Estão limitadas ao ambiente 2 nesta implementação. `NFSE_DHEMI_RECUO_SEGUNDOS` permite configurar a margem de emissão (30..300, padrão 30), sem alterar o relógio do sistema operacional.
