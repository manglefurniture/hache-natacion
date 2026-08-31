import fs from 'node:fs';
import assert from 'node:assert/strict';

const api=fs.readFileSync(new URL('../api/dashboard.php',import.meta.url),'utf8');
const alerts=fs.readFileSync(new URL('../api/alertas.php',import.meta.url),'utf8');
const clock=fs.readFileSync(new URL('../config/dashboard-tiempo.php',import.meta.url),'utf8');
const boundary=fs.readFileSync(new URL('./dashboard-fecha-operativa.php',import.meta.url),'utf8');
const page=fs.readFileSync(new URL('../public/dashboard.php',import.meta.url),'utf8');
const backendCss=fs.readFileSync(new URL('../public/assets/backend-menu.css',import.meta.url),'utf8');

assert.match(api,/periodos-financieros\.php/);
assert.match(api,/dashboard-tiempo\.php/);
assert.match(api,/dashboard_contexto_temporal\(\$sid/);
assert.doesNotMatch(api,/\bdate\('Y-m-d'\)/);
assert.doesNotMatch(api,/CURDATE\(\)/);
assert.match(api,/financiero_periodo_para_fecha\(\$pdo,\$sedeId,\$fecha\)/);
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

assert.match(alerts,/dashboard-tiempo\.php/);
assert.match(alerts,/\$hoy=hache_instante_operativo\(\)/);
assert.match(alerts,/\$hoyFecha=\$hoy->format\('Y-m-d'\)/);
assert.match(alerts,/m\.estado='PAGADA' AND :hoy BETWEEN m\.periodo_inicio AND m\.periodo_fin/);
assert.match(alerts,/'?:hoy'?/);
assert.doesNotMatch(alerts,/CURDATE\(\)/);
assert.doesNotMatch(alerts,/new DateTimeImmutable\('today'\)/);

assert.match(clock,/DateTimeZone\('America\/Cancun'\)/);
assert.match(clock,/function hache_instante_operativo/);
assert.match(clock,/\?DateTimeImmutable \$instante/);
assert.match(clock,/\$instante->setTimezone\(\$zona\)/);
assert.match(clock,/'fecha' => \$fecha/);
assert.match(clock,/'periodo_vigente' => \(string\)\$resolverPeriodo\(\$sedeId, \$fecha\)/);

assert.match(boundary,/2026-08-30T00:30:00\+00:00/);
assert.match(boundary,/2026-08-31T04:59:59\+00:00/);
assert.match(boundary,/2026-08-31T05:00:00\+00:00/);
assert.match(boundary,/00:30 UTC fecha Cancún/);
assert.match(boundary,/después medianoche periodo/);

assert.match(page,/Facturación del mes/);
assert.match(page,/d\.facturacion\?\.total/);
assert.doesNotMatch(page,/Ingresos del mes/);
assert.doesNotMatch(page,/d\.caja\?\.total/);
assert.match(page,/mensualidad vigente o intensivo en curso/);
assert.match(page,/--depth:inset 0 1px 0/);
assert.match(page,/\.hero-card\.dark\{[^}]*border-top-color:[^;]+;border-bottom-color:/s);
assert.match(page,/\.quick a\{[^}]*border-top-color:#fff;[^}]*border-bottom-color:#b8c5d3;/s);
assert.match(page,/\.quick a:active\{[^}]*box-shadow:inset 0 1px 2px/s);
assert.match(page,/\.quick a\.primary:active\{[^}]*box-shadow:inset 0 2px 4px/s);

assert.match(backendCss,/--hache-shadow-hover/);
assert.match(backendCss,/translateY\(-2px\)/);
assert.match(backendCss,/@media\(prefers-reduced-motion:reduce\)/);

console.log('dashboard period regression checks: OK');
