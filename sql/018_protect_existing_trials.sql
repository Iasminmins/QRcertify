-- Migração 018 — Proteção de empresas existentes ao ativar a régua de carência.
--
-- Contexto: a régua passou a expirar o trial em (created_at + 30 dias). Empresas
-- criadas há mais de ~40 dias cairiam direto no bloqueio ao subir a mudança.
--
-- Este script marca como 'active' as empresas de teste que JÁ existem e ainda
-- estão em 'trialing' — assim elas não são bloqueadas de surpresa. Preserva o
-- created_at original (não corrompe data de criação / relatórios).
-- Rode UMA ÚNICA VEZ, junto com a subida. Não apaga nenhum dado.
--
-- ATENÇÃO: revise antes de rodar. Se alguma dessas empresas NÃO deveria ficar
-- ativa de graça, ajuste o WHERE (ex.: limite por id) ou rode empresa a empresa.

UPDATE companies
   SET subscription_status = 'active'
 WHERE subscription_status = 'trialing'
   AND created_at < (NOW() - INTERVAL 30 DAY);
