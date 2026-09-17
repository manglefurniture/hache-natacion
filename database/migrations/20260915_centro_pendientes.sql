-- Hache Natación — Fase 1: Centro de pendientes.
--
-- Conserva solo gestión y trazabilidad sobre causas que pertenecen a otros
-- módulos. No copia importes, pagos, asistencia, mensualidades ni reposiciones.

CREATE TABLE IF NOT EXISTS pendientes_gestion (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    identidad CHAR(64) NOT NULL,
    sede_id CHAR(36) NULL,
    tipo VARCHAR(80) NOT NULL,
    origen_tipo VARCHAR(80) NOT NULL,
    origen_id VARCHAR(100) NOT NULL,
    alumno_id CHAR(36) NULL,
    periodo_inicio DATE NULL,
    periodo_fin DATE NULL,
    estado ENUM('PENDIENTE','ATENDIDO','RESUELTO') NOT NULL DEFAULT 'PENDIENTE',
    atendido_por CHAR(36) NULL,
    atendido_por_nombre VARCHAR(100) NULL,
    atendido_at DATETIME NULL,
    atencion_nota VARCHAR(500) NULL,
    resuelto_por CHAR(36) NULL,
    resuelto_por_nombre VARCHAR(100) NULL,
    resuelto_at DATETIME NULL,
    resolucion_nota VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pendientes_gestion_identidad (identidad),
    KEY idx_pendientes_gestion_sede_estado (sede_id,estado),
    KEY idx_pendientes_gestion_origen (origen_tipo,origen_id),
    CONSTRAINT fk_pendientes_gestion_sede FOREIGN KEY (sede_id) REFERENCES sedes(id),
    CONSTRAINT fk_pendientes_gestion_alumno FOREIGN KEY (alumno_id) REFERENCES alumnos(id) ON DELETE SET NULL,
    CONSTRAINT fk_pendientes_gestion_atendido_por FOREIGN KEY (atendido_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_pendientes_gestion_resuelto_por FOREIGN KEY (resuelto_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- F5/F4: los prospectos sin seguimiento son pendientes globales ADMIN. La FK
-- sigue protegiendo los casos con sede; NULL evita inventar una sede cuando F4
-- no la ha confirmado. Repetir este ALTER es seguro e idempotente.
ALTER TABLE pendientes_gestion MODIFY sede_id CHAR(36) NULL;
