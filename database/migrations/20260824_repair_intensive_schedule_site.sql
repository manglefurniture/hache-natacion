-- Repara asignaciones historicas de intensivos cuyo horario pertenece a otra
-- sede. La sustitucion solo se realiza cuando existe un unico horario activo
-- de intensivo en la sede del curso con exactamente la misma hora de inicio y
-- fin. Cualquier caso ambiguo o que tambien cruce alumno/curso aborta la
-- migracion para exigir revision manual.

DROP TEMPORARY TABLE IF EXISTS tmp_cia_horario_repair;
CREATE TEMPORARY TABLE tmp_cia_horario_repair (
    relacion_id CHAR(36) PRIMARY KEY,
    horario_correcto_id CHAR(36) NOT NULL
) ENGINE=InnoDB;

INSERT INTO tmp_cia_horario_repair(relacion_id,horario_correcto_id)
SELECT
    cia.id,
    MIN(h_correcto.id)
FROM curso_intensivo_alumnos cia
INNER JOIN cursos_intensivos ci
    ON ci.id=cia.curso_intensivo_id
INNER JOIN alumnos a
    ON a.id=cia.alumno_id
INNER JOIN horarios h_actual
    ON h_actual.id=cia.horario_id
INNER JOIN horarios h_correcto
    ON h_correcto.sede_id=ci.sede_id
   AND h_correcto.hora_inicio=h_actual.hora_inicio
   AND h_correcto.hora_fin=h_actual.hora_fin
   AND h_correcto.intensivo=TRUE
   AND h_correcto.activo=TRUE
WHERE a.sede_id=ci.sede_id
  AND h_actual.sede_id<>ci.sede_id
GROUP BY cia.id
HAVING COUNT(*)=1;

UPDATE curso_intensivo_alumnos cia
INNER JOIN tmp_cia_horario_repair reparacion
    ON reparacion.relacion_id=cia.id
SET cia.horario_id=reparacion.horario_correcto_id;

DROP PROCEDURE IF EXISTS assert_cia_sede_integrity;
DELIMITER $$
CREATE PROCEDURE assert_cia_sede_integrity()
BEGIN
    IF EXISTS (
        SELECT 1
        FROM curso_intensivo_alumnos cia
        INNER JOIN cursos_intensivos ci
            ON ci.id=cia.curso_intensivo_id
        INNER JOIN alumnos a
            ON a.id=cia.alumno_id
        INNER JOIN horarios h
            ON h.id=cia.horario_id
        WHERE a.sede_id<>ci.sede_id OR h.sede_id<>ci.sede_id
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT='Persisten cruces de sede en curso_intensivo_alumnos; se requiere revision manual';
    END IF;
END$$
DELIMITER ;

CALL assert_cia_sede_integrity();
DROP PROCEDURE assert_cia_sede_integrity;
DROP TEMPORARY TABLE tmp_cia_horario_repair;
