-- Migration 002 - Numeros de emergencia da empresa
ALTER TABLE `companies`
  ADD COLUMN `emergency_sesmt`    VARCHAR(20) DEFAULT NULL AFTER `logo`,
  ADD COLUMN `emergency_bombeiro` VARCHAR(6)  DEFAULT '193' AFTER `emergency_sesmt`,
  ADD COLUMN `emergency_samu`     VARCHAR(6)  DEFAULT '192' AFTER `emergency_bombeiro`,
  ADD COLUMN `emergency_policia`  VARCHAR(6)  DEFAULT '190' AFTER `emergency_samu`;
