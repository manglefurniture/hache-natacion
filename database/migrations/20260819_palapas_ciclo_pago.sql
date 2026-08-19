-- Hache Natación — ciclo de pago para alumnos de Palapas
-- P1: mensualidad con renovación el día 1.
-- P15: mensualidad con renovación el día 15.
-- Monteverde conserva su lógica actual y no necesita ciclo explícito.

ALTER TABLE alumnos
  ADD COLUMN IF NOT EXISTS ciclo_pago VARCHAR(3) NULL AFTER sede_id;

CREATE INDEX IF NOT EXISTS idx_alumnos_sede_ciclo
  ON alumnos(sede_id, ciclo_pago);

-- Protección de datos: Monteverde nunca debe quedar marcado como P1/P15.
UPDATE alumnos a
INNER JOIN sedes s ON s.id = a.sede_id
SET a.ciclo_pago = NULL
WHERE s.clave = 'MONTEVERDE'
  AND a.ciclo_pago IS NOT NULL;

-- Esta migración no asigna automáticamente P1/P15 a alumnos Palapas existentes.
-- Deben clasificarse explícitamente para evitar asumir un ciclo incorrecto.
