# IBS/CBS informado — NFS-e Padrão Nacional

Implementação de classificação e diferimento usados pelo método `NFSeService::montarIbsCbs($nota)` do emissor MadBuilder. Não há tabelas de CST/cClassTrib nem cálculo de valores de IBS/CBS na API. Os dados vêm do consumidor; os totais eventualmente devolvidos pela Sefin permanecem no XML autorizado.

Exemplo completo: [nfse-ibs-cbs-informados.json](../examples/nfse-ibs-cbs-informados.json). Os códigos do exemplo demonstram o contrato e não determinam a classificação fiscal de outra operação.

## JSON

```json
{
  "servico": {
    "codigo_tributacao_nacional": "140101",
    "nbs": "115021000",
    "descricao": "Serviço prestado"
  },
  "valores": { "servico": "100.00" },
  "ibs_cbs": {
    "finalidade": 0,
    "consumidor_final": 1,
    "indicador_operacao": "100301",
    "indicador_destinatario": 0,
    "cst": "000",
    "classificacao_tributaria": "000001"
  }
}
```

| Campo | Regra | Origem ACBr/MadBuilder |
|---|---|---|
| `finalidade` | Inteiro 0, NFS-e regular | `IBSCBSDPS.finNFSe` |
| `consumidor_final` | Inteiro 0 ou 1 | `IBSCBSDPS.indFinal` |
| `indicador_operacao` | String, 6 dígitos | `IBSCBSDPS.cIndOp` / `nota.indop` |
| `indicador_destinatario` | Inteiro 0, destinatário igual ao tomador | `IBSCBSDPS.indDest` |
| `cst` | String, 3 dígitos | `gIBSCBS.CST` |
| `classificacao_tributaria` | String, 6 dígitos | `gIBSCBS.cClassTrib` / `nota.cclasstrib` |
| `servico.nbs` | String, 9 dígitos; obrigatório com IBS/CBS | `servico.codigoNBS` / `nota.nbs` |

Os seis campos do objeto `ibs_cbs` são obrigatórios quando o grupo é informado. `null`, objetos vazios, campos desconhecidos e formatos incorretos retornam 422. Códigos numéricos JSON não substituem strings: preserve zeros à esquerda. Notas de ajuste, destinatário distinto, imóveis e demais grupos RTC ainda não implementados são recusados quando informados como campos extras; não são silenciosamente descartados.

O contrato geral mantém compatibilidade com consumidores que omitem o grupo conforme seu fluxo anterior. **O emissor MadBuilder passou a exigir a classificação em todas as suas montagens**: ausência ou preenchimento parcial de IndOp/cClassTrib/NBS bloqueia a geração com mensagem de cadastro incompleto. A antiga exceção que removia IBS/CBS em homologação foi eliminada. Não há reenvio automático removendo o grupo para tentar autorizar.

## Diferimento

Dentro de `ibs_cbs`, opcionalmente:

```json
"diferimento": {
  "percentual_uf": "0.00",
  "percentual_municipal": "12.50",
  "percentual_cbs": "100.00"
}
```

Os três percentuais devem ser informados juntos, entre 0 e 100, com até duas casas. São serializados com duas casas, preservando zero explícito. Não se calculam valores a partir desses percentuais. O emissor usa o percentual `nota.aliq_difer` nos três campos quando o CST é 510, seguindo sua montagem existente; a API também aceita percentuais diferentes entre UF, município e CBS, desde que explicitamente enviados.

## Posição dos campos no XML

| Campo/grupo | Produção, XSD 20260209 | Homologação, XSD 20261020 |
|---|---|---|
| finalidade | `infDPS/IBSCBS/finNFSe` | `infDPS/finNFSe` |
| indicadores | `infDPS/IBSCBS/{indFinal,cIndOp,indDest}` | mesmo caminho neste pacote XSD |
| CST/cClassTrib | `IBSCBS/valores/trib/gIBSCBS/{CST,cClassTrib}` | `IBSCBS/valores/trib/{CST,cClassTrib}` |
| diferimento | `IBSCBS/valores/trib/gIBSCBS/gDif` | mesmo caminho |
| NBS | `infDPS/serv/cServ/cNBS` | mesmo caminho, respeitando a ordem do novo `cServ` |

O adaptador envia um JSON único e a API seleciona a posição conforme o XSD do ambiente. Não foram alterados os schemas oficiais para acomodar o código. Fontes: [documentação de homologação e esquemas 20261020](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/producao-restrita), [documentação de produção](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual) e [NT 009 v1.01](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc/nota-tecnica-009-se-cgnfs-e-v-1-01.pdf). A NT descreve evoluções mais amplas; este escopo implementa os campos usados pelo método do emissor e valida contra os pacotes instalados.

## Teste sem transmissão

Use `POST /api/v1/nfse?dry_run=1` com chave da empresa e `ambiente_nfse=2`. O resultado contém uma DPS não assinada, sem protocolo de autorização. O teste local não confirma regras externas de habilitação/classificação da Sefin.

Validação real em 02/11/2026 para 11229442000120: nota 60, série 1, DPS 148, valor R$ 100,00; CST `000`, cClassTrib `000001`, IndOp `100301`, NBS `115021000`. HTTP 200, nenhum erro no XSD 20261020 e campos conferidos no XML por XPath. Registro original e numeração permaneceram inalterados; nenhuma NFS-e foi criada ou transmitida.

Artefatos privados: `.migration-backups/homologacao-madbuilder-20261102-205847/` na pasta da API, com `payload-nfse.json`, `dps-homologacao.xml` e `resultado.json`. Não contêm A1 ou senha. O teste automatizado cobre os dois leiautes, classificação com zeros à esquerda, diferimento, percentuais inválidos, grupos incompletos e campos sem suporte.
