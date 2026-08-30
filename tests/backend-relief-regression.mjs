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
const auditoria=fs.readFileSync(new URL('../public/auditoria.php',import.meta.url),'utf8');
const cambiarPassword=fs.readFileSync(new URL('../public/cambiar-password.php',import.meta.url),'utf8');
const mensajes=fs.readFileSync(new URL('../public/mensajes.php',import.meta.url),'utf8');
const notificaciones=fs.readFileSync(new URL('../public/notificaciones.php',import.meta.url),'utf8');
const agregarAlumno=fs.readFileSync(new URL('../public/agregar-alumno.php',import.meta.url),'utf8');
const editarAlumno=fs.readFileSync(new URL('../public/editar-alumno.php',import.meta.url),'utf8');
const miCuenta=fs.readFileSync(new URL('../public/mi-cuenta.php',import.meta.url),'utf8');
const exportaciones=fs.readFileSync(new URL('../public/exportaciones.php',import.meta.url),'utf8');
const configuracion=fs.readFileSync(new URL('../public/configuracion.php',import.meta.url),'utf8');
const usuarios=fs.readFileSync(new URL('../public/usuarios.php',import.meta.url),'utf8');
const historiasModeracion=fs.readFileSync(new URL('../public/historias-moderacion.php',import.meta.url),'utf8');
const reportes=fs.readFileSync(new URL('../public/reportes.php',import.meta.url),'utf8');
const comisiones=fs.readFileSync(new URL('../public/comisiones-proa.php',import.meta.url),'utf8');
const conciliacion=fs.readFileSync(new URL('../public/conciliacion-proa.php',import.meta.url),'utf8');
const sesiones=fs.readFileSync(new URL('../public/sesiones.php',import.meta.url),'utf8');
const intensivoFlow=fs.readFileSync(new URL('../public/assets/intensivo-flow.js',import.meta.url),'utf8');
const filtrosAlumnos=fs.readFileSync(new URL('../public/assets/filtros-alumnos.js',import.meta.url),'utf8');
const filtrosPagos=fs.readFileSync(new URL('../public/assets/filtros-pagos.js',import.meta.url),'utf8');
const personSearch=fs.readFileSync(new URL('../public/assets/person-search.js',import.meta.url),'utf8');
const proaFinanceUx=fs.readFileSync(new URL('../public/assets/proa-finance-ux.js',import.meta.url),'utf8');
const compactUi=fs.readFileSync(new URL('../public/assets/compact-ui.js',import.meta.url),'utf8');
const alumnosQuickPay=fs.readFileSync(new URL('../public/assets/alumnos-quick-pay.js',import.meta.url),'utf8');

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
assert.match(cierres,/id="ver"/);
assert.match(finanzas,/class="tab active"/);
assert.match(finanzas,/class="primary"/);
assert.match(resumen,/class="tab active"/);
assert.match(resumen,/\.load\{[^}]*background:#172033;color:#fff/);
assert.match(auditoria,/\.filters button\{background:#172033;color:#fff/);
assert.match(cambiarPassword,/\.btn\{[^}]*background:#123b5d;color:#fff/);
assert.match(cambiarPassword,/<button class="btn">Guardar contraseña<\/button>/);
assert.match(mensajes,/\.btn\{[^}]*background:#123b5d;color:#fff/);
assert.match(mensajes,/id="crear" class="btn"/);
assert.match(mensajes,/<button class=\"btn\" data-id=/);
assert.match(notificaciones,/\.btn\{[^}]*background:#172033;color:#fff/);
assert.match(notificaciones,/id="activar" class="btn"/);
assert.match(notificaciones,/id="desactivar" class="btn off"/);
assert.match(agregarAlumno,/button\{[^}]*background:#2563eb;color:#fff/);
assert.match(agregarAlumno,/id="btnGuardar"/);
assert.match(editarAlumno,/button\{background:#2563eb;color:#fff\}/);
assert.match(editarAlumno,/<div class="botones"><button type="submit">Guardar cambios<\/button>/);
assert.match(miCuenta,/\.logout\{[^}]*background:#172033;color:#fff/);
assert.match(miCuenta,/id="logout" class="logout"/);
assert.match(exportaciones,/\.btn\{[^}]*background:#172033;color:#fff/);
assert.match(exportaciones,/class="btn" href="\/api\/exportar-alumnos-horarios\.php"/);
assert.match(exportaciones,/id="liq" class="btn"/);
assert.match(exportaciones,/id="pag" class="btn"/);
assert.match(configuracion,/class="btn primary"/);
assert.match(usuarios,/class="primary"/);
assert.match(historiasModeracion,/\.tab\.is-active\{/);
assert.match(historiasModeracion,/button\.disabled=true/);
assert.match(reportes,/\.toolbar button\{background:var\(--ink\)/);
assert.match(reportes,/id="toolbar" class="toolbar"/);
assert.match(comisiones,/\.form button\{background:var\(--ink\)/);
assert.match(comisiones,/id="form" class="form"/);
assert.match(comisiones,/btn\.disabled=!d\.habilitado/);
assert.match(conciliacion,/\.form button\{border:0;background:var\(--ink\)/);
assert.match(conciliacion,/<button id="guardar">Registrar<\/button>/);
assert.match(conciliacion,/\.student-results button\{[^}]*border:0;[^}]*border-bottom:1px solid #eef2f7/);
assert.match(sesiones,/\.cerrar\{background:#172033/);
assert.match(sesiones,/class="cerrar"/);
assert.match(intensivoFlow,/hache-mini-action hache-mini-action-secondary/);
assert.match(filtrosPagos,/\.hache-filter-toggle\{background:#172033;color:#fff\}/);
assert.match(proaFinanceUx,/\.quick-entry \.entry-toggle\{[^}]*background:#123b5d;color:#fff/);
assert.match(proaFinanceUx,/\.proa-save\{background:#166534;color:#fff\}/);
assert.match(proaFinanceUx,/\.proa-edit-btn\{[^}]*top:50%;transform:translateY\(-50%\);border:0/);
assert.match(proaFinanceUx,/@media\(max-width:900px\)\{[\s\S]*?\.proa-edit-btn,\.proa-row-actions\{[^}]*transform:none/);
assert.match(compactUi,/\.hache-compact-panel>\.hache-compact-toggle\{[^}]*border:0;background:#fff/);
assert.match(compactUi,/\.hache-compact-panel\.hache-compact-open>\.hache-compact-toggle\{border-bottom:1px solid #edf1f5/);
assert.match(alumnosQuickPay,/id="hqp-save"[^>]*background:#172033;color:#fff/);

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

// Primarios oscuros: variantes compartidas, locales y dinámicas reales.
assert.match(relief,/\.hache-mini-action:not\(\.hache-mini-action-secondary\)/);
assert.match(relief,/\.hache-filter-toggle/);
assert.match(relief,/\.quick-entry \.entry-toggle/);
assert.match(relief,/button\.load/);
assert.match(relief,/\.filters > button#b/);
assert.match(relief,/#hqp-save/);
assert.match(relief,/#form > button\.btn/);
assert.match(relief,/#crear\.btn/);
assert.match(relief,/\.msg \.btn/);
assert.match(relief,/#activar\.btn/);
assert.match(relief,/#btnGuardar/);
assert.match(relief,/\.botones > button\[type="submit"\]/);
assert.match(relief,/#ver/);
assert.match(relief,/#logout\.logout/);
assert.match(relief,/a\.btn\[href\^="\/api\/exportar-"\]/);
assert.match(relief,/#liq\.btn/);
assert.match(relief,/#pag\.btn/);
assert.match(relief,/\.proa-save/);
assert.match(relief,/button\.primary/);
assert.match(relief,/button\.save/);
assert.match(relief,/#cerrar\.close/);
assert.match(relief,/#toolbar\.toolbar button/);
assert.match(relief,/#form\.form button:not\(\.danger\)/);
assert.match(relief,/\.form > button#guardar/);
assert.match(relief,/\.clase-actions \.cerrar/);
assert.match(relief,/:where\([\s\S]*\.tab\.active,[\s\S]*\.tab\.is-active,[\s\S]*\.hache-filter-toggle,[\s\S]*\.quick-entry \.entry-toggle,[\s\S]*button\.load,[\s\S]*\.filters > button#b,[\s\S]*#hqp-save,[\s\S]*#form > button\.btn,[\s\S]*#crear\.btn,[\s\S]*\.msg \.btn,[\s\S]*#activar\.btn,[\s\S]*#btnGuardar,[\s\S]*\.botones > button\[type="submit"\],[\s\S]*#ver,[\s\S]*#logout\.logout,[\s\S]*a\.btn\[href\^="\/api\/exportar-"\],[\s\S]*#liq\.btn,[\s\S]*#pag\.btn,[\s\S]*\.proa-save,[\s\S]*#toolbar\.toolbar button,[\s\S]*#form\.form button:not\(\.danger\),[\s\S]*\.form > button#guardar,[\s\S]*\.clase-actions \.cerrar[\s\S]*\)\{/);
assert.match(relief,/var\(--hache-primary-shadow-hover\)!important/);
assert.match(relief,/:where\([\s\S]*\.hache-mini-action:not\(\.hache-mini-action-secondary\)[\s\S]*\.hache-filter-toggle,[\s\S]*\.quick-entry \.entry-toggle,[\s\S]*button\.load,[\s\S]*\.filters > button#b,[\s\S]*#hqp-save,[\s\S]*#form > button\.btn,[\s\S]*#crear\.btn,[\s\S]*\.msg \.btn,[\s\S]*#activar\.btn,[\s\S]*#btnGuardar,[\s\S]*\.botones > button\[type="submit"\],[\s\S]*#ver,[\s\S]*#logout\.logout,[\s\S]*a\.btn\[href\^="\/api\/exportar-"\],[\s\S]*#liq\.btn,[\s\S]*#pag\.btn,[\s\S]*\.proa-save[\s\S]*\):active\{/);

for(const selector of ['#form > button.btn','#crear.btn','.msg .btn','#activar.btn','#btnGuardar','.botones > button[type="submit"]','#ver','#logout.logout','a.btn[href^="/api/exportar-"]','#liq.btn','#pag.btn','.proa-save']){
  const occurrences=relief.split(selector).length-1;
  assert.ok(occurrences>=4,`${selector} debe conservar bisel primario en normal, hover, disabled-hover y active`);
}

// En desktop, los bloqueados no se elevan ni heredan el hover de backend-menu.css.
assert.match(relief,/\):not\(:disabled\):hover\{[\s\S]*box-shadow:var\(--hache-control-shadow-hover\)!important/);
assert.match(relief,/\):disabled:hover\{[\s\S]*transform:none!important;[\s\S]*box-shadow:var\(--hache-control-shadow\)!important/);
assert.match(relief,/\.btn-primary,[\s\S]*\):disabled:hover\{[\s\S]*box-shadow:var\(--hache-primary-shadow\)!important/);

// Los controles contextuales transparentes de búsqueda conservan su geometría.
assert.match(filtrosAlumnos,/\.hache-search-clear\{[^}]*transform:translateY\(-50%\)/);
assert.match(filtrosAlumnos,/class="hache-search-option"/);
assert.match(personSearch,/class="hache-person-clear"/);
assert.match(personSearch,/class="hache-person-option"/);
assert.match(relief,/\.hache-search-clear,/);
assert.match(relief,/\.hache-search-option,/);
assert.match(relief,/\.hache-person-clear,/);
assert.match(relief,/\.hache-person-option/);
assert.match(relief,/\.hache-search-clear\{transform:translateY\(-50%\)!important\}/);
assert.match(relief,/\.hache-search-clear:active\{[\s\S]*transform:translateY\(-50%\)!important;/);
assert.match(relief,/\.hache-person-clear:active/);
assert.match(relief,/\.hache-person-option:active/);
assert.match(relief,/\.student-results button\{[^}]*border:0!important;[^}]*border-bottom:1px solid #eef2f7!important;[^}]*box-shadow:none!important;[^}]*transform:none!important;/);
assert.match(relief,/\.student-results button:not\(:disabled\):hover\{[^}]*box-shadow:none!important;[^}]*transform:none!important;/);
assert.match(relief,/\.student-results button:active\{[^}]*box-shadow:none!important;[^}]*transform:none!important;/);
assert.match(relief,/\.student-results button:last-child\{border-bottom:0!important\}/);

// Los auxiliares posicionados mantienen geometría y borde plano.
assert.match(relief,/\.proa-edit-btn\{[^}]*border:0!important;[^}]*transform:translateY\(-50%\)!important/);
assert.match(relief,/\.proa-edit-btn:not\(:disabled\):hover\{[^}]*border:0!important;[^}]*transform:translateY\(-50%\)!important/);
assert.match(relief,/\.proa-edit-btn:active\{[^}]*border:0!important;[^}]*transform:translateY\(-50%\)!important/);
assert.match(relief,/@media\(max-width:900px\)\{[\s\S]*\.proa-edit-btn,[\s\S]*transform:none!important;/);

// Los encabezados compactos móviles no heredan el relieve global.
assert.match(relief,/\.hache-compact-toggle\{[^}]*border:0!important;[^}]*box-shadow:none!important;[^}]*transform:none!important/);
assert.match(relief,/\.hache-compact-toggle:not\(:disabled\):hover\{[^}]*border:0!important;[^}]*box-shadow:none!important;[^}]*transform:none!important/);
assert.match(relief,/\.hache-compact-toggle:active\{[^}]*border:0!important;[^}]*box-shadow:none!important;[^}]*transform:none!important/);
assert.match(relief,/\.hache-compact-panel\.hache-compact-open>\.hache-compact-toggle\{[^}]*border-bottom:1px solid #edf1f5!important/);

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