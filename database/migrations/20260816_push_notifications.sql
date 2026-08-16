CREATE TABLE IF NOT EXISTS push_subscriptions (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  usuario_id CHAR(36) NOT NULL,
  endpoint TEXT NOT NULL,
  p256dh TEXT NOT NULL,
  auth TEXT NOT NULL,
  content_encoding VARCHAR(30) NOT NULL DEFAULT 'aes128gcm',
  user_agent VARCHAR(255) NULL,
  activo BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_push_endpoint (endpoint(190)),
  INDEX idx_push_usuario_activo (usuario_id,activo)
);

CREATE TABLE IF NOT EXISTS notification_events (
  id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
  tipo VARCHAR(80) NOT NULL,
  titulo VARCHAR(180) NOT NULL,
  cuerpo VARCHAR(500) NOT NULL,
  url VARCHAR(500) NULL,
  sede_id CHAR(36) NULL,
  alumno_id CHAR(36) NULL,
  payload_json LONGTEXT NULL,
  estado ENUM('PENDIENTE','ENVIADO','ERROR') NOT NULL DEFAULT 'PENDIENTE',
  intentos INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  last_error TEXT NULL,
  INDEX idx_notification_pending (estado,created_at),
  INDEX idx_notification_tipo (tipo,created_at)
);

DROP TRIGGER IF EXISTS trg_registro_publico_push;
DELIMITER $$
CREATE TRIGGER trg_registro_publico_push AFTER INSERT ON registros_publicos FOR EACH ROW
BEGIN
  INSERT INTO notification_events(id,tipo,titulo,cuerpo,url,sede_id,alumno_id,payload_json)
  SELECT UUID(),
         'NUEVO_REGISTRO_PUBLICO',
         'Nuevo usuario registrado',
         CONCAT(a.nombre,' · ',CASE WHEN NEW.tipo='INTENSIVO' THEN 'Intensivo' ELSE 'Regular' END,' · ',s.nombre),
         CONCAT('/ficha-alumno.php?id=',NEW.alumno_id),
         NEW.sede_id,
         NEW.alumno_id,
         JSON_OBJECT('registro_publico_id',NEW.id,'tipo',NEW.tipo,'sede',s.clave)
  FROM alumnos a JOIN sedes s ON s.id=NEW.sede_id
  WHERE a.id=NEW.alumno_id;
END$$
DELIMITER ;
