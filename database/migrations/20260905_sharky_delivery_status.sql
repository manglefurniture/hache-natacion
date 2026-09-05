-- Additive delivery-status evidence for Sharky outbound WhatsApp messages.
-- Safe to run repeatedly on MariaDB 11.x. Runtime remains backward-compatible
-- until this schema is present; no plaintext phone/contact data is stored here.

ALTER TABLE sharky_outbox
  ADD COLUMN IF NOT EXISTS provider_message_id VARCHAR(191) NULL AFTER sent_at,
  ADD UNIQUE INDEX IF NOT EXISTS uq_sharky_outbox_provider_message (provider_message_id);

CREATE TABLE IF NOT EXISTS sharky_delivery_status (
  provider_message_id VARCHAR(191) NOT NULL PRIMARY KEY,
  status ENUM('SENT','DELIVERED','READ','FAILED') NOT NULL,
  status_rank TINYINT UNSIGNED NOT NULL,
  provider_event_at_utc DATETIME NOT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sharky_delivery_status_state (status, provider_event_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
