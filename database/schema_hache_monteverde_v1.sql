-- HACHE NATACIÓN / MONTEVERDE
-- Schema v1 - MariaDB 10.x
-- Modelo lógico acordado en agosto de 2026.
--
-- Convertido desde PostgreSQL para MariaDB. Revisado para el esquema actual.

-- =========================================================
-- ENUMS
-- =========================================================

-- Los ENUM de PostgreSQL fueron convertidos a ENUM inline de MariaDB.

-- =========================================================
-- USUARIOS
-- =========================================================

CREATE TABLE usuarios (
    id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    usuario VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    rol ENUM('ADMIN','VERIFICADOR','ALUMNO') NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    alumno_id CHAR(36) UNIQUE,
    created_at DATETIME NOT NULL DEFAULT NOW(),
    last_login DATETIME
);

-- =========================================================
-- ALUMNOS
-- =========================================================

CREATE TABLE alumnos (
    id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    nombre VARCHAR(255) NOT NULL,
    fecha_nacimiento DATE NOT NULL,
    whatsapp VARCHAR(255) NOT NULL,
    correo VARCHAR(255),
    fecha_inicio DATE,
    horario_preferido_id CHAR(36),
    plan_actual_id CHAR(36),
    estado_administrativo ENUM('ACTIVO','BAJA') NOT NULL DEFAULT 'ACTIVO',
    observaciones VARCHAR(255),
    created_at DATETIME NOT NULL DEFAULT NOW(),
    updated_at DATETIME NOT NULL DEFAULT NOW()
);

-- =========================================================
-- RESPONSABLES
-- =========================================================

CREATE TABLE responsables (
    id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    nombre VARCHAR(255) NOT NULL,
    whatsapp VARCHAR(255) NOT NULL,
    correo VARCHAR(255),
    created_at DATETIME NOT NULL DEFAULT NOW(),
    updated_at DATETIME NOT NULL DEFAULT NOW()
);

CREATE TABLE alumno_responsable (
    id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    alumno_id CHAR(36) NOT NULL REFERENCES alumnos(id),
    responsable_id CHAR(36) NOT NULL REFERENCES responsables(id),
    relacion VARCHAR(255) NOT NULL,
    UNIQUE (alumno_id, responsable_id)
);

-- =========================================================
-- HORARIOS
-- =========================================================

CREATE TABLE horarios (
    id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
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

-- =========================================================
-- PLANES
-- =========================================================

CREATE TABLE planes (
    id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    nombre VARCHAR(255) NOT NULL UNIQUE,
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

-- =========================================================
-- INSCRIPCIONES
-- =========================================================

CREATE TABLE inscripciones (
    id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    alumno_id CHAR(36) NOT NULL REFERENCES alumnos(id),
    fecha DATE NOT NULL,
    origen ENUM('REGULAR','INTENSIVO') NOT NULL,
    importe DECIMAL(10,2) NOT NULL,
    observacion VARCHAR(255),
    created_by CHAR(36) NOT NULL REFERENCES usuarios(id),
    created_at DATETIME NOT NULL DEFAULT NOW(),
    CHECK (importe >= 0)
);

-- =========================================================
-- MENSUALIDADES
-- =========================================================

CREATE TABLE mensualidades (
    id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    alumno_id CHAR(36) NOT NULL REFERENCES alumnos(id),
    mes SMALLINT NOT NULL,
    anio SMALLINT NOT NULL,
    plan_id CHAR(36) NOT NULL REFERENCES planes(id),
    importe_estandar DECIMAL(10,2) NOT NULL,
    importe_a_cobrar DECIMAL(10,2) NOT NULL,
    importe_cobrado DECIMAL(10,2),
    estado ENUM('PENDIENTE','PAGADA') NOT NULL DEFAULT 'PENDIENTE',
    observacion VARCHAR(255),
    fecha_pago DATETIME,
    created_by CHAR(36) NOT NULL REFERENCES usuarios(id),
    created_at DATETIME NOT NULL DEFAULT NOW(),
    updated_at DATETIME NOT NULL DEFAULT NOW(),

    UNIQUE (alumno_id, mes, anio),

    CHECK (mes BETWEEN 1 AND 12),
    CHECK (anio BETWEEN 2000 AND 2100),
    CHECK (importe_estandar >= 0),
    CHECK (importe_a_cobrar >= 0),
    CHECK (importe_cobrado IS NULL OR importe_cobrado >= 0),

    CHECK (
        (importe_cobrado IS NULL AND estado = 'PENDIENTE')
        OR
        (importe_cobrado IS NOT NULL AND estado = 'PAGADA')
    ),

    CHECK (
        importe_cobrado IS NULL
        OR importe_cobrado <> importe_estandar
        OR observacion IS NOT NULL
    )
);

-- =========================================================
-- CURSOS INTENSIVOS
-- =========================================================

CREATE TABLE cursos_intensivos (
    id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    alumno_id CHAR(36) NOT NULL REFERENCES alumnos(id),
    horario_id CHAR(36) NOT NULL REFERENCES horarios(id),
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    precio DECIMAL(10,2) NOT NULL DEFAULT 1200.00,
    estado ENUM('PROGRAMADO','EN_CURSO','TERMINADO') NOT NULL DEFAULT 'PROGRAMADO',
    reposiciones INTEGER NOT NULL DEFAULT 0,
    continua_regular BOOLEAN,
    plan_continuidad_id CHAR(36) REFERENCES planes(id),
    importe_continuidad DECIMAL(10,2),
    observacion_continuidad VARCHAR(255),
    observaciones VARCHAR(255),
    created_by CHAR(36) NOT NULL REFERENCES usuarios(id),
    created_at DATETIME NOT NULL DEFAULT NOW(),

    CHECK (fecha_fin >= fecha_inicio),
    CHECK (precio >= 0),
    CHECK (reposiciones >= 0),
    CHECK (continua_regular IS NULL OR estado = 'TERMINADO'),
    CHECK (
        (continua_regular IS NULL OR continua_regular = FALSE)
        OR plan_continuidad_id IS NOT NULL
    ),
    CHECK (
        importe_continuidad IS NULL
        OR importe_continuidad >= 0
    )
);

-- Un alumno no puede tener dos intensivos activos simultáneamente.
-- MariaDB no soporta índices parciales; se usa una columna generada.
ALTER TABLE cursos_intensivos
    ADD COLUMN alumno_activo_id CHAR(36)
    AS (CASE WHEN estado IN ('PROGRAMADO','EN_CURSO') THEN alumno_id ELSE NULL END) PERSISTENT;
CREATE UNIQUE INDEX ux_un_intensivo_activo_por_alumno
ON cursos_intensivos (alumno_activo_id);

-- Validación del horario de intensivos: se realiza en la capa PHP antes de guardar.

-- =========================================================
-- AUSENCIAS DE INTENSIVOS
-- =========================================================

CREATE TABLE ausencias (
    id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    intensivo_id CHAR(36) NOT NULL REFERENCES cursos_intensivos(id),
    alumno_id CHAR(36) NOT NULL REFERENCES alumnos(id),
    fecha DATE NOT NULL,
    motivo VARCHAR(255) NOT NULL,
    fecha_aviso DATETIME NOT NULL DEFAULT NOW(),
    created_at DATETIME NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_ausencias_intensivo_fecha
ON ausencias (intensivo_id, fecha);

-- =========================================================
-- PAGOS
-- Un pago se relaciona con exactamente uno de:
-- inscripción, mensualidad o intensivo.
-- =========================================================

CREATE TABLE pagos (
    id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    folio BIGINT NOT NULL AUTO_INCREMENT UNIQUE,
    alumno_id CHAR(36) NOT NULL REFERENCES alumnos(id),

    inscripcion_id CHAR(36) REFERENCES inscripciones(id),
    mensualidad_id CHAR(36) REFERENCES mensualidades(id),
    intensivo_id CHAR(36) REFERENCES cursos_intensivos(id),

    tipo ENUM('INSCRIPCION','MENSUALIDAD','INTENSIVO') NOT NULL,
    importe DECIMAL(10,2) NOT NULL,
    metodo ENUM('EFECTIVO','TRANSFERENCIA','MERCADO_PAGO') NOT NULL,
    fecha DATETIME NOT NULL DEFAULT NOW(),
    estado ENUM('VALIDO','INVALIDADO') NOT NULL DEFAULT 'VALIDO',
    observacion VARCHAR(255),
    created_by CHAR(36) NOT NULL REFERENCES usuarios(id),
    created_at DATETIME NOT NULL DEFAULT NOW(),
    invalidated_at DATETIME,
    invalidated_by CHAR(36) REFERENCES usuarios(id),

    CHECK (importe >= 0),

    CHECK (
        (tipo = 'INSCRIPCION' AND inscripcion_id IS NOT NULL
            AND mensualidad_id IS NULL AND intensivo_id IS NULL)
        OR
        (tipo = 'MENSUALIDAD' AND mensualidad_id IS NOT NULL
            AND inscripcion_id IS NULL AND intensivo_id IS NULL)
        OR
        (tipo = 'INTENSIVO' AND intensivo_id IS NOT NULL
            AND inscripcion_id IS NULL AND mensualidad_id IS NULL)
    ),

    CHECK (
        (estado = 'VALIDO' AND invalidated_at IS NULL AND invalidated_by IS NULL)
        OR
        (estado = 'INVALIDADO' AND invalidated_at IS NOT NULL
            AND invalidated_by IS NOT NULL AND observacion IS NOT NULL)
    )
);

ALTER TABLE pagos
    ADD COLUMN pago_valido_inscripcion_id CHAR(36)
    AS (CASE WHEN estado = 'VALIDO' THEN inscripcion_id ELSE NULL END) PERSISTENT;
CREATE UNIQUE INDEX ux_pago_valido_inscripcion
ON pagos (pago_valido_inscripcion_id);

ALTER TABLE pagos
    ADD COLUMN pago_valido_mensualidad_id CHAR(36)
    AS (CASE WHEN estado = 'VALIDO' THEN mensualidad_id ELSE NULL END) PERSISTENT;
CREATE UNIQUE INDEX ux_pago_valido_mensualidad
ON pagos (pago_valido_mensualidad_id);

ALTER TABLE pagos
    ADD COLUMN pago_valido_intensivo_id CHAR(36)
    AS (CASE WHEN estado = 'VALIDO' THEN intensivo_id ELSE NULL END) PERSISTENT;
CREATE UNIQUE INDEX ux_pago_valido_intensivo
ON pagos (pago_valido_intensivo_id);

-- =========================================================
-- SESIONES / CANCELACIONES
-- horario_id NULL = bloque AM/PM completo.
-- horario_id con valor = horario específico.
-- =========================================================

CREATE TABLE sesiones (
    id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    fecha DATE NOT NULL,
    bloque ENUM('AM','PM') NOT NULL,
    horario_id CHAR(36) REFERENCES horarios(id),
    estado ENUM('PROGRAMADA','REALIZADA','CANCELADA') NOT NULL DEFAULT 'PROGRAMADA',
    motivo_cancelacion VARCHAR(255),
    observacion VARCHAR(255),
    cerrada BOOLEAN NOT NULL DEFAULT FALSE,
    fecha_cierre DATETIME,
    cerrada_por CHAR(36) REFERENCES usuarios(id),
    created_by CHAR(36) NOT NULL REFERENCES usuarios(id),
    created_at DATETIME NOT NULL DEFAULT NOW(),

    CHECK (
        estado <> 'CANCELADA'
        OR motivo_cancelacion IS NOT NULL
    )
);

ALTER TABLE sesiones
    ADD COLUMN sesion_bloque_unico VARCHAR(10)
    AS (CASE WHEN horario_id IS NULL THEN bloque ELSE NULL END) PERSISTENT;
CREATE UNIQUE INDEX ux_sesion_bloque_por_dia
ON sesiones (fecha, sesion_bloque_unico);

ALTER TABLE sesiones
    ADD COLUMN sesion_horario_unico CHAR(36)
    AS (CASE WHEN horario_id IS NOT NULL THEN horario_id ELSE NULL END) PERSISTENT;
CREATE UNIQUE INDEX ux_sesion_horario_por_dia
ON sesiones (fecha, sesion_horario_unico);

-- =========================================================
-- HISTORIAL
-- =========================================================

CREATE TABLE historial (
    id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    alumno_id CHAR(36) REFERENCES alumnos(id),
    tipo ENUM('INSCRIPCION','MENSUALIDAD','PAGO','INTENSIVO','CAMBIO_PLAN','INVALIDACION_PAGO','BAJA','REACTIVACION','OTRO') NOT NULL,
    fecha_hora DATETIME NOT NULL DEFAULT NOW(),
    descripcion VARCHAR(255) NOT NULL,
    usuario_id CHAR(36) NOT NULL REFERENCES usuarios(id),
    referencia_tipo VARCHAR(255),
    referencia_id CHAR(36)
);

CREATE INDEX idx_historial_alumno_fecha
ON historial (alumno_id, fecha_hora DESC);

-- =========================================================
-- NOTIFICACIONES
-- =========================================================

CREATE TABLE notificaciones (
    id CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    alumno_id CHAR(36) NOT NULL REFERENCES alumnos(id),
    tipo ENUM('RECORDATORIO_MENSUAL','RECORDATORIO_CONTINUIDAD','CONSTANCIA_PAGO','CANCELACION','REPOSICIONES_INTENSIVO','INTENSIVO') NOT NULL,
    fecha_programada DATETIME NOT NULL,
    fecha_enviada DATETIME,
    destinatario_whatsapp VARCHAR(255) NOT NULL,
    estado ENUM('PENDIENTE','ENVIADA','ENTREGADA','FALLIDA') NOT NULL DEFAULT 'PENDIENTE',
    referencia_tipo VARCHAR(255),
    referencia_id CHAR(36),
    error VARCHAR(255),
    created_at DATETIME NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_notificaciones_pendientes
ON notificaciones (fecha_programada, estado);

-- =========================================================
-- REGLAS / TRIGGERS DE INTEGRIDAD
-- =========================================================

-- Si un alumno es menor de 18 años, la aplicación debe exigir responsable.
-- Esta regla se valida en la capa PHP para permitir una validación transaccional clara.

DELIMITER //

CREATE TRIGGER trg_alumnos_updated_at
BEFORE UPDATE ON alumnos
FOR EACH ROW
BEGIN
    SET NEW.updated_at = CURRENT_TIMESTAMP;
END//

CREATE TRIGGER trg_responsables_updated_at
BEFORE UPDATE ON responsables
FOR EACH ROW
BEGIN
    SET NEW.updated_at = CURRENT_TIMESTAMP;
END//

CREATE TRIGGER trg_mensualidades_updated_at
BEFORE UPDATE ON mensualidades
FOR EACH ROW
BEGIN
    SET NEW.updated_at = CURRENT_TIMESTAMP;
END//

DELIMITER ;

-- =========================================================
-- DATOS INICIALES: PLANES
-- =========================================================

INSERT INTO planes (nombre, sesiones_semana, precio, activo)
VALUES
    ('Plan 3×', 3, 1000.00, TRUE),
    ('Plan 5×', 5, 1200.00, TRUE);

-- =========================================================
-- DATOS INICIALES: HORARIOS
-- =========================================================

INSERT INTO horarios (hora_inicio, hora_fin, regular, intensivo, activo)
VALUES
    ('06:00', '07:00', TRUE, FALSE, TRUE),
    ('07:00', '08:00', TRUE, FALSE, TRUE),
    ('08:00', '09:00', TRUE, TRUE, TRUE),
    ('18:00', '19:00', TRUE, FALSE, TRUE),
    ('19:00', '20:00', TRUE, TRUE, TRUE),
    ('20:00', '21:00', TRUE, TRUE, TRUE);
