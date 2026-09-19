import fs from 'node:fs';
import assert from 'node:assert/strict';

const page=fs.readFileSync(new URL('../public/resumen-diario.php',import.meta.url),'utf8');
const dashboard=fs.readFileSync(new URL('../public/dashboard.php',import.meta.url),'utf8');

assert.match(page,/page_require\(\['ADMIN','VERIFICADOR'\]\)/,'La UI F9 debe conservar acceso ADMIN/VERIFICADOR.');
assert.match(page,/new DateTimeZone\('America\/Cancun'\)/,'La fecha inicial debe usar el día operativo de Cancún.');
assert.match(page,/fetch\('\/api\/resumen-diario\.php\?'/,'La UI debe consumir únicamente el contrato F9.1.');
assert.match(page,/VIVA_RECONCILIABLE|tipo_lectura/,'La UI debe exponer el tipo de lectura recibido.');
assert.match(page,/snapshot===false/,'La UI debe declarar que no es un snapshot.');
assert.match(page,/Lectura viva y reconciliable/,'La UI debe avisar que correcciones posteriores pueden cambiar la lectura.');
assert.match(page,/pendientes_nuevos/,'Pendientes nuevos debe respetar la disponibilidad del backend.');
assert.match(page,/cobros.*total_valido/s,'La UI debe mostrar el total válido entregado por el backend.');
assert.match(page,/invalidados/,'La UI debe mantener visibles los cobros invalidados.');
assert.match(page,/correcciones_asistencia/,'La UI debe representar correcciones durables de asistencia.');
assert.match(page,/safeHref/,'Los enlaces devueltos por las fuentes deben limitarse a rutas internas.');
assert.match(page,/role="status" aria-live="polite"/,'La UI debe anunciar carga y errores.');
assert.match(page,/@media\(max-width:720px\)/,'La vista debe tener adaptación móvil explícita.');
assert.doesNotMatch(page,/method\s*:\s*['"]POST['"]|accion\s*:/,'La UI F9 debe permanecer read-only.');
assert.doesNotMatch(page,/INSERT\s|UPDATE\s|DELETE\s/i,'La UI no debe contener autoridad SQL paralela.');
assert.doesNotMatch(page,/fetch\([^)]*\/api\/(?:sesiones|pagos|pendientes|profesor-actividad)\.php/i,'La UI no debe reconstruir el resumen desde APIs laterales.');
assert.match(dashboard,/\/resumen-diario\.php/,'El dashboard debe ofrecer acceso al resumen diario.');
assert.match(dashboard,/grid-template-columns:repeat\(6,1fr\)/,'Las seis acciones rápidas deben conservar una cuadrícula estable en escritorio.');

console.log('F9_DAILY_SUMMARY_UI_OK');
