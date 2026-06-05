-- ============================================
-- Migration 009: Tabela de ASO
-- Atestado de Saúde Ocupacional
-- ============================================

CREATE TABLE IF NOT EXISTS `employee_asos` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `company_id`      INT NOT NULL,
  `employee_id`     INT NOT NULL,
  `aso_type`        ENUM('admissional','periodico','retorno_trabalho','mudanca_risco','demissional')
                    NOT NULL DEFAULT 'periodico',
  `exam_date`       DATE NOT NULL,
  `expiration_date` DATE NOT NULL,
  `result_status`   ENUM('apto','inapto','pendente') NOT NULL DEFAULT 'pendente',
  `document_status` ENUM('valido','vencendo','vencido') NOT NULL DEFAULT 'valido',
  `doctor_name`     VARCHAR(150) NULL,
  `doctor_crm`      VARCHAR(30)  NULL,
  `file_path`       VARCHAR(500) NULL COMMENT 'Caminho interno do PDF — não público',
  `notes`           TEXT NULL    COMMENT 'Observação interna — não exibir publicamente',
  `created_by`      INT NULL     COMMENT 'FK para users',
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`company_id`)  REFERENCES `companies`(`id`)  ON DELETE CASCADE,
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`)  ON DELETE CASCADE,
  FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`)      ON DELETE SET NULL,
  INDEX `idx_aso_company`   (`company_id`),
  INDEX `idx_aso_employee`  (`employee_id`),
  INDEX `idx_aso_expiration`(`expiration_date`),
  INDEX `idx_aso_status`    (`document_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
