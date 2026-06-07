-- ============================================
-- Migration 013: Tabela de Documentos
-- Armazena ASO, NR, Certificados assinados,
-- fotos, PDFs e outros documentos por colaborador
-- ============================================

CREATE TABLE IF NOT EXISTS `employee_documents` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `company_id`    INT NOT NULL,
  `employee_id`   INT NOT NULL,
  `doc_type`      ENUM(
                    'aso',
                    'nr_certificado',
                    'certificado_assinado',
                    'foto',
                    'pdf_geral',
                    'doc_word',
                    'outro'
                  ) NOT NULL DEFAULT 'outro',
  `title`         VARCHAR(255) NOT NULL COMMENT 'Nome/título do documento',
  `description`   TEXT NULL COMMENT 'Descrição ou observações',
  `file_path`     VARCHAR(500) NOT NULL COMMENT 'Caminho físico do arquivo salvo',
  `file_name`     VARCHAR(255) NOT NULL COMMENT 'Nome original do arquivo',
  `file_ext`      VARCHAR(10)  NOT NULL COMMENT 'Extensão: pdf, jpg, png, doc...',
  `file_size`     INT UNSIGNED DEFAULT 0 COMMENT 'Tamanho em bytes',
  `ocr_data`      TEXT NULL COMMENT 'Dados extraídos por OCR/leitura do documento',
  `issue_date`    DATE NULL COMMENT 'Data de emissão do documento',
  `expiry_date`   DATE NULL COMMENT 'Data de validade (se aplicável)',
  `status`        ENUM('valido','vencendo','vencido','sem_validade') DEFAULT 'sem_validade',
  `uploaded_by`   INT NULL COMMENT 'FK para users',
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`company_id`)  REFERENCES `companies`(`id`)  ON DELETE CASCADE,
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`)  ON DELETE CASCADE,
  FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`)      ON DELETE SET NULL,
  INDEX `idx_docs_company`    (`company_id`),
  INDEX `idx_docs_employee`   (`employee_id`),
  INDEX `idx_docs_type`       (`doc_type`),
  INDEX `idx_docs_expiry`     (`expiry_date`),
  INDEX `idx_docs_status`     (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
