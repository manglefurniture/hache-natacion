-- Phone and optional label are encrypted; the keyed hash is the unique lookup key.
CREATE TABLE IF NOT EXISTS sharky_protected_numbers (
  contact_hash CHAR(64) NOT NULL PRIMARY KEY,
  payload_ciphertext MEDIUMTEXT NOT NULL,
  payload_iv VARCHAR(32) NOT NULL,
  payload_tag VARCHAR(32) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
