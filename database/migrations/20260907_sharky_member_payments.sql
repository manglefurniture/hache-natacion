-- Durable checkout intents for payments initiated by already-registered students.
CREATE TABLE IF NOT EXISTS sharky_member_payment_intents (
  id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
  external_reference VARCHAR(80) NOT NULL,
  alumno_id CHAR(36) NOT NULL,
  payment_kind ENUM('MENSUALIDAD','INTENSIVO') NOT NULL,
  mensualidad_id CHAR(36) NULL,
  intensivo_id CHAR(36) NULL,
  base_amount DECIMAL(10,2) NOT NULL,
  charged_amount DECIMAL(10,2) NOT NULL,
  preference_id VARCHAR(120) NOT NULL,
  status ENUM('PENDING','APPROVED','FAILED') NOT NULL DEFAULT 'PENDING',
  reconciled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sharky_member_payment_external (external_reference),
  KEY idx_sharky_member_payment_student (alumno_id,status,created_at),
  CONSTRAINT fk_sharky_member_payment_student FOREIGN KEY (alumno_id) REFERENCES alumnos(id) ON DELETE CASCADE,
  CONSTRAINT fk_sharky_member_payment_monthly FOREIGN KEY (mensualidad_id) REFERENCES mensualidades(id) ON DELETE CASCADE,
  CONSTRAINT fk_sharky_member_payment_intensive FOREIGN KEY (intensivo_id) REFERENCES cursos_intensivos(id) ON DELETE CASCADE,
  CHECK ((payment_kind='MENSUALIDAD' AND mensualidad_id IS NOT NULL AND intensivo_id IS NULL) OR (payment_kind='INTENSIVO' AND intensivo_id IS NOT NULL AND mensualidad_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
