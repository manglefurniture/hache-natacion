import fs from 'node:fs';
import assert from 'node:assert/strict';

const relief=fs.readFileSync(new URL('../public/assets/backend-relief.css',import.meta.url),'utf8');
const conciliacion=fs.readFileSync(new URL('../public/conciliacion-proa.php',import.meta.url),'utf8');
const ficha=fs.readFileSync(new URL('../public/ficha-alumno.php',import.meta.url),'utf8');
const editar=fs.readFileSync(new URL('../public/editar-alumno.php',import.meta.url),'utf8');
const cuenta=fs.readFileSync(new URL('../public/mi-cuenta.php',import.meta.url),'utf8');
const reportes=fs.readFileSync(new URL('../public/reportes.php',import.meta.url),'utf8');
const fichaRelations=fs.readFileSync(new URL('../public/assets/ficha-relations.js',import.meta.url),'utf8');
const quickPay=fs.readFileSync(new URL('../public/assets/alumnos-quick-pay.js',import.meta.url),'utf8');
const quickEdit=fs.readFileSync(new URL('../public/assets/alumnos-quick-edit.js',import.meta.url),'utf8');

// Acciones enlace reales que no usan la convención .btn.
assert.match(conciliacion,/\.report-btn\{[^}]*background:#123b5d/);
assert.match(conciliacion,/class="report-btn"/);
assert.match(ficha,/\.boton-editar\{background:#2563eb/);
assert.match(ficha,/class="boton-editar"/);
assert.match(editar,/\.boton-cancelar\{background:#e5e7eb/);
assert.match(editar,/class="boton-cancelar"/);
assert.match(cuenta,/button,a\.action\{/);
assert.match(cuenta,/class="action ghost"/);

// Casos adicionales detectados por revisión: Reportes, relaciones de ficha y Pago rápido.
assert.match(reportes,/<a id="csv">Exportar CSV<\/a>/);
assert.match(reportes,/<a id="pdf" class="pdf"/);
assert.match(fichaRelations,/class="hache-relation-action hache-relation-action-primary"/);
assert.match(fichaRelations,/class="hache-relation-action"/);
assert.match(quickPay,/id="hqp-more"/);
assert.match(quickEdit,/id="hmm-save"[^>]*background:#172033;color:#fff/);

// Todos los enlaces de acción reciben relieve, foco, hover/active y reducción de movimiento.
for(const selector of ['.report-btn','.boton-editar','.boton-cancelar','a.action']){
  const occurrences=relief.split(selector).length-1;
  assert.ok(occurrences>=7,`${selector} debe participar en todos los estados compartidos del relieve`);
}
for(const selector of ['#csv','#pdf.pdf','#hqp-more','.hache-relation-action']){
  const occurrences=relief.split(selector).length-1;
  assert.ok(occurrences>=6,`${selector} debe participar en relieve, foco, hover/active, móvil y reduced-motion`);
}

// Los enlaces oscuros y el guardado rápido conservan el bisel primario.
for(const selector of ['.report-btn','.boton-editar']){
  const occurrences=relief.split(selector).length-1;
  assert.ok(occurrences>=10,`${selector} debe incluir también los tres estados de bisel primario`);
}
for(const selector of ['#pdf.pdf','.hache-relation-action-primary','#hmm-save']){
  const occurrences=relief.split(selector).length-1;
  assert.ok(occurrences>=3,`${selector} debe tener bisel primario en normal, hover y active`);
}

assert.match(relief,/:where\(\.btn,\.tab,\.hache-flow-btn,\.report-btn,\.boton-editar,\.boton-cancelar,a\.action\)\{min-height:42px\}/);
assert.match(relief,/:where\(#csv,#pdf\.pdf,#hqp-more,\.hache-relation-action\)\{min-height:42px\}/);
assert.match(relief,/#hmm-save:disabled:hover\{[\s\S]*box-shadow:var\(--hache-primary-shadow\)!important/);
assert.match(relief,/:focus-visible\{/);
assert.match(relief,/@media\(hover:hover\)/);
assert.match(relief,/@media\(hover:none\)/);
assert.match(relief,/@media\(prefers-reduced-motion:reduce\)/);

console.log('backend relief link action checks: OK');
