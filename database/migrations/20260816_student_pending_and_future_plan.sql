-- Hache Natación
-- Alumnos nuevos pendientes hasta su primer pago válido,
-- fecha de nacimiento opcional y cambio de plan programado.

ALTER TABLE alumnos
    MODIFY fecha_nacimiento DATE NULL,
    MODIFY estado_administrativo ENUM('PENDIENTE','ACTIVO','BAJA') NOT NULL DEFAULT 'PENDIENTE',
    ADD COLUMN plan_programado_id CHAR(36) NULL AFTER plan_actual_id,
    ADD COLUMN plan_programado_desde DATE NULL AFTER plan_programado_id,
    ADD CONSTRAINT fk_alumnos_plan_programado FOREIGN KEY (plan_programado_id) REFERENCES planes(id),
    ADD CONSTRAINT chk_alumnos_plan_programado CHECK (
        (plan_programado_id IS NULL AND plan_programado_desde IS NULL)
        OR
        (plan_programado_id IS NOT NULL AND plan_programado_desde IS NOT NULL)
    );

-- Los alumnos que ya están en un intensivo activo dejan de operar como regulares.
UPDATE alumnos a
INNER JOIN curso_intensivo_alumnos cia ON cia.alumno_id = a.id
INNER JOIN cursos_intensivos ci ON ci.id = cia.curso_intensivo_id
SET a.plan_actual_id = NULL,
    a.horario_preferido_id = NULL,
    a.plan_programado_id = NULL,
    a.plan_programado_desde = NULL,
    a.updated_at = NOW()
WHERE ci.estado IN ('PROGRAMADO','EN_CURSO');

-- Y los nuevos ingresos a intensivo se convierten automáticamente.
DROP TRIGGER IF EXISTS trg_cia_convertir_a_intensivo;
DELIMITER $$
CREATE TRIGGER trg_cia_convertir_a_intensivo
AFTER INSERT ON curso_intensivo_alumnos
FOR EACH ROW
BEGIN
    UPDATE alumnos
    SET plan_actual_id = NULL,
        horario_preferido_id = NULL,
        plan_programado_id = NULL,
        plan_programado_desde = NULL,
        updated_at = NOW()
    WHERE id = NEW.alumno_id;
END$$
DELIMITER ;
