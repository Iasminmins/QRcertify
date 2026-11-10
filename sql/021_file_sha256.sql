-- Migração 021: guarda o SHA-256 dos arquivos enviados.
-- Executar uma vez em MySQL 5.7+ ou MariaDB 10.4+.

ALTER TABLE certificates ADD COLUMN file_sha256 CHAR(64) NULL AFTER document;
ALTER TABLE employee_asos ADD COLUMN file_sha256 CHAR(64) NULL AFTER file_path;
ALTER TABLE employee_documents ADD COLUMN file_sha256 CHAR(64) NULL AFTER file_path;
