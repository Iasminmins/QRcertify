-- ============================================================
-- 012 - Campos de autorização por colaborador + SESMT empresa
-- ============================================================
-- ATENÇÃO: Este arquivo tem duas versões:
--   - Local (Laragon):    use sem AFTER (manager_name ainda não existe)
--   - Produção (Hostinger): use com AFTER (manager_name já existe via 011)
--
-- Execute os 3 comandos separadamente no phpMyAdmin.
-- Se algum der "Duplicate column name", ignore e siga para o próximo.
-- ============================================================

-- [1] Operações autorizadas por colaborador
ALTER TABLE `employees`
    ADD COLUMN `authorized_ops` JSON DEFAULT NULL
        COMMENT 'Operações autorizadas: ["Direção Defensiva","Espaço Confinado",...]'
    AFTER `manager_name`;

-- [2] Nome do responsável SESMT que elabora a carteira
ALTER TABLE `companies`
    ADD COLUMN `sesmt_elaborado` VARCHAR(150) DEFAULT NULL
        COMMENT 'Nome de quem elabora a carteira (SESMT)'
    AFTER `emergency_policia`;

-- [3] Nome do responsável SESMT que aprova a carteira
ALTER TABLE `companies`
    ADD COLUMN `sesmt_aprovado` VARCHAR(150) DEFAULT NULL
        COMMENT 'Nome de quem aprova a carteira (SESMT)'
    AFTER `sesmt_elaborado`;
