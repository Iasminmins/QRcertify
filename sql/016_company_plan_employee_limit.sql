-- ============================================
-- Migration 016: Limite personalizado de colaboradores
-- Permite planos customizados por empresa
-- ============================================

ALTER TABLE `companies`
    ADD COLUMN `plan_employee_limit` INT UNSIGNED NULL DEFAULT NULL
    COMMENT 'Limite customizado de colaboradores (NULL = usa o limite do plano padrão; 0 = ilimitado)'
    AFTER `plan`;
