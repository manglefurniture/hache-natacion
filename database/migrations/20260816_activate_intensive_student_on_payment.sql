-- Hache Natación
-- Una preinscripción pública de intensivo queda PENDIENTE hasta registrar su pago.
-- Al crear un pago válido de INTENSIVO, el alumno pasa automáticamente a ACTIVO.

DROP TRIGGER IF EXISTS trg_activate_intensive_student_on_payment;

DELIMITER $$
CREATE TRIGGER trg_activate_intensive_student_on_payment
AFTER INSERT ON pagos
FOR EACH ROW
BEGIN
    IF NEW.tipo = 'INTENSIVO' AND NEW.estado = 'VALIDO' THEN
        UPDATE alumnos
        SET estado_administrativo = 'ACTIVO',
            updated_at = NOW()
        WHERE id = NEW.alumno_id
          AND estado_administrativo = 'PENDIENTE';
    END IF;
END$$
DELIMITER ;
