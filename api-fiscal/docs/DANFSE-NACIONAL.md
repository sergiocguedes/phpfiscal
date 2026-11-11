# DANFSe nacional v2.0: geração e Base64 na API

A API gera o documento a partir do XML autorizado, na disposição do Anexo I da [NT 008 v1.02, de 14/07/2026](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc/nt-008-se-cgnfse-danfse-20260714-v1-02.pdf), publicada no [portal nacional](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc). A NT informa a suspensão da API de PDF do ADN em 03/08/2026; a chamada padrão utiliza geração local na API Fiscal.

## Retorno para o emissor

```http
GET /api/v1/nfse/{chave_ou_id_dps}/danfse?formato=base64
Authorization: Bearer SUA_CHAVE
```

```json
{
  "pdf_base64": "JVBERi0...",
  "tipo_conteudo": "application/pdf",
  "nome_arquivo": "DANFSe-CHAVE.pdf",
  "origem": "local",
  "modelo": "DANFSe v2.0"
}
```

`JVBERi0...` é somente uma indicação de conteúdo, não um PDF completo. A resposta real contém o documento inteiro em Base64, sem prefixo `data:`. O emissor decodifica o conteúdo para abrir/imprimir; não monta layout, não gera PDF e não recodifica o arquivo recebido. O adaptador mantém o nome `pdfBase64` esperado pelas telas atuais a partir de `pdf_base64` da API.

O endpoint sem `formato`, ou com `formato=pdf`, continua retornando PDF binário (`application/pdf`), para download e outros consumidores. Formato desconhecido retorna 422. Ambos passam pela mesma geração, autenticação e isolamento por empresa, com `Cache-Control: private, no-store`.

`origem=auto` (padrão) e `origem=local` geram o modelo atual na API, sem consultar o ADN nem reutilizar PDF antigo em cache. `origem=adn` permanece somente como opção legada explícita, sem garantia de disponibilidade. O cabeçalho `X-Danfse-Origem` informa a origem. Não é necessário retransmitir notas para obter o novo PDF.

## Modelo e dados

Uma página A4 retrato, logotipo nacional, campos na sequência do Anexo I, fontes e tamanhos previstos na NT, borda externa de um ponto e divisões de meio ponto. Inclui chave completa, QR code de consulta, partes da operação, serviço, tributos municipais/federais/IBS/CBS, totais e informações complementares. Canhoto opcional suprimido. Homologação identificada em vermelho; cancelamento identificado pela situação e marca diagonal cinza.

Valores vêm do XML; ausência é representada por traço. Os únicos somatórios são os de apresentação previstos na NT. Não há recálculo dos impostos, alteração da nota ou novo evento fiscal. Textos extensos recebem reticências para preservar a página única e as fontes mínimas; o XML conserva o conteúdo completo. Totais aproximados de tributos ficam nas informações complementares.

Código: `src/Nfse/DanfseRenderer.php`, `DanfsePdf.php`, `NfseService.php`, `src/Http/Controllers/NfseController.php`. Logotipo em `resources/nfse/logo-nacional.png`, obtido do [arquivo oficial](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/logos-da-nfs-e/Logo%20-%20NFS-e%20-%20Horizontal.png). A marca da empresa não substitui o logotipo nacional. NF-e/NFC-e não foram modificadas.

## Fontes: instalação Windows e VPS

As fontes Arial e Microsoft Sans Serif precisam ser fornecidas pela instalação com os direitos de uso/incorporação apropriados. Não são distribuídas no repositório. A instalação local foi preparada usando as fontes existentes no Windows.

O utilitário `bin/preparar-fontes-danfse.py` gera as definições FPDF e fontes comprimidas em `secrets/danfse-fonts/`, fora do `vendor` e dos arquivos públicos. A preparação exige Python com `fonttools`; a execução normal da API usa apenas PHP.

```powershell
python -m pip install fonttools
python bin/preparar-fontes-danfse.py C:/Windows/Fonts secrets/danfse-fonts
```

A origem precisa conter `arial.ttf`, `arialbd.ttf` e `micross.ttf`. O utilitário recusa fontes com incorporação restrita indicada no arquivo, sem substituir a conferência da licença.

Na VPS, forneça as fontes com licença apropriada à instalação de destino e disponibilize:

```text
secrets/danfse-fonts/arial.php
secrets/danfse-fonts/arial.z
secrets/danfse-fonts/arialbd.php
secrets/danfse-fonts/arialbd.z
secrets/danfse-fonts/micross.php
secrets/danfse-fonts/micross.z
```

Nos perfis Compose atuais, a pasta do projeto já está montada em `/app`; os arquivos devem estar em `/app/secrets/danfse-fonts/`, legíveis pelo usuário PHP 10096. Não exponha `secrets` pelo servidor HTTP. Se usar imagem sem o volume do projeto, monte a pasta privadamente e somente para leitura. Não é necessário instalar fontes no sistema Linux. Na VPS, `deploy/instalar-vps.sh` prepara as fontes quando recebe `FONTES_DANFSE=/pasta`.

Se as fontes faltarem, a impressão retorna erro em vez de trocar silenciosamente de fonte. A geração de um PDF cujo XML já está armazenado não exige transmissão fiscal ou certificado A1.

## MadBuilder e verificação

`NfseApiPhpService::dispatch('imprimir-pdf', ...)` chama a rota com `formato=base64`, verifica o PDF recebido e entrega o Base64 original às telas. Preserve essa alteração na classe personalizada ao reexportar pelo MadBuilder. As referências do guia de exportação foram atualizadas.

`tests/DanfseRendererTest.php` cobre os dois leiautes, finalidade zero, página única, fontes incorporadas, somatórios, cancelamento e textos extensos. `NfseTest` verifica PDF binário/Base64, ausência de consultas ADN na chamada padrão e opção legada explícita.

A revisão visual utilizou o XML existente da NFS-e 360, cancelada em homologação. Nenhuma nota ou evento foi transmitido. O PDF não substitui o XML autorizado no armazenamento.
