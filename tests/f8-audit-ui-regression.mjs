import fs from 'node:fs';
import assert from 'node:assert/strict';

const page=fs.readFileSync(new URL('../public/auditoria.php',import.meta.url),'utf8');

assert.match(page,/page_require\(\['ADMIN'\]\)/,'La vista F8 debe seguir siendo ADMIN-only.');
assert.match(page,/\/api\/auditoria-unificada\.php/,'La UI debe consumir únicamente el backend unificado F8.');
assert.doesNotMatch(page,/fetch\('\/api\/auditoria\.php/,'La UI no debe volver a calcular desde el endpoint legacy.');
assert.match(page,/Cambio confirmado/);
assert.match(page,/Resultado técnico/);
assert.match(page,/Actor no registrado/);
assert.match(page,/Sede no registrada/);
assert.match(page,/No registrado/);
assert.match(page,/data&&data\.available===true/,'Before/after deben depender del indicador de disponibilidad del backend.');
assert.doesNotMatch(page,/JSON\.parse\(v\.detalle/,'La UI no debe reinterpretar el detalle crudo legacy.');
assert.doesNotMatch(page,/usuario_nombre\|\|'sistema'/,'Actor desconocido no puede convertirse en sistema.');
assert.doesNotMatch(page,/v\.created_at|v\.usuario_nombre|v\.entidad_id/,'La UI no debe consumir el contrato legacy directamente.');
assert.match(page,/fuente.*auditoria_eventos/s);
assert.match(page,/fuente.*historial/s);
assert.match(page,/id="desde"/);
assert.match(page,/id="hasta"/);
assert.match(page,/id="limite"/);
assert.match(page,/\.filters button\{background:#172033;color:#fff/,'Debe conservar el contrato visual existente del backend.');
assert.match(page,/\.route\{[^}]*color:#475569/,'Debe conservar cobertura de dark mode existente.');
assert.match(page,/@media\(max-width:520px\)/,'La vista debe conservar adaptación móvil.');

console.log('F8_AUDIT_UI_REGRESSION_OK');
