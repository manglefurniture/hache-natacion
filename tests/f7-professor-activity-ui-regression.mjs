import fs from 'node:fs';
import assert from 'node:assert/strict';

const page=fs.readFileSync(new URL('../public/profesor-actividad.php',import.meta.url),'utf8');
const professors=fs.readFileSync(new URL('../public/profesores.php',import.meta.url),'utf8');

assert.match(page,/page_require\(\['ADMIN'\]\)/,'La UI de actividad debe permanecer ADMIN-only.');
assert.match(page,/new DateTimeZone\('America\/Cancun'\)/,'La UI debe usar el día operativo de Cancún.');
assert.match(page,/fetch\('\/api\/profesor-actividad\.php\?desde=/,'La UI debe consumir únicamente el contrato F7.4 para la actividad.');
assert.match(page,/carga_prevista/,'La UI debe mostrar la carga prevista retornada por F7.4.');
assert.match(page,/carga_realizada/,'La UI debe mostrar la carga realizada retornada por F7.4.');
assert.match(page,/sesiones_realizadas_sin_atribucion/,'La UI debe conservar los casos sin atribución.');
assert.match(page,/imparticion_confirmada/,'La UI debe representar la evidencia de impartición del backend.');
assert.match(page,/docencia_compartida/,'La UI debe conservar la señal de docencia compartida.');
assert.match(page,/incidencia/,'La UI debe mostrar incidencias devueltas por F7.4.');
assert.match(page,/sustituciones/,'La UI debe mostrar sustituciones devueltas por F7.4.');
assert.match(page,/historia_previa_reconstruida/,'La UI debe exponer el límite de cobertura histórica.');
assert.match(page,/role="status" aria-live="polite"/,'La UI debe anunciar estados de carga y error.');
assert.match(page,/@media\(max-width:540px\)/,'La UI debe tener adaptación móvil explícita.');
assert.doesNotMatch(page,/method\s*:\s*['"]POST['"]|accion\s*:/,'La vista de actividad debe ser de solo lectura.');
assert.doesNotMatch(page,/INSERT\s|UPDATE\s|DELETE\s/i,'La vista no debe contener autoridad SQL paralela.');
assert.doesNotMatch(page,/fetch\([^)]*\/api\/(?:sesiones|asistencia|profesor-sustituciones)\.php/i,'La actividad no debe reconstruirse desde otras APIs.');
assert.match(professors,/href="\/profesor-actividad\.php"/,'Profesores debe enlazar la vista general de actividad.');
assert.match(professors,/\/profesor-actividad\.php\?profesor_id=/,'Cada profesor debe ofrecer acceso directo a su actividad.');

console.log('F7_PROFESSOR_ACTIVITY_UI_OK');
