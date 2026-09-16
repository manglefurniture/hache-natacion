import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(new URL(`../${p}`,import.meta.url),'utf8');
const api=read('api/finanzas-internas.php');
const page=read('public/finanzas-internas.php');
const hub=read('public/finanzas.php');
const roadmap=read('docs/HACHE-INTERNAL-IMPROVEMENTS-ROADMAP.md');

// La lectura financiera comparte las autoridades vigentes, no un cálculo paralelo.
assert.match(api,/require_once __DIR__\.'\/\.\.\/config\/periodos-financieros\.php'/);
assert.match(api,/financiero_totales\(\$pdo,\$sede,\$periodo\)/);
assert.match(api,/\$rango=\$totales\['rango'\]/);
assert.match(api,/porcentaje_mensualidad_socio/);
assert.match(api,/porcentaje_intensivo_socio/);
assert.match(api,/porcentaje_inscripcion_socio/);

// Obligaciones: monto histórico/registrado, nunca tarifa actual reconstruida.
assert.match(api,/m\.importe_a_cobrar total_obligacion/);
assert.match(api,/i\.importe total_obligacion/);
assert.match(api,/ci\.precio total_obligacion/);
assert.doesNotMatch(api,/JOIN planes/);

// Solo pagos válidos reducen saldo; invalidados siguen contándose como historia.
assert.match(api,/CASE WHEN p\.estado='VALIDO' THEN p\.importe ELSE 0 END/);
assert.match(api,/SUM\(p\.estado='INVALIDADO'\)/);
assert.match(api,/\$saldo=max\(0\.0,round\(\$total-\$pagado,2\)\)/);

// Los abonos de intensivo están cercados por alumno + curso.
assert.match(api,/p\.intensivo_id=ci\.id AND p\.alumno_id=cia\.alumno_id AND p\.tipo='INTENSIVO'/);

// Un cierre guardado se compara; este endpoint no lo crea ni lo actualiza.
assert.match(api,/FROM cierres_mensuales c/);
assert.match(api,/diferencia_total/);
assert.doesNotMatch(api,/\b(?:INSERT|UPDATE|DELETE)\s+(?:INTO\s+)?cierres_mensuales\b/i);

// Endpoint estrictamente de lectura.
assert.match(api,/REQUEST_METHOD.*GET/s);
assert.doesNotMatch(api,/\b(?:INSERT|UPDATE|DELETE|REPLACE)\s+(?:INTO\s+)?(?:pagos|mensualidades|inscripciones|cursos_intensivos|curso_intensivo_alumnos|periodos_financieros)\b/i);

// Integración UI y contrato visible.
assert.match(hub,/data-v="obligaciones"/);
assert.match(hub,/data-src="\/finanzas-internas\.php\?sede=/);
assert.match(page,/Saldo = obligación registrada/);
assert.match(page,/pagos <strong>VALIDO<\/strong>/);
assert.match(page,/No sobrescribe el cierre guardado/);
assert.match(page,/page_require\(\['ADMIN','VERIFICADOR'\]\)/);

// El roadmap registra la transición real y P-02 resuelta.
assert.match(roadmap,/F1 \*\*Implementado\*\*/);
assert.match(roadmap,/F2 \*\*En análisis\*\*/);
assert.match(roadmap,/Decisión P-02 — resuelta para el primer incremento/);
assert.match(roadmap,/PR #254 fue integrado/);
assert.match(roadmap,/PR #257 corrigió/);

console.log('finanzas internas regression checks: OK');
