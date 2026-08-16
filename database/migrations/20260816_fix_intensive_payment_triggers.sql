-- Hache Natación
-- Corrige la unicidad de pagos de intensivo para cursos con múltiples alumnos.
-- Debe existir un pago válido por alumno + curso, no un único pago por curso.

DROP TRIGGER IF EXISTS trg_un_pago_valido_insert;
DROP TRIGGER IF EXISTS trg_un_pago_valido_update;

DELIMITER $$
CREATE TRIGGER trg_un_pago_valido_insert
BEFORE INSERT ON pagos
FOR EACH ROW
BEGIN
    IF NEW.estado = 'VALIDO' THEN
        IF NEW.inscripcion_id IS NOT NULL AND EXISTS (
            SELECT 1 FROM pagos p WHERE p.inscripcion_id = NEW.inscripcion_id AND p.estado = 'VALIDO'
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya existe un pago válido para esta inscripción';
        END IF;

        IF NEW.mensualidad_id IS NOT NULL AND EXISTS (
            SELECT 1 FROM pagos p WHERE p.mensualidad_id = NEW.mensualidad_id AND p.estado = 'VALIDO'
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya existe un pago válido para esta mensualidad';
        END IF;

        IF NEW.intensivo_id IS NOT NULL AND EXISTS (
            SELECT 1 FROM pagos p
            WHERE p.intensivo_id = NEW.intensivo_id
              AND p.alumno_id = NEW.alumno_id
              AND p.estado = 'VALIDO'
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este alumno ya tiene un pago válido para este intensivo';
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_un_pago_valido_update
BEFORE UPDATE ON pagos
FOR EACH ROW
BEGIN
    IF NEW.estado = 'VALIDO' THEN
        IF NEW.inscripcion_id IS NOT NULL AND EXISTS (
            SELECT 1 FROM pagos p WHERE p.inscripcion_id = NEW.inscripcion_id AND p.estado = 'VALIDO' AND p.id <> NEW.id
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya existe un pago válido para esta inscripción';
        END IF;

        IF NEW.mensualidad_id IS NOT NULL AND EXISTS (
            SELECT 1 FROM pagos p WHERE p.mensualidad_id = NEW.mensualidad_id AND p.estado = 'VALIDO' AND p.id <> NEW.id
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya existe un pago válido para esta mensualidad';
        END IF;

        IF NEW.intensivo_id IS NOT NULL AND EXISTS (
            SELECT 1 FROM pagos p
            WHERE p.intensivo_id = NEW.intensivo_id
              AND p.alumno_id = NEW.alumno_id
              AND p.estado = 'VALIDO'
              AND p.id <> NEW.id
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este alumno ya tiene un pago válido para este intensivo';
        END IF;
    END IF;
END$$
DELIMITER ;
