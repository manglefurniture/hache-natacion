-- F7.2: explicit professor substitutions, forward-only.
-- No rows are inferred from current assignments or cancellation records.

CREATE TABLE IF NOT EXISTS profesor_sustituciones (
  id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
  sesion_id CHAR(36) NOT NULL,
  profesor_original_id CHAR(36) NOT NULL,
  profesor_sustituto_id CHAR(36) NOT NULL,
  motivo VARCHAR(500) NOT NULL,
  origen ENUM('ADMIN') NOT NULL DEFAULT 'ADMIN',
  estado ENUM('ACTIVA','ANULADA') NOT NULL DEFAULT 'ACTIVA',
  created_by CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  anulada_by CHAR(36) NULL,
  anulada_at DATETIME NULL,
  motivo_anulacion VARCHAR(500) NULL,
  activa TINYINT(1) AS (CASE WHEN estado='ACTIVA' THEN 1 ELSE NULL END) PERSISTENT,
  UNIQUE KEY uq_profesor_sustitucion_activa (sesion_id,profesor_original_id,activa),
  KEY idx_profesor_sustituciones_original (profesor_original_id,created_at),
  KEY idx_profesor_sustituciones_sustituto (profesor_sustituto_id,created_at),
  KEY idx_profesor_sustituciones_sesion (sesion_id,estado),
  CONSTRAINT fk_profesor_sustituciones_sesion FOREIGN KEY (sesion_id) REFERENCES sesiones(id) ON DELETE RESTRICT,
  CONSTRAINT fk_profesor_sustituciones_original FOREIGN KEY (profesor_original_id) REFERENCES profesores(id) ON DELETE RESTRICT,
  CONSTRAINT fk_profesor_sustituciones_sustituto FOREIGN KEY (profesor_sustituto_id) REFERENCES profesores(id) ON DELETE RESTRICT,
  CONSTRAINT fk_profesor_sustituciones_created_by FOREIGN KEY (created_by) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_profesor_sustituciones_anulada_by FOREIGN KEY (anulada_by) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT chk_profesor_sustituciones_distintos CHECK (profesor_original_id<>profesor_sustituto_id),
  CONSTRAINT chk_profesor_sustituciones_anulacion CHECK (
    (estado='ACTIVA' AND anulada_at IS NULL AND motivo_anulacion IS NULL)
    OR
    (estado='ANULADA' AND anulada_at IS NOT NULL AND motivo_anulacion IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO configuracion(clave,valor,descripcion)
VALUES(
  'profesores_sustituciones_cobertura_desde',
  DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%d %H:%i:%s'),
  'Inicio forward-only del registro explícito de sustituciones F7'
);
