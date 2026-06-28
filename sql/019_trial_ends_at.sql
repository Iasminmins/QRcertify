-- Migração 019 — Campo dedicado de fim de trial (trial_ends_at).
--
-- Antes o trial era medido por (created_at + 30 dias), o que obrigava a mexer no
-- created_at para renovar. Agora há uma coluna própria, sem corromper a data de criação.
--
-- Rode UMA ÚNICA VEZ. Não apaga nenhum dado.

-- 1) Cria a coluna (se sua versão do MySQL não aceitar IF NOT EXISTS, remova-o).
ALTER TABLE companies ADD COLUMN trial_ends_at DATE DEFAULT NULL;

-- 2) Backfill: para quem já existe e está em trial, define o fim do trial como
--    created_at + 30 dias (mantém a regra antiga para os atuais).
UPDATE companies
   SET trial_ends_at = DATE(created_at) + INTERVAL 30 DAY
 WHERE trial_ends_at IS NULL
   AND subscription_status = 'trialing';
