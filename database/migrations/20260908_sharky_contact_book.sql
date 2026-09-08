-- Sharky contact book — durable local authority for WhatsApp contacts.
-- Raw phone numbers and names live only inside AES-GCM encrypted payloads.
CREATE TABLE IF NOT EXISTS sharky_contacts (
  contact_hash CHAR(64) NOT NULL PRIMARY KEY,
  contact_ciphertext MEDIUMTEXT NOT NULL,
  contact_iv VARCHAR(32) NOT NULL,
  contact_tag VARCHAR(32) NOT NULL,
  desired_hash CHAR(64) NOT NULL,
  role ENUM('PROSPECT','STUDENT','TEACHER','UNKNOWN') NOT NULL DEFAULT 'PROSPECT',
  alumno_id CHAR(36) NULL,
  profesor_id CHAR(36) NULL,
  google_resource_name VARCHAR(191) NULL,
  sync_status ENUM('PENDING','SYNCED','UNMANAGED','FAILED') NOT NULL DEFAULT 'PENDING',
  last_error VARCHAR(255) NULL,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_sync_attempt_at DATETIME NULL,
  synced_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sharky_contacts_sync (sync_status,last_sync_attempt_at,last_seen_at),
  INDEX idx_sharky_contacts_role (role,last_seen_at),
  INDEX idx_sharky_contacts_alumno (alumno_id),
  INDEX idx_sharky_contacts_profesor (profesor_id),
  UNIQUE KEY uq_sharky_contacts_google_resource (google_resource_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
