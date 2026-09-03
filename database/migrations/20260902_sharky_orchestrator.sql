-- Sharky 2.0 — durable attribution, idempotency, conversation state, identity verification, action audit and queue recovery.
-- Additive/idempotent for a fresh Sharky 2.0 install. Do not run in production until the orchestrator adapter is enabled.

-- This table is also the durable encrypted inbox. Events are persisted before the
-- webhook ACK; processing uses a lease so a crashed worker can be retried by CLI.
CREATE TABLE IF NOT EXISTS sharky_message_receipts (
  message_id VARCHAR(191) NOT NULL PRIMARY KEY,
  contact_hash CHAR(64) NOT NULL,
  message_type VARCHAR(30) NOT NULL,
  payload_ciphertext MEDIUMTEXT NULL,
  payload_iv VARCHAR(32) NULL,
  payload_tag VARCHAR(32) NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  lease_until DATETIME NULL,
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(255) NULL,
  processed_at DATETIME NULL,
  INDEX idx_sharky_receipts_contact (contact_hash, received_at),
  INDEX idx_sharky_receipts_processed (processed_at),
  INDEX idx_sharky_receipts_lease (processed_at, lease_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sharky_referrals (
  id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
  message_id VARCHAR(191) NOT NULL,
  contact_hash CHAR(64) NOT NULL,
  alumno_id CHAR(36) NULL,
  source_type VARCHAR(30) NULL,
  source_id VARCHAR(191) NULL,
  ctwa_clid VARCHAR(255) NULL,
  source_url VARCHAR(1000) NULL,
  headline VARCHAR(500) NULL,
  body VARCHAR(1000) NULL,
  media_type VARCHAR(30) NULL,
  image_url VARCHAR(1000) NULL,
  video_url VARCHAR(1000) NULL,
  thumbnail_url VARCHAR(1000) NULL,
  referral_json JSON NULL,
  captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sharky_referral_message (message_id),
  INDEX idx_sharky_referral_contact (contact_hash, captured_at),
  INDEX idx_sharky_referral_source (source_type, source_id),
  INDEX idx_sharky_referral_student (alumno_id, captured_at),
  CONSTRAINT fk_sharky_referral_alumno FOREIGN KEY (alumno_id) REFERENCES alumnos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sharky_conversation_state (
  contact_hash CHAR(64) NOT NULL PRIMARY KEY,
  state_json JSON NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  INDEX idx_sharky_state_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sharky_identity_challenges (
  id CHAR(36) NOT NULL PRIMARY KEY,
  contact_hash CHAR(64) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  status ENUM('PENDING','VERIFIED','EXPIRED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  verified_student_id CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  verified_at DATETIME NULL,
  UNIQUE KEY uq_sharky_identity_token (token_hash),
  INDEX idx_sharky_identity_contact (contact_hash, created_at),
  INDEX idx_sharky_identity_status (status, expires_at),
  INDEX idx_sharky_identity_student (verified_student_id, verified_at),
  CONSTRAINT fk_sharky_identity_student FOREIGN KEY (verified_student_id) REFERENCES alumnos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sharky_action_audit (
  id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
  idempotency_key CHAR(64) NOT NULL,
  action_type VARCHAR(60) NOT NULL,
  contact_hash CHAR(64) NOT NULL,
  alumno_id CHAR(36) NULL,
  status ENUM('PENDING','COMPLETED','FAILED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  payload_hash CHAR(64) NOT NULL,
  result_code VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  UNIQUE KEY uq_sharky_action_idempotency (idempotency_key),
  INDEX idx_sharky_action_contact (contact_hash, created_at),
  INDEX idx_sharky_action_student (alumno_id, created_at),
  INDEX idx_sharky_action_status (status, created_at),
  CONSTRAINT fk_sharky_action_alumno FOREIGN KEY (alumno_id) REFERENCES alumnos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outbound messages are encrypted at rest. The raw WhatsApp number lives only inside
-- the encrypted payload, never in a searchable/plaintext column.
CREATE TABLE IF NOT EXISTS sharky_outbox (
  id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
  dedupe_key CHAR(64) NOT NULL,
  contact_hash CHAR(64) NOT NULL,
  payload_ciphertext MEDIUMTEXT NOT NULL,
  payload_iv VARCHAR(32) NOT NULL,
  payload_tag VARCHAR(32) NOT NULL,
  status ENUM('PENDING','SENT','DEAD') NOT NULL DEFAULT 'PENDING',
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  lease_until DATETIME NULL,
  last_error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  UNIQUE KEY uq_sharky_outbox_dedupe (dedupe_key),
  INDEX idx_sharky_outbox_pending (status, available_at, lease_until),
  INDEX idx_sharky_outbox_contact (contact_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
