-- MySQL/MariaDB, executar uma vez após verificar se a coluna já existe.
ALTER TABLE nota_fiscal_cabecalho ADD COLUMN mensagem_retorno_nfse LONGTEXT NULL;
-- No model NotaFiscalCabecalho, registrar também:
-- parent::addAttribute('mensagem_retorno_nfse');
