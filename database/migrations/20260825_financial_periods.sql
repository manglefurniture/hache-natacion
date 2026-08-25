-- Hache Natación — periodos financieros configurables por sede

CREATE TABLE IF NOT EXISTS periodos_financieros (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  sede_id CHAR(36) NOT NULL,
  periodo DATE NOT NULL,
  fecha_inicio DATE NOT NULL,
  fecha_cierre DATE NOT NULL,
  updated_by CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_periodo_financiero_sede FOREIGN KEY (sede_id) REFERENCES sedes(id),
  CONSTRAINT fk_periodo_financiero_usuario FOREIGN KEY (updated_by) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT chk_periodo_financiero_periodo CHECK (DAY(periodo)=1),
  CONSTRAINT chk_periodo_financiero_rango CHECK (fecha_inicio<=fecha_cierre),
  UNIQUE KEY uq_periodo_financiero_sede (sede_id,periodo),
  KEY idx_periodo_financiero_rango (sede_id,fecha_inicio,fecha_cierre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Regla operativa aprobada: agosto 2026 cierra el 30 y septiembre comienza el 31 de agosto.
INSERT IGNORE INTO periodos_financieros(sede_id,periodo,fecha_inicio,fecha_cierre)
SELECT id,'2026-08-01','2026-08-01','2026-08-30' FROM sedes WHERE activo=1
;

INSERT IGNORE INTO periodos_financieros(sede_id,periodo,fecha_inicio,fecha_cierre)
SELECT id,'2026-09-01','2026-08-31','2026-09-30' FROM sedes WHERE activo=1
;
