import fs from 'node:fs';
import assert from 'node:assert/strict';

const api=fs.readFileSync(new URL('../api/dashboard.php',import.meta.url),'utf8');
const page=fs.readFileSync(new URL('../public/dashboard.php',import.meta.url),'utf8');
const backendCss=fs.readFileSync(new URL('../public/assets/backend-menu.css',import.meta.url),'utf8');

assert.match(api,/periodos-financieros\.php/);
assert.match(api,/financiero_periodo_para_fecha\(\$pdo,\$sid,\$hoy\)/);
assert.match(api,/financiero_totales\(\$pdo,\$s,\$periodoVigente\)/);
assert.match(api,/m\.estado='PAGADA'.*m\.mes=:mes.*m\.anio=:anio/s);
assert.match(api,/UNION\s+SELECT cia\.alumno_id/s);
assert.match(api,/CURDATE\(\) BETWEEN ci\.fecha_inicio AND ci\.fecha_fin/);
assert.match(api,/p\.tipo='INTENSIVO' AND p\.estado='VALIDO'/);
assert.match(api,/'facturacion'=>\['cantidad'/);

assert.match(page,/Facturación del mes/);
assert.match(page,/d\.facturacion\?\.total/);
assert.doesNotMatch(page,/Ingresos del mes/);
assert.doesNotMatch(page,/d\.caja\?\.total/);
assert.match(page,/mensualidad vigente o intensivo en curso/);

assert.match(backendCss,/--hache-shadow-hover/);
assert.match(backendCss,/translateY\(-2px\)/);
assert.match(backendCss,/@media\(prefers-reduced-motion:reduce\)/);

console.log('dashboard period regression checks: OK');
