# Nota 60 — autorização em homologação

Operação realizada em 02/11/2026, exclusivamente no ambiente 2 da Sefin Nacional de produção restrita, por solicitação do usuário. Nenhum envio em produção foi realizado.

## Resultado final

| Informação | Resultado |
|---|---|
| Empresa | 11229442000120 |
| Nota no emissor | 60 |
| NFS-e autorizada | **360** |
| Série / RPS-DPS | 1 / 148, preservados |
| Status API | autorizada |
| Status emissor | 2 — AUTORIZADA |
| Chave NFS-e | 13026032211229442000120000000000036026119653745015 |
| Referência DPS | DPS130260321122944200012000001000000000000148 |
| Valor do serviço / líquido gravado | R$ 100,00 / R$ 100,00 |
| ISS gravado a partir do XML | R$ 2,00 |
| XML | NFSe autorizado, ambiente 2, válido no XSD 20261020 |
| Integridade | Duas assinaturas RSA-SHA256 e seus digests verificados |
| Correspondência | XML salvo no retorno do emissor idêntico ao XML da API por SHA-256 |

SHA-256 do XML: `6c35186c2581ce2d9cc8fbcae11e8d1592fa624af43ac184476b85ac95479dd4`.

O retorno completo, contendo `xmlRetorno`, foi gravado em `nota_fiscal_cabecalho.mensagem_retorno_nfse`, junto do número, chaves, data, status e valores devolvidos pela Sefin. As outras notas foram comparadas antes/depois e permaneceram inalteradas.

## Dados confirmados para esta emissão

O tomador foi identificado com o CPF e cadastro corrigidos pelo usuário, incluindo endereço nacional. O documento pessoal não é reproduzido neste relatório.

Após as rejeições de cadastro, o usuário forneceu os dados de um XML já autorizado. Estes foram aplicados somente à nota 60 e ao seu item:

```text
Município de prestação: 1302603
cTribNac: 010501
cTribMun: 100
xDescServ: Nota de Servico referente ao vencimento 30/09/2026
cNBS: 111032200
cIndOp: 100501
```

A competência da nota permaneceu 02/11/2026. A data escrita na descrição não foi convertida automaticamente em competência. CST 000 e cClassTrib 000001 foram mantidos conforme a origem. O código municipal 100 é um dado do item, não um padrão da API ou de outras notas.

## Tratamentos necessários durante o teste

1. **Persistência do retorno:** adicionado o campo LONGTEXT `mensagem_retorno_nfse` ao banco e ao modelo do emissor, ausente no dump/modelo anterior.
2. **E0008 — horário:** o relógio local estava aproximadamente dois minutos à frente da data retornada pela Sefin. Foi configurado `NFSE_DHEMI_RECUO_SEGUNDOS=180`, com limite de 30..300 segundos no gerador. O sistema operacional não teve o relógio alterado.
3. **E0239 — tomador:** identificado o cliente conforme informado pelo usuário; completado o endereço no cadastro pelo próprio usuário. O emissor deixou de usar o CEP do prestador como fallback do tomador e resolve o município pelo CEP real do cliente.
4. **E0116 — IM:** a sincronização passou a usar o formato que o emissor anterior já utilizava: IM numérica completada até 15 posições. Para esta empresa, `000000013064001`. A API preserva a string enviada.
5. **E0312 — serviço:** os códigos inicialmente usados foram rejeitados. A emissão foi autorizada após o usuário fornecer os campos do XML autorizado acima.
6. **Reenvio sem duplicação:** consultas prévias confirmaram a ausência de NFS-e para a DPS antes das correções. A referência, série e RPS foram mantidos em todas as tentativas. As DPS anteriores foram arquivadas em `nfse/tentativas/` na persistência fiscal.

Foi acrescentado ao item do emissor o campo opcional `codigo_tributacao_municipal`, enviado como `servico.codigo_tributacao_municipal`. Os scripts SQL e os ajustes de modelo estão descritos em `integracoes/madbuilder/migrations/`; preserve-os ao reexportar pelo MadBuilder.

## Rotas de recuperação implementadas

- `POST /api/v1/nfse/{ref}/reenviar-horario`: somente homologação, rejeição E0008 sem outros erros; corrige dhEmi com margem limitada, valida e assina novamente a mesma DPS.
- `POST /api/v1/nfse/{ref}/corrigir`: somente homologação e rejeição definitiva; recebe o JSON corrigido com a mesma série/número e confirma ausência de autorização antes do reenvio.

Ambas exigem papel de proprietário e `Idempotency-Key`. Não reenviam quando a consulta prévia é inconclusiva. Se encontrarem autorização, recuperam o documento. Timeout/estado em processamento usa `/sincronizar`, não a rota de correção.

O adaptador consulta o estado atual antes de transmitir. Autorizadas são recuperadas sem POST de emissão; erro/processando são sincronizados; rejeitadas usam a rota de correção. A chave da tentativa corrigida considera o JSON e o contexto fiscal do prestador (inclusive IM), sem timestamps/contadores, para não repetir o cache de uma rejeição após corrigir o cadastro. Repetir a mesma tentativa não gera novo envio.

## Artefatos privados e estado ao encerrar

Pasta: `D:\fontes\php\www\emissor\.migration-backups\transmissao-homologacao-nota60-20261102-213410\`.

- `nfse-autorizada.xml`: XML original recebido, sem reformatação.
- `retorno-emissor.json`: retorno completo salvo pelo emissor.
- `registro-api.json`: registro fiscal autorizado.
- `resultado.json`: resumo da transmissão.
- `verificacao-xml.json`: resultado do XSD, assinaturas e digests.
- `verificacao-emissor.json`: comparação entre banco do emissor e XML da API.

Os XML/JSON fiscais contêm dados do tomador; não são arquivos públicos. A1 e senha não foram incluídos nesses artefatos. O banco anterior ao envio foi preservado em `.migration-backups/transmissao-nota60-20261102-210612/`.

Na ocasião deste teste, foi restaurado o bloqueio global de transmissão existente naquela versão. Essa configuração foi posteriormente removida: a emissão segue o ambiente NFS-e da empresa. A margem de 180 segundos permaneceu configurada; sincronize o relógio do host para tratar a causa da diferença. Empresa e parâmetros do emissor continuam em homologação. A nota 60 não deve ser emitida novamente: já possui autorização 360; use consulta/download.
