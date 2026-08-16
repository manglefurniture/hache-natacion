-- Hache Natación
-- Corrige la regla de mensualidades: una observación solo es obligatoria
-- cuando el importe cobrado es distinto al importe estándar.
-- Fecha: 2026-08-16

ALTER TABLE mensualidades
    DROP CONSTRAINT CONSTRAINT_7;

ALTER TABLE mensualidades
    ADD CONSTRAINT chk_mensualidad_importe_observacion
    CHECK (
        importe_cobrado IS NULL
        OR importe_cobrado = importe_estandar
        OR observacion IS NOT NULL
    );
