# Ativação da edição enxuta

A edição ativa foi separada em `api-fiscal/`. O projeto original permanece na pasta superior como referência; não é carregado pelo autoload da aplicação executada no Docker.

O Compose `docker/docker-compose.yml` da pasta superior monta:

| Origem no Windows | Destino no contêiner | Uso |
|---|---|---|
| `api-fiscal/` | `/app` | Código e painel da nova edição |
| `vendor/` | `/app/vendor` | Dependências Composer compartilhadas, somente leitura |
| `.env` | `/app/.env` | Segredos e configuração existentes, somente leitura |
| `storage/` | `/app/storage` | Dados, XML, certificados cifrados e sessões existentes |

Não houve exclusão de empresas, XML, certificados, chaves ou configurações. Não foi criado usuário padrão de produção. Os dados usados na validação visual ficaram em uma instância temporária separada e foram descartados ao final.

## Mudanças de escopo

- Painel apenas para cadastro/acesso da empresa, emitente, numeração/CSC/responsável técnico, certificado A1, chaves, prestador/serviços NFS-e e perfil/MFA.
- APIs de NF-e/NFC-e, PDV, Reforma Tributária e NFS-e preservadas.
- Sem telas de emissão de notas ou impressão.
- Sem módulos de catálogo, marketplace, WooCommerce, MCP, contador, recorrência, CSV em lote, marca própria, alertas comerciais, SMTP de notas ou webhooks.
- Dados de produtos, clientes e valores são enviados pela integração. A RTC informada permanece calculada no Delphi.
- Worker dedicado à contingência e recuperação das NFC-e do PDV. Pendências antigas de módulos retirados não são executadas por ele.

## Voltar à edição original

A configuração original foi preservada em `docker/docker-compose.legado.yml` na pasta superior. Se necessário:

```powershell
docker compose -f docker/docker-compose.legado.yml up -d --force-recreate
```

Esse comando volta a carregar o código original sobre o mesmo armazenamento. Não execute as duas configurações simultaneamente na mesma porta/armazenamento.
