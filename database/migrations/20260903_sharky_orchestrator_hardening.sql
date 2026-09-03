-- Sharky 2.0 incremental hardening for lab/staging databases that may have
-- executed an earlier revision of 20260902_sharky_orchestrator.sql.
-- Safe to run repeatedly on MariaDB 11.x. Do not enable the Sharky 2.0 feature
-- flag until this migration and the base migration have both been applied.

-- Durable encrypted inbox / message receipts.
ALTER TABLE sharky_message_receipts
  ADD COLUMN IF NOT EXISTS payload_ciphertext MEDIUMTEXT NULL AFTER message_type,
  ADD COLUMN IF NOT EXISTS payload_iv VARCHAR(32) NULL AFTER payload_ciphertext,
  ADD COLUMN IF NOT EXISTS payload_tag VARCHAR(32) NULL AFTER payload_iv,
  ADD COLUMN IF NOT EXISTS lease_until DATETIME NULL AFTER received_at,
  ADD COLUMN IF NOT EXISTS attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER lease_until,
  ADD COLUMN IF NOT EXISTS last_error VARCHAR(255) NULL AFTER attempt_count,
  ADD COLUMN IF NOT EXISTS handoff_pending_at DATETIME NULL AFTER last_error,
  ADD COLUMN IF NOT EXISTS processed_at DATETIME NULL AFTER handoff_pending_at,
  ADD INDEX IF NOT EXISTS idx_sharky_receipts_contact (contact_hash, received_at),
  ADD INDEX IF NOT EXISTS idx_sharky_receipts_processed (processed_at),
  ADD INDEX IF NOT EXISTS idx_sharky_receipts_lease (processed_at, lease_until),
  ADD INDEX IF NOT EXISTS idx_sharky_receipts_handoff (processed_at, handoff_pending_at);

-- Referral payload evolved after the first lab cut.
ALTER TABLE sharky_referrals
  ADD COLUMN IF NOT EXISTS image_url VARCHAR(1000) NULL AFTER media_type,
  ADD COLUMN IF NOT EXISTS video_url VARCHAR(1000) NULL AFTER image_url,
  ADD COLUMN IF NOT EXISTS thumbnail_url VARCHAR(1000) NULL AFTER video_url,
  ADD COLUMN IF NOT EXISTS referral_json JSON NULL AFTER thumbnail_url,
  ADD INDEX IF NOT EXISTS idx_sharky_referral_source (source_type, source_id),
  ADD INDEX IF NOT EXISTS idx_sharky_referral_student (alumno_id, captured_at);

-- Conversation state is now encrypted at rest. Keep state_json nullable only for
-- one-time compatibility; runtime reseals any legacy plaintext row and clears it.
ALTER TABLE sharky_conversation_state
  MODIFY COLUMN state_json JSON NULL,
  ADD COLUMN IF NOT EXISTS state_ciphertext MEDIUMTEXT NULL AFTER state_json,
  ADD COLUMN IF NOT EXISTS state_iv VARCHAR(32) NULL AFTER state_ciphertext,
  ADD COLUMN IF NOT EXISTS state_tag VARCHAR(32) NULL AFTER state_iv,
  ADD INDEX IF NOT EXISTS idx_sharky_state_expires (expires_at);

-- Verification session columns used by the bounded verified-session flow.
ALTER TABLE sharky_identity_challenges
  ADD COLUMN IF NOT EXISTS verified_at DATETIME NULL AFTER expires_at,
  ADD INDEX IF NOT EXISTS idx_sharky_identity_status (status, expires_at),
  ADD INDEX IF NOT EXISTS idx_sharky_identity_student (verified_student_id, verified_at);

-- Recoverable/idempotent business action audit.
ALTER TABLE sharky_action_audit
  MODIFY COLUMN status ENUM('PENDING','COMPLETED','FAILED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  ADD COLUMN IF NOT EXISTS source_message_id VARCHAR(191) NULL AFTER payload_hash,
  ADD COLUMN IF NOT EXISTS result_json JSON NULL AFTER result_code,
  ADD COLUMN IF NOT EXISTS result_ciphertext MEDIUMTEXT NULL AFTER result_json,
  ADD COLUMN IF NOT EXISTS result_iv VARCHAR(32) NULL AFTER result_ciphertext,
  ADD COLUMN IF NOT EXISTS result_tag VARCHAR(32) NULL AFTER result_iv,
  ADD COLUMN IF NOT EXISTS result_message VARCHAR(500) NULL AFTER result_tag,
  ADD COLUMN IF NOT EXISTS delivery_queued_at DATETIME NULL AFTER result_message,
  ADD COLUMN IF NOT EXISTS lease_until DATETIME NULL AFTER delivery_queued_at,
  ADD COLUMN IF NOT EXISTS owner_token CHAR(48) NULL AFTER lease_until,
  ADD COLUMN IF NOT EXISTS attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER owner_token,
  ADD COLUMN IF NOT EXISTS completed_at DATETIME NULL AFTER created_at,
  ADD INDEX IF NOT EXISTS idx_sharky_action_status (status, lease_until, created_at),
  ADD INDEX IF NOT EXISTS idx_sharky_action_delivery (source_message_id, status, delivery_queued_at);

-- Encrypted outbound queue and fencing token.
ALTER TABLE sharky_outbox
  MODIFY COLUMN status ENUM('PENDING','SENT','DEAD','CANCELLED') NOT NULL DEFAULT 'PENDING',
  ADD COLUMN IF NOT EXISTS payload_ciphertext MEDIUMTEXT NULL AFTER contact_hash,
  ADD COLUMN IF NOT EXISTS payload_iv VARCHAR(32) NULL AFTER payload_ciphertext,
  ADD COLUMN IF NOT EXISTS payload_tag VARCHAR(32) NULL AFTER payload_iv,
  ADD COLUMN IF NOT EXISTS attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN IF NOT EXISTS available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER attempt_count,
  ADD COLUMN IF NOT EXISTS lease_until DATETIME NULL AFTER available_at,
  ADD COLUMN IF NOT EXISTS owner_token CHAR(48) NULL AFTER lease_until,
  ADD COLUMN IF NOT EXISTS last_error VARCHAR(255) NULL AFTER owner_token,
  ADD COLUMN IF NOT EXISTS sent_at DATETIME NULL AFTER created_at,
  ADD INDEX IF NOT EXISTS idx_sharky_outbox_pending (status, available_at, lease_until),
  ADD INDEX IF NOT EXISTS idx_sharky_outbox_contact (contact_hash, created_at);
