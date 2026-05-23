-- Migração 003 — Integração Asaas
-- Execute uma vez no banco de dados

ALTER TABLE companies
    ADD COLUMN IF NOT EXISTS plan                  VARCHAR(20)  DEFAULT 'starter',
    ADD COLUMN IF NOT EXISTS asaas_customer_id     VARCHAR(50)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS asaas_subscription_id VARCHAR(50)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS subscription_status   VARCHAR(20)  DEFAULT 'trialing';
-- subscription_status: trialing | active | overdue | canceled
