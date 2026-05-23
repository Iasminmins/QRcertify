-- Migration 006 - ajusta unicidade de colaboradores para multi-tenant
-- Permite que empresas diferentes tenham colaboradores com o mesmo email/CPF.

ALTER TABLE `employees`
  DROP INDEX `email`,
  DROP INDEX `cpf`,
  ADD UNIQUE KEY `uniq_employees_company_email` (`company_id`, `email`),
  ADD UNIQUE KEY `uniq_employees_company_cpf` (`company_id`, `cpf`);
