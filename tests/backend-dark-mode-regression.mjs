import fs from 'node:fs';
import assert from 'node:assert/strict';

const bootstrap=fs.readFileSync(new URL('../config/backend-bootstrap.php',import.meta.url),'utf8');
const css=fs.readFileSync(new URL('../public/assets/backend-theme.css',import.meta.url),'utf8');
const js=fs.readFileSync(new URL('../public/assets/backend-theme.js',import.meta.url),'utf8');
const ficha=fs.readFileSync(new URL('../public/ficha-alumno.php',import.meta.url),'utf8');
const editar=fs.readFileSync(new URL('../public/editar-alumno.php',import.meta.url),'utf8');
const sesiones=fs.readFileSync(new URL('../public/sesiones.php',import.meta.url),'utf8');
const historias=fs.readFileSync(new URL('../public/historias-moderacion.php',import.meta.url),'utf8');

// El theme se carga solo desde el bootstrap protegido del backend.
assert.match(bootstrap,/backend-theme\.js\?v=20260831-1/);
assert.match(bootstrap,/backend-theme\.css\?v=20260831-1/);
const themeJsPos=bootstrap.indexOf("['/assets/backend-theme.js'");
const menuCssPos=bootstrap.indexOf("['/assets/backend-menu.css'");
const reliefPos=bootstrap.indexOf("['/assets/backend-relief.css'");
const themeCssPos=bootstrap.indexOf("['/assets/backend-theme.css'");
assert.ok(themeJsPos>=0&&menuCssPos>themeJsPos,'el init de tema debe ejecutarse antes del CSS para evitar flash de tema incorrecto');
assert.ok(themeCssPos>reliefPos,'la capa de theme debe cargar después del relieve para poder adaptar sus tokens');

// Contrato Light / Dark / System y persistencia segura.
assert.match(js,/hache-backend-theme/);
assert.match(js,/new Set\(\['light','dark','system'\]\)/);
assert.match(js,/prefers-color-scheme: dark/);
assert.match(js,/try\{\s*const stored=localStorage\.getItem/);
assert.match(js,/root\.dataset\.themePreference=preference/);
assert.match(js,/root\.dataset\.theme=resolvedTheme\(preference\)/);
assert.match(js,/data-hache-theme-value="light"/);
assert.match(js,/data-hache-theme-value="dark"/);
assert.match(js,/data-hache-theme-value="system"/);
assert.match(js,/aria-pressed/);
assert.match(js,/MutationObserver/);

// Dark mode transversal: tokens, shell, superficies, tablas, forms y menú.
assert.match(css,/html\[data-theme="dark"\]\{/);
assert.match(css,/color-scheme:dark/);
for(const token of ['--hache-bg:','--hache-card:','--hache-line:','--hache-ink:','--hache-muted:','--ink:','--paper:','--bg:','--depth:']){
  assert.ok(css.includes(token),`${token} debe quedar tematizado`);
}
assert.match(css,/html\[data-theme="dark"\] body/);
assert.match(css,/\.tabla-contenedor/);
assert.match(css,/table\.hache-responsive-table tr/);
assert.match(css,/:where\(input,select,textarea,\.hache-flow-control\)/);
assert.match(css,/#hache-menu-panel/);
assert.match(css,/\.hache-menu-link\.is-active/);
assert.match(css,/\.hache-theme-switcher/);
assert.match(css,/\.hache-theme-options button/);
assert.match(css,/\.attention/);
assert.match(css,/prefers-reduced-motion:reduce/);

// Regresiones de la revisión Codex: las pantallas históricas usan nombres locales
// que deben recibir superficie y texto dark aunque conserven sus estilos inline light.
assert.match(ficha,/\.tarjeta\{background:white/);
assert.match(ficha,/\.dato\{[^}]*background:#f8fafc/);
assert.match(editar,/\.tarjeta\{background:#fff/);
assert.match(editar,/\.check\{[^}]*background:#f8fafc/);
assert.match(sesiones,/\.clase\{background:#fff/);
assert.match(sesiones,/\.descanso\{background:#fff/);
assert.match(historias,/\.comment\{[^}]*color:#34495b/);

for(const selector of ['.tarjeta','.dato','.clase','.descanso','.modal-card','.check']){
  assert.ok(css.includes(selector),`${selector} debe tener cobertura en dark mode`);
}
assert.match(css,/\.clase\.cancelada\{/);
assert.match(css,/:where\(\.comment,\.observaciones,\.modal-card p,\.modal-card li\)\{color:#c7d4e3!important\}/);
assert.match(css,/\.estado\.historica/);
assert.match(css,/html\[data-theme="dark"\] \.state\{/);
assert.match(css,/\.action-button\.approve/);
assert.match(css,/\.action-button\.delete/);

console.log('BACKEND_DARK_MODE_REGRESSION_OK');
