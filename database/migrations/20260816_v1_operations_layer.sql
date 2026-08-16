-- Franky v1 · capa operativa final

CREATE TABLE IF NOT EXISTS cierres_mensuales (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  periodo DATE NOT NULL UNIQUE,
  total_cobrado DECIMAL(12,2) NOT NULL,
  mensualidades DECIMAL(12,2) NOT NULL,
  inscripciones DECIMAL(12,2) NOT NULL,
  intensivos DECIMAL(12,2) NOT NULL,
  participacion_hache DECIMAL(12,2) NOT NULL,
  participacion_proa DECIMAL(12,2) NOT NULL,
  minimo_proa DECIMAL(12,2) NOT NULL,
  minimo_alcanzado TINYINT(1) NOT NULL,
  total_pagos INT NOT NULL DEFAULT 0,
  observacion TEXT NULL,
  cerrado_por CHAR(36) NOT NULL,
  cerrado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cierre_usuario FOREIGN KEY (cerrado_por) REFERENCES usuarios(id),
  CONSTRAINT chk_cierre_periodo CHECK (DAY(periodo)=1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auditoria_eventos (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  usuario_id CHAR(36) NULL,
  usuario_nombre VARCHAR(100) NULL,
  accion VARCHAR(80) NOT NULL,
  entidad VARCHAR(80) NOT NULL,
  entidad_id CHAR(36) NULL,
  detalle TEXT NULL,
  metodo VARCHAR(12) NULL,
  ruta VARCHAR(190) NULL,
  ip VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_auditoria_fecha (created_at),
  KEY idx_auditoria_entidad (entidad,entidad_id),
  KEY idx_auditoria_usuario (usuario_id),
  CONSTRAINT fk_auditoria_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO configuracion(clave,valor,descripcion) VALUES
 ('version_app','1.0','Versión funcional visible de Franky'),
 ('alerta_dias_fin_intensivo','7','Días de anticipación para alertar fin de intensivo')
ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion);
