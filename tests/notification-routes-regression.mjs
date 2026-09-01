import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(new URL('..', import.meta.url).pathname);
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');

const publicRegistration = read('public/registro.php');
const adminStudents = read('api/alumnos.php');
const intensiveStudents = read('api/intensivo-alumnos.php');

assert.match(publicRegistration, /config\/notificaciones-email\.php/, 'el registro público debe cargar el notificador por correo');
assert.match(publicRegistration, /\$pdo->commit\(\);[\s\S]*\$alertaAlumno=/, 'la alerta pública solo debe prepararse después de confirmar la transacción');
assert.match(publicRegistration, /hache_notificar_nueva_inscripcion\(\$alertaAlumno,\$tipo,\$alertaDetalle\)/, 'el registro público regular e intensivo debe disparar la alerta');
assert.match(publicRegistration, /fastcgi_finish_request[\s\S]*hache_notificar_nueva_inscripcion/, 'en PHP-FPM el correo público debe enviarse después de entregar la respuesta');
assert.match(publicRegistration, /catch\(Throwable \$x\).*Falló alerta de registro público/s, 'un fallo de correo público no debe romper el registro confirmado');

assert.match(adminStudents, /hache_notificar_nueva_inscripcion\(\$alumno,\$tipoIngreso/, 'el alta manual de alumno debe conservar su alerta existente');

assert.match(intensiveStudents, /config\/notificaciones-email\.php/, 'el endpoint de intensivos debe cargar el notificador por correo');
assert.match(intensiveStudents, /SELECT a\.\*,s\.clave AS sede_clave,s\.nombre AS sede_nombre/, 'la alerta de intensivo debe conservar datos del alumno y sede');
assert.match(intensiveStudents, /SELECT id,hora_inicio,hora_fin FROM horarios/, 'la alerta de intensivo debe incluir el horario validado');
assert.match(intensiveStudents, /\$pdo->commit\(\);[\s\S]*hache_notificar_nueva_inscripcion\(\$alumnoNotificacion,'INTENSIVO'/, 'la alerta de intensivo debe dispararse solo después del commit');
assert.match(intensiveStudents, /'curso_inicio'=>\(string\)\$curso\['fecha_inicio'\]/, 'la alerta de intensivo debe incluir la fecha de inicio');
assert.match(intensiveStudents, /catch\(Throwable \$e\).*Falló alerta de alta a intensivo/s, 'un fallo de correo de intensivo no debe revertir el alta');

console.log('notification-routes-regression: OK');
