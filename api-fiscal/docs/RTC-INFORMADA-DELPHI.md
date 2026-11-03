# Reforma Tributária informada pelo Delphi

O modo `informado` recebe os grupos que `TNFCe.GerarNFe` preenche no ClickPDV (`UNFCe.pas`, blocos de itens e totais RTC). A API monta o XML, valida o XSD, assina e transmite, preservando os valores do Delphi. Não chama o calculador RTC antigo, não aplica o padrão RTC da empresa, não calcula crédito, base, alíquota, valor de imposto ou total RTC e não arredonda os campos informados.

O novo contrato não altera os dados nem o código do PDV automaticamente. O Delphi precisa enviar os novos objetos e os totais. Tanto `/api/v1/nfe` quanto `/api/v1/pdv/nfce` exigem modo informado quando existem grupos IBS/CBS ou IS. Sem esses grupos, não há classificação ou cálculo RTC automático.

## Como ativar

Dentro de `nota`, no POST do PDV:

```json
{
  "reforma_tributaria": {
    "modo": "informado",
    "totais": {
      "IBSCBSTot": {
        "vBCIBSCBS": "10.00",
        "gIBS": {
          "gIBSUF": { "vDif": "0.00", "vDevTrib": "0.00", "vIBSUF": "0.01" },
          "gIBSMun": { "vDif": "0.00", "vDevTrib": "0.00", "vIBSMun": "0.00" },
          "vIBS": "0.01",
          "vCredPres": "0.00",
          "vCredPresCondSus": "0.00"
        },
        "gCBS": {
          "vDif": "0.00", "vDevTrib": "0.00", "vCBS": "0.09",
          "vCredPres": "0.00", "vCredPresCondSus": "0.00"
        }
      },
      "vNFTot": "10.00"
    }
  }
}
```

Em cada item, além dos campos comerciais e ICMS/PIS/COFINS já exigidos:

```json
{
  "valor_item": "10.00",
  "impostos": {
    "ibscbs": {
      "CST": "000",
      "cClassTrib": "000001",
      "gIBSCBS": {
        "vBC": "10.00",
        "gIBSUF": { "pIBSUF": "0.1000", "vIBSUF": "0.01" },
        "gIBSMun": { "pIBSMun": "0.0000", "vIBSMun": "0.00" },
        "vIBS": "0.01",
        "gCBS": { "pCBS": "0.9000", "vCBS": "0.09" }
      }
    }
  }
}
```

Os trechos acima complementam a nota; não substituem os campos obrigatórios. Exemplo completo: [pdv-nfce-rtc-informada.json](../examples/pdv-nfce-rtc-informada.json).

Todos os escalares dentro dos grupos RTC são **strings**, incluindo códigos, datas, percentuais, quantidades e valores monetários. Use ponto decimal, sem separador de milhar. Exemplos: `"000"`, `"000001"`, `"10.00"`, `"0.1234"`, `"2026-11"`. Valores numéricos JSON nesses grupos são recusados para evitar alteração de escala ou zeros de códigos. Os campos comerciais e os impostos anteriores mantêm seu contrato original.

Omitir grupos opcionais não utilizados. Não enviar null nem objetos vazios. Para doação, usar `indDoacao: "1"`; quando não for doação, omitir a tag. A API ordena as tags conforme o XSD independentemente da ordem das propriedades JSON.

## Grupos cobertos

| Grupo do ClickPDV / ACBr | Caminho JSON |
|---|---|
| `IBSCBS` | `itens[].impostos.ibscbs` |
| `gIBSCBS` | `...ibscbs.gIBSCBS` |
| IBS UF, municipal e CBS | `...gIBSCBS.gIBSUF`, `gIBSMun`, `gCBS` |
| Diferimento, devolução e redução independentes por tributo | `...gIBSUF.gDif/gDevTrib/gRed`, idem município e CBS |
| Tributação regular | `...gIBSCBS.gTribRegular` |
| Tributação de compra governamental | `...gIBSCBS.gTribCompraGov` |
| Monofasia nova | `...ibscbs.gIBSCBSMono.gIBSMonoAdRem/gIBSMonoAdValorem/gCBSMonoAdRem/gCBSMonoAdValorem` |
| Padrão, retenção, retido anteriormente e diferença de biocombustível | Dentro de cada grupo mono: `gMonoPadrao`, `gMonoReten`, `gMonoRet`, `gpBioDiferenca` |
| Totais mono do item | `...gIBSCBSMono.vTotIBSMonoItem`, `vTotCBSMonoItem` |
| Transferência de crédito | `...ibscbs.gTransfCred` |
| Ajuste de competência | `...ibscbs.gAjusteCompet` |
| Estorno de crédito | `...ibscbs.gEstornoCred` |
| Crédito presumido | `...ibscbs.gCredPresOper.gIBSCredPres/gCBSCredPres` |
| Crédito presumido ZFM | `...ibscbs.gCredPresIBSZFM` |
| `ISel` | `itens[].impostos.is`, incluindo `adRemIS`, `uTrib`, `qTrib` |
| `det.vItem` | `itens[].valor_item` |
| `Total.ISTot`, `Total.IBSCBSTot`, `Total.vNFTot` | `reforma_tributaria.totais.ISTot/IBSCBSTot/vNFTot` |

O [mapa completo de campos](RTC-CAMPOS-INFORMADOS.md) e o OpenAPI são gerados do XSD local por `php bin/rtc-contrato.php`.

### Diferenças de nome e preenchimento em relação ao componente Delphi

- O contrato usa nomes das tags XML, não nomes de propriedades internas do ACBr. Nos totais, `gIBSUFTot` do ACBr vira `gIBSUF` e `gIBSMunTot` vira `gIBSMun`.
- Os grupos Ad Rem e Ad Valorem do mesmo tributo são alternativas. `gIBSCBS`, monofasia, transferência e ajuste também são alternativas exclusivas. Não enviar simultaneamente as alternativas.
- Crédito presumido da operação e crédito ZFM são alternativas no schema instalado. Dentro do crédito presumido, observe a alternativa entre `vCredPres` e `vCredPresCondSus`.
- Campos obrigatórios não podem depender de um valor default do componente. O Delphi deve enviar explicitamente os campos exigidos pelo XSD, inclusive zeros obrigatórios. Por exemplo, quantidade base própria no grupo CBS Ad Rem e o valor CBS no grupo CBS Ad Valorem, se utilizados.
- O bloco IS do Delphi mostrado no estudo preenche valores, mas o contrato também exige `CSTIS` e `cClassTribIS` para formar um grupo válido.

## Validação e transmissão

Campos desconhecidos, excesso de casas decimais, códigos fora do formato, campos obrigatórios ausentes e escolhas incompatíveis são recusados com 422, sem arredondamento ou descarte silencioso. A API não consulta tabelas de CST/cClassTrib. Ela verifica em centavos a igualdade entre a soma de `valor_item` e `vNFTot`. Essa conferência não altera os valores.

A seleção de CST, cClassTrib e grupos permitidos para cada modelo é responsabilidade de quem envia o JSON. A API valida os campos pelo XSD, sem resolver a classificação por tabela local.

O modo informado valida a estrutura; não implementa todas as regras de negócio da SEFAZ nem substitui os cálculos e a seleção tributária do Delphi. Os testes são locais, com SEFAZ simulada. É necessário homologar os cenários utilizados pela empresa.

Os grupos novos são montados antes da assinatura XMLDSig. A nota assinada e o envelope `enviNFe` são validados contra o PL_010f_v1.04 local. O adaptador `SefazTools` mantém a assinatura/QR-Code/transporte da sped-nfe e usa o XSD local nessas duas etapas; eventos continuam usando os schemas da biblioteca. Nenhum arquivo de vendor ou XSD oficial original foi alterado. Referência da biblioteca: [TraitTagDetIBSCBS](https://github.com/nfephp-org/sped-nfe/blob/master/src/Traits/TraitTagDetIBSCBS.php).

Autorização, impressão, contingência e recuperação por `operacao_id` continuam seguindo o [contrato do PDV](PDV-NFCE.md). O XML persistido já contém os valores informados; a fila não reconstrói nem recalcula tributos ao recuperar a operação.
