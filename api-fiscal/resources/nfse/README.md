# Recursos oficiais da NFS-e (Sistema Nacional)

Baixado em 14/10/2026, somente de <https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual>.

- `schemas/1.01/`: conteúdo de `Schemas/1.01/` do pacote `nfse-esquemas_xsd-v1-01-20260209.zip`. O ZIP tem SHA-256
  `e7935cbd9470527c6cc32984c1b2263e614183bf0139ce2733eaaed2de9a8072`. O gov.br não publica checksum: as somas de
  cada arquivo, calculadas no download, estão em `schemas/1.01/SHA256SUMS`, e o teste `NfseMcpTest` confere.
  **Não edite esses arquivos.** A correção da âncora em `TSSerieDPS` é aplicada numa cópia de trabalho em
  `storage/tmp/nfse-xsd/` (ver `App\Nfse\NfseXml::schemaDir()`).
- `lista-servicos-nacional.json` e `nbs.json`: extraídos de
  `anexo_b-nbs2-lista_servico_nacional-snnfse-v1-01-20260122.xlsx` (SHA-256
  `e74b0be8ad204e458932efb1716225a3fb5703a84e5475ce5c4c6789d1c61b1a`). Cada um tem 338 códigos de tributação nacional
  e 918 códigos NBS de 9 dígitos.

Para atualizar quando sair uma nova versão:

1. Baixe o ZIP e os anexos da mesma página.
2. Crie `schemas/<versão>/` com um novo `SHA256SUMS`.
3. Ajuste `NfseXml::SCHEMA_VERSION` e `DpsBuilder::VERSAO`.
4. Rode a suíte de testes.

Detalhes em [`docs/NFSE.md`](../../docs/NFSE.md).
