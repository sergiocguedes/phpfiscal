# Configurar o PDV Delphi para a API local ou online

O contrato JSON é o mesmo nos dois perfis. O PDV não conecta ao PostgreSQL. Ele usa a API PHP com uma URL base e uma chave restrita à empresa.

| Configuração | Local | Online |
|---|---|---|
| `UrlBase` | `http://localhost:8081/api/v1` | `https://SEU-DOMINIO/api/v1` |
| `Chave` | Chave `nfk_...` criada na instalação local | Chave `nfk_...` criada na instalação online |

O nome do perfil de hospedagem não escolhe homologação ou produção: essa escolha é feita no cadastro da empresa. Sistemas distintos devem ter chaves distintas, mesmo usando a mesma empresa. Uma chave `adm_...` é destinada a integrações administrativas, não ao caixa comum.

## Onde obter a chave

Entre no painel administrativo da instalação correta, selecione a empresa e abra **Chaves da empresa**. Crie uma chave com um nome como `ClickPDV Caixa 01`. Copie o valor exibido uma única vez para a configuração do PDV. Proteja o arquivo de configuração e não registre a chave em logs.

Modelos de INI:

- [Local](../examples/delphi/fiscal-api-local.ini.example).
- [Online](../examples/delphi/fiscal-api-online.ini.example).

Troque o domínio e os valores de exemplo. A URL inclui `/api/v1`; as rotas abaixo não repetem esse prefixo. Uma chave de empresa já seleciona o contexto; não é necessário enviar `X-Empresa-Id`. Cabeçalho `X-Empresa-Id` é necessário somente para credenciais administrativas globais.

## Requisição

```http
POST /api/v1/pdv/nfce
Authorization: Bearer nfk_CHAVE_DA_EMPRESA
Content-Type: application/json

{"operacao_id":"caixa01-venda000123","nota":{ ... dados fiscais ... }}
```

O JSON completo da NFC-e está em `examples/pdv-nfce-exemplo.json`; a NFS-e com tributos informados está em `examples/nfse-tributos-informados.json`. Os cálculos desses contratos continuam no Delphi nesta etapa.

Há um [cliente de referência](../examples/delphi/FiscalApiCliente.pas) usando `THTTPClient`, para versões Delphi que incluem `System.Net.HttpClient`. Ele lê o INI e devolve o status HTTP e o corpo sem descartar os erros da API:

```pascal
RetornoJSON := TFiscalApiCliente.PostJSON(
  'fiscal-api.ini', '/pdv/nfce', JSONDaVenda, '', StatusHTTP);
```

O exemplo é um ponto de integração; não altera os fontes atuais do PDV e não foi compilado com o compilador Delphi do cliente. Em projetos com Indy ou outro cliente HTTP, mantenha os mesmos cabeçalhos e tratamento de retorno. A verificação do certificado HTTPS deve permanecer habilitada.

Referência do cliente HTTP: [THTTPClient.Post na documentação da Embarcadero](https://docwiki.embarcadero.com/Libraries/Athens/en/System.Net.HttpClient.THTTPClient.Post).

## Retorno, timeout e repetição

- Confira o campo `status` do JSON; um HTTP 202 não significa autorização.
- Quando autorizada, a operação NFC-e devolve `xml`. Passe esse XML ao ACBr para a impressão e preserve a referência da operação.
- Em timeout ou situação incerta, consulte `GET /pdv/nfce/{operacao_id}` e, se necessário, `POST /pdv/nfce/{operacao_id}/sincronizar` com `{}`. Não crie outra operação para a mesma venda.
- Repetir `POST /pdv/nfce` usa o mesmo `operacao_id` e o mesmo conteúdo original. Conteúdo alterado gera conflito.
- Para emitir NFS-e em `POST /nfse`, use `Idempotency-Key` estável por documento. O cliente de referência aceita esse valor no quarto argumento. Preserve a referência retornada para `/nfse/{ref}/sincronizar`; o XML autorizado é baixado em `/nfse/{ref}/xml`.
- Os caminhos em `links` começam em `/api/v1`. Resolva-os contra a origem (`http://localhost:8081` ou `https://SEU-DOMINIO`), sem duplicar `/api/v1`. Downloads de XML/PDF também exigem Bearer.
- Diferencie indisponibilidade de rede de rejeição fiscal. Preserve `request_id`, código e mensagem do erro para diagnóstico, sem registrar credenciais.

Timeout do cliente de referência: conexão 10 segundos e resposta 150 segundos. Os timeouts não garantem que a nota não foi autorizada: sempre recupere a operação original antes de tentar outra emissão.

## Uso de mais de uma instalação

As duas instalações são independentes. Não há sincronização automática nem roteamento de contingência entre local e online. Nunca faça fallback automático para a outra instalação após timeout: a primeira pode ter autorizado a nota, e a segunda não conhece a operação. Defina qual instalação controla cada empresa/modelo/série e mantenha a numeração coerente entre todos os emissores.
