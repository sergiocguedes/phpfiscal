# Validação local de integração — 02/11/2026

Empresa: 11229442000120. Fluxo executado pelo PHP do emissor MadBuilder para a API local, com chave restrita à empresa.

## Configuração por API

`NFSeService::configurarCliente()` abriu o A1 cadastrado no emissor, conferiu sua validade e enviou a configuração por HTTP: `/tenant` (nome e ambiente NFS-e 2), `/config` (identidade/endereço/regime, sem IE), `/nfse/config` (prestador, IM 13064001, regime e série) e `/certificado` (A1 e senha). O A1 tem validade até 08/01/2027. Nenhuma credencial foi gravada nos artefatos do teste.

Cidade e IBGE estavam vazios no cadastro importado. O emissor resolveu o CEP comercial 69053575 no ViaCEP, conferiu UF AM e enviou Manaus, IBGE 1302603. O cadastro original não foi alterado automaticamente. Complete esses dois campos no emissor para evitar a consulta de CEP nas próximas montagens.

## JSON e XML

- Nota de origem: 60, preservada em digitação.
- Série: 1; número DPS: 148.
- Competência: 02/11/2026; código nacional: 140101; valor bruto: R$ 100,00.
- Endpoint: `POST /api/v1/nfse?dry_run=1`.
- HTTP 200; `dry_run=true`; `valida=true`; nenhum erro de schema.
- XML: DPS v1.01, pacote XSD 20261020, `tpAmb=2`.
- ID: `DPS130260321122944200012000001000000000000148`.
- CNPJ e IM conferidos no XML; ausência de IE não bloqueou a NFS-e.
- Registro completo da nota e parâmetros de numeração do emissor iguais antes/depois.
- Numeração e lista de notas da API iguais antes/depois; nenhuma NFS-e criada.

Artefatos privados na pasta da API:

```text
.migration-backups/homologacao-madbuilder-20261102-204340/payload-nfse.json
.migration-backups/homologacao-madbuilder-20261102-204340/dps-homologacao.xml
.migration-backups/homologacao-madbuilder-20261102-204340/resultado.json
```

O JSON da nota não contém configuração, certificado ou senha: estes são enviados separadamente nos endpoints de configuração. O XML gerado é uma DPS não assinada, sem protocolo de autorização.

## Limites e validações adicionais

Avisos da API: alíquota ISS não informada no XML neste regime, pois o serviço nacional a determina; situação do convênio municipal não confirmada. O teste não certifica habilitação do prestador, regras externas da Sefin, assinatura/transmissão/autorização, nem persistência posterior da autorização no banco do emissor.

Regressão da API: 75 testes, 1.330 verificações. Contrato do adaptador: 28 verificações, incluindo configuração por API, chave idempotente, isolamento por CNPJ, preservação de contadores e ausência de segredos nos retornos.
