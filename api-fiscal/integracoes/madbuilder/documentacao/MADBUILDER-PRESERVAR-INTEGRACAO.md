# Como preservar a integração API PHP ao exportar pelo MadBuilder

Projeto consumidor: `D:\fontes\php\www\emissor_servicos`. API: `D:\fontes\php\www\emissor\api-fiscal`.

As alterações existem nos PHP exportados. **Este trabalho não editou o projeto interno no editor MadBuilder.** Para não perder os ajustes, transfira as classes, métodos e campos descritos abaixo para os recursos personalizados do projeto no editor. Os nomes dos menus dependem da versão do MadBuilder; não há aqui uma confirmação de que já foram cadastrados no editor.

Os arquivos da pasta `referencia/` deste pacote são a referência exata do fonte atual, sem a configuração local com segredos. Não substitua controladores inteiros cegamente após futuras mudanças: preserve os pontos indicados e compare os métodos.

Anexos: [métodos NFSeService](referencia/NFSeService-trechos.md), [onSave dos parâmetros](referencia/ParametrosGeraisForm-trechos.md), [saída NFS-e do PainelApiService](referencia/PainelApiService-trechos.md) e [manifesto de conferência](referencia/manifesto.json).

## 1. Inventário completo

| Arquivo no emissor | Tratamento dentro do MadBuilder | Referência |
|---|---|---|
| `app/service/NfseApiPhpService.php` | Cadastrar como classe/serviço personalizado integral | `referencia/NfseApiPhpService.php` |
| `app/service/NfseApiPayload.php` | Cadastrar como classe/serviço personalizado integral | `referencia/NfseApiPayload.php` |
| `app/service/NfseApiConfiguracao.php` | Cadastrar como classe/serviço personalizado integral | `referencia/NfseApiConfiguracao.php` |
| `app/service/PainelApiService.php` | Alterar somente importação e método `postNfse`; preservar outras integrações | `referencia/PainelApiService-trechos.md` |
| `app/control/NFSeService.php` | Preservar importações e os 13 métodos relacionados abaixo no código personalizado | `referencia/NFSeService-trechos.md` |
| `app/control/configuracoes/ParametrosGeraisForm.php` | Substituir o fluxo `onSave` e ajustar rótulos | `referencia/ParametrosGeraisForm-trechos.md` |
| `app/control/configuracoes/EmpresaForm.php` | Conferir chamada de sincronização no salvamento | Seção 5 |
| `app/control/configuracoes/CertificadoDigitalForm.php` | Conferir chamada de configuração após salvar o A1 | Seção 5 |
| `app/model/NotaFiscalCabecalho.php` | Acrescentar campo ao modelo de dados, não apenas ao PHP gerado | Seção 6 |
| `app/model/NotaFiscalDetalhe.php` | Acrescentar campo ao modelo de dados, não apenas ao PHP gerado | Seção 6 |
| `app/control/principal/NotaFiscalCadItemForm.php` | Expor/preservar código municipal do item no editor; extensão ainda necessária para edição visual | Seção 7 |
| `app/config/nfse-api.example.php` | Distribuir modelo sem credenciais | Seção 8 |
| `app/config/nfse-api.local.php` | Configuração de cada instalação; conservar fora da exportação | Seção 8 |

Os arquivos de conexão `app/config/emissor.php`, `permission.php`, `communication.php` e `log.php` também são locais. Não os sobrescreva com conexões vazias durante uma exportação. Eles não são credenciais da API: conectam o emissor ao MySQL/MariaDB.

## 2. Classes personalizadas: importar completas

Cadastre cada uma das três classes de serviço com o mesmo nome e conteúdo da referência. Garanta que a exportação inclua os arquivos em `app/service/`. Não crie uma segunda classe com o mesmo nome em outra pasta.

- **NfseApiPhpService:** configuração por CNPJ, Bearer, validação da empresa autenticada, chamadas REST, adaptação de respostas, consulta antes de enviar, correção de rejeitadas, idempotência, XML/PDF e cancelamento. Manter `config`, `request`, `dispatch`, `sincronizar` e `record` em conjunto. Não copiar apenas a função de emissão.
- **NfseApiPayload:** conversão do JSON anterior para o JSON nativo. Inclui tomador/endereço, serviço, descontos, ISS, tributos federais, suspensão, tributos aproximados, IBS/CBS, NBS e diferimento. Não recalcula tributos nem completa classificações por tabela padrão.
- **NfseApiConfiguracao:** origem dos dados de empresa/prestador/A1, ambiente NFS-e, IE opcional, IM numérica em 15 posições, resolução de município pelo CEP, validação local do A1 e numeração por série. A modalidade de sincronização dos parâmetros dispensa reenviar o certificado.

## 3. PainelApiService: trocar somente a saída NFS-e

Antes da declaração da classe, manter:

```php
require_once __DIR__ . '/NfseApiPhpService.php';
```

Preservar a assinatura e substituir o corpo de `postNfse`:

```php
public static function postNfse($endpoint, $payload, $timeout = 120)
{
    return NfseApiPhpService::dispatch($endpoint, $payload, (int) $timeout);
}
```

Não remover ou alterar `postHorse`, pagamentos, cobranças ou autenticação de outros serviços para migrar NFS-e. Não copiar as credenciais antigas dessa classe para as três classes novas. As chamadas NFS-e não devem voltar a usar `urlNfse()` e `headersNfse()` do gateway C#.

## 4. NFSeService: métodos e trechos obrigatórios

No início do arquivo, antes da classe:

```php
require_once dirname(__DIR__) . '/service/NfseApiPayload.php';
require_once dirname(__DIR__) . '/service/NfseApiConfiguracao.php';
```

O anexo `referencia/NFSeService-trechos.md` contém os métodos completos, com linhas de referência. As linhas podem mudar após exportar; localize pelo **nome do método**.

| Método | O que deve permanecer |
|---|---|
| `configurarCliente` | Chamar a sincronização completa, incluindo A1 |
| `sincronizarParametros` | Sincronizar sem exigir/reenviar A1 |
| `sincronizarConfiguracao` | Ler empresa/parâmetros da unidade, montar por `NfseApiConfiguracao`, fechar transação antes do HTTP e conferir sucesso |
| `emitirNFSe` | Delegar emissão ao adaptador; não remover IBS/CBS em tentativas posteriores |
| `emitirNFSePorId` | Fluxo de gravação de número/chaves/XML/retorno, status e valores autorizados; manter compatível com o retorno adaptado |
| `inscricaoMunicipal` | Preservar a normalização numérica para 15 posições |
| `montarIbsCbs` | Obrigatório nesta integração; exigir IndOp/cClassTrib/NBS; CST com zeros à esquerda e diferimento informado |
| `cepTomador` | Usar o CEP real do tomador; não substituir pelo CEP do prestador |
| `montarPayload` | Localização real de empresa/tomador, IBS/CBS sempre montado, código municipal do item e conversão nativa ao final |
| `proximoNumeroRps` | Buscar o maior RPS somente na série e unidade selecionadas |
| `cancelarNotaFiscalCabecalho` | Guardar XML/ID/data/motivo do evento e status cancelado; preservar o JSON/XML de autorização ao acrescentar `cancelamento` |
| `extrairEventoCancelamento` | Aceitar `xmlEvento` e `sucesso` do adaptador, além do formato antigo INI |
| `requestNfseApi` | Manter a passagem por `PainelApiService::postNfse`, que agora delega à API PHP |

No final de `montarPayload`, o fluxo deve continuar equivalente a:

```php
$codigoMunicipal = trim((string) $itens[0]->codigo_tributacao_municipal);
if ($codigoMunicipal !== '') {
    if (!preg_match('/^\d{3}$/', $codigoMunicipal)) {
        throw new Exception('Código de tributação municipal do item deve ter 3 dígitos.');
    }
    $dados['_codigo_tributacao_municipal'] = $codigoMunicipal;
}
$dados['_aliquota_iss'] = self::valorNota($nota->aliq_iss);
$payload = NfseApiPayload::converter($dados);
$payload['pedido_id'] = 'mad-' . (int) $nota->system_unit_id . '-' . (int) $nota->id;
$payload['_cnpj'] = $cnpjEmpresa;
return $payload;
```

`_cnpj` e `nota_id` são metadados internos; o cliente os retira antes do JSON fiscal. Não recrie o antigo JSON ACBr como corpo final do POST.

Eliminar a antiga constante/condição `OMITIR_IBSCBS_HOMOLOGACAO`. Não deixar `$ibsCbs = null` no lugar de `self::montarIbsCbs($nota)`. A diferença de posição dos campos XML entre produção e homologação é resolvida pela API, não pela omissão do grupo no emissor.

## 5. Telas e assistente inicial

### ParametrosGeraisForm

Preservar **todo** o `onSave` do anexo. Colocar somente a chamada à API em cima do método antigo não mantém as regras necessárias.

O fluxo é: validar ambiente/série/último → salvar apenas a unidade da sessão → fechar transação → chamar `NFSeService::sincronizarParametros()` → alinhar último local ao próximo confirmado pela API → mostrar sucesso ou continuar o assistente. Se a API falhar depois do commit, informar que o local foi salvo e a sincronização ficou pendente.

Manter nomes dos campos e rótulos:

| Campo | Rótulo | Regra |
|---|---|---|
| `ambiente_fiscal_id` | Ambiente NFS-e | 1 produção / 2 homologação |
| `ultimo_numero_nfse` | Último número RPS/DPS | Último + 1, respeitando números já atribuídos/reservados |
| `serie_nfse` | Série RPS/DPS | 1..49999; contadores independentes por série/ambiente |

Não confundir esse último número com o número da NFS-e atribuído pela Sefin. O assistente sincroniza parâmetros **antes** de avançar para o certificado; por isso essa modalidade não exige A1.

### EmpresaForm e CertificadoDigitalForm

As duas telas já tinham chamadas de `NFSeService::configurarCliente` no fonte exportado. Preserve o vínculo no editor. A assinatura atual lê os dados pela unidade da sessão; a chamada recomendada é sem parâmetros:

```php
// Depois de gravar/confirmar os dados locais e fechar sua transação:
$retornoConfig = NFSeService::configurarCliente();
if (empty($retornoConfig['sucesso'])) {
    throw new Exception($retornoConfig['mensagem'] ?? 'Falha ao configurar a NFS-e na API PHP.');
}
```

Na etapa inicial de empresa, sem certificado cadastrado, preserve o encaminhamento do assistente para parâmetros/certificado. Na tela do A1, grave primeiro o base64 e a senha pelo mecanismo existente do emissor, depois chame a sincronização completa. Não mantenha uma transação externa aberta durante a chamada: o serviço abre/fecha a própria leitura antes do HTTP.

As chamadas antigas com `$empresa, $parametros` não representam o contrato novo e devem ser normalizadas no editor. A rotina não deve voltar a montar `certificado_Base64`, `Senha_Certificado` ou o ambiente 0/1 do gateway C# diretamente nessas telas.

## 6. Modelo de dados e migrations

Cadastrar no **modelo de dados do MadBuilder**, para que o gerador produza esses atributos novamente:

| Tabela / model | Campo | Tipo no MySQL/MariaDB | Finalidade |
|---|---|---|---|
| `nota_fiscal_cabecalho` / `NotaFiscalCabecalho` | `mensagem_retorno_nfse` | LONGTEXT, permite NULL | Retorno completo, XML autorizado e cancelamento |
| `nota_fiscal_detalhe` / `NotaFiscalDetalhe` | `codigo_tributacao_municipal` | VARCHAR(3), permite NULL | Código municipal informado pelo item |

Os modelos exportados precisam conter, respectivamente:

```php
parent::addAttribute('mensagem_retorno_nfse');
```

```php
parent::addAttribute('codigo_tributacao_municipal');
```

Na instalação local esses campos **já existem**. Não repetir `ALTER TABLE ADD COLUMN` sem conferir o schema. Os scripts de referência estão em `integracoes/madbuilder/migrations/20261102-retorno-nfse.sql` e `20261102-codigo-municipal-item.sql` na API. Aplicar migrations em outra instalação exige credencial administrativa; a credencial normal da aplicação não tem permissão de DDL.

## 7. Código municipal na edição visual dos itens

O campo foi criado no banco/model e é lido na montagem, mas **não foi acrescentado visualmente ao formulário de itens nesta implementação**. A nota de teste recebeu o valor específico por atualização controlada. Para os novos itens serem editáveis pelo usuário após exportar, acrescente no editor um campo opcional ligado ao detalhe, com máximo de 3 caracteres e validação `^\d{3}$` quando preenchido.

No projeto atual, `NotaFiscalCadItemForm` trabalha com uma lista de detalhes, não apenas um registro simples. Propague `codigo_tributacao_municipal` em todos os pontos dessa lista: inclusão do item, edição/carregamento, campos ocultos/serialização da linha e gravação de `NotaFiscalDetalhe`. Não basta adicionar um TEntry ao cabeçalho da nota. Preservar o valor ao editar quantidade ou preço de um item existente.

Não usar `100` como default global. Esse foi o código confirmado para o item da nota 60. Se desejar herdá-lo de um cadastro de serviço/natureza, criar também o campo nesse cadastro e copiar explicitamente para o detalhe ao incluir o item. Essa herança ainda não foi implementada e não deve ser presumida pelo guia.

## 8. Configuração local e segredos

Distribuir `app/config/nfse-api.example.php`, sem chave real. Manter `nfse-api.local.php` separado da exportação e fora do versionamento. Alternativamente, configurar `NFSE_API_CONFIG` com caminho protegido fora da pasta pública.

```php
return [
    'url' => 'http://host.docker.internal:8081/api/v1',
    'empresas' => [
        'CNPJ_DA_EMPRESA' => ['chave' => 'CHAVE_RESTRITA', 'empresa_id' => ''],
    ],
];
```

PHP no Windows usa `http://localhost:8081/api/v1`; PHP dentro do Docker local usa `host.docker.internal`. Online usa HTTPS e a chave da instalação online. Não colocar chaves Supabase/B2 nem senha do A1 no código do cliente, no JavaScript, na URL ou na documentação exportada. Não inserir uma chave administrativa para todos os caixas.

## 9. Procedimento de exportação e conferência

1. Registrar classes, trechos e campos no projeto do editor e salvar uma versão dele.
2. Exportar para **outra pasta**, preservando o projeto funcional e seus arquivos locais.
3. Conferir as três classes personalizadas e os hooks. Comparar os métodos com os anexos, sem sobrescrever as outras integrações.
4. Conferir models e migrations. Preservar `nfse-api.local.php` e conexões locais.
5. Instalar dependências da exportação usando seu composer.lock. Conferir a sintaxe dos PHP alterados.
6. Rodar a conferência de referência abaixo. Um hash diferente exige comparação; pode ser apenas formatação/alteração legítima do gerador, não significa automaticamente defeito.
7. Testar GET `/tenant`, `/config` e `/nfse/config`; comparar CNPJ e ambientes. Testes fiscais adicionais devem ser **dry_run**, com numeração/status verificados antes/depois.

```powershell
# Somente leitura de arquivos; execute dentro do emissor no Docker:
docker exec -w /var/www/html/emissor_servicos madbuilder-local-web-1 php /var/www/html/emissor/api-fiscal/integracoes/madbuilder/documentacao/conferir-exportacao.php /var/www/html/emissor_servicos

# Contrato com transporte simulado, na API:
docker compose --env-file .env -f docker/compose.yml exec -T web php integracoes/madbuilder/tests/contrato.php
```

Não usar os scripts `transmitir-nota60-homologacao.php`, `cancelar-nota60-homologacao.php` ou `testar-parametros.php` como simples teste de exportação: os dois primeiros são operações reais e o último salva parâmetros. A nota 60/NFS-e 360 já está cancelada; não deve ser reemitida para validar o projeto.

## 10. Limites conhecidos e itens que não devem ser afirmados como concluídos

- `NFSeService` ainda possui uma chamada legada a `preview-xml`, sem case correspondente no adaptador. Para restaurar a prévia dessa tela, mapear esse endpoint para `/nfse?dry_run=1` e adaptar `valida/xml/erros_schema`; este guia não altera essa funcionalidade.
- A edição visual do código municipal do detalhe e eventual herança do cadastro precisam ser implementadas no editor (seção 7).
- As regras e métodos internos da API PHP, Compose, `.env` da API e banco PostgreSQL não são arquivos para colar no projeto MadBuilder. Permanecem no projeto da API.
- A conferência de hashes não substitui testar a exportação. O guia e os anexos não modificam automaticamente o projeto salvo no editor.
