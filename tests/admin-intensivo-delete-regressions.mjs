import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = p => fs.readFileSync(new URL(`../${p}`, import.meta.url), 'utf8');
const helper = read('config/intensivos-estado.php');
const historical = read('config/admin-historical-corrections.php');
const registro = read('public/registro.php');
const alta = read('api/alumnos.php');
const altaUi = read('public/agregar-alumno.php');
const intensivo = read('api/intensivo-alumnos.php');
const intensivoUi = read('public/intensivo-detalle.php');
const gestion = read('api/alumno-gestion.php');
const ficha = read('public/ficha-alumno.php');

assert.match(helper, /intensivo_lunes_semana_actual/);
assert.match(helper, /modify\('\+1 day'\)/);
assert.match(helper, /solo admite incorporación lunes o martes/);
assert.match(helper, /intensivo_inscripcion_abierta/);
assert.match(registro, /intensivo_lunes_registro\(10\)/);
assert.match(registro, /solo puede incorporarse hasta el martes/);

assert.match(altaUi, /curso_intensivo_id/);
assert.match(altaUi, /horario_intensivo_id/);
assert.match(alta, /INSERT INTO curso_intensivo_alumnos/);
assert.match(alta, /intensivo_inscripcion_abierta/);
assert.match(intensivo, /La ventana de inscripción de este curso cerró/);
assert.doesNotMatch(intensivo, /UPDATE alumnos SET plan_actual_id=NULL/);
assert.match(intensivo, /UPDATE alumnos SET estado_administrativo='PENDIENTE'/);

// La regla pública permanece cerrada, pero ADMIN dispone de un carril explícito,
// motivado y auditable para completar datos históricos atrasados.
assert.match(intensivo, /correccion_historica/);
assert.match(intensivo, /Escribe el motivo de la corrección histórica/);
assert.match(intensivo, /hache_admin_historical_overlap/);
assert.match(intensivo, /hache_admin_history\(\$pdo,\$alumnoId,'INTENSIVO'/);
assert.match(intensivo, /if\(\$historica\)exit;/, 'Una corrección histórica no debe disparar el correo de nueva inscripción.');
assert.match(intensivo,/if\(\$historica\)[\s\S]{0,500}WHERE a\.id=:id AND a\.sede_id=:s LIMIT 1 FOR UPDATE/,'La importación histórica de ADMIN debe poder recuperar un alumno de la sede aunque hoy esté en BAJA.');
assert.match(intensivo,/intensivo=1 AND \(activo=1 OR :hist=1\)/,'Una corrección histórica debe poder conservar un horario intensivo que hoy ya esté inactivo.');
assert.match(historical, /INSERT INTO historial/);
assert.match(historical, /Corrección histórica administrativa/);
assert.match(intensivoUi, /Corrección histórica de ADMIN/);
assert.match(intensivoUi, /motivo_correccion/);
assert.match(intensivoUi, /sincronizar_fecha_inicio/);
assert.match(intensivoUi, /La inscripción normal está cerrada/);
assert.match(intensivoUi, /Observaciones/);

// Si el alumno todavía no existe, el mismo carril ADMIN debe poder crearlo
// directamente dentro del curso histórico sin abrir esa fecha al registro normal.
assert.match(altaUi, /OR ci\.id=:preset/,'Agregar alumno debe poder cargar el curso histórico exacto recibido desde su detalle.');
assert.match(altaUi, /data-historico=/);
assert.match(altaUi, /Corrección histórica de ADMIN/);
assert.match(altaUi, /motivo_correccion:hist\?obs:null/);
assert.match(alta, /hache_admin_bool\(\$input\['correccion_historica'\]/);
assert.match(alta, /if\(!\$abierta&&!\$historica\)/,'La ventana normal debe seguir bloqueando un curso cerrado.');
assert.match(alta, /\$estadoInicial=\(\$historica&&is_array\(\$curso\)/,'Un alta histórica terminada debe tener un estado actual seguro en vez de fingir una inscripción vigente.');
assert.match(alta, /hache_admin_history\(\$pdo,\$id,'INTENSIVO'/);
assert.match(alta, /if\(\$historica\)out\(\$respuesta,201\);/,'Una alta histórica no debe ejecutar el notificador de nueva inscripción.');

assert.match(gestion, /password_verify\(\$password,\$hash\)/);
assert.match(gestion, /periodos_cerrados_alumno/);
assert.match(gestion, /p\.created_at,p\.invalidated_at/);
assert.match(gestion, /\$p\['invalidated_at'\]===null\|\|\(string\)\$p\['invalidated_at'\]>=\(string\)\$cerradoAt/, 'Una invalidación en el mismo segundo del cierre debe conservar el pago por falta de orden fiable');
assert.match(gestion, /SELECT responsable_id FROM alumno_responsable WHERE alumno_id=:a FOR UPDATE/);
assert.match(gestion, /DELETE r FROM responsables r LEFT JOIN alumno_responsable ar/);
assert.match(gestion, /ar\.responsable_id IS NULL/);
assert.match(gestion, /foreach\(\['pagos','reposiciones_regulares','asistencias'/);
assert.match(gestion, /\$n=borrar_por_alumno\(\$pdo,\$tabla,\$alumnoId\)/);
assert.match(gestion, /DELETE FROM alumnos WHERE id=:id AND sede_id=:s/);
assert.match(ficha, /Tu contraseña de administrador/);
assert.match(ficha, /accion:'ELIMINAR'/);
assert.match(ficha, /csrf:/);

console.log('OK: altas de intensivo, corrección histórica ADMIN y eliminación administrativa protegidas.');
