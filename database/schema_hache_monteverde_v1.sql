-- HACHE NATACIÓN / MONTEVERDE
-- Schema v1 - MariaDB 11.8+
-- Modelo lógico y reglas administrativas acordadas en agosto de 2026.
--
-- Este schema es la base de datos de desarrollo/referencia.
-- NO ejecutar sobre producción sin respaldo y revisión previa.

CREATE TABLE usuarios (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    usuario VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    rol ENUM('ADMIN','VERIFICADOR','ALUMNO') NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    alumno_id CHAR(36) UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME
);

CREATE TABLE alumnos (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    nombre TEXT NOT NULL,
    fecha_nacimiento DATE NOT NULL,
    whatsapp TEXT NOT NULL,
    correo TEXT,
    fecha_inicio DATE,
    horario_preferido_id CHAR(36),
    plan_actual_id CHAR(36),
    estado_administrativo ENUM('ACTIVO','BAJA') NOT NULL DEFAULT 'ACTIVO',
    observaciones TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE responsables (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    nombre TEXT NOT NULL,
    whatsapp TEXT NOT NULL,
    correo TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE alumno_responsable (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    alumno_id CHAR(36) NOT NULL REFERENCES alumnos(id),
    responsable_id CHAR(36) NOT NULL REFERENCES responsables(id),
    relacion TEXT NOT NULL,
    UNIQUE (alumno_id, responsable_id)
);

CREATE TABLE horarios (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    regular BOOLEAN NOT NULL DEFAULT FALSE,
    intensivo BOOLEAN NOT NULL DEFAULT FALSE,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE (hora_inicio, hora_fin),
    CHECK (hora_inicio < hora_fin),
    CHECK (regular OR intensivo)
);

ALTER TABLE alumnos
    ADD CONSTRAINT fk_alumnos_horario
    FOREIGN KEY (horario_preferido_id) REFERENCES horarios(id);

CREATE TABLE planes (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    nombre VARCHAR(100) NOT NULL UNIQUE,
    sesiones_semana INTEGER NOT NULL,
    precio DECIMAL(10,2) NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    CHECK (sesiones_semana > 0),
    CHECK (precio >= 0)
);

ALTER TABLE alumnos
    ADD CONSTRAINT fk_alumnos_plan
    FOREIGN KEY (plan_actual_id) REFERENCES planes(id);

ALTER TABLE usuarios
    ADD CONSTRAINT fk_usuarios_alumno
    FOREIGN KEY (alumno_id) REFERENCES alumnos(id);

CREATE TABLE inscripciones (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    alumno_id CHAR(36) NOT NULL REFERENCES alumnos(id),
    fecha DATE NOT NULL,
    origen ENUM('REGULAR','INTENSIVO') NOT NULL,
    importe DECIMAL(10,2) NOT NULL,
    observacion TEXT,
    created_by CHAR(36) NOT NULL REFERENCES usuarios(id),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (importe >= 0)
);

CREATE TABLE mensualidades (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    alumno_id CHAR(36) NOT NULL REFERENCES alumnos(id),
    mes SMALLINT NOT NULL,
    anio SMALLINT NOT NULL,
    plan_id CHAR(36) NOT NULL REFERENCES planes(id),
    importe_estandar DECIMAL(10,2) NOT NULL,
    importe_a_cobrar DECIMAL(10,2) NOT NULL,
    importe_cobrado DECIMAL(10,2),
    estado ENUM('PENDIENTE','PAGADA') NOT NULL DEFAULT 'PENDIENTE',
    observacion TEXT,
    fecha_pago DATETIME,
    created_by CHAR(36) NOT NULL REFERENCES usuarios(id),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (alumno_id, mes, anio),
    CHECK (mes BETWEEN 1 AND 12),
    CHECK (anio BETWEEN 2000 AND 2100),
    CHECK (importe_estandar >= 0),
    CHECK (importe_a_cobrar >= 0),
    CHECK (importe_cobrado IS NULL OR importe_cobrado >= 0),
    CHECK ((importe_cobrado IS NULL AND estado = 'PENDIENTE') OR (importe_cobrado IS NOT NULL AND estado = 'PAGADA')),
    CHECK (importe_cobrado IS NULL OR importe_cobrado <> importe_estandar OR observacion IS NOT NULL)
);

CREATE TABLE cursos_intensivos (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    alumno_id CHAR(36) NOT NULL REFERENCES alumnos(id),
    horario_id CHAR(36) NOT NULL REFERENCES horarios(id),
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    precio DECIMAL(10,2) NOT NULL DEFAULT 1200.00,
    estado ENUM('PROGRAMADO','EN_CURSO','TERMINADO') NOT NULL DEFAULT 'PROGRAMADO',
    reposiciones_justificadas TINYINT UNSIGNED NOT NULL DEFAULT 0,
    reposiciones_cancelacion TINYINT UNSIGNED NOT NULL DEFAULT 0,
    continua_regular BOOLEAN,
    plan_continuidad_id CHAR(36) REFERENCES planes(id),
    importe_continuidad DECIMAL(10,2),
    observacion_continuidad TEXT,
    observaciones TEXT,
    created_by CHAR(36) NOT NULL REFERENCES usuarios(id),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (fecha_fin >= fecha_inicio),
    CHECK (precio >= 0),
    CHECK (reposiciones_justificadas BETWEEN 0 AND 5),
    CHECK (continua_regular IS NULL OR estado = 'TERMINADO'),
    CHECK ((continua_regular IS NULL OR continua_regular = FALSE) OR plan_continuidad_id IS NOT NULL),
    CHECK (importe_continuidad IS NULL OR importe_continuidad >= 0)
);

-- Un intensivo solo puede utilizar un horario habilitado para intensivos.
DELIMITER $$
CREATE TRIGGER trg_validar_horario_intensivo_insert
BEFORE INSERT ON cursos_intensivos
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM horarios h
        WHERE h.id = NEW.horario_id AND h.intensivo = TRUE AND h.activo = TRUE
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El horario seleccionado no está habilitado para intensivos';
    END IF;
END$$

CREATE TRIGGER trg_validar_horario_intensivo_update
BEFORE UPDATE ON cursos_intensivos
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM horarios h
        WHERE h.id = NEW.horario_id AND h.intensivo = TRUE AND h.activo = TRUE
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El horario seleccionado no está habilitado para intensivos';
    END IF;
END$$

-- Un alumno no puede tener dos intensivos activos simultáneamente.
CREATE TRIGGER trg_un_intensivo_activo_insert
BEFORE INSERT ON cursos_intensivos
FOR EACH ROW
BEGIN
    IF NEW.estado IN ('PROGRAMADO','EN_CURSO') AND EXISTS (
        SELECT 1 FROM cursos_intensivos ci
        WHERE ci.alumno_id = NEW.alumno_id AND ci.estado IN ('PROGRAMADO','EN_CURSO')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El alumno ya tiene un intensivo activo';
    END IF;
END$$

CREATE TRIGGER trg_un_intensivo_activo_update
BEFORE UPDATE ON cursos_intensivos
FOR EACH ROW
BEGIN
    IF NEW.estado IN ('PROGRAMADO','EN_CURSO') AND EXISTS (
        SELECT 1 FROM cursos_intensivos ci
        WHERE ci.alumno_id = NEW.alumno_id
          AND ci.estado IN ('PROGRAMADO','EN_CURSO')
          AND ci.id <> NEW.id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El alumno ya tiene un intensivo activo';
    END IF;
END$$
DELIMITER ;

CREATE TABLE ausencias (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    intensivo_id CHAR(36) NOT NULL REFERENCES cursos_intensivos(id),
    alumno_id CHAR(36) NOT NULL REFERENCES alumnos(id),
    fecha DATE NOT NULL,
    motivo TEXT NOT NULL,
    fecha_aviso DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (intensivo_id, fecha)
);

-- Garantiza que la ausencia pertenece al alumno del intensivo.
DELIMITER $$
CREATE TRIGGER trg_validar_ausencia_intensivo_insert
BEFORE INSERT ON ausencias
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM cursos_intensivos ci
        WHERE ci.id = NEW.intensivo_id AND ci.alumno_id = NEW.alumno_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La ausencia no corresponde al alumno del intensivo';
    END IF;
END$$

CREATE TRIGGER trg_validar_ausencia_intensivo_update
BEFORE UPDATE ON ausencias
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM cursos_intensivos ci
        WHERE ci.id = NEW.intensivo_id AND ci.alumno_id = NEW.alumno_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La ausencia no corresponde al alumno del intensivo';
    END IF;
END$$
DELIMITER ;

CREATE INDEX idx_ausencias_intensivo_fecha ON ausencias (intensivo_id, fecha);

CREATE TABLE pagos (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    folio BIGINT NOT NULL AUTO_INCREMENT UNIQUE,
    alumno_id CHAR(36) NOT NULL REFERENCES alumnos(id),
    inscripcion_id CHAR(36) REFERENCES inscripciones(id),
    mensualidad_id CHAR(36) REFERENCES mensualidades(id),
    intensivo_id CHAR(36) REFERENCES cursos_intensivos(id),
    tipo ENUM('INSCRIPCION','MENSUALIDAD','INTENSIVO') NOT NULL,
    importe DECIMAL(10,2) NOT NULL,
    metodo ENUM('EFECTIVO','TRANSFERENCIA','MERCADO_PAGO') NOT NULL,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    estado ENUM('VALIDO','INVALIDADO') NOT NULL DEFAULT 'VALIDO',
    observacion TEXT,
    created_by CHAR(36) NOT NULL REFERENCES usuarios(id),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    invalidated_at DATETIME,
    invalidated_by CHAR(36) REFERENCES usuarios(id),
    CHECK (importe >= 0),
    CHECK ((tipo = 'INSCRIPCION' AND inscripcion_id IS NOT NULL AND mensualidad_id IS NULL AND intensivo_id IS NULL) OR (tipo = 'MENSUALIDAD' AND mensualidad_id IS NOT NULL AND inscripcion_id IS NULL AND intensivo_id IS NULL) OR (tipo = 'INTENSIVO' AND intensivo_id IS NOT NULL AND inscripcion_id IS NULL AND mensualidad_id IS NULL)),
    CHECK ((estado = 'VALIDO' AND invalidated_at IS NULL AND invalidated_by IS NULL) OR (estado = 'INVALIDADO' AND invalidated_at IS NOT NULL AND invalidated_by IS NOT NULL AND observacion IS NOT NULL))
);

-- Solo puede existir un pago VÁLIDO por inscripción, mensualidad o intensivo.
-- La corrección se hace invalidando el anterior y registrando uno nuevo.
DELIMITER $$
CREATE TRIGGER trg_un_pago_valido_insert
BEFORE INSERT ON pagos
FOR EACH ROW
BEGIN
    IF NEW.estado = 'VALIDO' THEN
        IF NEW.inscripcion_id IS NOT NULL AND EXISTS (SELECT 1 FROM pagos p WHERE p.inscripcion_id = NEW.inscripcion_id AND p.estado = 'VALIDO') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya existe un pago válido para esta inscripción';
        END IF;
        IF NEW.mensualidad_id IS NOT NULL AND EXISTS (SELECT 1 FROM pagos p WHERE p.mensualidad_id = NEW.mensualidad_id AND p.estado = 'VALIDO') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya existe un pago válido para esta mensualidad';
        END IF;
        IF NEW.intensivo_id IS NOT NULL AND EXISTS (SELECT 1 FROM pagos p WHERE p.intensivo_id = NEW.intensivo_id AND p.estado = 'VALIDO') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya existe un pago válido para este intensivo';
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_un_pago_valido_update
BEFORE UPDATE ON pagos
FOR EACH ROW
BEGIN
    IF NEW.estado = 'VALIDO' THEN
        IF NEW.inscripcion_id IS NOT NULL AND EXISTS (SELECT 1 FROM pagos p WHERE p.inscripcion_id = NEW.inscripcion_id AND p.estado = 'VALIDO' AND p.id <> NEW.id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya existe un pago válido para esta inscripción';
        END IF;
        IF NEW.mensualidad_id IS NOT NULL AND EXISTS (SELECT 1 FROM pagos p WHERE p.mensualidad_id = NEW.mensualidad_id AND p.estado = 'VALIDO' AND p.id <> NEW.id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya existe un pago válido para esta mensualidad';
        END IF;
        IF NEW.intensivo_id IS NOT NULL AND EXISTS (SELECT 1 FROM pagos p WHERE p.intensivo_id = NEW.intensivo_id AND p.estado = 'VALIDO' AND p.id <> NEW.id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya existe un pago válido para este intensivo';
        END IF;
    END IF;
END$$
DELIMITER ;

CREATE TABLE sesiones (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    fecha DATE NOT NULL,
    bloque ENUM('AM','PM') NOT NULL,
    horario_id CHAR(36) REFERENCES horarios(id),
    estado ENUM('PROGRAMADA','REALIZADA','CANCELADA') NOT NULL DEFAULT 'PROGRAMADA',
    motivo_cancelacion TEXT,
    observacion TEXT,
    cerrada BOOLEAN NOT NULL DEFAULT FALSE,
    fecha_cierre DATETIME,
    cerrada_por CHAR(36) REFERENCES usuarios(id),
    created_by CHAR(36) NOT NULL REFERENCES usuarios(id),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (estado <> 'CANCELADA' OR motivo_cancelacion IS NOT NULL)
);

-- horario_id NULL = bloque AM/PM completo; horario_id con valor = horario específico.
DELIMITER $$
CREATE TRIGGER trg_sesion_unica_insert
BEFORE INSERT ON sesiones
FOR EACH ROW
BEGIN
    IF NEW.horario_id IS NULL THEN
        IF EXISTS (SELECT 1 FROM sesiones s WHERE s.fecha = NEW.fecha AND s.bloque = NEW.bloque AND s.horario_id IS NULL) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya existe una sesión para este bloque AM/PM';
        END IF;
    ELSE
        IF EXISTS (SELECT 1 FROM sesiones s WHERE s.fecha = NEW.fecha AND s.horario_id = NEW.horario_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya existe una sesión para este horario';
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_sesion_unica_update
BEFORE UPDATE ON sesiones
FOR EACH ROW
BEGIN
    IF NEW.horario_id IS NULL THEN
        IF EXISTS (SELECT 1 FROM sesiones s WHERE s.fecha = NEW.fecha AND s.bloque = NEW.bloque AND s.horario_id IS NULL AND s.id <> NEW.id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya existe una sesión para este bloque AM/PM';
        END IF;
    ELSE
        IF EXISTS (SELECT 1 FROM sesiones s WHERE s.fecha = NEW.fecha AND s.horario_id = NEW.horario_id AND s.id <> NEW.id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ya existe una sesión para este horario';
        END IF;
    END IF;
END$$
DELIMITER ;

CREATE TABLE historial (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    alumno_id CHAR(36) REFERENCES alumnos(id),
    tipo ENUM('INSCRIPCION','MENSUALIDAD','PAGO','INTENSIVO','CAMBIO_PLAN','INVALIDACION_PAGO','BAJA','REACTIVACION','OTRO') NOT NULL,
    fecha_hora DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    descripcion TEXT NOT NULL,
    usuario_id CHAR(36) NOT NULL REFERENCES usuarios(id),
    referencia_tipo TEXT,
    referencia_id CHAR(36)
);

CREATE INDEX idx_historial_alumno_fecha ON historial (alumno_id, fecha_hora DESC);

CREATE TABLE notificaciones (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
    alumno_id CHAR(36) NOT NULL REFERENCES alumnos(id),
    tipo ENUM('RECORDATORIO_MENSUAL','RECORDATORIO_CONTINUIDAD','CONSTANCIA_PAGO','CANCELACION','REPOSICIONES_INTENSIVO','INTENSIVO') NOT NULL,
    fecha_programada DATETIME NOT NULL,
    fecha_enviada DATETIME,
    destinatario_whatsapp TEXT NOT NULL,
    estado ENUM('PENDIENTE','ENVIADA','ENTREGADA','FALLIDA') NOT NULL DEFAULT 'PENDIENTE',
    referencia_tipo TEXT,
    referencia_id CHAR(36),
    error TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_notificaciones_pendientes ON notificaciones (fecha_programada, estado);

DELIMITER $$

CREATE FUNCTION alumno_es_menor(p_alumno_id CHAR(36))
RETURNS BOOLEAN
NOT DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE fn DATE;
    SELECT fecha_nacimiento INTO fn FROM alumnos WHERE id = p_alumno_id;
    RETURN fn > DATE_SUB(CURRENT_DATE, INTERVAL 18 YEAR);
END$$

CREATE TRIGGER trg_alumnos_updated_at
BEFORE UPDATE ON alumnos
FOR EACH ROW
BEGIN
    SET NEW.updated_at = CURRENT_TIMESTAMP;
END$$

CREATE TRIGGER trg_responsables_updated_at
BEFORE UPDATE ON responsables
FOR EACH ROW
BEGIN
    SET NEW.updated_at = CURRENT_TIMESTAMP;
END$$

CREATE TRIGGER trg_mensualidades_updated_at
BEFORE UPDATE ON mensualidades
FOR EACH ROW
BEGIN
    SET NEW.updated_at = CURRENT_TIMESTAMP;
END$$

DELIMITER ;

INSERT INTO planes (nombre, sesiones_semana, precio, activo)
VALUES
    ('Plan 3×', 3, 1000.00, TRUE),
    ('Plan 5×', 5, 1200.00, TRUE);

INSERT INTO horarios (hora_inicio, hora_fin, regular, intensivo, activo)
VALUES
    ('06:00', '07:00', TRUE, FALSE, TRUE),
    ('07:00', '08:00', TRUE, FALSE, TRUE),
    ('08:00', '09:00', TRUE, TRUE, TRUE),
    ('18:00', '19:00', TRUE, FALSE, TRUE),
    ('19:00', '20:00', TRUE, TRUE, TRUE),
    ('20:00', '21:00', TRUE, TRUE, TRUE);