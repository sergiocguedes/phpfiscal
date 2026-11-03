# ParametrosGeraisForm — sincronização da API

Ao salvar a tela, ambiente NFS-e, série e numeração são sincronizados com a API Fiscal usando a chave da empresa. Não há emissão de notas nessa operação.

## Campos e regras

| Tela / origem | API |
|---|---|
| `ambiente_fiscal_id` | `PUT /tenant`: `ambiente_nfse` (1 produção, 2 homologação) |
| `serie_nfse` | `PUT /nfse/config`: `serie` |
| `ultimo_numero_nfse` | próximo RPS/DPS = último + 1, considerando os números já atribuídos na mesma série |

O campo historicamente chamado “último número NFS-e” controla a numeração do RPS/DPS no emissor. Ele não define o número da NFS-e autorizada, atribuído pelo serviço nacional. A tela agora identifica “Último número RPS/DPS” e “Série RPS/DPS”.

Valores permitidos: ambiente 1/2, série 1..49999 e último número 0..999999999999998. A maior numeração já atribuída a uma nota da mesma série no emissor também é considerada. Na API, o contador da série de destino é lido depois de selecionar o ambiente; o cliente mantém o maior entre o próximo número solicitado e o contador já existente. Quando necessário, a tela atualiza o último número local para `proximo_numero_api - 1`. Dessa forma, salvar um valor menor não faz os sistemas voltarem sobre números reservados.

`NFSeService::proximoNumeroRps()` também considera a série configurada ao procurar o maior número no emissor. A numeração de outra série não força um salto na série atual.

## Salvamento e falhas

1. Valida os campos e salva os parâmetros somente da unidade da sessão; ignora ID/unidade enviados pelo formulário.
2. Fecha a transação local antes das chamadas HTTP.
3. Chama `NFSeService::sincronizarParametros()` para enviar nome/ambiente, dados fiscais e prestador/IM/regime/série/contador.
4. Ajusta o último número local quando a API confirmar um próximo número maior.
5. Exibe sucesso somente após a confirmação da API. No assistente inicial, sincroniza antes de avançar para a tela de certificado.

Se a API falhar depois do commit local, a tela avisa expressamente que os parâmetros foram salvos no emissor, mas não sincronizados. Corrija a conexão/cadastro e salve novamente. Não é uma transação distribuída entre MySQL e PostgreSQL.

Salvar parâmetros não exige nem reenvia o A1. O certificado permanece sendo enviado por `NFSeService::configurarCliente()` nos fluxos de certificado/empresa. A ausência de IE não impede configurar um prestador NFS-e; a IM segue no cadastro do prestador.

## Arquivos alterados

- `app/control/configuracoes/ParametrosGeraisForm.php`;
- `app/control/NFSeService.php`;
- `app/service/NfseApiConfiguracao.php`;
- `app/service/NfseApiPhpService.php`.

As versões anteriores foram preservadas em `.migration-backups/parametros-api-<data-hora>` na pasta do projeto API. Reexportar pelo MadBuilder pode sobrescrever os controladores: replique as alterações nos pontos de extensão do projeto original.

## Validação de 02/11/2026

Foi executado o `onSave` real da tela com os valores existentes da empresa 11229442000120. Resultado:

```text
emissor: ambiente 2, série 1, último RPS/DPS 148
API:     ambiente 2, série 1, próximo RPS/DPS 149
```

O teste registrou as chamadas `GET/PUT /tenant`, `GET/PUT /config` e `GET/PUT /nfse/config`. Não chamou `/certificado` nem a emissão de NFS-e. Todas as notas permaneceram inalteradas. Uma simulação em transação revertida confirmou o isolamento da busca do próximo RPS por série. O dry-run da nota 60 continuou válido no XSD de homologação e preservou a numeração.

O adaptador passou por 33 verificações, incluindo avanço de contador, manutenção de contador maior, histórico da série de destino e salvamento sem A1.

Para repetir o salvamento com os parâmetros atuais (pode elevar o último local para respeitar a API/notas existentes):

```powershell
docker exec -w /var/www/html/emissor_servicos madbuilder-local-web-1 php /var/www/html/emissor/api-fiscal/integracoes/madbuilder/testar-parametros.php
```
