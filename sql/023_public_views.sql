-- Migração 023: contabiliza consultas públicas sem dados do visitante.

CREATE TABLE public_views (
  id INT AUTO_INCREMENT PRIMARY KEY,
  entity_type ENUM('certificate','employee','authorization') NOT NULL,
  entity_id INT NOT NULL,
  company_id INT NOT NULL,
  viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_public_views_company (company_id),
  INDEX idx_public_views_entity (entity_type, entity_id),
  INDEX idx_public_views_viewed_at (viewed_at),
  CONSTRAINT fk_public_views_company
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
