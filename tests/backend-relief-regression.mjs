import fs from 'node:fs';
import assert from 'node:assert/strict';

const bootstrap=fs.readFileSync(new URL('../config/backend-bootstrap.php',import.meta.url),'utf8');
const relief=fs.readFileSync(new URL('../public/assets/backend-relief.css',import.meta.url),'utf8');
const pagos=fs.readFileSync(new URL('../public/pagos.php',import.meta.url),'utf8');
const alumnos=fs.readFileSync(new URL('../public/alumnos.php',import.meta.url),'utf8');
const dashboard=fs.readFileSync(new URL('../public/dashboard.php',import.meta.url),'utf8');
const cierres=fs.readFileSync(new URL('../public/cierres-mensuales.php',import.meta.url),'utf8');
const finanzas=fs.readFileSync(new URL('../public/finanzas.php',import.meta.url),'utf8');
const resumen=fs.readFileSync(new URL('../public/resumen-financiero.php',import.meta.url),'utf8');
const configuracion=fs.readFileSync(new URL('../public/configuracion.php',import.meta.url),'utf8');
const usuarios=fs.readFileSync(new URL('../public/usuarios.php',import.meta.url),'utf8');
const historiasModeracion=fs.readFileSync(new URL('../public/historias-moderacion.php',import.meta.url),'utf8');
const intensivoFlow=fs.readFileSync(new URL('../public/assets/intensivo-flow.js',import.meta.url),'utf8');
const filtrosAlumnos=fs.readFileSync(new URL('../public/assets/filtros-alumnos.js',import.meta.url),'utf8');
const personSearch=fs.readFileSync(new URL('../public/assets/person-search.js',import.meta.url),'utf8');

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
assert.match(cierres,/id="cerrar" class="close"/);
assert.match(cierres,/class="save"/);
assert.match(finanzas,/class="tab active"/);
assert.match(finanzas,/class="primary"/);
assert.match(resumen,/class="tab active"/);
assert.match(configuracion,/class="btn primary"/);
assert.match(usuarios,/class="primary"/);
assert.match(historiasModeracion,/\.tab\.is-active\{/);
assert.match(historiasModeracion,/button\.disabled=true/);
assert.match(intensivoFlow,/hache-mini-action hache-mini-action-secondary/);

// Relieve: normal, hover de escritorio, pulsado y foco accesible.
assert.match(relief,/--hache-control-shadow:/);
assert.match(relief,/--hache-primary-shadow:/);
assert.match(relief,/--hache-primary-shadow-hover:/);
assert.match(relief,/button:not\(\.hache-flow-close\)/);
assert.doesNotMatch(relief,/button:not\(\.close\)/);
assert.match(relief,/\.modal \.close/);
assert.match(relief,/\.btn,/);
assert.match(relief,/\.tab,/);
assert.match(relief,/\.tab\.active/);
assert.match(relief,/\.tab\.is-active/);
assert.match(relief,/\.mini-edit,/);
assert.match(relief,/\.quick-pay/);
assert.match(relief,/:focus-visible/);
assert.match(relief,/@media\(hover:hover\)/);
assert.match(relief,/:active\{/);
assert.match(relief,/--hache-control-shadow-active:/);

// Primarios oscuros: variantes compartidas, locales, hover y active coherentes.
assert.match(relief,/\.hache-mini-action:not\(\.hache-mini-action-secondary\)/);
assert.match(relief,/button\.primary/);
assert.match(relief,/button\.save/);
assert.match(relief,/#cerrar\.close/);
assert.match(relief,/:where\([\s\S]*\.tab\.active,[\s\S]*\.tab\.is-active,[\s\S]*button\.primary,[\s\S]*button\.save,[\s\S]*#cerrar\.close[\s\S]*\)\{/);
assert.match(relief,/var\(--hache-primary-shadow-hover\)!important/);
assert.match(relief,/:where\([\s\S]*\.hache-mini-action:not\(\.hache-mini-action-secondary\)[\s\S]*\):active\{/);

// En desktop solo los controles habilitados reaccionan al hover.
const desktopHover=relief.match(/@media\(hover:hover\)\{([\s\S]*?)\n\}/)?.[1]||'';
assert.match(desktopHover,/\):not\(:disabled\):hover\{[\s\S]*box-shadow:var\(--hache-control-shadow-hover\)!important/);
assert.match(desktopHover,/\.tab\.is-active,[\s\S]*\):not\(:disabled\):hover\{[\s\S]*var\(--hache-primary-shadow-hover\)!important/);

// Los controles contextuales transparentes de búsqueda conservan su geometría.
assert.match(filtrosAlumnos,/\.hache-search-clear\{[^}]*transform:translateY\(-50%\)/);
assert.match(filtrosAlumnos,/class="hache-search-option"/);
assert.match(personSearch,/class="hache-person-clear"/);
assert.match(personSearch,/class="hache-person-option"/);
assert.match(relief,/\.hache-search-clear,/);
assert.match(relief,/\.hache-search-option,/);
assert.match(relief,/\.hache-person-clear,/);
assert.match(relief,/\.hache-person-option/);
assert.match(relief,/\.hache-search-clear\{\s*transform:translateY\(-50%\)!important;/);
assert.match(relief,/\.hache-search-clear:active\{[\s\S]*transform:translateY\(-50%\)!important;/);
assert.match(relief,/\.hache-person-clear:active/);
assert.match(relief,/\.hache-person-option:active/);

// Móvil/táctil y accesibilidad de movimiento.
assert.match(relief,/@media\(max-width:750px\)/);
assert.match(relief,/min-height:42px/);
assert.match(relief,/@media\(hover:none\)/);
assert.match(relief,/\.hache-search-clear:hover\{transform:translateY\(-50%\)!important\}/);
assert.match(relief,/@media\(prefers-reduced-motion:reduce\)/);

// El dashboard conserva su tratamiento específico del PR visual precedente.
assert.match(dashboard,/\.quick a\.primary:active/);
assert.match(dashboard,/--depth:inset 0 1px 0/);

console.log('backend relief regression checks: OK');
