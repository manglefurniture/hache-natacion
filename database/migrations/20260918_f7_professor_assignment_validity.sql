-- F7.1: forward-only validity periods for professor schedule assignments.
-- Existing active assignments receive a baseline period starting only at the
-- F7 coverage marker. No earlier teaching history is inferred.

CREATE TABLE IF NOT EXISTS profesor_horario_vigencias (
  id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
  profesor_horario_id CHAR(36) NOT NULL,
  vigente_desde DATETIME NOT NULL,
  vigente_hasta DATETIME NULL,
  origen ENUM('F7_BASELINE','ADMIN') NOT NULL,
  created_by CHAR(36) NULL,
  closed_by CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  abierta TINYINT(1) AS (CASE WHEN vigente_hasta IS NULL THEN 1 ELSE NULL END) PERSISTENT,
  UNIQUE KEY uq_profesor_horario_vigencia_abierta (profesor_horario_id,abierta),
  KEY idx_profesor_horario_vigencias_periodo (profesor_horario_id,vigente_desde,vigente_hasta),
  CONSTRAINT fk_profesor_horario_vigencias_asignacion FOREIGN KEY (profesor_horario_id) REFERENCES profesor_horarios(id) ON DELETE RESTRICT,
  CONSTRAINT fk_profesor_horario_vigencias_created_by FOREIGN KEY (created_by) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_profesor_horario_vigencias_closed_by FOREIGN KEY (closed_by) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT chk_profesor_horario_vigencias_periodo CHECK (vigente_hasta IS NULL OR vigente_hasta>=vigente_desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO configuracion(clave,valor,descripcion)
VALUES(
  'profesores_asignaciones_cobertura_desde',
  DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%d %H:%i:%s'),
  'Inicio forward-only de vigencia durable de asignaciones de profesores F7'
);

-- Reconcile legacy assignments that still appear active even though the
-- professor was already inactive. If the previous F7.1 baseline created an
-- open period for one of those rows, collapse only that synthetic baseline to
-- a zero-length interval rather than inventing teaching history.
UPDATE profesor_horario_vigencias v
JOIN profesor_horarios ph ON ph.id=v.profesor_horario_id
JOIN profesores p ON p.id=ph.profesor_id
SET v.vigente_hasta=v.vigente_desde,
    v.closed_by=NULL
WHERE p.activo=0
  AND v.vigente_hasta IS NULL
  AND v.origen='F7_BASELINE';

UPDATE profesor_horarios ph
JOIN profesores p ON p.id=ph.profesor_id
SET ph.activo=0,
    ph.updated_at=UTC_TIMESTAMP()
WHERE ph.activo=1
  AND p.activo=0;

INSERT INTO profesor_horario_vigencias(
  id,profesor_horario_id,vigente_desde,vigente_hasta,origen,created_by,closed_by
)
SELECT
  UUID(),ph.id,CAST(cfg.valor AS DATETIME),NULL,'F7_BASELINE',NULL,NULL
FROM profesor_horarios ph
JOIN profesores p ON p.id=ph.profesor_id
JOIN configuracion cfg
  ON cfg.clave='profesores_asignaciones_cobertura_desde'
WHERE ph.activo=1
  AND p.activo=1
  AND NOT EXISTS(
    SELECT 1 FROM configuracion
    WHERE clave='profesores_asignaciones_baseline_aplicado'
  )
  AND NOT EXISTS(
    SELECT 1 FROM profesor_horario_vigencias v
    WHERE v.profesor_horario_id=ph.id AND v.vigente_hasta IS NULL
  );

INSERT IGNORE INTO configuracion(clave,valor,descripcion)
VALUES(
  'profesores_asignaciones_baseline_aplicado',
  '1',
  'Baseline forward-only F7 de asignaciones activas aplicado'
);
