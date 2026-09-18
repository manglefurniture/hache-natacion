import fs from 'node:fs';
import assert from 'node:assert/strict';

const page=fs.readFileSync(new URL('../public/profesor-sustituciones.php',import.meta.url),'utf8');
const professors=fs.readFileSync(new URL('../public/profesores.php',import.meta.url),'utf8');

assert.match(page,/page_require\(\['ADMIN'\]\)/,'La UI debe permanecer ADMIN-only.');
assert.match(page,/new DateTimeZone\('America\/Cancun'\)/,'La UI debe usar el día operativo de Cancún.');
assert.match(page,/fetch\('\/api\/profesor-sustituciones\.php\?desde=/,'La UI debe consumir la lectura F7.2.');
assert.match(page,/fetch\('\/api\/profesores\.php'/,'La UI debe reutilizar profesores existentes.');
assert.match(page,/accion:'REGISTRAR'/,'La UI debe registrar mediante la API F7.2.');
assert.match(page,/accion:'ANULAR'/,'La UI debe anular mediante la API F7.2.');
assert.match(page,/Number\(p\.activo\)===1/,'La selección debe mostrar sustitutos activos.');
assert.match(page,/profesores_asignados/,'La selección del profesor original debe usar asignaciones retornadas por backend.');
assert.doesNotMatch(page,/\/api\/sesiones\.php|\/api\/asistencia\.php|sharky/i,'La UI no debe mutar sesiones, asistencia ni Sharky.');
assert.doesNotMatch(page,/INSERT\s|UPDATE\s|DELETE\s/i,'La vista no debe contener autoridad SQL paralela.');
assert.match(professors,/href="\/profesor-sustituciones\.php"/,'Profesores debe enlazar la gestión de sustituciones.');

console.log('F7_PROFESSOR_SUBSTITUTIONS_UI_OK');
