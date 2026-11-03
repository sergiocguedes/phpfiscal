# Reforma tributária: dados informados pelo consumidor

A API não mantém tabelas de CST/cClassTrib, classificação padrão por empresa ou cálculo automático de IBS/CBS. O consumidor informa códigos, bases, alíquotas, valores e totais no JSON.

Para NF-e/NFC-e, use [RTC-INFORMADA-DELPHI.md](RTC-INFORMADA-DELPHI.md) e o [mapa de campos do XSD](RTC-CAMPOS-INFORMADOS.md). O comando `php bin/rtc-contrato.php` gera o mapa estrutural a partir dos XSD, sem tabela de classificação tributária.

A API preserva a precisão informada, recusa campos desconhecidos e valida a estrutura XML com os schemas locais. As regras tributárias e a seleção de CST/cClassTrib pertencem ao consumidor; a autorização e as regras de negócio externas continuam a cargo da Sefaz.

Os endpoints de padrões e consulta de classificações `/api/v1/reforma-tributaria` e `/api/v1/reforma-tributaria/classificacoes` foram removidos.
