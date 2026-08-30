import fs from 'node:fs';
import assert from 'node:assert/strict';

const bootstrap=fs.readFileSync(new URL('../config/backend-bootstrap.php',import.meta.url),'utf8');
const relief=fs.readFileSync(new URL('../public/assets/backend-relief.css',import.meta.url),'utf8');
const pagos=fs.readFileSync(new URL('../public/pagos.php',import.meta.url),'utf8');
const alumnos=fs.readFileSync(new URL('../public/alumnos.php',import.meta.url),'utf8');
const dashboard=fs.readFileSync(new URL('../public/dashboard.php',import.meta.url),'utf8');

// La capa se carga únicamente desde el bootstrap del backend y después del CSS base.
assert.match(bootstrap,/backend-menu\.css/);
assert.match(bootstrap,/backend-relief\.css\?v=20260830-1/);
assert.match(bootstrap,/\['\/assets\/backend-menu\.css',\$css,'<\/head>'\],\['\/assets\/backend-relief\.css',\$relief,'<\/head>'\]/);

// Cobertura de controles existentes en pantallas representativas.
assert.match(pagos,/class="btn btn-primary"/);
assert.match(pagos,/class="btn btn-secondary"/);
assert.match(alumnos,/class="tab activa"/);
assert.match(alumnos,/class="mini-edit"/);
assert.match(alumnos,/class="quick-pay/);

// Relieve: normal, hover de escritorio, pulsado y foco accesible.
assert.match(relief,/--hache-control-shadow:/);
assert.match(relief,/button:not\(\.close\)/);
assert.match(relief,/\.btn,/);
assert.match(relief,/\.tab,/);
assert.match(relief,/\.mini-edit,/);
assert.match(relief,/\.quick-pay/);
assert.match(relief,/:focus-visible/);
assert.match(relief,/@media\(hover:hover\)/);
assert.match(relief,/:active\{/);
assert.match(relief,/--hache-control-shadow-active:/);

// Móvil/táctil y accesibilidad de movimiento.
assert.match(relief,/@media\(max-width:750px\)/);
assert.match(relief,/min-height:42px/);
assert.match(relief,/@media\(hover:none\)/);
assert.match(relief,/@media\(prefers-reduced-motion:reduce\)/);

// El dashboard conserva su tratamiento específico del PR visual precedente.
assert.match(dashboard,/\.quick a\.primary:active/);
assert.match(dashboard,/--depth:inset 0 1px 0/);

console.log('backend relief regression checks: OK');
