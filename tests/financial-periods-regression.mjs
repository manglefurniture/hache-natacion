import fs from 'node:fs';
import assert from 'node:assert/strict';

const helper=fs.readFileSync(new URL('../config/periodos-financieros.php',import.meta.url),'utf8');
const correctionRules=fs.readFileSync(new URL('../config/finanzas-correcciones.php',import.meta.url),'utf8');
const correctionApi=fs.readFileSync(new URL('../api/corregir-obligacion-mensual.php',import.meta.url),'utf8');
const migration=fs.readFileSync(new URL('../database/migrations/20260825_financial_periods.sql',import.meta.url),'utf8');
const close=fs.readFileSync(new URL('../api/cierres-mensuales.php',import.meta.url),'utf8');
const reports=fs.readFileSync(new URL('../api/reportes.php',import.meta.url),'utf8');
const proa=fs.readFileSync(new URL('../api/comisiones-proa.php',import.meta.url),'utf8');
const reconciliation=fs.readFileSync(new URL('../api/conciliacion-proa.php',import.meta.url),'utf8');
const reconciliationPdf=fs.readFileSync(new URL('../api/conciliacion-proa-pdf.php',import.meta.url),'utf8');
const exportCsv=fs.readFileSync(new URL('../api/reportes-exportar.php',import.meta.url),'utf8');
const internal=fs.readFileSync(new URL('../api/finanzas-internas.php',import.meta.url),'utf8');
const internalPage=fs.readFileSync(new URL('../public/finanzas-internas.php',import.meta.url),'utf8');
const roadmap=fs.readFileSync(new URL('../docs/HACHE-INTERNAL-IMPROVEMENTS-ROADMAP.md',import.meta.url),'utf8');

assert.match(migration,/2026-08-30/);
assert.match(migration,/2026-08-31/);
assert.match(helper,/financiero_periodo_para_fecha/);
assert.match(close,/accion.*PERIODO/s);
assert.match(reports,/financiero_totales/);
assert.match(proa,/financiero_totales/);
assert.doesNotMatch(proa,/DATE\(p\.fecha\).*INTENSIVO/s);
assert.match(reconciliation,/periodos-financieros\.php/);
assert.match(reconciliation,/financiero_totales\(\$pdo,\$site,\$period\)/);
assert.match(reconciliation,/\$rango=\$base\['rango'\]/);
assert.match(reconciliation,/\$start=\$rango\['inicio'\].*\$end=.*\$rango\['cierre'\]/s);
assert.doesNotMatch(reconciliation,/function\s+monthRange\s*\(/);
assert.match(reconciliationPdf,/periodos-financieros\.php/);
assert.match(reconciliationPdf,/financiero_totales\(\$pdo,\$site,\$periodo\)/);
assert.match(reconciliationPdf,/\$desde=\$rango\['inicio'\].*\$hasta=.*\$rango\['cierre'\]/s);
assert.doesNotMatch(reconciliationPdf,/function\s+rangeMonth\s*\(/);
assert.match(exportCsv,/financiero_periodo_para_fecha/);
assert.match(exportCsv,/\$periodoDesde=financiero_periodo_para_fecha\(\$pdo,\(string\)\$s\['id'\],\$desde\)/);
assert.match(exportCsv,/\$periodoHasta=financiero_periodo_para_fecha\(\$pdo,\(string\)\$s\['id'\],\$hasta\)/);
assert.match(migration,/INSERT IGNORE INTO periodos_financieros/g);
assert.doesNotMatch(migration,/ON DUPLICATE KEY UPDATE/);
assert.match(close,/closedPeriod\(\$pdo,\(string\)\$site\['id'\],\$nextPeriod\)/);

// Fase 2: una sola autoridad financiera y lectura sin efectos.
assert.match(internal,/financiero_totales\(\$pdo,\$sede,\$periodo\)/);
assert.match(internal,/\$rango=\$totales\['rango'\]/);
assert.match(internal,/m\.importe_a_cobrar total_obligacion/);
assert.match(internal,/i\.importe total_obligacion/);
assert.match(internal,/ci\.precio total_obligacion/);
assert.doesNotMatch(internal,/JOIN planes/);
assert.match(internal,/CASE WHEN p\.estado='VALIDO' THEN p\.importe ELSE 0 END/);
assert.match(internal,/SUM\(p\.estado='INVALIDADO'\)/);
assert.match(internal,/p\.intensivo_id=ci\.id AND p\.alumno_id=cia\.alumno_id AND p\.tipo='INTENSIVO'/);
assert.match(internal,/FROM cierres_mensuales c/);
assert.match(internal,/diferencia_total/);
assert.doesNotMatch(internal,/\b(?:INSERT|UPDATE|DELETE|REPLACE)\s+(?:INTO\s+)?(?:pagos|mensualidades|inscripciones|cursos_intensivos|curso_intensivo_alumnos|periodos_financieros|cierres_mensuales)\b/i);

// Una corrección histórica es una acción ADMIN separada de la lectura y debe
// negarse en cuanto exista movimiento financiero o falte evidencia de intensivo.
assert.match(correctionRules,/\$estado==='PENDIENTE'/);
assert.match(correctionRules,/\$sinCobro && \$sinPagos/);
assert.match(correctionRules,/\$continuidad \|\| \$intensivoSolapado/);
assert.match(correctionApi,/auth_require\(\['ADMIN'\]\)/);
assert.match(correctionApi,/mb_strlen\(\$motivo\)<5/);
assert.match(correctionApi,/SELECT id FROM pagos WHERE mensualidad_id=:id FOR UPDATE/);
assert.match(correctionApi,/finanzas_mensualidad_corregible\(\$m\)/);
assert.match(correctionApi,/hache_admin_history\([\s\S]*'MENSUALIDAD'[\s\S]*'MENSUALIDAD_CORREGIDA'/);
assert.match(correctionApi,/DELETE FROM mensualidades WHERE id=:id[\s\S]*estado='PENDIENTE'[\s\S]*importe_cobrado IS NULL[\s\S]*NOT EXISTS \(SELECT 1 FROM pagos p WHERE p\.mensualidad_id=:pid\)/);
assert.match(internal,/\(\$viewer\['rol'\]\?\?'\'\)==='ADMIN'&&finanzas_mensualidad_corregible\(\$r\)/);
assert.match(internal,/COUNT\(p\.id\) pagos_totales/);
assert.match(internal,/intensivo_solapado/);

assert.match(internalPage,/Saldo = obligación registrada/);
assert.match(internalPage,/No sobrescribe el cierre guardado/);
assert.match(internalPage,/ultima_fecha_pago/);
assert.match(internalPage,/Último cobro/);
assert.match(internalPage,/Corregir obligación/);
assert.match(internalPage,/corregir-obligacion-mensual\.php/);
assert.match(internalPage,/Motivo obligatorio de la corrección histórica/);
assert.match(roadmap,/Decisión P-02 resuelta para el primer incremento/);
assert.match(roadmap,/Fase 1 \*\*Implementada\*\*/);
assert.match(roadmap,/Fase 2 \*\*Desplegado\*\*/);
assert.match(roadmap,/PR #261/);

console.log('financial period regression checks: OK');
