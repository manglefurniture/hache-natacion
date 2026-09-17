import fs from 'node:fs';
import assert from 'node:assert/strict';

const api=fs.readFileSync(new URL('../api/dashboard.php',import.meta.url),'utf8');
const financeApi=fs.readFileSync(new URL('../api/finanzas-internas.php',import.meta.url),'utf8');
const obligations=fs.readFileSync(new URL('../config/finanzas-obligaciones.php',import.meta.url),'utf8');
const alerts=fs.readFileSync(new URL('../api/alertas.php',import.meta.url),'utf8');
const clock=fs.readFileSync(new URL('../config/dashboard-tiempo.php',import.meta.url),'utf8');
const pendingComposite=fs.readFileSync(new URL('../config/centro-pendientes-compuesto.php',import.meta.url),'utf8');
const boundary=fs.readFileSync(new URL('./dashboard-fecha-operativa.php',import.meta.url),'utf8');
const page=fs.readFileSync(new URL('../public/dashboard.php',import.meta.url),'utf8');
const backendCss=fs.readFileSync(new URL('../public/assets/backend-menu.css',import.meta.url),'utf8');

assert.match(api,/periodos-financieros\.php/);
assert.match(api,/finanzas-obligaciones\.php/);
assert.match(api,/dashboard-tiempo\.php/);
assert.match(api,/centro-pendientes-compuesto\.php/);
assert.match(api,/dashboard_contexto_temporal\(\s*\$sid/);
assert.doesNotMatch(api,/\bdate\('Y-m-d'\)/);
assert.doesNotMatch(api,/CURDATE\(\)/);
assert.match(api,/financiero_periodo_para_fecha\(\$pdo,\$sedeId,\$fecha\)/);
assert.match(api,/financiero_totales\(\$pdo,\$sede,\$periodoVigente\)/);
assert.match(api,/finanzas_obligaciones_periodo\(\$pdo,\$sede,\$periodoVigente,false\)/);
assert.match(api,/m\.estado='PAGADA'.*:hoy_m BETWEEN m\.periodo_inicio AND m\.periodo_fin/s);
assert.match(api,/UNION\s+SELECT cia\.alumno_id/s);
assert.match(api,/:hoy_i BETWEEN ci\.fecha_inicio AND ci\.fecha_fin/);
assert.match(api,/p\.tipo='INTENSIVO' AND p\.estado='VALIDO'/);
assert.match(api,/mensualidades WHERE sede_id=:s AND :hoy BETWEEN periodo_inicio AND periodo_fin/);
assert.match(api,/aa\.estado='ACTIVO' AND :hoy BETWEEN aa\.fecha_desde AND aa\.fecha_hasta/);
assert.doesNotMatch(api,/m\.mes=:mes.*m\.anio=:anio/s);
assert.match(api,/'facturacion'=>\[/);
assert.match(api,/'saldos_periodo'=>\[/);
assert.match(api,/centro_pendientes_compuesto_resumen_activo\(\$pdo,\$sede,\$includeGlobalProspects\)/);
assert.match(api,/'centro_pendientes'=>\$pendientesResumen/);
assert.match(api,/'alertas_f5'=>\[/);
assert.match(api,/REPOSICION_REGULAR_DISPONIBLE/);
assert.match(api,/MENSUALIDAD_REGULAR_SIN_COBERTURA/);
assert.doesNotMatch(api,/\$sqlSinPago/);
assert.doesNotMatch(api,/FROM reposiciones_regulares rr/);

assert.match(financeApi,/finanzas-obligaciones\.php/);
assert.match(financeApi,/finanzas_obligaciones_periodo\(/);
assert.match(financeApi,/'obligaciones_resumen'=>\$lecturaObligaciones\['resumen'\]/);
assert.match(financeApi,/'obligaciones'=>\$lecturaObligaciones\['obligaciones'\]/);
assert.doesNotMatch(financeApi,/function finanzas_normalizar_obligacion/);
assert.doesNotMatch(financeApi,/FROM mensualidades m/);
assert.doesNotMatch(financeApi,/FROM curso_intensivo_alumnos cia/);

assert.match(obligations,/function finanzas_obligaciones_periodo/);
assert.match(obligations,/function finanzas_normalizar_obligacion/);
assert.match(obligations,/FROM mensualidades m/);
assert.match(obligations,/FROM inscripciones i/);
assert.match(obligations,/FROM curso_intensivo_alumnos cia/);
assert.match(obligations,/p\.estado='VALIDO'/);
assert.match(obligations,/ci\.fecha_inicio BETWEEN :d AND :h/);
assert.match(obligations,/i\.fecha BETWEEN :d AND :h/);
assert.match(obligations,/'con_saldo'=>0/);
assert.match(obligations,/\$o\['saldo'\]>0\.009/);

assert.match(pendingComposite,/function centro_pendientes_compuesto_fuentes_activas/);
assert.match(pendingComposite,/centro_pendientes_fuentes_activas\(/);
assert.match(pendingComposite,/centro_pendientes_continuidad_fuentes_activas\(/);
assert.match(pendingComposite,/centro_pendientes_prospectos_fuentes_activas\(/);
assert.match(pendingComposite,/function centro_pendientes_compuesto_resumen_activo/);
assert.match(pendingComposite,/centro_pendientes_estado_efectivo\(\$historico\[\$identidad\] \?\? null, true\)/);
assert.match(pendingComposite,/'globales' => 0/);
assert.match(pendingComposite,/ksort\(\$resumen\['por_tipo'\]\)/);

assert.match(alerts,/dashboard-tiempo\.php/);
assert.match(alerts,/\$hoy=hache_instante_operativo\(\)/);
assert.match(alerts,/\$hoyFecha=\$hoy->format\('Y-m-d'\)/);
assert.match(alerts,/reglas-acceso\.php/);
assert.match(alerts,/regla_mensualidad_regular_cubierta\(/);
assert.doesNotMatch(alerts,/m\.estado='PAGADA' AND :hoy BETWEEN m\.periodo_inicio AND m\.periodo_fin/);
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

assert.match(page,/Facturación del periodo/);
assert.match(page,/d\.facturacion\?\.total/);
assert.doesNotMatch(page,/Ingresos del mes/);
assert.doesNotMatch(page,/d\.caja\?\.total/);
assert.match(page,/mensualidad vigente o intensivo vigente con pago/);
assert.match(page,/d\.saldos_periodo\?\.saldo/);
assert.match(page,/Saldo del periodo/);
assert.match(page,/finanzas-internas\.php\?periodo=/);
assert.match(page,/d\.alertas_f5\?\.total/);
assert.match(page,/d\.centro_pendientes\?\.pendientes/);
assert.match(page,/Centro de pendientes/);
assert.doesNotMatch(page,/d\.sin_pago_mes/);
assert.match(page,/--depth:inset 0 1px 0/);
assert.match(page,/\.hero-card\.dark\{[^}]*border-top-color:[^;]+;border-bottom-color:/s);
assert.match(page,/\.quick a\{[^}]*border-top-color:#fff;[^}]*border-bottom-color:#b8c5d3;/s);
assert.match(page,/\.quick a:active\{[^}]*box-shadow:inset 0 1px 2px/s);
assert.match(page,/\.quick a\.primary:active\{[^}]*box-shadow:inset 0 2px 4px/s);

assert.match(backendCss,/--hache-shadow-hover/);
assert.match(backendCss,/translateY\(-2px\)/);
assert.match(backendCss,/@media\(prefers-reduced-motion:reduce\)/);

console.log('dashboard period regression checks: OK');
