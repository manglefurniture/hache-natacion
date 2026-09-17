-- F6 / P-06: reliable forward coverage for attendance and student-status events.
CREATE TABLE IF NOT EXISTS sesion_asistencia_cobertura (
  sesion_id CHAR(36) NOT NULL PRIMARY KEY,
  expected_count INT UNSIGNED NOT NULL,
  marked_count INT UNSIGNED NOT NULL,
  complete TINYINT(1) NOT NULL DEFAULT 0,
  captured_by CHAR(36) NULL,
  captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sesion_asistencia_cobertura_complete (complete,captured_at),
  CONSTRAINT fk_sesion_asistencia_cobertura_sesion FOREIGN KEY (sesion_id) REFERENCES sesiones(id) ON DELETE CASCADE,
  CONSTRAINT fk_sesion_asistencia_cobertura_usuario FOREIGN KEY (captured_by) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT chk_sesion_asistencia_cobertura_counts CHECK (expected_count>=0 AND marked_count>=0 AND marked_count<=expected_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO configuracion(clave,valor,descripcion)
VALUES
  ('dashboard_bajas_cobertura_desde',DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i:%s'),'Inicio de cobertura fiable para bajas registradas por F6'),
  ('dashboard_asistencia_cobertura_desde',DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i:%s'),'Inicio de cobertura persistida para porcentaje de asistencia F6');
