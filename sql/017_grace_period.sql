-- Migração 017 — Régua de carência (grace period) de assinatura
-- Execute uma vez no banco de dados.
--
-- overdue_since: data em que a cobrança ficou vencida (PAYMENT_OVERDUE do Asaas).
-- A partir dela contamos os dias de carência antes de bloquear o acesso.
-- NULL = não está vencida. Bloquear = apenas suspender acesso; nenhum dado é apagado.

-- Nota: se sua versão do MySQL não aceitar "IF NOT EXISTS" no ADD COLUMN,
-- use a linha simples abaixo (e ignore o erro caso a coluna já exista):
--   ALTER TABLE companies ADD COLUMN overdue_since DATE DEFAULT NULL;

ALTER TABLE companies
    ADD COLUMN overdue_since DATE DEFAULT NULL;
