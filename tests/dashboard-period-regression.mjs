import fs from 'node:fs';
import assert from 'node:assert/strict';

const api=fs.readFileSync(new URL('../api/dashboard.php',import.meta.url),'utf8');
const page=fs.readFileSync(new URL('../public/dashboard.php',import.meta.url),'utf8');
const backendCss=fs.readFileSync(new URL('../public/assets/backend-menu.css',import.meta.url),'utf8');

assert.match(api,/periodos-financieros\.php/);
assert.match(api,/DateTimeZone\('America\/Cancun'\)/);
assert.match(api,/new DateTimeImmutable\('now',new DateTimeZone\('America\/Cancun'\)\)/);
assert.doesNotMatch(api,/\bdate\('Y-m-d'\)/);
assert.doesNotMatch(api,/CURDATE\(\)/);
assert.match(api,/financiero_periodo_para_fecha\(\$pdo,\$sid,\$hoy\)/);
assert.match(api,/financiero_totales\(\$pdo,\$s,\$periodoVigente\)/);
assert.match(api,/m\.estado='PAGADA'.*:hoy_m BETWEEN m\.periodo_inicio AND m\.periodo_fin/s);
assert.match(api,/UNION\s+SELECT cia\.alumno_id/s);
assert.match(api,/:hoy_i BETWEEN ci\.fecha_inicio AND ci\.fecha_fin/);
assert.match(api,/p\.tipo='INTENSIVO' AND p\.estado='VALIDO'/);
assert.match(api,/mensualidades WHERE sede_id=:s AND :hoy BETWEEN periodo_inicio AND periodo_fin/);
assert.match(api,/aa\.estado='ACTIVO' AND :hoy BETWEEN aa\.fecha_desde AND aa\.fecha_hasta/);
assert.match(api,/m\.estado='PAGADA' AND :hoy BETWEEN m\.periodo_inicio AND m\.periodo_fin/);
assert.doesNotMatch(api,/m\.mes=:mes.*m\.anio=:anio/s);
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
