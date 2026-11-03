# Comparação da NFS-e: Horse Delphi e API PHP

Análise estática realizada em 02/11/2026. Nenhuma nota foi transmitida e nenhuma rotina fiscal foi alterada nesta tarefa.

Atualização posterior: tributos federais, retenções, suspensão e deduções simples (valor ou percentual) foram implementados. Consulte `NFSE.md` e `examples/nfse-tributos-informados.json`. A matriz abaixo registra a situação encontrada antes dessa implementação. Conforme a definição da integração, o envio de IBS/CBS não integra esta etapa; os cálculos retornados pelo serviço nacional permanecem no XML autorizado.

## Escopo e conclusão

Fonte Delphi: `D:/fontes/lsisistemas/trunk/lsiclick/Projetos/Projetos clientes/ConnectWeb/Servidor Horse DFe/`.
Implementação PHP considerada: `api-fiscal/src/Nfse/`, edição ativa no Docker.

**A API PHP ainda não substitui integralmente a geração de NFS-e do Horse.** O núcleo básico está presente, mas faltam tributos federais, suspensão de exigibilidade, tratamento de deduções/ajustes e os dados declarados de IBS/CBS. A ampliação anterior de reforma tributária de NF-e/NFC-e não implementa esses grupos na NFS-e.

O fluxo Delphi analisado força `proPadraoNacional` e `lnfsPadraoNacionalv101` em `UdmNFSe.pas:746`; portanto, a comparação é com NFS-e Nacional, e não pressupõe necessidade de implementar provedores municipais distintos.

Esta análise verifica atribuições no código e os XSD disponíveis no repositório. Sem compilar o ACBr usado pelo Horse e comparar documentos efetivamente gerados, uma atribuição em componente não comprova que a tag chega à DPS transmitida. Não é uma certificação das regras fiscais em vigor.

## Matriz funcional

| Recurso do Delphi | Situação no PHP | Ação |
|---|---|---|
| Prestador CNPJ, inscrição municipal, Simples e regimes | Presente na configuração e DPS | Mapear os códigos de domínio; não copiar os IDs internos do banco |
| Série, número e competência | Presente | Converter `NUMERO_LOTE` para número da DPS; separar do número da NFS-e autorizada |
| Data/hora de emissão informada no Delphi | PHP gera a hora no servidor, com margem de 30 segundos | Documentar a diferença; avaliar data explícita somente se necessária |
| Código nacional, municipal, NBS e discriminação | Presente | Normalizar códigos e enviar a descrição em `servico.descricao` |
| Município de prestação | Presente; PHP permite informar explicitamente | Delphi usa o município da empresa neste gerador |
| Tomador CPF/CNPJ, IM, nome, endereço, contato | Presente para tomador nacional | Sem identificação: omitir `tomador`; não enviar CPF/CNPJ preenchido com zeros |
| Valor do serviço e descontos condicionado/incondicionado | Presente | Enviar os valores já classificados; não precisa migrar a opção interna `USA_DESCONTO_INCONDICIONAL` |
| ISS tributável, imunidade, não incidência, retenção pelo tomador e alíquota | Presente, com validações próprias | Comparar os cenários de regime/retenção; alíquota pode ser omitida pelo PHP conforme a regra existente |
| Suspensão de exigibilidade: tipo e processo | Ausente | Implementar `tribMun/exigSusp/tpSusp` e `nProcesso` |
| `ValorDeducoes` | Ausente | Criar contrato de deduções/ajustes e serialização por versão do XSD |
| PIS/COFINS: CST, base, alíquotas e valores | Ausente | Implementar `tribFed/piscofins`, recebendo os valores do Delphi |
| Retenção de IRRF e CSLL; tipo de retenção PIS/COFINS | Ausente | Implementar campos e validar sua relação, sem recalcular silenciosamente |
| Tributos aproximados federal/estadual/municipal e percentual do Simples | Presente | Mapear para `tributos_aproximados`; não confundir com valores de PIS/COFINS ou IBS/CBS |
| IBS/CBS declarado na DPS | Ausente | Implementar contrato próprio de NFS-e e montagem por leiaute |
| Assinatura e transmissão nacional | Presente | PHP usa certificado A1; configuração WinCrypt/serial do Windows não se transfere diretamente para o container |
| Consulta por referência e recuperação de DPS | Presente para documentos locais | `sincronizar` consulta DPS para obter chave e depois baixa a NFS-e |
| Consulta/importação de documento externo por chave ou DPS | Sem uma operação explícita equivalente de importação | Implementar se for necessário recuperar notas emitidas antes da migração |
| Cancelamento | Presente | Adaptar o contrato de código/motivo e tratar resultado da operação |
| Impressão/PDF | Presente | Usar `GET /api/v1/nfse/{ref}/danfse`; XML em `/xml` |
| XML autorizado no mesmo JSON de emissão | Não está presente no retorno normal | Acrescentar `xml` da NFS-e autorizada se esse for o contrato desejado; hoje retorna `links.xml` |
| Cálculo de IBS/CBS | Horse possui rota de cálculo; PHP não tem equivalente NFS-e | Manter fora desta etapa: cálculos continuam no Delphi |

## Evidências e diferenças importantes

### Tributos federais

`UdmNFSe.pas:230–285` preenche CST, base, alíquotas e valores de PIS/COFINS, IRRF, CSLL e tipo de retenção dentro do ramo de não optante do Simples. Quando os valores de PIS/COFINS da nota são zero, o Delphi calcula valores a partir da base e da alíquota.

No PHP, `DpsBuilder.php:508–519` passa de `tribMun` diretamente a `totTrib`; não há montagem de `tribFed`.

Há um ponto que exige revisão antes de reproduzir o comportamento: o Horse soma `VALOR_CSLL + vCofins + vPis` em `vRetCSLL` (`UdmNFSe.pas:275–282`). O comentário do Delphi explica a intenção de agregar retenções, mas os XSD locais descrevem `vRetCSLL` como valor de CSLL. Não transportar essa soma automaticamente: confrontar o tipo de retenção, documentação aplicável e um XML autorizado representativo. Separar no JSON valores de apuração e valores retidos.

### Suspensão e deduções

O Horse atribui suspensão e processo somente quando `ID_TIPO_SUSPENSAO_EXIGIBILIDADE = 2` (`UdmNFSe.pas:183–192`). Isso demonstra um caso utilizado pelo código, não cobertura de todas as hipóteses.

`ValorDeducoes` é atribuído em `UdmNFSe.pas:156`, mas não há comprovação nesta análise de como a versão instalada do ACBr o serializa. Os XSD locais têm estruturas diferentes: `vDedRed` no pacote `1.01-20260209` e `vAjusteBC` no pacote `1.01-20261020`. Portanto, um campo genérico `deducoes` sem adaptação por ambiente/leiaute não basta.

### Reforma tributária: declarar na DPS e ler no retorno

O código ativo do Horse informa, condicionado à ativação da reforma:

- `tpOper` e `tpEnteGov` para cliente de governo;
- `finNFSe`, `indFinal`, `cIndOp` e `indDest`;
- CST e classificação tributária em `IBSCBS.valores.trib.gIBSCBS`.

Referência: `UdmNFSe.pas:391–427`.

Também atribui `infNFSe.IBSCBS.valores.vBC`, localidade de incidência e `totCIBS.vTotNF` (`UdmNFSe.pas:467–485`). Essas atribuições estão na estrutura de NFS-e, distinta da informação declarada na DPS. A API deve enviar os campos permitidos pela DPS e preservar os valores efetivamente retornados pela Sefin no XML autorizado. Não deve fabricar uma NFS-e autorizada com totais locais.

Os seguintes trechos estão comentados no gerador Delphi e **não foram considerados funcionalidades ativas**:

- crédito presumido `cCredPres`;
- `gTribRegular` e `gDif` da informação declarada;
- `pRedutor` e `vCalcReeRepRes`;
- grande bloco de totais de IBS/CBS, compra governamental e créditos presumidos (`UdmNFSe.pas:490–588`).

O PHP já possui XSD com grupos RTC, mas `DpsBuilder` não os gera. Ter schema ou DTO com os campos não significa implementar o recurso.

Existe diferença de estrutura nos pacotes locais: no `20260209`, `finNFSe` está em `IBSCBS`; no `20261020`, está em `infDPS` e já é gerado pelo PHP. No pacote `20261020`, CST e `cClassTrib` aparecem em `IBSCBS/valores/trib`, e `gIBSCBS` é um grupo subordinado opcional. O contrato deve manter nomes estáveis e o serializador deve adaptar a posição exata por versão; copiar a hierarquia do componente ACBr literalmente pode produzir XML inválido.

### Integração e recuperação

As rotas de emissão do Horse leem credenciais de banco e IDs, conectam ao banco e executam `LoadFromQuery_nfse` ou `LoadFromQuery_nfce` (`UdmNFSe.pas:701–780` e `857–936`). Embora exista `TNFSeDTO.LoadFromJSON`, ele não é a carga utilizada nesses dois fluxos. `EmiteNFSe_api` está vazio (`UdmNFSe.pas:852`).

Na arquitetura desejada, o Delphi deve consultar seu banco e enviar os dados fiscais em JSON para a API PHP. Não é necessário implementar acesso ao banco do PDV no PHP. Será necessário um adaptador de contrato no Delphi; trocar apenas a URL do Horse não funciona.

`NfseService.php:97–108` salva a DPS assinada antes da transmissão. O serviço trata falhas de comunicação e permite sincronizar; também tenta recuperar quando recebe E0014, DPS já convertida. A sincronização (`:237`) não equivale a um mecanismo completo de reenvio automático com a mesma DPS assinada. Preservar a referência e evitar reservar uma nova DPS enquanto houver situação incerta.

Para importação histórica, `sincronizarRef` parte de registro local. Se o objetivo for recuperar XML de qualquer nota antiga do Horse, será necessária operação específica que consulte, valide a titularidade e importe o documento na empresa correta.

`NfseService.php:589` devolve metadados e links, não o conteúdo do XML autorizado. O XML presente em `dry_run` é somente a DPS não assinada, e não o documento autorizado.

### Campos extras no JSON

`DpsBuilder::validatePayload` monta um novo objeto normalizado com os campos conhecidos. Não existe rejeição geral de propriedades desconhecidas. Assim, acrescentar arbitrariamente `rt`, `ibscbs`, deduções ou tributos federais ao JSON não os inclui no XML e pode deixar o cliente acreditar que foram considerados.

Antes da migração, rejeitar explicitamente grupos fiscais ainda não suportados ou implementar sua validação e serialização. Isso deve ter prioridade para evitar perda silenciosa de informação fiscal.

## Implementação recomendada, em ordem

1. Formalizar o JSON de NFS-e, mapear os IDs internos do Delphi para códigos fiscais e rejeitar grupos não suportados. Manter cálculos no Delphi.
2. Implementar PIS/COFINS e retenções federais; revisar a soma usada em `vRetCSLL`. Ajustar a estimativa local de líquido para não continuar considerando somente os campos antigos.
3. Implementar suspensão e deduções/ajustes com serialização específica dos dois pacotes XSD.
4. Implementar RTC declarado na DPS: indicadores da operação, governo, CST e classificação; ampliar grupos adicionais apenas com cenários definidos. Ler e retornar o XML autorizado sem reconstruir seus totais.
5. Padronizar o retorno de autorização e sincronização com XML, referência da DPS, chave da NFS-e, número e estado. Preservar as rotas de download XML/PDF.
6. Avaliar importação de notas antigas e reenvio controlado da mesma DPS assinada após consulta, sem gerar outra numeração durante incerteza.

## Validação necessária para a migração

Comparar DPS e NFS-e de cenários reais anonimizados: Simples com/sem retenção, regime normal com PIS/COFINS e retenções, suspensão, dedução/ajuste e RTC com cliente comum/governo. Verificar os dois pacotes XSD usados pelo PHP, assinatura, precisão decimal e ausência versus zero. Cobrir timeout antes/depois da autorização, repetição da mesma requisição e recuperação por DPS.

Não comparar apenas bytes de XML: IDs, horário e assinatura variam. Comparar campos e caminhos XML, valores, códigos e resultado da autorização. Aprovação no XSD não comprova aceitação das regras de negócio da Sefin.

## Arquivos principais consultados

- Delphi: `UdmNFSe.pas`, `Controller.NFSe.pas`, `uNFSeDTO.pas`, `UNFSeCalculoRTService.pas`.
- PHP: `src/Nfse/DpsBuilder.php`, `NfseService.php`, `NfseXml.php` e documentação `docs/NFSE.md`.
- XSD local: `resources/nfse/schemas/1.01-20260209/tiposComplexos_v1.01.xsd` e `1.01-20261020/tiposComplexos_v1.01.xsd`.

Os caminhos PHP acima são relativos a `api-fiscal/`. Não foram feitas alterações no fonte Delphi.
