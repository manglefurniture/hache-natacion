-- Hache Natación — variantes de plan con la misma frecuencia semanal.
-- Se conserva la unicidad por sede + nombre, pero se elimina la unicidad por
-- sede + sesiones_semana para permitir, por ejemplo, dos planes de 3 días con
-- nombres/precios/reglas comerciales diferentes.

SET @idx_plan_sessions := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema=DATABASE()
    AND table_name='planes'
    AND index_name='uq_planes_sede_sesiones'
    AND non_unique=0
);

SET @drop_plan_sessions := IF(
  @idx_plan_sessions>0,
  'ALTER TABLE planes DROP INDEX uq_planes_sede_sesiones',
  'SELECT 1'
);

PREPARE stmt_drop_plan_sessions FROM @drop_plan_sessions;
EXECUTE stmt_drop_plan_sessions;
DEALLOCATE PREPARE stmt_drop_plan_sessions;
