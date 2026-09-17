-- Hache Natación — F4.3.2: registro explícito de gestión interna de prospectos.
--
-- Guarda únicamente trazabilidad administrativa sobre la identidad estable del
-- contacto. No duplica datos de contacto ni contexto comercial ya autoritativo.

CREATE TABLE IF NOT EXISTS sharky_crm_managements (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    contact_hash CHAR(64) NOT NULL,
    admin_user_id CHAR(36) NOT NULL,
    managed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    observed_last_contact_at DATETIME NOT NULL,
    KEY idx_sharky_crm_managements_contact_time (contact_hash, managed_at, id),
    KEY idx_sharky_crm_managements_admin_time (admin_user_id, managed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
