-- ============================================
-- Migration 014: Campos adicionais do ASO
-- Informações completas do Atestado de Saúde
-- Ocupacional conforme NR-7 e Portaria 24/14/84
-- ============================================

ALTER TABLE `employee_asos`
    -- Empresa examinadora
    ADD COLUMN `clinic_name`        VARCHAR(255) NULL COMMENT 'Nome da clínica/empresa examinadora'   AFTER `doctor_crm`,
    ADD COLUMN `clinic_cnpj`        VARCHAR(20)  NULL COMMENT 'CNPJ da empresa examinadora'           AFTER `clinic_name`,
    ADD COLUMN `clinic_address`     VARCHAR(500) NULL COMMENT 'Endereço da clínica'                   AFTER `clinic_cnpj`,

    -- Dados do colaborador no momento do exame
    ADD COLUMN `work_location`      VARCHAR(255) NULL COMMENT 'Local de trabalho'                     AFTER `clinic_address`,
    ADD COLUMN `work_sector`        VARCHAR(150) NULL COMMENT 'Setor no momento do exame'             AFTER `work_location`,
    ADD COLUMN `work_function`      VARCHAR(150) NULL COMMENT 'Função no momento do exame'            AFTER `work_sector`,
    ADD COLUMN `admission_date`     DATE         NULL COMMENT 'Data de admissão do colaborador'       AFTER `work_function`,

    -- Riscos ocupacionais (checkboxes armazenados como JSON)
    ADD COLUMN `risks_physical`     TEXT         NULL COMMENT 'Riscos físicos marcados (JSON array)'  AFTER `admission_date`,
    ADD COLUMN `risks_chemical`     TEXT         NULL COMMENT 'Riscos químicos (JSON array)'          AFTER `risks_physical`,
    ADD COLUMN `risks_biological`   TEXT         NULL COMMENT 'Riscos biológicos (JSON array)'        AFTER `risks_chemical`,
    ADD COLUMN `risks_ergonomic`    TEXT         NULL COMMENT 'Riscos ergonômicos (JSON array)'       AFTER `risks_biological`,
    ADD COLUMN `risks_accident`     TEXT         NULL COMMENT 'Riscos de acidentes (JSON array)'      AFTER `risks_ergonomic`,
    ADD COLUMN `risks_other`        VARCHAR(500) NULL COMMENT 'Outros riscos (texto livre)'           AFTER `risks_accident`,

    -- Exames complementares
    ADD COLUMN `exams_done`         TEXT         NULL COMMENT 'Exames realizados (JSON array)'        AFTER `risks_other`,
    ADD COLUMN `exams_other`        VARCHAR(500) NULL COMMENT 'Outros exames (texto livre)'           AFTER `exams_done`,

    -- Datas de controle
    ADD COLUMN `next_exam_date`     DATE         NULL COMMENT 'Previsão do próximo exame'             AFTER `exams_other`,

    -- Responsável técnico (SESMT)
    ADD COLUMN `sesmt_name`         VARCHAR(150) NULL COMMENT 'Responsável SESMT'                     AFTER `next_exam_date`,
    ADD COLUMN `sesmt_register`     VARCHAR(50)  NULL COMMENT 'Registro profissional SESMT'           AFTER `sesmt_name`;
