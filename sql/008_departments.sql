-- ============================================
-- Migration 008: Tabela de Áreas / Setores
-- PARA PRODUÇÃO (banco zerado / novo)
-- Rodar este arquivo inteiro de uma vez
-- ============================================

-- 1. Cria tabela departments
CREATE TABLE IF NOT EXISTS `departments` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `company_id`   INT NOT NULL,
  `name`         VARCHAR(100) NOT NULL,
  `manager_name` VARCHAR(150) NULL COMMENT 'Nome livre do gestor responsável',
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
  INDEX `idx_dept_company` (`company_id`),
  UNIQUE KEY `uniq_dept_company_name` (`company_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Adiciona coluna department_id nos employees
ALTER TABLE `employees`
  ADD COLUMN `department_id` INT NULL COMMENT 'FK para departments' AFTER `department`;

-- 3. FK de employees → departments
ALTER TABLE `employees`
  ADD CONSTRAINT `fk_employees_department`
    FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`)
    ON DELETE SET NULL;

-- 4. Índice para performance
ALTER TABLE `employees`
  ADD INDEX `idx_employees_department` (`department_id`);
