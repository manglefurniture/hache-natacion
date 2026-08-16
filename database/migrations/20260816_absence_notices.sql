-- Hache Natación — avisos anticipados de ausencia
CREATE TABLE IF NOT EXISTS avisos_ausencia (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    alumno_id CHAR(36) NOT NULL,
    fecha_desde DATE NOT NULL,
    fecha_hasta DATE NOT NULL,
    motivo TEXT NULL,
    estado ENUM('ACTIVO','CANCELADO') NOT NULL DEFAULT 'ACTIVO',
    created_by CHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cancelled_at DATETIME NULL,
    CONSTRAINT fk_aviso_ausencia_alumno FOREIGN KEY (alumno_id) REFERENCES alumnos(id),
    CONSTRAINT fk_aviso_ausencia_created_by FOREIGN KEY (created_by) REFERENCES usuarios(id),
    CONSTRAINT chk_aviso_ausencia_fechas CHECK (fecha_hasta >= fecha_desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_aviso_ausencia_alumno_fechas
    ON avisos_ausencia (alumno_id, fecha_desde, fecha_hasta, estado);
