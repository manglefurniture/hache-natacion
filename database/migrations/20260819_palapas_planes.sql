-- Hache Natación — planes regulares por sede
-- Monteverde conserva precios actuales.
-- Palapas: X3 = $800, X5 = $1,000.

ALTER TABLE planes ADD COLUMN IF NOT EXISTS sede_id CHAR(36) NULL AFTER id;

SET @monteverde := (SELECT id FROM sedes WHERE clave='MONTEVERDE' LIMIT 1);
SET @palapas := (SELECT id FROM sedes WHERE clave='PALAPAS' LIMIT 1);

-- Todo plan existente antes de esta migración pertenece a Monteverde.
UPDATE planes SET sede_id=@monteverde WHERE sede_id IS NULL;

-- El schema original tenía nombre UNIQUE global. Lo convertimos a UNIQUE por sede.
SET @idx_nombre := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema=DATABASE() AND table_name='planes' AND index_name='nombre' AND non_unique=0
);
SET @drop_nombre := IF(@idx_nombre>0,'ALTER TABLE planes DROP INDEX nombre','SELECT 1');
PREPARE stmt_drop_nombre FROM @drop_nombre; EXECUTE stmt_drop_nombre; DEALLOCATE PREPARE stmt_drop_nombre;

-- Crea los dos planes de Palapas tomando el nombre de referencia de Monteverde
-- para mantener la nomenclatura ya usada por la aplicación.
INSERT INTO planes(id,sede_id,nombre,sesiones_semana,precio,activo)
SELECT UUID(),@palapas,p.nombre,p.sesiones_semana,800.00,1
FROM planes p
WHERE p.sede_id=@monteverde AND p.sesiones_semana=3
  AND NOT EXISTS (SELECT 1 FROM planes x WHERE x.sede_id=@palapas AND x.sesiones_semana=3)
ORDER BY p.activo DESC,p.precio ASC LIMIT 1;

INSERT INTO planes(id,sede_id,nombre,sesiones_semana,precio,activo)
SELECT UUID(),@palapas,p.nombre,p.sesiones_semana,1000.00,1
FROM planes p
WHERE p.sede_id=@monteverde AND p.sesiones_semana=5
  AND NOT EXISTS (SELECT 1 FROM planes x WHERE x.sede_id=@palapas AND x.sesiones_semana=5)
ORDER BY p.activo DESC,p.precio ASC LIMIT 1;

-- Fuerza precios correctos si la migración se repite.
UPDATE planes SET precio=800.00,activo=1 WHERE sede_id=@palapas AND sesiones_semana=3;
UPDATE planes SET precio=1000.00,activo=1 WHERE sede_id=@palapas AND sesiones_semana=5;

ALTER TABLE planes MODIFY sede_id CHAR(36) NOT NULL;
CREATE INDEX IF NOT EXISTS idx_planes_sede ON planes(sede_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_planes_sede_nombre ON planes(sede_id,nombre);
CREATE UNIQUE INDEX IF NOT EXISTS uq_planes_sede_sesiones ON planes(sede_id,sesiones_semana);
