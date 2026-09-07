-- Sharky member operations: professors, schedule assignments and auditable teacher cancellations.
-- Additive/idempotent migration. Raw evidence files are never stored here; only Meta media references.

CREATE TABLE IF NOT EXISTS profesores (
  id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
  nombre VARCHAR(180) NOT NULL,
  whatsapp VARCHAR(20) NOT NULL,
  correo VARCHAR(190) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_by CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_profesores_whatsapp (whatsapp),
  KEY idx_profesores_activo (activo),
  CONSTRAINT fk_profesores_created_by FOREIGN KEY (created_by) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS profesor_horarios (
  id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
  profesor_id CHAR(36) NOT NULL,
  horario_id CHAR(36) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_by CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_profesor_horario (profesor_id,horario_id),
  KEY idx_profesor_horarios_horario (horario_id,activo),
  CONSTRAINT fk_profesor_horarios_profesor FOREIGN KEY (profesor_id) REFERENCES profesores(id) ON DELETE CASCADE,
  CONSTRAINT fk_profesor_horarios_horario FOREIGN KEY (horario_id) REFERENCES horarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_profesor_horarios_created_by FOREIGN KEY (created_by) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS profesor_cancelaciones (
  id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
  profesor_id CHAR(36) NOT NULL,
  sesion_id CHAR(36) NOT NULL,
  motivo VARCHAR(500) NOT NULL,
  source ENUM('SHARKY','BACKEND') NOT NULL DEFAULT 'SHARKY',
  action_key CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_profesor_cancelacion_sesion (sesion_id),
  KEY idx_profesor_cancelaciones_profesor (profesor_id,created_at),
  CONSTRAINT fk_profesor_cancelaciones_profesor FOREIGN KEY (profesor_id) REFERENCES profesores(id) ON DELETE RESTRICT,
  CONSTRAINT fk_profesor_cancelaciones_sesion FOREIGN KEY (sesion_id) REFERENCES sesiones(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sharky_ausencia_evidencias (
  id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
  ausencia_id CHAR(36) NOT NULL,
  alumno_id CHAR(36) NOT NULL,
  source_message_id VARCHAR(191) NOT NULL,
  media_id VARCHAR(191) NOT NULL,
  media_type ENUM('image','document') NOT NULL,
  filename VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sharky_ausencia_evidencia_message (source_message_id),
  KEY idx_sharky_ausencia_evidencia_ausencia (ausencia_id),
  CONSTRAINT fk_sharky_ausencia_evidencia_ausencia FOREIGN KEY (ausencia_id) REFERENCES avisos_ausencia(id) ON DELETE CASCADE,
  CONSTRAINT fk_sharky_ausencia_evidencia_alumno FOREIGN KEY (alumno_id) REFERENCES alumnos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
