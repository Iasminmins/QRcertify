-- ============================================
-- Migration 011: Gestor direto do colaborador
-- Adiciona coluna manager_name nos employees
-- ============================================

ALTER TABLE `employees`
  ADD COLUMN `manager_name` VARCHAR(150) NULL
    COMMENT 'Gestor direto do colaborador'
    AFTER `department`;
