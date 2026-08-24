-- Hache Natación — tablas de soporte para reglas y diagnóstico.
-- Evita ejecutar DDL desde solicitudes web y elimina la contraseña temporal
-- compartida que usaban versiones anteriores.

CREATE TABLE IF NOT EXISTS alumno_reglas_negocio (
    alumno_id CHAR(36) PRIMARY KEY,
    inscripcion_historica_cubierta TINYINT(1) NOT NULL DEFAULT 0,
    nota VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_alumno_reglas_negocio_alumno FOREIGN KEY (alumno_id) REFERENCES alumnos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS diagnostico_eventos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    usuario_id CHAR(36) NULL,
    rol VARCHAR(20) NULL,
    tipo VARCHAR(30) NOT NULL,
    nivel VARCHAR(12) NOT NULL DEFAULT 'INFO',
    pagina VARCHAR(190) NULL,
    recurso VARCHAR(190) NULL,
    mensaje VARCHAR(500) NULL,
    detalle TEXT NULL,
    duracion_ms INT NULL,
    status_http SMALLINT NULL,
    dispositivo VARCHAR(40) NULL,
    user_agent VARCHAR(255) NULL,
    PRIMARY KEY(id),
    KEY idx_diag_fecha(creado_en),
    KEY idx_diag_tipo(tipo),
    KEY idx_diag_nivel(nivel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Los avisos también pertenecen a una sede. Los mensajes históricos se
-- originaron antes de Palapas y, por tanto, se conservan en Monteverde.
ALTER TABLE mensajes ADD COLUMN IF NOT EXISTS sede_id CHAR(36) NULL AFTER id;
SET @mensajes_monteverde := (SELECT id FROM sedes WHERE clave='MONTEVERDE' LIMIT 1);
UPDATE mensajes SET sede_id=@mensajes_monteverde WHERE sede_id IS NULL;
ALTER TABLE mensajes MODIFY sede_id CHAR(36) NOT NULL;
CREATE INDEX IF NOT EXISTS idx_mensajes_sede_fecha ON mensajes(sede_id,created_at);

-- Compatibilidad de rollback: la versión estable anterior aún inserta mensajes
-- sin sede_id. Los mensajes dirigidos heredan la sede del alumno y los demás
-- conservan el comportamiento histórico de Monteverde.
DROP TRIGGER IF EXISTS trg_mensajes_sede_default;
DELIMITER $$
CREATE TRIGGER trg_mensajes_sede_default
BEFORE INSERT ON mensajes
FOR EACH ROW
BEGIN
    IF NEW.sede_id IS NULL OR NEW.sede_id='' THEN
        SET NEW.sede_id=COALESCE(
            (SELECT sede_id FROM alumnos WHERE id=NEW.alumno_id LIMIT 1),
            (SELECT id FROM sedes WHERE clave='MONTEVERDE' LIMIT 1)
        );
    END IF;
END$$
DELIMITER ;

SET @has_mensajes_sede_fk := (
    SELECT COUNT(*)
    FROM information_schema.key_column_usage
    WHERE table_schema=DATABASE()
      AND table_name='mensajes'
      AND column_name='sede_id'
      AND referenced_table_name='sedes'
      AND referenced_column_name='id'
);
SET @add_mensajes_sede_fk := IF(
    @has_mensajes_sede_fk=0,
    'ALTER TABLE mensajes ADD CONSTRAINT fk_mensajes_sede FOREIGN KEY (sede_id) REFERENCES sedes(id)',
    'SELECT 1'
);
PREPARE stmt_mensajes_sede_fk FROM @add_mensajes_sede_fk;
EXECUTE stmt_mensajes_sede_fk;
DEALLOCATE PREPARE stmt_mensajes_sede_fk;

-- La aplicación ya valida estos cruces, pero las restricciones en base de
-- datos impiden que una importación o una operación manual mezcle sedes o
-- asigne simultáneamente al mismo alumno a dos intensivos activos.
DROP TRIGGER IF EXISTS trg_cia_validar_insert;
DROP TRIGGER IF EXISTS trg_cia_validar_update;
DROP TRIGGER IF EXISTS trg_curso_intensivo_estado_update;
DELIMITER $$
CREATE TRIGGER trg_cia_validar_insert
BEFORE INSERT ON curso_intensivo_alumnos
FOR EACH ROW
BEGIN
    DECLARE v_estado VARCHAR(20) DEFAULT NULL;
    SELECT MAX(ci.estado) INTO v_estado
    FROM cursos_intensivos ci
    INNER JOIN alumnos a
        ON a.id=NEW.alumno_id AND a.sede_id=ci.sede_id
    INNER JOIN horarios h
        ON h.id=NEW.horario_id AND h.sede_id=ci.sede_id
       AND h.intensivo=TRUE AND h.activo=TRUE
    WHERE ci.id=NEW.curso_intensivo_id;

    IF v_estado IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Curso, alumno y horario deben pertenecer a la misma sede';
    END IF;
    IF v_estado IN ('PROGRAMADO','EN_CURSO') AND EXISTS (
        SELECT 1
        FROM curso_intensivo_alumnos cia
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
    DECLARE v_estado VARCHAR(20) DEFAULT NULL;
    SELECT MAX(ci.estado) INTO v_estado
    FROM cursos_intensivos ci
    INNER JOIN alumnos a
        ON a.id=NEW.alumno_id AND a.sede_id=ci.sede_id
    INNER JOIN horarios h
        ON h.id=NEW.horario_id AND h.sede_id=ci.sede_id
       AND h.intensivo=TRUE AND h.activo=TRUE
    WHERE ci.id=NEW.curso_intensivo_id;

    IF v_estado IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Curso, alumno y horario deben pertenecer a la misma sede';
    END IF;
    IF v_estado IN ('PROGRAMADO','EN_CURSO') AND EXISTS (
        SELECT 1
        FROM curso_intensivo_alumnos cia
        INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id
        WHERE cia.alumno_id=NEW.alumno_id
          AND cia.id<>OLD.id
          AND ci.id<>NEW.curso_intensivo_id
          AND ci.estado IN ('PROGRAMADO','EN_CURSO')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El alumno ya tiene otro intensivo activo';
    END IF;
END$$

CREATE TRIGGER trg_curso_intensivo_estado_update
BEFORE UPDATE ON cursos_intensivos
FOR EACH ROW
BEGIN
    IF NEW.estado IN ('PROGRAMADO','EN_CURSO') AND EXISTS (
        SELECT 1
        FROM curso_intensivo_alumnos propia
        INNER JOIN curso_intensivo_alumnos otra
            ON otra.alumno_id=propia.alumno_id
           AND otra.curso_intensivo_id<>propia.curso_intensivo_id
        INNER JOIN cursos_intensivos otro_curso
            ON otro_curso.id=otra.curso_intensivo_id
           AND otro_curso.estado IN ('PROGRAMADO','EN_CURSO')
        WHERE propia.curso_intensivo_id=NEW.id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Un participante ya tiene otro intensivo activo';
    END IF;
END$$
DELIMITER ;

DELETE FROM configuracion WHERE clave='password_temporal';

-- Una edición de pago no debe reinterpretar una mensualidad histórica con el
-- ciclo P1/P15 que el alumno tenga hoy. Las fechas explícitas son inmutables;
-- el trigger solo completa instalaciones o flujos antiguos que aún manden NULL.
DROP TRIGGER IF EXISTS trg_mensualidades_periodo_insert;
DROP TRIGGER IF EXISTS trg_mensualidades_periodo_update;
DELIMITER $$
CREATE TRIGGER trg_mensualidades_periodo_insert BEFORE INSERT ON mensualidades FOR EACH ROW
BEGIN
    DECLARE v_clave VARCHAR(30);
    DECLARE v_ciclo VARCHAR(3);
    IF NEW.periodo_inicio IS NULL OR NEW.periodo_fin IS NULL THEN
        SELECT s.clave,a.ciclo_pago INTO v_clave,v_ciclo
        FROM alumnos a INNER JOIN sedes s ON s.id=a.sede_id
        WHERE a.id=NEW.alumno_id LIMIT 1;
        IF v_clave='PALAPAS' AND v_ciclo='P15' THEN
            SET NEW.periodo_inicio=STR_TO_DATE(CONCAT(NEW.anio,'-',LPAD(NEW.mes,2,'0'),'-15'),'%Y-%m-%d');
            SET NEW.periodo_fin=DATE_SUB(DATE_ADD(NEW.periodo_inicio,INTERVAL 1 MONTH),INTERVAL 1 DAY);
        ELSE
            SET NEW.periodo_inicio=STR_TO_DATE(CONCAT(NEW.anio,'-',LPAD(NEW.mes,2,'0'),'-01'),'%Y-%m-%d');
            SET NEW.periodo_fin=LAST_DAY(NEW.periodo_inicio);
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_mensualidades_periodo_update BEFORE UPDATE ON mensualidades FOR EACH ROW
BEGIN
    DECLARE v_clave VARCHAR(30);
    DECLARE v_ciclo VARCHAR(3);
    IF NEW.periodo_inicio IS NULL OR NEW.periodo_fin IS NULL THEN
        SELECT s.clave,a.ciclo_pago INTO v_clave,v_ciclo
        FROM alumnos a INNER JOIN sedes s ON s.id=a.sede_id
        WHERE a.id=NEW.alumno_id LIMIT 1;
        IF v_clave='PALAPAS' AND v_ciclo='P15' THEN
            SET NEW.periodo_inicio=STR_TO_DATE(CONCAT(NEW.anio,'-',LPAD(NEW.mes,2,'0'),'-15'),'%Y-%m-%d');
            SET NEW.periodo_fin=DATE_SUB(DATE_ADD(NEW.periodo_inicio,INTERVAL 1 MONTH),INTERVAL 1 DAY);
        ELSE
            SET NEW.periodo_inicio=STR_TO_DATE(CONCAT(NEW.anio,'-',LPAD(NEW.mes,2,'0'),'-01'),'%Y-%m-%d');
            SET NEW.periodo_fin=LAST_DAY(NEW.periodo_inicio);
        END IF;
    END IF;
END$$
DELIMITER ;
