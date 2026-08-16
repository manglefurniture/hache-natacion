-- Formularios públicos por sede/tipo + horarios Palapas
SET @palapas := (SELECT id FROM sedes WHERE clave='PALAPAS' LIMIT 1);

-- La unicidad original impedía repetir la misma franja en otra sede.
SET @idx_h := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='horarios' AND index_name='hora_inicio');
-- Detecta cualquier UNIQUE antiguo exactamente sobre hora_inicio/hora_fin y lo elimina dinámicamente.
SET @old_unique := (
 SELECT INDEX_NAME FROM information_schema.statistics
 WHERE table_schema=DATABASE() AND table_name='horarios' AND NON_UNIQUE=0 AND INDEX_NAME<>'PRIMARY'
 GROUP BY INDEX_NAME
 HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)= 'hora_inicio,hora_fin'
 LIMIT 1
);
SET @drop_sql := IF(@old_unique IS NULL,'SELECT 1',CONCAT('ALTER TABLE horarios DROP INDEX `',@old_unique,'`'));
PREPARE st FROM @drop_sql; EXECUTE st; DEALLOCATE PREPARE st;
CREATE UNIQUE INDEX IF NOT EXISTS uq_horarios_sede_franja ON horarios(sede_id,hora_inicio,hora_fin);

INSERT INTO horarios(id,sede_id,hora_inicio,hora_fin,regular,intensivo,activo)
SELECT UUID(),@palapas,'07:00:00','08:00:00',1,1,1 WHERE NOT EXISTS(SELECT 1 FROM horarios WHERE sede_id=@palapas AND hora_inicio='07:00:00' AND hora_fin='08:00:00');
INSERT INTO horarios(id,sede_id,hora_inicio,hora_fin,regular,intensivo,activo)
SELECT UUID(),@palapas,'08:00:00','09:00:00',1,1,1 WHERE NOT EXISTS(SELECT 1 FROM horarios WHERE sede_id=@palapas AND hora_inicio='08:00:00' AND hora_fin='09:00:00');
INSERT INTO horarios(id,sede_id,hora_inicio,hora_fin,regular,intensivo,activo)
SELECT UUID(),@palapas,'09:00:00','10:00:00',1,1,1 WHERE NOT EXISTS(SELECT 1 FROM horarios WHERE sede_id=@palapas AND hora_inicio='09:00:00' AND hora_fin='10:00:00');
INSERT INTO horarios(id,sede_id,hora_inicio,hora_fin,regular,intensivo,activo)
SELECT UUID(),@palapas,'20:00:00','21:00:00',1,1,1 WHERE NOT EXISTS(SELECT 1 FROM horarios WHERE sede_id=@palapas AND hora_inicio='20:00:00' AND hora_fin='21:00:00');

CREATE TABLE IF NOT EXISTS registros_publicos (
 id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
 alumno_id CHAR(36) NOT NULL,
 sede_id CHAR(36) NOT NULL,
 tipo ENUM('REGULAR','INTENSIVO') NOT NULL,
 horario_id CHAR(36) NOT NULL,
 fecha_inicio_intensivo DATE NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_reg_publicos_created(created_at),
 INDEX idx_reg_publicos_sede(sede_id)
);
