-- Hache Natación — limpieza controlada de datos de prueba
-- DESTRUCTIVO: ejecutar únicamente después de generar respaldo completo.
-- Conserva: estructura, planes, horarios, configuración, usuarios ADMIN/VERIFICADOR.
-- Elimina: alumnos y toda la operación de prueba relacionada.

SET FOREIGN_KEY_CHECKS = 0;

-- Asistencia / avisos / reposiciones
DELETE FROM reposiciones_regulares;
DELETE FROM asistencias;
DELETE FROM avisos_ausencia;
DELETE FROM ausencias;
DELETE FROM notificaciones;

-- Economía
DELETE FROM pagos;
DELETE FROM mensualidades;
DELETE FROM inscripciones;

-- Intensivos
DELETE FROM curso_intensivo_alumnos;
DELETE FROM cursos_intensivos;

-- Historial y sesiones generadas durante pruebas
DELETE FROM historial;
DELETE FROM sesiones;

-- Responsables
DELETE FROM alumno_responsable;
DELETE FROM responsables;

-- Cuentas del portal de alumnos
DELETE FROM usuarios WHERE rol = 'ALUMNO';

-- Finalmente, alumnos
DELETE FROM alumnos;

-- Reinicia folios de pago para comenzar la operación real limpia.
ALTER TABLE pagos AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS = 1;

-- Comprobaciones finales. Todos estos contadores deben ser 0.
SELECT 'alumnos' tabla, COUNT(*) registros FROM alumnos
UNION ALL SELECT 'usuarios_alumno', COUNT(*) FROM usuarios WHERE rol='ALUMNO'
UNION ALL SELECT 'pagos', COUNT(*) FROM pagos
UNION ALL SELECT 'mensualidades', COUNT(*) FROM mensualidades
UNION ALL SELECT 'inscripciones', COUNT(*) FROM inscripciones
UNION ALL SELECT 'cursos_intensivos', COUNT(*) FROM cursos_intensivos
UNION ALL SELECT 'curso_intensivo_alumnos', COUNT(*) FROM curso_intensivo_alumnos
UNION ALL SELECT 'sesiones', COUNT(*) FROM sesiones
UNION ALL SELECT 'asistencias', COUNT(*) FROM asistencias
UNION ALL SELECT 'reposiciones_regulares', COUNT(*) FROM reposiciones_regulares
UNION ALL SELECT 'avisos_ausencia', COUNT(*) FROM avisos_ausencia
UNION ALL SELECT 'ausencias', COUNT(*) FROM ausencias
UNION ALL SELECT 'historial', COUNT(*) FROM historial
UNION ALL SELECT 'responsables', COUNT(*) FROM responsables
UNION ALL SELECT 'alumno_responsable', COUNT(*) FROM alumno_responsable
UNION ALL SELECT 'notificaciones', COUNT(*) FROM notificaciones;

-- Estos datos se muestran para confirmar que la configuración base se conservó.
SELECT 'planes' tabla, COUNT(*) registros FROM planes
UNION ALL SELECT 'horarios', COUNT(*) FROM horarios
UNION ALL SELECT 'usuarios_admin_verificador', COUNT(*) FROM usuarios WHERE rol IN ('ADMIN','VERIFICADOR')
UNION ALL SELECT 'configuracion', COUNT(*) FROM configuracion;
