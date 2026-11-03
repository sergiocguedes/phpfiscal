-- MySQL/MariaDB, executar uma vez após conferir se a coluna já existe.
ALTER TABLE nota_fiscal_detalhe ADD COLUMN codigo_tributacao_municipal VARCHAR(3) NULL;
-- No model NotaFiscalDetalhe, registrar:
-- parent::addAttribute('codigo_tributacao_municipal');
