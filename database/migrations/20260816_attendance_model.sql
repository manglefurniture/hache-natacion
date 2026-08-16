-- Hache Natación — sesiones y asistencia
-- Modelo mobile-first para control diario de clases regulares e intensivos.

CREATE TABLE IF NOT EXISTS asistencias (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    sesion_id CHAR(36) NOT NULL,
    alumno_id CHAR(36) NOT NULL,
    estado ENUM('PRESENTE','AUSENTE_JUSTIFICADA','AUSENTE_NO_JUSTIFICADA') NOT NULL,
    observacion TEXT NULL,
    created_by CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_asistencia_sesion_alumno (sesion_id, alumno_id),
    CONSTRAINT fk_asistencia_sesion FOREIGN KEY (sesion_id) REFERENCES sesiones(id),
    CONSTRAINT fk_asistencia_alumno FOREIGN KEY (alumno_id) REFERENCES alumnos(id),
    CONSTRAINT fk_asistencia_created_by FOREIGN KEY (created_by) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_asistencia_alumno_fecha ON asistencias (alumno_id, created_at);

-- Garantiza una sola sesión por fecha+horario. Se añade solo si no hay
-- duplicados históricos y si el índice todavía no existe.
SET @sesiones_dup := (
    SELECT COUNT(*)
    FROM (
        SELECT fecha, horario_id
        FROM sesiones
        WHERE horario_id IS NOT NULL
        GROUP BY fecha, horario_id
        HAVING COUNT(*) > 1
    ) d
);
SET @sesiones_idx := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'sesiones'
      AND index_name = 'uq_sesion_fecha_horario'
);
SET @sql_sesiones := IF(
    @sesiones_dup = 0 AND @sesiones_idx = 0,
    'ALTER TABLE sesiones ADD UNIQUE KEY uq_sesion_fecha_horario (fecha, horario_id)',
    'SELECT 1'
);
PREPARE stmt_sesiones FROM @sql_sesiones;
EXECUTE stmt_sesiones;
DEALLOCATE PREPARE stmt_sesiones;

-- Las ausencias antiguas asumían un solo alumno por curso. El modelo actual
-- admite varios alumnos por intensivo, así que la unicidad correcta es alumno+curso+fecha.
DROP TRIGGER IF EXISTS trg_validar_ausencia_intensivo_insert;
DROP TRIGGER IF EXISTS trg_validar_ausencia_intensivo_update;
ALTER TABLE ausencias DROP INDEX intensivo_id;
ALTER TABLE ausencias ADD UNIQUE KEY uq_ausencia_intensivo_alumno_fecha (intensivo_id, alumno_id, fecha);

DELIMITER $$
CREATE TRIGGER trg_validar_ausencia_intensivo_insert
BEFORE INSERT ON ausencias
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM curso_intensivo_alumnos cia
        WHERE cia.curso_intensivo_id = NEW.intensivo_id
          AND cia.alumno_id = NEW.alumno_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La ausencia no corresponde al alumno del intensivo';
    END IF;
END$$

CREATE TRIGGER trg_validar_ausencia_intensivo_update
BEFORE UPDATE ON ausencias
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM curso_intensivo_alumnos cia
        WHERE cia.curso_intensivo_id = NEW.intensivo_id
          AND cia.alumno_id = NEW.alumno_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La ausencia no corresponde al alumno del intensivo';
    END IF;
END$$
DELIMITER ;

-- Reposiciones regulares se contabilizan explícitamente y quedan auditables.
CREATE TABLE IF NOT EXISTS reposiciones_regulares (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    alumno_id CHAR(36) NOT NULL,
    ausencia_asistencia_id CHAR(36) NOT NULL,
    sesion_reposicion_id CHAR(36) NULL,
    estado ENUM('DISPONIBLE','USADA','VENCIDA','CANCELADA') NOT NULL DEFAULT 'DISPONIBLE',
    created_by CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used_at DATETIME NULL,
    UNIQUE KEY uq_reposicion_ausencia (ausencia_asistencia_id),
    CONSTRAINT fk_repo_regular_alumno FOREIGN KEY (alumno_id) REFERENCES alumnos(id),
    CONSTRAINT fk_repo_regular_ausencia FOREIGN KEY (ausencia_asistencia_id) REFERENCES asistencias(id),
    CONSTRAINT fk_repo_regular_sesion FOREIGN KEY (sesion_reposicion_id) REFERENCES sesiones(id),
    CONSTRAINT fk_repo_regular_created_by FOREIGN KEY (created_by) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
