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

-- P-06: one durable row per prospective participant/opportunity.
-- Raw names and phone numbers stay out of this analytics ledger.
CREATE TABLE IF NOT EXISTS sharky_prospect_opportunities (
  id CHAR(36) NOT NULL PRIMARY KEY,
  contact_hash CHAR(64) NOT NULL,
  entry_source VARCHAR(30) NULL,
  sede_clave VARCHAR(20) NULL,
  status ENUM('OPEN','CONVERTED','EXCLUDED') NOT NULL DEFAULT 'OPEN',
  open_slot TINYINT UNSIGNED NULL DEFAULT 1,
  alumno_id CHAR(36) NULL,
  created_at DATETIME NOT NULL,
  converted_at DATETIME NULL,
  excluded_at DATETIME NULL,
  excluded_reason VARCHAR(40) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sharky_prospect_open (contact_hash,open_slot),
  UNIQUE KEY uq_sharky_prospect_student (alumno_id),
  INDEX idx_sharky_prospect_cohort (created_at,status),
  INDEX idx_sharky_prospect_sede (created_at,sede_clave),
  CONSTRAINT fk_sharky_prospect_student FOREIGN KEY (alumno_id) REFERENCES alumnos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO configuracion(clave,valor,descripcion)
VALUES
  ('dashboard_bajas_cobertura_desde',DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%d %H:%i:%s'),'Inicio de cobertura fiable para bajas registradas por F6'),
  ('dashboard_asistencia_cobertura_desde',DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%d %H:%i:%s'),'Inicio de cobertura persistida para porcentaje de asistencia F6'),
  ('dashboard_prospectos_cobertura_desde',DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%d %H:%i:%s'),'Inicio de cobertura fiable para oportunidades y conversiones F6');
