-- Configuración dinámica y cifrada de pasarelas de pago.
-- Los secretos se cifran en la aplicación; HACHE_PAYMENT_CONFIG_KEY vive solo en el servidor.

CREATE TABLE IF NOT EXISTS pasarelas_pago_config (
  proveedor VARCHAR(32) PRIMARY KEY,
  activo TINYINT(1) NOT NULL DEFAULT 0,
  entorno ENUM('TEST','PRODUCTION') NOT NULL DEFAULT 'TEST',
  public_key VARCHAR(255) NULL,
  access_token_enc TEXT NULL,
  webhook_secret_enc TEXT NULL,
  updated_by CHAR(36) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pasarela_config_usuario
    FOREIGN KEY (updated_by) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO pasarelas_pago_config(proveedor,activo,entorno)
VALUES ('mercadopago',0,'TEST')
ON DUPLICATE KEY UPDATE proveedor=VALUES(proveedor);
