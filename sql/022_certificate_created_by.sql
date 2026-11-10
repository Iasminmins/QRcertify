-- Migração 022: registra o usuário que cadastrou o certificado.

ALTER TABLE certificates
  ADD COLUMN created_by INT NULL AFTER file_sha256,
  ADD INDEX idx_certificates_created_by (created_by),
  ADD CONSTRAINT fk_certificates_created_by
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;
