CREATE TABLE IF NOT EXISTS alumno_reglas_negocio (
    alumno_id CHAR(36) PRIMARY KEY,
    inscripcion_historica_cubierta TINYINT(1) NOT NULL DEFAULT 0,
    nota VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_alumno_reglas_negocio_alumno
        FOREIGN KEY (alumno_id) REFERENCES alumnos(id)
        ON DELETE CASCADE
);
