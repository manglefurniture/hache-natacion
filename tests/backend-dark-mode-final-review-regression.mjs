import fs from 'node:fs';
import assert from 'node:assert/strict';

const bootstrap=fs.readFileSync(new URL('../config/backend-bootstrap.php',import.meta.url),'utf8');
const fixes=fs.readFileSync(new URL('../public/assets/backend-theme-review-fixes.css',import.meta.url),'utf8');
const themeJs=fs.readFileSync(new URL('../public/assets/backend-theme.js',import.meta.url),'utf8');
const sesiones=fs.readFileSync(new URL('../public/sesiones.php',import.meta.url),'utf8');
const resumen=fs.readFileSync(new URL('../public/resumen-financiero.php',import.meta.url),'utf8');
const conciliacion=fs.readFileSync(new URL('../public/conciliacion-proa.php',import.meta.url),'utf8');
const comisiones=fs.readFileSync(new URL('../public/comisiones-proa.php',import.meta.url),'utf8');
const usuarios=fs.readFileSync(new URL('../public/usuarios.php',import.meta.url),'utf8');
const miCuenta=fs.readFileSync(new URL('../public/mi-cuenta.php',import.meta.url),'utf8');
const pagoDetalle=fs.readFileSync(new URL('../public/pago-detalle.php',import.meta.url),'utf8');
const agregar=fs.readFileSync(new URL('../public/agregar-alumno.php',import.meta.url),'utf8');
const configuracion=fs.readFileSync(new URL('../public/configuracion.php',import.meta.url),'utf8');
const cierres=fs.readFileSync(new URL('../public/cierres-mensuales.php',import.meta.url),'utf8');

assert.match(bootstrap,/backend-theme-review-fixes\.css\?v=20260831-1/);
const themePos=bootstrap.indexOf("['/assets/backend-theme.css'");
const fixesPos=bootstrap.indexOf("['/assets/backend-theme-review-fixes.css'");
assert.ok(themePos>=0&&fixesPos>themePos,'los fixes finales deben cargar después del theme principal');

// P1 Codex: el template neutral renderiza `estado `; el selector debe coincidir y excluir .cancel.
assert.match(sesiones,/\.estado\{[^}]*background:#e2e8f0/);
assert.match(sesiones,/class="estado \$\{cancel\?'cancel':''\}"/);
assert.match(fixes,/\.clase \.estado:not\(\.cancel\)/);
assert.doesNotMatch(fixes,/\.estado\[class="estado"\]/);
assert.doesNotMatch(fixes,/\.estado\.cancel\s*\{/);

// P2 Codex: spans secundarios del resumen, limitados a sus paneles únicos.
assert.match(resumen,/\.row span\{font-size:13px;color:#64748b\}/);
assert.match(fixes,/:where\(#actual,#siguiente,#historial\) \.row>span/);
assert.doesNotMatch(fixes,/html\[data-theme="dark"\] \.row>span\s*\{/);

// P1 Codex: feedback de formularios legible en dark y limitado a sus contenedores.
assert.match(conciliacion,/\.msg\.bad\{color:var\(--bad\)\}/);
assert.match(comisiones,/\.msg\.bad\{color:var\(--danger\)\}/);
assert.match(usuarios,/\.err\{color:#991b1b\}/);
assert.match(miCuenta,/\.msg\{margin-top:8px;font-size:12px;color:#166534/);
assert.match(configuracion,/\.msg\{min-height:22px;margin-bottom:8px;color:#b42318/);
assert.match(cierres,/\.err\{color:#b42318\}/);
assert.match(fixes,/#movementFold #msg\.msg\.bad/);
assert.match(fixes,/section\.panel>#msg\.msg\.bad/);
assert.match(fixes,/\.card>#msg\.msg\.err/);
assert.match(fixes,/\.card>#perfilMsg\.msg/);
assert.match(fixes,/main\.wrap>#msg\.msg/);
assert.match(fixes,/\.range>#rm\.msg\.err/);
assert.match(fixes,/\.range>#rm\.msg\.ok/);

// P2 Codex: no usar fallback inline hasta confirmar rol ALUMNO.
assert.match(miCuenta,/page_require\(\['ALUMNO'\]\)/);
assert.match(themeJs,/function currentRole\(\)/);
assert.match(themeJs,/mountSwitcher\(\{allowInline=false\}=\{\}\)/);
assert.match(themeJs,/if\(role==='ALUMNO'\)/);
assert.match(themeJs,/mountSwitcher\(\{allowInline:true\}\)/);
assert.match(themeJs,/setInterval\(\(\)=>\{/);
assert.doesNotMatch(themeJs,/const inlineHost=footer\?null:document\.querySelector\('main\.wrap'\)/);
assert.match(themeJs,/hache-theme-switcher-inline/);
assert.match(fixes,/main\.wrap>\.hache-theme-switcher-inline/);

// P2 Codex: navegación de regreso del detalle de pago.
assert.match(pagoDetalle,/class="back"/);
assert.match(fixes,/main\.wrap>\.back\{color:#8dcdf2!important\}/);

// P2 Codex: ayudas del alta de alumnos.
assert.match(agregar,/\.ayuda\{margin-top:2px;color:#667085/);
assert.match(fixes,/#formAlumno \.ayuda\{color:var\(--hache-muted\)!important\}/);

console.log('BACKEND_DARK_MODE_FINAL_REVIEW_OK');
