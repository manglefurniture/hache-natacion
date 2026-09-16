-- Hache Natación — accesos de un solo uso al portal enviados por WhatsApp.

CREATE TABLE IF NOT EXISTS portal_access_tokens (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    user_id CHAR(36) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_portal_access_token_hash (token_hash),
    KEY idx_portal_access_user_state (user_id,consumed_at,expires_at),
    CONSTRAINT fk_portal_access_user FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
