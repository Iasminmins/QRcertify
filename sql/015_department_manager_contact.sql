-- ============================================
-- Migration 015: Campos de contato do gestor
-- Compatível com MySQL 5.7+ e MariaDB
-- ============================================

ALTER TABLE `departments`
    ADD COLUMN `manager_email` VARCHAR(255) NULL COMMENT 'E-mail do gestor responsável' AFTER `manager_name`,
    ADD COLUMN `manager_phone` VARCHAR(20)  NULL COMMENT 'Telefone/WhatsApp do gestor'  AFTER `manager_email`;
