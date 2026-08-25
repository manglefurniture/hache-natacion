import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = p => fs.readFileSync(new URL(`../${p}`, import.meta.url), 'utf8');
const helper = read('config/intensivos-estado.php');
const registro = read('public/registro.php');
const alta = read('api/alumnos.php');
const altaUi = read('public/agregar-alumno.php');
const intensivo = read('api/intensivo-alumnos.php');
const gestion = read('api/alumno-gestion.php');
const ficha = read('public/ficha-alumno.php');

assert.match(helper, /intensivo_lunes_semana_actual/);
assert.match(helper, /modify\('\+6 days'\)/);
assert.match(helper, /intensivo_inscripcion_abierta/);
assert.match(registro, /intensivo_lunes_registro\(10\)/);
assert.match(registro, /El curso de la semana actual permanece disponible hasta el domingo/);

assert.match(altaUi, /curso_intensivo_id/);
assert.match(altaUi, /horario_intensivo_id/);
assert.match(alta, /INSERT INTO curso_intensivo_alumnos/);
assert.match(alta, /intensivo_inscripcion_abierta/);
assert.match(intensivo, /La ventana de inscripción de este curso cerró/);

assert.match(gestion, /password_verify\(\$password,\$hash\)/);
assert.match(gestion, /periodos_cerrados_alumno/);
assert.match(gestion, /foreach\(\['pagos','reposiciones_regulares','asistencias'/);
assert.match(gestion, /\$n=borrar_por_alumno\(\$pdo,\$tabla,\$alumnoId\)/);
assert.match(gestion, /DELETE FROM alumnos WHERE id=:id AND sede_id=:s/);
assert.match(ficha, /Tu contraseña de administrador/);
assert.match(ficha, /accion:'ELIMINAR'/);
assert.match(ficha, /csrf:/);

console.log('OK: altas de intensivo y eliminación administrativa protegidas.');
