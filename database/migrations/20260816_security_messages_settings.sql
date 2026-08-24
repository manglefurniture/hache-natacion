-- Seguridad, mensajería y configuración de Franky
ALTER TABLE usuarios
  ADD COLUMN IF NOT EXISTS debe_cambiar_password TINYINT(1) NOT NULL DEFAULT 0 AFTER activo;

CREATE TABLE IF NOT EXISTS mensajes (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  titulo VARCHAR(160) NOT NULL,
  cuerpo TEXT NOT NULL,
  audiencia ENUM('TODOS','REGULARES','INTENSIVOS','ALUMNO') NOT NULL DEFAULT 'TODOS',
  alumno_id CHAR(36) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  vigencia_desde DATE NULL,
  vigencia_hasta DATE NULL,
  created_by CHAR(36) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mensajes_activos (activo,vigencia_desde,vigencia_hasta),
  KEY idx_mensajes_alumno (alumno_id),
  CONSTRAINT fk_mensajes_alumno FOREIGN KEY (alumno_id) REFERENCES alumnos(id),
  CONSTRAINT fk_mensajes_usuario FOREIGN KEY (created_by) REFERENCES usuarios(id),
  CONSTRAINT chk_mensajes_audiencia CHECK ((audiencia='ALUMNO' AND alumno_id IS NOT NULL) OR (audiencia<>'ALUMNO' AND alumno_id IS NULL)),
  CONSTRAINT chk_mensajes_vigencia CHECK (vigencia_hasta IS NULL OR vigencia_desde IS NULL OR vigencia_hasta>=vigencia_desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS configuracion (
  clave VARCHAR(100) PRIMARY KEY,
  valor TEXT NULL,
  descripcion VARCHAR(255) NULL,
  updated_by CHAR(36) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_config_usuario FOREIGN KEY (updated_by) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO configuracion(clave,valor,descripcion) VALUES
 ('nombre_app','Hache Natación','Nombre visible de la aplicación'),
 ('dias_clase','1,2,3,4,5','Días lectivos ISO: lunes=1 ... domingo=7')
ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion);
