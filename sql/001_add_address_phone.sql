-- ============================================================
-- Migration 001 - Endereco, telefone e auditoria
-- Execute no phpMyAdmin: Importar este arquivo
-- ============================================================

-- Novos campos em employees
ALTER TABLE `employees`
  ADD COLUMN `phone`        VARCHAR(20)  DEFAULT NULL AFTER `email`,
  ADD COLUMN `cep`          VARCHAR(9)   DEFAULT NULL AFTER `role`,
  ADD COLUMN `address`      VARCHAR(255) DEFAULT NULL AFTER `cep`,
  ADD COLUMN `number`       VARCHAR(10)  DEFAULT NULL AFTER `address`,
  ADD COLUMN `complement`   VARCHAR(100) DEFAULT NULL AFTER `number`,
  ADD COLUMN `neighborhood` VARCHAR(100) DEFAULT NULL AFTER `complement`,
  ADD COLUMN `city`         VARCHAR(100) DEFAULT NULL AFTER `neighborhood`,
  ADD COLUMN `state`        CHAR(2)      DEFAULT NULL AFTER `city`;

-- Novos campos em companies
ALTER TABLE `companies`
  ADD COLUMN `cep`          VARCHAR(9)   DEFAULT NULL AFTER `phone`,
  ADD COLUMN `address`      VARCHAR(255) DEFAULT NULL AFTER `cep`,
  ADD COLUMN `number`       VARCHAR(10)  DEFAULT NULL AFTER `address`,
  ADD COLUMN `complement`   VARCHAR(100) DEFAULT NULL AFTER `number`,
  ADD COLUMN `neighborhood` VARCHAR(100) DEFAULT NULL AFTER `complement`,
  ADD COLUMN `city`         VARCHAR(100) DEFAULT NULL AFTER `neighborhood`,
  ADD COLUMN `state`        CHAR(2)      DEFAULT NULL AFTER `city`,
  ADD COLUMN `logo`         VARCHAR(500) DEFAULT NULL AFTER `state`;

-- Tabela de auditoria
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT          NULL,
  `company_id` INT          NULL,
  `action`     VARCHAR(100) NOT NULL COMMENT 'Ex: employee.create, cert.delete',
  `target`     VARCHAR(100) NULL     COMMENT 'Ex: employee #42',
  `detail`     TEXT         NULL     COMMENT 'JSON com dados relevantes',
  `ip`         VARCHAR(45)  NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_audit_user`    (`user_id`),
  INDEX `idx_audit_company` (`company_id`),
  INDEX `idx_audit_action`  (`action`),
  INDEX `idx_audit_date`    (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
