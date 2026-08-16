-- Verificadores por sede.
-- Regla: todos los verificadores existentes pertenecen a MONTEVERDE.

ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS sede_id CHAR(36) NULL AFTER alumno_id;

SET @monteverde := (SELECT id FROM sedes WHERE clave='MONTEVERDE' LIMIT 1);
UPDATE usuarios SET sede_id=@monteverde WHERE rol='VERIFICADOR' AND sede_id IS NULL;

CREATE INDEX IF NOT EXISTS idx_usuarios_sede ON usuarios(sede_id);
