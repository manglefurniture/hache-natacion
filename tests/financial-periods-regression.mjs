import fs from 'node:fs';
import assert from 'node:assert/strict';

const helper=fs.readFileSync(new URL('../config/periodos-financieros.php',import.meta.url),'utf8');
const migration=fs.readFileSync(new URL('../database/migrations/20260825_financial_periods.sql',import.meta.url),'utf8');
const close=fs.readFileSync(new URL('../api/cierres-mensuales.php',import.meta.url),'utf8');
const reports=fs.readFileSync(new URL('../api/reportes.php',import.meta.url),'utf8');
const proa=fs.readFileSync(new URL('../api/comisiones-proa.php',import.meta.url),'utf8');
const exportCsv=fs.readFileSync(new URL('../api/reportes-exportar.php',import.meta.url),'utf8');

assert.match(migration,/2026-08-30/);
assert.match(migration,/2026-08-31/);
assert.match(helper,/financiero_periodo_para_fecha/);
assert.match(close,/accion.*PERIODO/s);
assert.match(reports,/financiero_totales/);
assert.match(proa,/financiero_totales/);
assert.doesNotMatch(proa,/DATE\(p\.fecha\).*INTENSIVO/s);
assert.match(exportCsv,/financiero_periodo_para_fecha/);
assert.match(exportCsv,/\$periodoDesde=financiero_periodo_para_fecha\(\$pdo,\(string\)\$s\['id'\],\$desde\)/);
assert.match(exportCsv,/\$periodoHasta=financiero_periodo_para_fecha\(\$pdo,\(string\)\$s\['id'\],\$hasta\)/);
assert.match(migration,/INSERT IGNORE INTO periodos_financieros/g);
assert.doesNotMatch(migration,/ON DUPLICATE KEY UPDATE/);
assert.match(close,/closedPeriod\(\$pdo,\(string\)\$site\['id'\],\$nextPeriod\)/);
console.log('financial period regression checks: OK');
