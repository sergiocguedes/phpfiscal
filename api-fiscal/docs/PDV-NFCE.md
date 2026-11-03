# NFC-e do PDV: JSON → API local → XML → ACBr Delphi

**PDF opcional:** `GET /api/v1/nfe/{chave}/danfe`, com `Authorization: Bearer <chave da empresa>`, retorna diretamente os bytes do DANFC-e em 80 mm (`application/pdf`). A resposta do PDV inclui o endereço em `links.danfe`. A chamada usa o XML armazenado, sem transmitir ou reemitir. Está disponível para notas autorizadas/canceladas e NFC-e assinada em contingência; uma tentativa normal com resposta incerta retorna 409 até a recuperação da autorização. Para NF-e (modelo 55), a mesma rota gera DANFE A4.

**Reforma Tributária calculada no Delphi:** use [modo RTC informado](RTC-INFORMADA-DELPHI.md) para grupos detalhados e totais sem recálculo. No endpoint do PDV, IBS/CBS e IS exigem esse modo explicitamente.

O contrato dedicado do PDV usa `/api/v1/pdv/nfce`. O endpoint anterior `/api/v1/nfe` continua disponível com seu contrato anterior. Nesta etapa, o PDV informa ICMS, PIS e COFINS por item; não há aplicação dos perfis tributários nem do catálogo de produtos no novo endpoint. A montagem continua utilizando as validações e totalizações existentes do emissor. O motor tributário da segunda tarefa não foi implementado.

URL local desta instalação: `http://localhost:8081`. Todas as rotas abaixo exigem `Authorization: Bearer <chave da empresa>`. O emitente, certificado A1 e CSC são cadastrados previamente na API. O tenant nunca é informado no JSON.

## Emitir

`POST /api/v1/pdv/nfce`, `Content-Type: application/json`.

```json
{
  "operacao_id": "loja01-caixa01-venda12345",
  "contingencia": "automatica",
  "nota": {
    "modelo": 65,
    "serie": 1,
    "natureza_operacao": "VENDA AO CONSUMIDOR",
    "presenca": 1,
    "itens": [
      {
        "codigo": "SKU-001",
        "descricao": "Produto de teste",
        "ncm": "61091000",
        "cfop": "5102",
        "unidade": "UN",
        "quantidade": 1,
        "valor_unitario": 10.00,
        "impostos": {
          "icms": { "orig": 0, "CSOSN": "102" },
          "pis": { "CST": "07" },
          "cofins": { "CST": "07" }
        }
      }
    ],
    "pagamentos": [{ "forma": "01", "valor": 10.00 }],
    "troco": 0
  }
}
```

Exemplo ilustrativo: os tributos devem corresponder à operação real. Os campos de `nota` seguem `NfeInput` no OpenAPI, com modelo 65; `numero` é proibido neste contrato. A API reserva o número por modelo/série. Configure série e próximo número antes da migração e dedique as séries a uma única autoridade de emissão. Nesta primeira etapa continuam válidas as limitações de campos fiscais do builder existente; não há garantia de paridade com todos os grupos do ACBr.

`operacao_id` deve ter 8–128 caracteres `[A-Za-z0-9_.:-]`. Salve esse identificador e o JSON no banco do PDV antes de enviar; use uma identificação única e durável por venda/documento. Não use apenas um contador que será reiniciado no próximo dia.

- Mesmo identificador + mesmo conteúdo: retorna o estado atual, sem transmitir novamente e sem consumir outra numeração. Ordem das propriedades JSON não altera a identidade da requisição.
- Mesmo identificador + outro conteúdo: HTTP 409 `operacao_conflito`.
- As operações não expiram após sete dias. Não dependem da cache do cabeçalho `Idempotency-Key`; neste endpoint a identidade obrigatória fica no corpo, em `operacao_id`.
- Após rejeição definitiva, uma correção exige outra operação explicitamente identificada; nunca fazê-lo automaticamente quando a situação for incerta. O número reservado não é reutilizado.

## Resposta

```json
{
  "operacao_id": "loja01-caixa01-venda12345",
  "status": "autorizada",
  "modelo": 65,
  "ambiente": 2,
  "chave": "...44 digitos...",
  "numero": 1,
  "serie": 1,
  "tipo_emissao": 1,
  "cStat": "100",
  "xMotivo": "Autorizado o uso da NF-e",
  "protocolo": "...",
  "autorizado_em": "...",
  "xml": "<?xml version=\"1.0\" encoding=\"UTF-8\"?><nfeProc ...>...</nfeProc>",
  "imprimivel": true,
  "requer_intervencao": false,
  "links": {
    "self": "/api/v1/pdv/nfce/loja01-caixa01-venda12345",
    "sincronizar": "/api/v1/pdv/nfce/loja01-caixa01-venda12345/sincronizar"
  }
}
```

`xml` é texto XML dentro do JSON, sem base64. Desserialize o JSON antes de carregar o XML no ACBr. Em autorização ele contém `nfeProc` com protocolo. Em contingência ele contém `NFe` assinada, com `tpEmis=9`, data/motivo e QR-Code, ainda sem protocolo. Também são retornados QR-Code, URL de consulta e digest quando o XML está disponível.

| Estado | HTTP no POST inicial/replay | Ação do Delphi |
|---|---|---|
| `autorizada` | 201 | Salvar XML/protocolo, atualizar banco, carregar no ACBr e imprimir |
| `contingencia_pendente` | 202 | Salvar e imprimir XML de contingência; acompanhar autorização posterior |
| `situacao_incerta` | 202 | Consultar a operação; não gerar outra nota. XML normal não é liberado como autorizado |
| `preparando` / `pronta` | 202 | Acompanhar; estado intermediário recuperável após interrupção |
| `rejeitada` / `denegada` | 422 | Exibir retorno fiscal e encaminhar correção; não reemitir automaticamente |
| `cancelada` | 200 | Atualizar situação local; não imprimir como documento ativo |

Para um XML offline já emitido, `situacao_incerta` também pode retornar o XML de contingência. Ele permanece imprimível nessa condição; isso não significa autorização. Sempre verifique estado e tipo de emissão.

`cStat` e `xMotivo` são fiscais. `cStat_lote` e `xMotivo_lote`, quando presentes, identificam o envelope da resposta. No timeout inicial pode não existir cStat; não substituir por código fiscal fictício. Erros anteriores à criação da operação usam o envelope padrão `erro` da API.

## Consulta e recuperação

- `GET /api/v1/pdv/nfce/{operacao_id}`: retorna o estado persistido e o XML quando disponível, sem falar com a SEFAZ.
- `POST /api/v1/pdv/nfce/{operacao_id}/sincronizar`: tenta recuperar uma operação pendente. Retorna HTTP 200 com o estado atual, que ainda pode estar pendente. Respeita `proxima_tentativa` (Unix timestamp).

Depois de timeout HTTP do Delphi, consulte por `operacao_id`. Se retornar 404, reenvie o MESMO JSON com o MESMO identificador: pode haver uma primeira chamada ainda chegando/processando. O lock por operação impede que ambas transmitam como documentos diferentes.

A API grava a intenção e o XML antes do envio. Antes de retransmitir uma tentativa incerta, consulta a chave na SEFAZ. Uma autorização recuperada é protocolada e atualiza a projeção usada pelo painel/download. Somente respostas de chave não encontrada (217/562), seguidas de serviço em operação, permitem retransmitir o mesmo XML. Falha na consulta não autoriza retransmissão. Nunca há mudança automática de emissão normal para offline após tentativa de transmissão normal.

Não altere CNPJ ou UF com operações pendentes: a recuperação exige a identidade original. O worker recupera as operações no ambiente em que foram criadas, independentemente do ambiente atual da NFC-e. Uma mudança de certificado A1 válido da mesma empresa é possível, sem reconstruir o XML já assinado.

## Contingência

- `automatica` (padrão): consulta status antes de montar a nota definitiva; 108/109 ou falha de comunicação nessa consulta selecionam emissão offline. Resposta de status inesperada interrompe a criação.
- `offline`: gera XML assinado em contingência e devolve sem transmissão inicial. Pode informar `motivo_contingencia` com 15–256 caracteres.
- `desativada`: tenta emissão normal, sem pré-consulta de status. Timeout continua gerando situação incerta recuperável.

Em homologação, o fluxo offline pode ser exercitado com certificado de teste nos testes automatizados. Para produção (`ambiente_nfce=1`), habilite explicitamente as UFs já homologadas na instalação, por exemplo `NFCE_CONTINGENCIA_UFS=AM`. A lista começa vazia. Confira as regras e os prazos aplicáveis à UF antes da habilitação; a lista não valida o credenciamento fiscal.

O worker existente (`bin/worker.php`) recupera a fila automaticamente, com espera crescente até uma hora entre tentativas, no máximo dez operações elegíveis por rodada e doze tentativas contabilizadas por operação. O contador inclui consultas e envios. Ao atingir o limite, `requer_intervencao=true`; a sincronização manual continua disponível. Não existe exclusão automática de pendências ou de operações antigas.

O Delphi deve acompanhar a operação e baixar/gravar a versão protocolada após autorização. Impressão, TEF, estoque e financeiro continuam no Delphi. O novo fluxo não adiciona despacho automático de webhooks/e-mails; a integração do PDV acompanha os endpoints acima.

## Persistência e execução

Operações e XML ficam em `storage/tenants/<tenant>/pdv`, com projeção em `nfe` para os endpoints existentes de consulta, XML, DANFCE e cancelamento. A numeração possui registro de unicidade compartilhado com o emissor anterior em `numeros`. Backups precisam incluir todo o `storage` e a chave do `.env`.

No painel e nas rotas antigas, estados pendentes do PDV aparecem como `processando`, com o estado detalhado em `status_pdv` e no histórico. O contrato dedicado do PDV retorna os estados detalhados da tabela acima.

O serviço foi implementado para uma instalação com armazenamento local e locks de arquivo compartilhados. Os cálculos/validações tributárias existentes não foram expandidos. Os testes usam SEFAZ simulada com assinatura e QR-Code reais da biblioteca; não substituem o ciclo de homologação com a SEFAZ da empresa.
