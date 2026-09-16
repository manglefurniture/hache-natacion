import fs from 'node:fs';
import assert from 'node:assert/strict';

const helper=fs.readFileSync(new URL('../config/periodos-financieros.php',import.meta.url),'utf8');
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
assert.match(internalPage,/Saldo = obligación registrada/);
assert.match(internalPage,/No sobrescribe el cierre guardado/);
assert.match(roadmap,/Decisión P-02 resuelta para el primer incremento/);
assert.match(roadmap,/Fase 1 \*\*Implementada\*\*/);
assert.match(roadmap,/Fase 2 \*\*En revisión\*\*/);
assert.match(roadmap,/PR #261/);

console.log('financial period regression checks: OK');