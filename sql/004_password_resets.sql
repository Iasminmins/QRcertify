-- Migração 004 — tabela de tokens para recuperação de senha
CREATE TABLE IF NOT EXISTS password_resets (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(191) NOT NULL,
    token      VARCHAR(64)  NOT NULL UNIQUE,
    expires_at DATETIME     NOT NULL,
    used       TINYINT(1)   DEFAULT 0,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_email (email)
);
