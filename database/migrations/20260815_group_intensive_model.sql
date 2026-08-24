-- Hache Natación — modelo de cursos intensivos con varios participantes.
-- Debe ejecutarse antes de las migraciones 20260816_* que usan
-- curso_intensivo_alumnos. Es idempotente para instalaciones donde la tabla
-- ya fue creada manualmente.

DROP TRIGGER IF EXISTS trg_validar_horario_intensivo_insert;
DROP TRIGGER IF EXISTS trg_validar_horario_intensivo_update;
DROP TRIGGER IF EXISTS trg_un_intensivo_activo_insert;
DROP TRIGGER IF EXISTS trg_un_intensivo_activo_update;

CREATE TABLE IF NOT EXISTS curso_intensivo_alumnos (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    curso_intensivo_id CHAR(36) NOT NULL,
    alumno_id CHAR(36) NOT NULL,
    horario_id CHAR(36) NOT NULL,
    reposiciones_justificadas TINYINT UNSIGNED NOT NULL DEFAULT 0,
    reposiciones_cancelacion TINYINT UNSIGNED NOT NULL DEFAULT 0,
    continua_regular BOOLEAN NULL,
    plan_continuidad_id CHAR(36) NULL,
    importe_continuidad DECIMAL(10,2) NULL,
    observacion_continuidad TEXT NULL,
    observaciones TEXT NULL,
    created_by CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_curso_intensivo_alumno (curso_intensivo_id,alumno_id),
    KEY idx_cia_alumno (alumno_id),
    KEY idx_cia_horario (horario_id),
    CONSTRAINT fk_cia_curso FOREIGN KEY (curso_intensivo_id) REFERENCES cursos_intensivos(id),
    CONSTRAINT fk_cia_alumno FOREIGN KEY (alumno_id) REFERENCES alumnos(id),
    CONSTRAINT fk_cia_horario FOREIGN KEY (horario_id) REFERENCES horarios(id),
    CONSTRAINT fk_cia_plan_continuidad FOREIGN KEY (plan_continuidad_id) REFERENCES planes(id),
    CONSTRAINT fk_cia_created_by FOREIGN KEY (created_by) REFERENCES usuarios(id),
    CONSTRAINT chk_cia_reposiciones_justificadas CHECK (reposiciones_justificadas BETWEEN 0 AND 5),
    CONSTRAINT chk_cia_importe_continuidad CHECK (importe_continuidad IS NULL OR importe_continuidad >= 0),
    CONSTRAINT chk_cia_continuidad_plan CHECK (continua_regular IS NULL OR continua_regular=FALSE OR plan_continuidad_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compatibilidad con variantes incompletas que ya tenían la tabla.
ALTER TABLE curso_intensivo_alumnos
    ADD COLUMN IF NOT EXISTS reposiciones_justificadas TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS reposiciones_cancelacion TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS continua_regular BOOLEAN NULL,
    ADD COLUMN IF NOT EXISTS plan_continuidad_id CHAR(36) NULL,
    ADD COLUMN IF NOT EXISTS importe_continuidad DECIMAL(10,2) NULL,
    ADD COLUMN IF NOT EXISTS observacion_continuidad TEXT NULL,
    ADD COLUMN IF NOT EXISTS observaciones TEXT NULL,
    ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

CREATE UNIQUE INDEX IF NOT EXISTS uq_curso_intensivo_alumno ON curso_intensivo_alumnos(curso_intensivo_id,alumno_id);
CREATE INDEX IF NOT EXISTS idx_cia_alumno ON curso_intensivo_alumnos(alumno_id);
CREATE INDEX IF NOT EXISTS idx_cia_horario ON curso_intensivo_alumnos(horario_id);

-- CREATE TABLE IF NOT EXISTS no completa restricciones cuando la tabla ya
-- existía. Agregar cada CHECK faltante de forma idempotente para instalaciones
-- creadas manualmente antes de que esta migración se incorporara al repositorio.
SET @has_chk_cia_reposiciones := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema=DATABASE() AND table_name='curso_intensivo_alumnos'
      AND constraint_name='chk_cia_reposiciones'
);
SET @add_chk_cia_reposiciones := IF(
    @has_chk_cia_reposiciones=0,
    'ALTER TABLE curso_intensivo_alumnos ADD CONSTRAINT chk_cia_reposiciones CHECK (reposiciones_justificadas BETWEEN 0 AND 5)',
    'SELECT 1'
);
PREPARE stmt_chk_cia_reposiciones FROM @add_chk_cia_reposiciones;
EXECUTE stmt_chk_cia_reposiciones;
DEALLOCATE PREPARE stmt_chk_cia_reposiciones;

SET @has_chk_cia_importe := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema=DATABASE() AND table_name='curso_intensivo_alumnos'
      AND constraint_name='chk_cia_importe_continuidad'
);
SET @add_chk_cia_importe := IF(
    @has_chk_cia_importe=0,
    'ALTER TABLE curso_intensivo_alumnos ADD CONSTRAINT chk_cia_importe_continuidad CHECK (importe_continuidad IS NULL OR importe_continuidad >= 0)',
    'SELECT 1'
);
PREPARE stmt_chk_cia_importe FROM @add_chk_cia_importe;
EXECUTE stmt_chk_cia_importe;
DEALLOCATE PREPARE stmt_chk_cia_importe;

SET @has_chk_cia_continuidad := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema=DATABASE() AND table_name='curso_intensivo_alumnos'
      AND constraint_name='chk_cia_continuidad_plan'
);
SET @add_chk_cia_continuidad := IF(
    @has_chk_cia_continuidad=0,
    'ALTER TABLE curso_intensivo_alumnos ADD CONSTRAINT chk_cia_continuidad_plan CHECK (continua_regular IS NULL OR continua_regular=FALSE OR plan_continuidad_id IS NOT NULL)',
    'SELECT 1'
);
PREPARE stmt_chk_cia_continuidad FROM @add_chk_cia_continuidad;
EXECUTE stmt_chk_cia_continuidad;
DEALLOCATE PREPARE stmt_chk_cia_continuidad;

-- El schema original guardaba un alumno por fila de curso. Migra esos datos
-- si las columnas antiguas todavía existen y conserva después las columnas
-- como NULL para que las instalaciones antiguas sigan siendo compatibles.
SET @legacy_intensive_columns := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='cursos_intensivos'
      AND column_name IN ('alumno_id','horario_id')
);
SET @copy_legacy_intensives := IF(
    @legacy_intensive_columns=2,
    'INSERT IGNORE INTO curso_intensivo_alumnos(id,curso_intensivo_id,alumno_id,horario_id,reposiciones_justificadas,reposiciones_cancelacion,continua_regular,plan_continuidad_id,importe_continuidad,observacion_continuidad,observaciones,created_by,created_at) SELECT UUID(),id,alumno_id,horario_id,reposiciones_justificadas,reposiciones_cancelacion,continua_regular,plan_continuidad_id,importe_continuidad,observacion_continuidad,observaciones,created_by,created_at FROM cursos_intensivos WHERE alumno_id IS NOT NULL AND horario_id IS NOT NULL',
    'SELECT 1'
);
PREPARE stmt_copy_legacy_intensives FROM @copy_legacy_intensives;
EXECUTE stmt_copy_legacy_intensives;
DEALLOCATE PREPARE stmt_copy_legacy_intensives;

SET @has_legacy_alumno := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='cursos_intensivos' AND column_name='alumno_id'
);
SET @make_legacy_alumno_nullable := IF(@has_legacy_alumno>0,'ALTER TABLE cursos_intensivos MODIFY COLUMN alumno_id CHAR(36) NULL','SELECT 1');
PREPARE stmt_legacy_alumno FROM @make_legacy_alumno_nullable;
EXECUTE stmt_legacy_alumno;
DEALLOCATE PREPARE stmt_legacy_alumno;

SET @has_legacy_horario := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='cursos_intensivos' AND column_name='horario_id'
);
SET @make_legacy_horario_nullable := IF(@has_legacy_horario>0,'ALTER TABLE cursos_intensivos MODIFY COLUMN horario_id CHAR(36) NULL','SELECT 1');
PREPARE stmt_legacy_horario FROM @make_legacy_horario_nullable;
EXECUTE stmt_legacy_horario;
DEALLOCATE PREPARE stmt_legacy_horario;

-- Las reglas que antes vivían en cursos_intensivos ahora corresponden a cada
-- participante del curso.
DROP TRIGGER IF EXISTS trg_cia_validar_insert;
DROP TRIGGER IF EXISTS trg_cia_validar_update;
DELIMITER $$
CREATE TRIGGER trg_cia_validar_insert
BEFORE INSERT ON curso_intensivo_alumnos
FOR EACH ROW
BEGIN
    DECLARE v_estado VARCHAR(20);
    IF NOT EXISTS (
        SELECT 1 FROM horarios h
        WHERE h.id=NEW.horario_id AND h.intensivo=TRUE AND h.activo=TRUE
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El horario seleccionado no está habilitado para intensivos';
    END IF;
    SELECT estado INTO v_estado FROM cursos_intensivos WHERE id=NEW.curso_intensivo_id LIMIT 1;
    IF v_estado IN ('PROGRAMADO','EN_CURSO') AND EXISTS (
        SELECT 1 FROM curso_intensivo_alumnos cia
        INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id
        WHERE cia.alumno_id=NEW.alumno_id
          AND ci.id<>NEW.curso_intensivo_id
          AND ci.estado IN ('PROGRAMADO','EN_CURSO')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El alumno ya tiene otro intensivo activo';
    END IF;
END$$

CREATE TRIGGER trg_cia_validar_update
BEFORE UPDATE ON curso_intensivo_alumnos
FOR EACH ROW
BEGIN
    DECLARE v_estado VARCHAR(20);
    IF NOT EXISTS (
        SELECT 1 FROM horarios h
        WHERE h.id=NEW.horario_id AND h.intensivo=TRUE AND h.activo=TRUE
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El horario seleccionado no está habilitado para intensivos';
    END IF;
    SELECT estado INTO v_estado FROM cursos_intensivos WHERE id=NEW.curso_intensivo_id LIMIT 1;
    IF v_estado IN ('PROGRAMADO','EN_CURSO') AND EXISTS (
        SELECT 1 FROM curso_intensivo_alumnos cia
        INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id
        WHERE cia.alumno_id=NEW.alumno_id
          AND cia.id<>OLD.id
          AND ci.id<>NEW.curso_intensivo_id
          AND ci.estado IN ('PROGRAMADO','EN_CURSO')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El alumno ya tiene otro intensivo activo';
    END IF;
END$$
DELIMITER ;
