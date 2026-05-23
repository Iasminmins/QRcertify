-- ============================================
-- Migration 007: Adiciona campo department
-- Setor/Departamento nos funcionários
-- ============================================

ALTER TABLE `employees`
  ADD COLUMN `department` VARCHAR(100) DEFAULT NULL
    COMMENT 'Setor ou Departamento do funcionário'
    AFTER `role`;
