-- Sharky conversation review — hourly, privacy-preserving triage over existing encrypted logs.
-- Raw conversation text is NOT duplicated here. Findings reference encrypted source rows by id.

CREATE TABLE IF NOT EXISTS sharky_conversation_review_state (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  next_run_at DATETIME NOT NULL,
  last_started_at DATETIME NULL,
  last_finished_at DATETIME NULL,
  last_status ENUM('NEVER','RUNNING','OK','ERROR') NOT NULL DEFAULT 'NEVER',
  last_error VARCHAR(255) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sharky_conversation_review_state(id,next_run_at,last_status)
VALUES(1,NOW(),'NEVER');

CREATE TABLE IF NOT EXISTS sharky_conversation_reviews (
  id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
  contact_hash CHAR(64) NOT NULL,
  window_start DATETIME NOT NULL,
  window_end DATETIME NOT NULL,
  classification ENUM('OK','REVIEW','PROBLEM') NOT NULL,
  finding_count INT UNSIGNED NOT NULL DEFAULT 0,
  high_count INT UNSIGNED NOT NULL DEFAULT 0,
  review_fingerprint CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sharky_review_fingerprint (review_fingerprint),
  INDEX idx_sharky_review_contact (contact_hash, window_end),
  INDEX idx_sharky_review_classification (classification, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sharky_conversation_findings (
  id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
  review_id CHAR(36) NOT NULL,
  contact_hash CHAR(64) NOT NULL,
  finding_type VARCHAR(60) NOT NULL,
  severity ENUM('INFO','WARN','HIGH') NOT NULL,
  status ENUM('NEW','REVIEWED','REGRESSION_CANDIDATE','IGNORED','RESOLVED') NOT NULL DEFAULT 'NEW',
  regression_candidate TINYINT(1) NOT NULL DEFAULT 1,
  source_message_id VARCHAR(191) NULL,
  related_message_id VARCHAR(191) NULL,
  evidence_json JSON NULL,
  finding_fingerprint CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  UNIQUE KEY uq_sharky_finding_fingerprint (finding_fingerprint),
  INDEX idx_sharky_finding_status (status, severity, created_at),
  INDEX idx_sharky_finding_contact (contact_hash, created_at),
  INDEX idx_sharky_finding_review (review_id),
  CONSTRAINT fk_sharky_finding_review FOREIGN KEY (review_id) REFERENCES sharky_conversation_reviews(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
