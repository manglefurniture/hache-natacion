-- Hache Natación — comentarios moderados y reacciones para Historias Hache

CREATE TABLE IF NOT EXISTS historia_comentarios (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  historia_slug VARCHAR(120) NOT NULL,
  autor_nombre VARCHAR(80) NOT NULL,
  comentario VARCHAR(700) NOT NULL,
  estado ENUM('PENDIENTE','APROBADO','RECHAZADO','OCULTO','ELIMINADO') NOT NULL DEFAULT 'PENDIENTE',
  origen_hash CHAR(64) NOT NULL,
  visitante_hash CHAR(64) NULL,
  flags VARCHAR(255) NULL,
  moderado_por CHAR(36) NULL,
  moderado_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_historia_comentario_moderador FOREIGN KEY (moderado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
  KEY idx_historia_comentarios_publicos (historia_slug,estado,created_at),
  KEY idx_historia_comentarios_origen (origen_hash,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS historia_reacciones (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  historia_slug VARCHAR(120) NOT NULL,
  tipo ENUM('CORAZON','APLAUSOS','INSPIRA','FUERZA','SONRISA') NOT NULL,
  visitante_hash CHAR(64) NOT NULL,
  origen_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_historia_reaccion_visitante (historia_slug,visitante_hash),
  KEY idx_historia_reacciones_conteo (historia_slug,tipo),
  KEY idx_historia_reacciones_origen (origen_hash,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS historia_bloqueos (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  origen_hash CHAR(64) NOT NULL,
  motivo VARCHAR(160) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_by CHAR(36) NULL,
  updated_by CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_historia_bloqueo_origen (origen_hash),
  CONSTRAINT fk_historia_bloqueo_creador FOREIGN KEY (created_by) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_historia_bloqueo_editor FOREIGN KEY (updated_by) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
