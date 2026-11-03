CREATE TABLE IF NOT EXISTS fiscal_documents (
    namespace text NOT NULL,
    path text NOT NULL,
    content jsonb NOT NULL,
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (namespace, path)
);
CREATE TABLE IF NOT EXISTS fiscal_blobs (
    namespace text NOT NULL,
    path text NOT NULL,
    content bytea NOT NULL,
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (namespace, path)
);
CREATE INDEX IF NOT EXISTS fiscal_documents_prefix ON fiscal_documents(namespace, path text_pattern_ops);
CREATE INDEX IF NOT EXISTS fiscal_documents_content ON fiscal_documents USING gin(content);
-- Sem políticas públicas: o proprietário do schema/tabelas usado pelo backend mantém acesso.
ALTER TABLE fiscal_documents ENABLE ROW LEVEL SECURITY;
ALTER TABLE fiscal_blobs ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON fiscal_documents, fiscal_blobs FROM PUBLIC;
CREATE TABLE IF NOT EXISTS fiscal_api_settings (
    namespace text PRIMARY KEY,
    config jsonb NOT NULL,
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
ALTER TABLE fiscal_api_settings ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON fiscal_api_settings FROM PUBLIC;
