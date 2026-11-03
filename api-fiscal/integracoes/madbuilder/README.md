# MadBuilder emissor_servicos → API Fiscal PHP

Para transferir as alterações ao projeto do editor e preservá-las ao exportar, consulte o [guia completo por arquivo/método, modelos e conferência pós-exportação](documentacao/MADBUILDER-PRESERVAR-INTEGRACAO.md).

Validação ponta a ponta concluída: [nota 60 autorizada em homologação como NFS-e 360](TRANSMISSAO-NOTA60-HOMOLOGACAO.md), XML verificado e retorno gravado no emissor. O relatório registra os ajustes de cadastro, a migração do campo de retorno e os fluxos seguros de correção/reenvio.

Integração preparada para o projeto `D:\fontes\php\www\emissor_servicos`. O JSON enviado usa o contrato nativo `/api/v1/nfse`; as telas continuam recebendo os nomes que já utilizam (`sucesso`, `numeroNota`, `chaveNFSe`, `chaveDps`, `xmlRetorno`). Nenhuma nota é emitida ao instalar esta integração.

## Testes locais

| Componente | Endereço/configuração |
|---|---|
| MadBuilder | `http://localhost:8080/emissor_servicos/` |
| API Fiscal e painel | `http://localhost:8081/` |
| MadBuilder no container → API no outro container | `http://host.docker.internal:8081/api/v1` |
| MadBuilder executando PHP diretamente no Windows → API | `http://localhost:8081/api/v1` |

O navegador e o processo PHP não compartilham o mesmo significado de `localhost`: no container, localhost aponta para o próprio container. Supabase/B2 e `.env.online` não são necessários para estes testes; a API local continua com seu PostgreSQL e armazenamento locais.

O MadBuilder tem seu próprio banco de negócio e a API tem seu banco fiscal. É possível usar o mesmo servidor PostgreSQL Docker, com **bancos e usuários separados**. Não aponte as tabelas do MadBuilder para o banco fiscal nem modifique o schema da API para instalar o sistema consumidor. No Docker, conecte os containers por uma rede comum e use um alias estável para o servidor; não é preciso publicar a porta PostgreSQL no Windows. Se o PHP executar no Windows, publique PostgreSQL apenas em `127.0.0.1` e configure essa porta no MadBuilder.

Após correção do banco de origem informada pelo usuário, o MadBuilder usa os dumps MariaDB restaurados no MySQL 8.4 já existente: `emite` e `log_emite`, com usuário próprio. As quatro conexões estão configuradas. Consulte [bancos locais restaurados](MYSQL-LOCAL.md) para acesso, inicialização e backup. A API Fiscal continua no PostgreSQL; a configuração PostgreSQL anterior do MadBuilder é histórica e não deve ser reaplicada.

## Arquivos

- `app/service/NfseApiPayload.php`: converte a montagem existente para o contrato nativo; cálculos continuam no MadBuilder.
- `app/service/NfseApiPhpService.php`: autenticação Bearer, emissão, sincronização, XML/PDF e cancelamento.
- `app/config/nfse-api.example.php`: modelo sem segredos; copiar para `nfse-api.local.php` no projeto consumidor.
- `app/control/NFSeService.php`: versão adaptada do serviço das telas, agendamento e e-mail.
- `PainelApiService::postNfse`: deve delegar exclusivamente para `NfseApiPhpService::dispatch($endpoint, $payload, (int) $timeout)`. Inclua `require_once __DIR__ . '/NfseApiPhpService.php';` no arquivo. As rotas Horse/pagamentos não são alteradas.

O arquivo preenchido fica no servidor MadBuilder, não no navegador. Contém URL e uma chave restrita por CNPJ. Uma chave central exige também `empresa_id`, mas prefira chaves restritas. Não versione esse arquivo. Para guardar fora da pasta pública, defina `NFSE_API_CONFIG` no processo PHP com seu caminho absoluto.

Para a empresa 11229442000120, a configuração local foi preparada com chave restrita. Nenhuma chave Supabase/B2 foi copiada para o MadBuilder. `NFSeService::configurarCliente()` agora monta a configuração pelo cadastro do emissor e sincroniza nome/ambiente NFS-e, identidade fiscal, endereço, prestador/IM/regime/série e A1 através de `/tenant`, `/config`, `/nfse/config` e `/certificado`. Não é necessário preencher esses dados manualmente no painel da API. A chave restrita identifica a empresa existente; criar uma empresa nova exige o fluxo administrativo central. A sincronização preserva a numeração existente e não altera ambientes NF-e/NFC-e.

`NfseApiConfiguracao` normaliza os dados no emissor antes do envio. Se cidade/IBGE estiverem vazios, resolve o CEP via HTTPS no ViaCEP, exige correspondência de UF e retorna aviso para completar o cadastro. A montagem da nota usa a mesma resolução; não presume Manaus/AM. IE é opcional para NFS-e e segue como `null` quando ausente; a IM vem do cadastro. A abertura e validade do A1 são conferidas no emissor antes das chamadas, e senha/base64 não aparecem nos retornos ou artefatos.

A [ParametrosGeraisForm também sincroniza ambiente, série e último número](PARAMETROS-GERAIS-API.md) ao salvar, inclusive no assistente inicial. Usa `NFSeService::sincronizarParametros()`, que dispensa reenviar o A1. O próximo RPS/DPS é calculado a partir do último e das notas da mesma série, respeitando o contador existente na API; o último local é alinhado ao contador confirmado. Falhas após o commit local são mostradas como sincronização pendente, sem mensagem de sucesso indevida.

Ambientes na empresa API: `ambiente_nfse=1` produção, `2` homologação. Mantenha o correspondente no cadastro do MadBuilder. Não existe habilitação global do transporte: o ambiente da empresa governa o envio. Estar rodando localmente não impede transmissão real: para os primeiros testes use `POST /nfse?dry_run=1`.

## Mapeamento de JSON

| Origem antiga | Contrato API PHP |
|---|---|
| identificacaoRps.numero / serie | numero / serie, inteiros |
| identificacaoRps.competencia `dd/mm/aaaa` | competencia `aaaa-mm-dd` |
| servico.itemListaServico | servico.codigo_tributacao_nacional, 6 dígitos |
| servico.discriminacao / codigoMunicipio | servico.descricao / municipio_prestacao |
| valores.valorServicos | valores.servico, bruto já calculado no MadBuilder |
| valores.descontoIncondicionado | valores.desconto_incondicionado |
| tribMun.tribISSQN / tpRetISSQN | valores.tributacao_iss / retencao_iss |
| alíquota da nota | valores.aliquota_iss, aplicação conforme regime validada pela API |
| tribMun.tpSusp / nProcesso | valores.suspensao.tipo / numero_processo |
| tribFederal.CST, base, alíquotas e valores | tributos_federais.pis_cofins |
| tribFederal.vRetCP / vRetIRRF / vRetCSLL | tributos_federais.retencao_cp / retencao_irrf / retencao_csll |
| totTrib | tributos_aproximados, percentual/percentual_sn/nao_informar conforme regime |
| tomador.CNPJCPF | tomador.cpf ou tomador.cnpj |
| endereço do tomador | tomador.endereco |
| observação | informacoes_complementares |
| unidade e ID da nota | pedido_id `mad-<unidade>-<nota>` |

O código municipal fixo `100` da implementação anterior não é herdado. Um item LC 116 de 4 dígitos não determina o código nacional de 6 dígitos: ajuste o cadastro do serviço, sem completar com zeros automaticamente. IBS/CBS NFS-e é enviado: `montarIbsCbs()` é obrigatório na montagem, sem omissão em homologação. O adaptador converte `IBSCBSDPS`, `gIBSCBS` e `gDif` para `ibs_cbs`, incluindo NBS no serviço; preserva os dados informados e a API posiciona os campos conforme o XSD. Intermediador é recusado antes da transmissão, pois a API ainda não possui esse grupo. Deduções simples já existem no contrato API, mas não havia campo equivalente na montagem examinada do MadBuilder.

## Timeout, retornos e histórico

Cada CNPJ/série/número usa a mesma `Idempotency-Key`, `mad-nfse-<cnpj>-<serie>-<numero>`. O cliente retira `_cnpj` e `nota_id` antes do POST; não envia data/hora variável. Em estado erro/processando, sincroniza a referência antes de novo envio. O fluxo existente consulta a DPS nas tentativas após falha de comunicação. Não troque número para contornar timeout. Se o conteúdo mudar com a mesma chave, o conflito deve ser analisado; não se gera uma chave aleatória para forçar emissão.

Antes do POST, o cliente também consulta o registro atual: se autorizado, recupera XML sem reemitir. Para uma rejeição definitiva, usa `/nfse/{ref}/corrigir` em homologação, com chave derivada do JSON e das configurações fiscais relevantes (incluindo IM). O servidor consulta a DPS no governo e exige ausência de autorização antes de enviar a correção, mantendo série/número. Contadores e timestamps não geram novas chaves de correção. Não utilize a rota para contornar estado incerto.

Autorização só é reconhecida quando o status é `autorizada`/`cancelada` e o XML foi obtido; número/chave/XML são adaptados para as telas, preservando a atualização dos valores calculados pela Sefin. A API gera o DANFSe nacional v2.0 e retorna `pdf_base64` em `GET /nfse/{ref}/danfse?formato=base64`; o adaptador entrega esse mesmo conteúdo como `pdfBase64` às telas. O emissor apenas decodifica e abre para imprimir. Veja [modelo e preparação das fontes na API](../../docs/DANFSE-NACIONAL.md). Eventos e arquivos XML usam as rotas nativas. O envio comercial de e-mail continua no MadBuilder.

Notas anteriores da API C# não aparecem automaticamente na API PHP. Será necessário planejar a importação do histórico ou manter os XML antigos já salvos no MadBuilder; não reemita uma nota antiga para migrar dados. O arquivo `PainelApiService` ainda pode conter configurações de outros serviços existentes, mas `postNfse` não as utiliza mais.

## Validação e manutenção

No container da API: `php integracoes/madbuilder/tests/contrato.php`. O teste usa o gerador e XSD reais para o JSON convertido e transporte simulado para as operações; não acessa a Sefin. Sintaxe e conectividade também devem ser conferidas no PHP do MadBuilder.

Para repetir o teste real, sem transmitir, execute na pasta do projeto dentro do container MadBuilder:

```powershell
docker exec -w /var/www/html/emissor_servicos madbuilder-local-web-1 php /var/www/html/emissor/api-fiscal/integracoes/madbuilder/testar-homologacao.php 60
```

O script exige ambiente 2, monta a nota com o serviço instalado, chama somente `/nfse?dry_run=1`, valida o XML e compara o registro da nota, os contadores e a lista de NFS-e antes/depois. Não chama a função de emissão nem altera o status da nota. Salva JSON/XML/resultado em `.migration-backups/homologacao-madbuilder-<data-hora>` na pasta da API; esses arquivos contêm dados fiscais privados e não devem ser publicados. Não salvam A1/senha.

Validação de 02/11/2026: empresa 11229442000120 sincronizada pelo emissor; A1 válido até 08/01/2027; nota 60, série 1, DPS 148, serviço 140101 e valor R$ 100,00. Retorno HTTP 200, `valida=true`, XSD `20261020`, `tpAmb=2`, CNPJ e IM corretos. Nenhuma nota criada, numeração e registro de origem preservados. O município/conveniamento ainda não foi confirmado junto à Sefin; este dry-run valida estrutura local, não habilitação ou autorização fiscal. A persistência de uma autorização no emissor ainda não foi exercitada, pois a transmissão não foi autorizada.

O dry-run foi repetido com IBS/CBS presente, conferindo CST `000`, cClassTrib `000001`, IndOp `100301` e NBS `115021000` no JSON e no XML. Artefatos em `.migration-backups/homologacao-madbuilder-20261102-205847/`. O contrato do adaptador passou por 38 verificações. Consulte `docs/NFSE-IBS-CBS.md` no projeto da API, ou `docs/NFSE-IBS-CBS.md` copiado para o emissor.

Reexportar o projeto no MadBuilder pode sobrescrever as alterações em `NFSeService` e `PainelApiService`. Mantenha as classes de integração como serviços personalizados e replique as alterações nos pontos de extensão do projeto original. Não sobrescreva versões mais novas com esta cópia sem comparar as diferenças. O backup anterior à adaptação foi salvo fora do projeto consumidor, em `.migration-backups/madbuilder-20261102-195138` na pasta do projeto API.
