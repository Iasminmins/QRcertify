-- ============================================
-- Carteira Digital de Certificados - Schema MySQL
-- Para Hostinger (MySQL / MariaDB)
-- ============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `password_resets`;
DROP TABLE IF EXISTS `audit_log`;
DROP TABLE IF EXISTS `certificates`;
DROP TABLE IF EXISTS `employees`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `companies`;

-- ============================================
-- Tabela: companies
-- ============================================
CREATE TABLE `companies` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `cnpj` VARCHAR(20) UNIQUE,
  `email` VARCHAR(255),
  `phone` VARCHAR(20),
  `cep` VARCHAR(9) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `number` VARCHAR(10) DEFAULT NULL,
  `complement` VARCHAR(100) DEFAULT NULL,
  `neighborhood` VARCHAR(100) DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `state` CHAR(2) DEFAULT NULL,
  `logo` VARCHAR(500) DEFAULT NULL,
  `emergency_sesmt` VARCHAR(20) DEFAULT NULL,
  `emergency_bombeiro` VARCHAR(6) DEFAULT '193',
  `emergency_samu` VARCHAR(6) DEFAULT '192',
  `emergency_policia` VARCHAR(6) DEFAULT '190',
  `plan` VARCHAR(20) DEFAULT 'starter',
  `asaas_customer_id` VARCHAR(50) DEFAULT NULL,
  `asaas_subscription_id` VARCHAR(50) DEFAULT NULL,
  `subscription_status` VARCHAR(20) DEFAULT 'trialing',
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Tabela: users
-- ============================================
CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `company_id` INT NULL,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('superadmin', 'admin', 'employee') NOT NULL,
  `photo` VARCHAR(500) DEFAULT NULL,
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Tabela: employees
-- ============================================
CREATE TABLE `employees` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NULL,
  `company_id` INT NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `cpf` VARCHAR(14) NOT NULL,
  `role` VARCHAR(100) NOT NULL COMMENT 'Cargo/funcao do funcionario',
  `phone` VARCHAR(20) DEFAULT NULL,
  `photo` VARCHAR(500) DEFAULT NULL,
  `public_hash` VARCHAR(64) DEFAULT NULL COMMENT 'Hash publico da carteira digital',
  `cep` VARCHAR(9) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `number` VARCHAR(10) DEFAULT NULL,
  `complement` VARCHAR(100) DEFAULT NULL,
  `neighborhood` VARCHAR(100) DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `state` CHAR(2) DEFAULT NULL,
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
  INDEX `idx_employees_company` (`company_id`),
  INDEX `idx_employees_status` (`status`),
  UNIQUE KEY `uniq_employees_company_email` (`company_id`, `email`),
  UNIQUE KEY `uniq_employees_company_cpf` (`company_id`, `cpf`),
  UNIQUE KEY `uniq_employees_public_hash` (`public_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Tabela: certificates
-- ============================================
CREATE TABLE `certificates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `employee_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `type` VARCHAR(100) NOT NULL COMMENT 'Tipo: NR-10, NR-35, ISO, CIPA, etc.',
  `issued_date` DATE NOT NULL,
  `expiry_date` DATE NOT NULL,
  `status` ENUM('valid', 'expired', 'expiring') DEFAULT 'valid',
  `hash` VARCHAR(64) UNIQUE COMMENT 'Hash unico para validacao por QR Code',
  `document` VARCHAR(500) DEFAULT NULL COMMENT 'Caminho do PDF anexo',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
  INDEX `idx_certificates_employee` (`employee_id`),
  INDEX `idx_certificates_status` (`status`),
  INDEX `idx_certificates_expiry` (`expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Tabela: audit_log
-- ============================================
CREATE TABLE `audit_log` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NULL,
  `company_id` INT NULL,
  `action` VARCHAR(100) NOT NULL COMMENT 'Ex: employee.create, cert.delete',
  `target` VARCHAR(100) NULL COMMENT 'Ex: employee #42',
  `detail` TEXT NULL COMMENT 'JSON com dados relevantes',
  `ip` VARCHAR(45) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_audit_user` (`user_id`),
  INDEX `idx_audit_company` (`company_id`),
  INDEX `idx_audit_action` (`action`),
  INDEX `idx_audit_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Tabela: password_resets
-- ============================================
CREATE TABLE `password_resets` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(191) NOT NULL,
  `token` VARCHAR(64) NOT NULL UNIQUE,
  `expires_at` DATETIME NOT NULL,
  `used` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_token` (`token`),
  INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- Dados de exemplo
-- Senha de todos os usuarios: 123456
-- ============================================

INSERT INTO `companies` (`id`, `name`, `cnpj`, `email`, `phone`) VALUES
(1, 'Industria Exemplo Ltda', '12.345.678/0001-90', 'contato@exemplo.com.br', '(24) 3322-1100');

INSERT INTO `users` (`company_id`, `name`, `email`, `password`, `role`) VALUES
(NULL, 'Super Admin', 'super@admin.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'superadmin'),
(1, 'Amanda Silva', 'admin@empresa.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
(1, 'Carlos Oliveira', 'carlos@empresa.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee');

INSERT INTO `employees` (`user_id`, `company_id`, `name`, `email`, `cpf`, `role`, `public_hash`, `status`) VALUES
(3, 1, 'Carlos Oliveira', 'carlos@empresa.com', '123.456.789-00', 'Eletricista', SHA2(CONCAT('emp1', NOW()), 256), 'active'),
(NULL, 1, 'Joao da Silva', 'joao@empresa.com', '987.654.321-00', 'Operador de Maquinas', SHA2(CONCAT('emp2', NOW()), 256), 'active'),
(NULL, 1, 'Maria Santos', 'maria@empresa.com', '111.222.333-44', 'Soldador', SHA2(CONCAT('emp3', NOW()), 256), 'active');

INSERT INTO `certificates` (`employee_id`, `title`, `type`, `issued_date`, `expiry_date`, `hash`) VALUES
(1, 'NR-10 - Seguranca em Instalacoes Eletricas', 'NR-10', '2024-01-15', '2026-01-15', SHA2(CONCAT('cert1', NOW()), 256)),
(1, 'NR-35 - Trabalho em Altura', 'NR-35', '2024-03-20', '2026-03-20', SHA2(CONCAT('cert2', NOW()), 256)),
(2, 'NR-11 - Transporte e Manuseio de Materiais', 'NR-11', '2024-06-10', '2025-12-10', SHA2(CONCAT('cert3', NOW()), 256)),
(3, 'NR-34 - Industria Naval', 'NR-34', '2023-01-05', '2024-01-05', SHA2(CONCAT('cert4', NOW()), 256));

UPDATE `certificates` SET `status` = 'expired' WHERE `expiry_date` < CURDATE();
UPDATE `certificates` SET `status` = 'expiring' WHERE `expiry_date` BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY);
UPDATE `certificates` SET `status` = 'valid' WHERE `expiry_date` > DATE_ADD(CURDATE(), INTERVAL 30 DAY);
