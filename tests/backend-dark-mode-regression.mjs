import fs from 'node:fs';
import assert from 'node:assert/strict';

const bootstrap=fs.readFileSync(new URL('../config/backend-bootstrap.php',import.meta.url),'utf8');
const css=fs.readFileSync(new URL('../public/assets/backend-theme.css',import.meta.url),'utf8');
const js=fs.readFileSync(new URL('../public/assets/backend-theme.js',import.meta.url),'utf8');
const ficha=fs.readFileSync(new URL('../public/ficha-alumno.php',import.meta.url),'utf8');
const editar=fs.readFileSync(new URL('../public/editar-alumno.php',import.meta.url),'utf8');
const sesiones=fs.readFileSync(new URL('../public/sesiones.php',import.meta.url),'utf8');
const historias=fs.readFileSync(new URL('../public/historias-moderacion.php',import.meta.url),'utf8');
const alertas=fs.readFileSync(new URL('../public/alertas.php',import.meta.url),'utf8');
const finanzas=fs.readFileSync(new URL('../public/finanzas.php',import.meta.url),'utf8');
const auditoria=fs.readFileSync(new URL('../public/auditoria.php',import.meta.url),'utf8');
const reportes=fs.readFileSync(new URL('../public/reportes.php',import.meta.url),'utf8');
const resumen=fs.readFileSync(new URL('../public/resumen-financiero.php',import.meta.url),'utf8');
const pago=fs.readFileSync(new URL('../public/pago-detalle.php',import.meta.url),'utf8');
const conciliacion=fs.readFileSync(new URL('../public/conciliacion-proa.php',import.meta.url),'utf8');
const comisiones=fs.readFileSync(new URL('../public/comisiones-proa.php',import.meta.url),'utf8');
const cierres=fs.readFileSync(new URL('../public/cierres-mensuales.php',import.meta.url),'utf8');
const mensajes=fs.readFileSync(new URL('../public/mensajes.php',import.meta.url),'utf8');

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
for(const token of ['--hache-bg:','--hache-card:','--hache-line:','--hache-ink:','--hache-muted:','--hache-action-bg:','--ink:','--paper:','--bg:','--depth:']){
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

// Regresiones de revisión: superficies y foregrounds históricos deben recibir dark.
assert.match(ficha,/\.tarjeta\{background:white/);
assert.match(ficha,/\.dato\{[^}]*background:#f8fafc/);
assert.match(editar,/\.tarjeta\{background:#fff/);
assert.match(editar,/\.check\{[^}]*background:#f8fafc/);
assert.match(editar,/<small style="display:block;color:#64748b/);
assert.match(sesiones,/\.clase\{background:#fff/);
assert.match(sesiones,/\.descanso\{background:#fff/);
assert.match(historias,/\.comment\{[^}]*color:#34495b/);
assert.match(alertas,/\.a\{[^}]*background:#fff/);
assert.match(alertas,/\.det\{[^}]*color:#64748b/);
assert.match(finanzas,/\.builder\{background:#fff/);
assert.match(finanzas,/\.builder p\{[^}]*color:#64748b/);
assert.match(finanzas,/\.field label\{[^}]*color:#64748b/);
assert.match(finanzas,/\.secondary\{background:#e8eef3;color:#172033/);
assert.match(finanzas,/\.note\{[^}]*background:#f8fafc[^}]*color:#64748b/);
assert.match(auditoria,/\.route\{[^}]*color:#475569/);

for(const selector of ['.tarjeta','.dato','.clase','.descanso','.modal-card','.check','.a','.builder']){
  assert.ok(css.includes(selector),`${selector} debe tener cobertura en dark mode`);
}
assert.match(css,/\.clase\.cancelada\{/);
assert.match(css,/:where\(\.comment,\.observaciones,\.modal-card p,\.modal-card li\)\{color:#c7d4e3!important\}/);
assert.match(css,/:where\(\.tarjeta small,\.card small,\.panel small,\.modal-card small,\.form-card small,\.builder small\)\{color:var\(--hache-muted\)!important\}/);
assert.match(css,/html\[data-theme="dark"\] a:not\(\[class\]\)\{color:#8dcdf2\}/);
assert.doesNotMatch(css,/a:not\(\.btn\):not\(\.tab\)/);
assert.match(css,/\.boton-cancelar/);
assert.match(css,/\.a\.ALTA\{border-left-color:#ef6a6a!important\}/);
assert.match(css,/\.a\.MEDIA\{border-left-color:#f2b84b!important\}/);
assert.match(css,/\.a\.BAJA\{border-left-color:#65a5ff!important\}/);
assert.match(css,/\.a\.OK\{border-left-color:#53c878!important\}/);
assert.match(css,/\.route,\.builder p,\.field label,\.note/);
assert.match(css,/html\[data-theme="dark"\] \.note\{/);
assert.match(css,/\.secondary/);
assert.match(css,/\.estado\.historica/);
assert.match(css,/:where\(\.state,\.tag\)/);
assert.match(css,/\.action-button\.approve/);
assert.match(css,/\.action-button\.delete/);

// P1: --ink es texto histórico; las acciones necesitan fondo dark independiente.
assert.match(reportes,/\.toolbar button\{background:var\(--ink\);color:#fff/);
assert.match(conciliacion,/\.form button\{border:0;background:var\(--ink\);color:#fff/);
assert.match(comisiones,/\.form button\{background:var\(--ink\);color:#fff/);
assert.match(historias,/\.tab\.is-active\{background:var\(--ink\);color:#fff/);
assert.match(css,/--hache-action-bg:#245f86/);
assert.match(css,/:where\(\.toolbar button,\.form>button,\.load,\.tab\.active,\.tab\.is-active\)\{/);
assert.match(css,/background:var\(--hache-action-bg\)!important/);

// P1: superficies financieras anidadas no pueden conservar fondos claros en dark.
assert.match(reportes,/\.proa-box\{[^}]*background:#fbfcfe/);
assert.match(reportes,/\.asis-item\{[^}]*background:#f8fafc/);
assert.match(resumen,/\.mini\{background:#fff/);
assert.match(resumen,/\.split\{background:#fff/);
assert.match(pago,/\.item\{[^}]*background:#f8fafc/);
for(const selector of ['.proa-box','.asis-item','.mini','.split']){
  assert.ok(css.includes(selector),`${selector} debe tener superficie dark explícita`);
}
assert.match(css,/:where\(\.card \.item,\.links a,#lista>\.msg\)\{/);
assert.match(css,/:where\(\.history-row\.head,\.row\.head,\.formula>div,\.history-controls \.field,\.panel>\.form,\.fold\.primary-fold>summary,\.fold-icon,\.row\.auto\)\{/);
assert.match(css,/:where\(\.progress,\.bar\)\{background:#24344a!important\}/);
assert.match(css,/:where\(\.progress>span,\.fill\)\{background:linear-gradient\(90deg,#367da8,#65a9d3\)!important\}/);

// P1: enlaces neutrales y superficies locales restantes deben oscurecerse sin selectores globales peligrosos.
assert.match(reportes,/\.toolbar a\{background:#eef3f7;color:var\(--ink\)/);
assert.match(reportes,/<a id="csv">Exportar CSV<\/a>/);
assert.match(css,/\.toolbar a:not\(\.pdf\)/);
assert.match(cierres,/\.range\{[^}]*background:#f8fafc/);
assert.match(css,/\.card \.range/);
assert.match(mensajes,/\.panel,\.msg\{background:#fff/);
assert.match(mensajes,/<article class="msg /);
assert.match(css,/#lista>\.msg/);
assert.doesNotMatch(css,/:where\([^)]*\.msg[,)]/,'no debe tematizarse .msg globalmente');

console.log('BACKEND_DARK_MODE_REGRESSION_OK');
