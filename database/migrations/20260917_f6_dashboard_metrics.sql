-- F6 / P-06: reliable forward coverage for attendance and student-status events.
CREATE TABLE IF NOT EXISTS sesion_asistencia_cobertura (
  sesion_id CHAR(36) NOT NULL PRIMARY KEY,
  expected_count INT UNSIGNED NOT NULL,
  marked_count INT UNSIGNED NOT NULL,
  present_count INT UNSIGNED NOT NULL DEFAULT 0,
  justified_count INT UNSIGNED NOT NULL DEFAULT 0,
  unjustified_count INT UNSIGNED NOT NULL DEFAULT 0,
  complete TINYINT(1) NOT NULL DEFAULT 0,
  captured_by CHAR(36) NULL,
  captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sesion_asistencia_cobertura_complete (complete,captured_at),
  CONSTRAINT fk_sesion_asistencia_cobertura_sesion FOREIGN KEY (sesion_id) REFERENCES sesiones(id) ON DELETE CASCADE,
  CONSTRAINT fk_sesion_asistencia_cobertura_usuario FOREIGN KEY (captured_by) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT chk_sesion_asistencia_cobertura_counts CHECK (
    expected_count>=0
    AND marked_count>=0
    AND present_count>=0
    AND justified_count>=0
    AND unjustified_count>=0
    AND marked_count=present_count+justified_count+unjustified_count
    AND marked_count<=expected_count
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- F6 / P-06: inert authority for future prospect opportunities.
-- This table is intentionally not populated by this micro-step. It permits
-- multiple opportunities for the same contact and uses a hashed origin event
-- as the idempotency boundary, without storing raw phone/name/message content.
CREATE TABLE IF NOT EXISTS sharky_prospect_opportunities (
  id CHAR(36) NOT NULL PRIMARY KEY,
  contact_hash CHAR(64) NOT NULL,
  origin_message_hash CHAR(64) NOT NULL,
  entry_source VARCHAR(30) NULL,
  sede_clave VARCHAR(20) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
  conversion_action_hash CHAR(64) NULL,
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sharky_prospect_origin (origin_message_hash),
  UNIQUE KEY uq_sharky_prospect_conversion_action (conversion_action_hash),
  INDEX idx_sharky_prospect_contact (contact_hash,opened_at),
  INDEX idx_sharky_prospect_cohort (opened_at,status),
  INDEX idx_sharky_prospect_sede (opened_at,sede_clave),
  CONSTRAINT chk_sharky_prospect_status CHECK (status IN ('OPEN','CONVERTED','EXCLUDED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing F6 installations predate the conversion link. Keep the migration
-- additive and idempotent without publishing prospect metrics yet.
ALTER TABLE sharky_prospect_opportunities
  ADD COLUMN IF NOT EXISTS conversion_action_hash CHAR(64) NULL AFTER status,
  ADD UNIQUE INDEX IF NOT EXISTS uq_sharky_prospect_conversion_action (conversion_action_hash);

INSERT IGNORE INTO configuracion(clave,valor,descripcion)
VALUES
  ('dashboard_bajas_cobertura_desde',DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%d %H:%i:%s'),'Inicio de cobertura fiable para bajas registradas por F6'),
  ('dashboard_asistencia_cobertura_desde',DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%d %H:%i:%s'),'Inicio de cobertura persistida para porcentaje de asistencia F6');
