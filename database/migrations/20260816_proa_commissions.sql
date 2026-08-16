-- Convenio Hache Natación / PROA Nadadores
-- Comisiones privadas para ADMIN. El derecho de un mes se genera al alcanzar
-- el mínimo PROA durante el mes anterior.

CREATE TABLE IF NOT EXISTS comisiones_proa (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  periodo DATE NOT NULL,
  alumno_proa_nombre VARCHAR(180) NOT NULL,
  importe DECIMAL(10,2) NOT NULL,
  observacion TEXT NULL,
  created_by CHAR(36) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_comisiones_proa_periodo (periodo),
  KEY idx_comisiones_proa_nombre (alumno_proa_nombre),
  CONSTRAINT fk_comisiones_proa_usuario FOREIGN KEY (created_by) REFERENCES usuarios(id),
  CONSTRAINT chk_comisiones_proa_importe CHECK (importe >= 0),
  CONSTRAINT chk_comisiones_proa_periodo CHECK (DAY(periodo) = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO configuracion(clave,valor,descripcion)
VALUES ('minimo_proa_mensual','28000','Aporte mínimo mensual de Hache a PROA que habilita comisiones del mes siguiente')
ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion);
