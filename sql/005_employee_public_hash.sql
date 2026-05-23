-- Migração 005 — hash público da carteira do funcionário (QR sem ID sequencial)
ALTER TABLE `employees`
  ADD COLUMN IF NOT EXISTS `public_hash` VARCHAR(64) NULL UNIQUE COMMENT 'Hash público da carteira digital' AFTER `photo`;

-- Preenche funcionários existentes (execute uma vez)
UPDATE `employees`
SET `public_hash` = LOWER(SHA2(CONCAT('emp-wallet-', id, '-', UNIX_TIMESTAMP(created_at)), 256))
WHERE `public_hash` IS NULL OR `public_hash` = '';
