-- Hache Natación — respuestas y avisos opcionales para Historias Hache
-- Extensión conservadora del módulo 20260828_historias_interacciones.sql.
-- El correo vive únicamente en una tabla privada y nunca forma parte de la respuesta pública.

CREATE TABLE IF NOT EXISTS historia_respuestas (
  comentario_id CHAR(36) PRIMARY KEY,
  parent_id CHAR(36) NOT NULL,
  reply_to_id CHAR(36) NOT NULL,
  notificacion_estado ENUM('NO_APLICA','PENDIENTE','ENVIANDO','ENVIADA','FALLO') NOT NULL DEFAULT 'NO_APLICA',
  notificacion_intentos TINYINT UNSIGNED NOT NULL DEFAULT 0,
  notificacion_enviada_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_historia_respuesta_comentario FOREIGN KEY (comentario_id) REFERENCES historia_comentarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_historia_respuesta_parent FOREIGN KEY (parent_id) REFERENCES historia_comentarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_historia_respuesta_target FOREIGN KEY (reply_to_id) REFERENCES historia_comentarios(id) ON DELETE CASCADE,
  KEY idx_historia_respuestas_parent (parent_id,created_at),
  KEY idx_historia_respuestas_target (reply_to_id),
  KEY idx_historia_respuestas_notificacion (notificacion_estado,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS historia_comentario_suscripciones (
  comentario_id CHAR(36) PRIMARY KEY,
  email VARCHAR(254) NOT NULL,
  estado ENUM('PENDIENTE','ACTIVA','CANCELADA') NOT NULL DEFAULT 'PENDIENTE',
  confirm_token_hash CHAR(64) NOT NULL,
  confirm_expires_at DATETIME NOT NULL,
  confirmacion_estado ENUM('PENDIENTE','ENVIANDO','ENVIADA','FALLO') NOT NULL DEFAULT 'PENDIENTE',
  confirmacion_intentos TINYINT UNSIGNED NOT NULL DEFAULT 0,
  confirmacion_enviada_at DATETIME NULL,
  confirmado_at DATETIME NULL,
  cancelado_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_historia_suscripcion_comentario FOREIGN KEY (comentario_id) REFERENCES historia_comentarios(id) ON DELETE CASCADE,
  UNIQUE KEY uq_historia_suscripcion_confirm_token (confirm_token_hash),
  KEY idx_historia_suscripcion_estado (estado,updated_at),
  KEY idx_historia_suscripcion_confirmacion (confirmacion_estado,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
