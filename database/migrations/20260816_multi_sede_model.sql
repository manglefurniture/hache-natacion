-- Hache Natación — modelo multi-sede
-- Regla de migración: TODO dato existente pertenece a MONTEVERDE.

CREATE TABLE IF NOT EXISTS sedes (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    clave VARCHAR(30) NOT NULL UNIQUE,
    nombre VARCHAR(100) NOT NULL,
    socio VARCHAR(100) NOT NULL,
    porcentaje_mensualidad_socio DECIMAL(5,2) NOT NULL,
    porcentaje_intensivo_socio DECIMAL(5,2) NOT NULL,
    porcentaje_inscripcion_socio DECIMAL(5,2) NOT NULL,
    minimo_mensual_socio DECIMAL(10,2) NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO sedes (id,clave,nombre,socio,porcentaje_mensualidad_socio,porcentaje_intensivo_socio,porcentaje_inscripcion_socio,minimo_mensual_socio)
SELECT UUID(),'MONTEVERDE','Monteverde','PROA Nadadores',50.00,50.00,100.00,28000.00
WHERE NOT EXISTS (SELECT 1 FROM sedes WHERE clave='MONTEVERDE');

INSERT INTO sedes (id,clave,nombre,socio,porcentaje_mensualidad_socio,porcentaje_intensivo_socio,porcentaje_inscripcion_socio,minimo_mensual_socio)
SELECT UUID(),'PALAPAS','Palapas','PROTUDEC',60.00,50.00,100.00,NULL
WHERE NOT EXISTS (SELECT 1 FROM sedes WHERE clave='PALAPAS');

SET @monteverde := (SELECT id FROM sedes WHERE clave='MONTEVERDE' LIMIT 1);

ALTER TABLE alumnos ADD COLUMN IF NOT EXISTS sede_id CHAR(36) NULL AFTER id;
ALTER TABLE cursos_intensivos ADD COLUMN IF NOT EXISTS sede_id CHAR(36) NULL AFTER id;
ALTER TABLE inscripciones ADD COLUMN IF NOT EXISTS sede_id CHAR(36) NULL AFTER id;
ALTER TABLE mensualidades ADD COLUMN IF NOT EXISTS sede_id CHAR(36) NULL AFTER id;
ALTER TABLE horarios ADD COLUMN IF NOT EXISTS sede_id CHAR(36) NULL AFTER id;

UPDATE alumnos SET sede_id=@monteverde WHERE sede_id IS NULL;
UPDATE cursos_intensivos SET sede_id=@monteverde WHERE sede_id IS NULL;
UPDATE inscripciones SET sede_id=@monteverde WHERE sede_id IS NULL;
UPDATE mensualidades SET sede_id=@monteverde WHERE sede_id IS NULL;
UPDATE horarios SET sede_id=@monteverde WHERE sede_id IS NULL;

ALTER TABLE alumnos MODIFY sede_id CHAR(36) NOT NULL;
ALTER TABLE cursos_intensivos MODIFY sede_id CHAR(36) NOT NULL;
ALTER TABLE inscripciones MODIFY sede_id CHAR(36) NOT NULL;
ALTER TABLE mensualidades MODIFY sede_id CHAR(36) NOT NULL;
ALTER TABLE horarios MODIFY sede_id CHAR(36) NOT NULL;

CREATE INDEX IF NOT EXISTS idx_alumnos_sede ON alumnos(sede_id);
CREATE INDEX IF NOT EXISTS idx_cursos_sede ON cursos_intensivos(sede_id);
CREATE INDEX IF NOT EXISTS idx_inscripciones_sede ON inscripciones(sede_id);
CREATE INDEX IF NOT EXISTS idx_mensualidades_sede_periodo ON mensualidades(sede_id,anio,mes);
CREATE INDEX IF NOT EXISTS idx_horarios_sede ON horarios(sede_id);
