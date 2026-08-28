<?php
declare(strict_types=1);
require_once __DIR__.'/../config/auth.php';
$me = page_require(['ADMIN','VERIFICADOR']);
$admin = $me['rol'] === 'ADMIN';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Configuración — Hache Natación</title>
  <style>
    *{box-sizing:border-box}body{margin:0;background:#f3f6fa;font-family:Manrope,Arial,sans-serif;color:#172033}.wrap{max-width:900px;margin:auto;padding:82px 14px 32px}h1{margin:0 0 6px}.sub{color:#64748b;margin-bottom:12px}.panel{background:#fff;border-radius:15px;padding:15px;margin-bottom:12px}.row{display:grid;grid-template-columns:1fr 100px 120px 90px;gap:8px;align-items:center;padding:9px 0;border-bottom:1px solid #eef2f7}.row input{width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:8px}.btn{border:0;border-radius:9px;padding:9px 12px;font-weight:800;cursor:pointer}.primary{background:#123b5d;color:#fff}.secondary{background:#e8eef2;color:#172033}.links{display:flex;gap:8px;flex-wrap:wrap}.links a{padding:10px 12px;background:#e8eef2;color:#172033;border-radius:9px;text-decoration:none;font-weight:800}.msg{min-height:22px;margin-bottom:8px;color:#b42318;font-weight:700}.gateway-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.field{display:flex;flex-direction:column;gap:6px}.field.full{grid-column:1/-1}.field label{font-weight:800}.field input,.field select{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:9px;background:#fff}.field small,.gateway-note{color:#64748b}.gateway-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.gateway-state{margin-top:10px;min-height:22px;font-weight:800}.secret-state{font-size:12px;color:#0f766e;font-weight:800}.checkline{display:flex;gap:8px;align-items:center;font-weight:700}.checkline input{width:auto}.danger-note{color:#92400e;font-size:12px}@media(max-width:650px){.row{grid-template-columns:1fr 1fr}.row .name{grid-column:1/-1}.gateway-grid{grid-template-columns:1fr}.field.full{grid-column:auto}}
  </style>
</head>
<body>
<main class="wrap">
  <h1>Configuración</h1>
  <div id="subtitle" class="sub">Planes y parámetros de Franky</div>
  <div id="msg" class="msg" role="status"></div>
  <div class="panel">
    <h3>Accesos de configuración</h3>
    <div class="links"><a href="/horarios.php">Horarios</a><?php if ($admin): ?><a href="/usuarios.php">Usuarios</a><?php endif; ?></div>
  </div>
  <div class="panel">
    <h3>Planes</h3>
    <div id="planes"></div>
    <?php if ($admin): ?><button id="nuevo" class="btn primary">+ Nuevo plan</button><?php endif; ?>
  </div>
  <div class="panel"><h3>Parámetros</h3><div id="cfg"></div></div>

  <?php if ($admin): ?>
  <section class="panel" id="mercadopago-panel">
    <h3>Mercado Pago</h3>
    <p class="gateway-note">Configura la cuenta que usará la tienda sin modificar código. Los secretos se guardan cifrados y nunca se muestran de nuevo en el navegador.</p>
    <div class="gateway-grid">
      <div class="field">
        <label for="mp-activo">Estado</label>
        <label class="checkline"><input id="mp-activo" type="checkbox"> Habilitar Mercado Pago</label>
      </div>
      <div class="field">
        <label for="mp-entorno">Entorno</label>
        <select id="mp-entorno"><option value="TEST">Pruebas</option><option value="PRODUCTION">Producción</option></select>
      </div>
      <div class="field full">
        <label for="mp-public-key">Public Key</label>
        <input id="mp-public-key" maxlength="255" autocomplete="off" spellcheck="false" placeholder="APP_USR-... o TEST-...">
      </div>
      <div class="field full">
        <label for="mp-access-token">Access Token</label>
        <input id="mp-access-token" type="password" maxlength="500" autocomplete="new-password" spellcheck="false" placeholder="Déjalo vacío para conservar el token guardado">
        <span id="mp-access-state" class="secret-state"></span>
        <label class="checkline"><input id="mp-clear-access" type="checkbox"> Borrar el Access Token guardado</label>
      </div>
      <div class="field full">
        <label for="mp-webhook-secret">Webhook Secret <small>(opcional hasta activar webhooks)</small></label>
        <input id="mp-webhook-secret" type="password" maxlength="500" autocomplete="new-password" spellcheck="false" placeholder="Déjalo vacío para conservar el secreto guardado">
        <span id="mp-webhook-state" class="secret-state"></span>
        <label class="checkline"><input id="mp-clear-webhook" type="checkbox"> Borrar el Webhook Secret guardado</label>
      </div>
    </div>
    <div class="gateway-actions">
      <button id="mp-guardar" class="btn primary" type="button">Guardar Mercado Pago</button>
      <button id="mp-probar" class="btn secondary" type="button">Probar conexión</button>
    </div>
    <div class="danger-note">Para cambiar a otra cuenta, pega la nueva Public Key y el nuevo Access Token y guarda. No requiere editar GitHub ni desplegar código.</div>
    <div id="mp-status" class="gateway-state" role="status"></div>
  </section>
  <?php endif; ?>
</main>
<script>
const admin=<?=json_encode($admin)?>;
const P=document.getElementById('planes'),C=document.getElementById('cfg'),M=document.getElementById('msg'),S=document.getElementById('subtitle');
const esc=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
async function api(body){const r=await fetch('/api/configuracion.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}),d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'Error');return d}
function planRow(p={id:'',nombre:'',sesiones_semana:3,precio:0,activo:1}){
  const e=document.createElement('div');e.className='row';
  e.innerHTML=`<input class="name" maxlength="100" value="${esc(p.nombre)}" ${admin?'':'disabled'}><input type="number" min="1" max="7" value="${Number(p.sesiones_semana)||1}" ${admin?'':'disabled'}><input type="number" min="0" max="100000" step="0.01" value="${Number(p.precio)||0}" ${admin?'':'disabled'}><label><input type="checkbox" ${Number(p.activo)?'checked':''} ${admin?'':'disabled'}> Activo</label>${admin?'<button class="btn primary">Guardar</button>':''}`;
  if(admin)e.querySelector('button').onclick=async()=>{const i=e.querySelectorAll('input');try{await api({accion:'PLAN',id:p.id,nombre:i[0].value,sesiones_semana:i[1].value,precio:i[2].value,activo:i[3].checked});M.textContent='Plan guardado.';await load()}catch(err){M.textContent=err.message||'No se pudo guardar.'}};
  return e;
}
async function load(){
  try{
    const r=await fetch('/api/configuracion.php',{cache:'no-store'}),d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'No se pudo cargar.');
    S.textContent=`Planes de ${d.sede.nombre} · parámetros generales de Franky`;
    P.replaceChildren(...d.planes.map(planRow));
    C.innerHTML=d.configuracion.map(x=>`<div class="row"><strong class="name">${esc(x.clave)}<small style="display:block;color:#64748b">${esc(x.descripcion||'')}</small></strong><input style="grid-column:span 2" maxlength="500" data-key="${esc(x.clave)}" value="${esc(x.valor||'')}" ${admin?'':'disabled'}>${admin?'<button class="btn primary">Guardar</button>':''}</div>`).join('');
    if(admin)C.querySelectorAll('button').forEach(b=>b.onclick=async()=>{const i=b.parentElement.querySelector('input');try{await api({accion:'CONFIG',clave:i.dataset.key,valor:i.value});M.textContent='Parámetro guardado.';await load()}catch(err){M.textContent=err.message||'No se pudo guardar.'}});
  }catch(err){M.textContent=err.message||'No se pudo cargar la configuración.'}
}
<?php if ($admin): ?>
const mp={
  activo:document.getElementById('mp-activo'),entorno:document.getElementById('mp-entorno'),publicKey:document.getElementById('mp-public-key'),
  access:document.getElementById('mp-access-token'),webhook:document.getElementById('mp-webhook-secret'),clearAccess:document.getElementById('mp-clear-access'),
  clearWebhook:document.getElementById('mp-clear-webhook'),accessState:document.getElementById('mp-access-state'),webhookState:document.getElementById('mp-webhook-state'),
  status:document.getElementById('mp-status'),guardar:document.getElementById('mp-guardar'),probar:document.getElementById('mp-probar')
};
async function mpRequest(body=null){
  const options=body?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}:{cache:'no-store'};
  const r=await fetch('/api/mercadopago-config.php',options),d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'No se pudo completar la operación.');return d;
}
function renderMp(c){
  mp.activo.checked=!!c.activo;mp.entorno.value=c.entorno==='PRODUCTION'?'PRODUCTION':'TEST';mp.publicKey.value=c.public_key||'';
  mp.access.value='';mp.webhook.value='';mp.clearAccess.checked=false;mp.clearWebhook.checked=false;
  mp.accessState.textContent=c.access_token_configurado?'Access Token configurado y oculto.':'Sin Access Token configurado.';
  mp.webhookState.textContent=c.webhook_secret_configurado?'Webhook Secret configurado y oculto.':'Sin Webhook Secret configurado.';
}
async function loadMp(){try{const d=await mpRequest();renderMp(d.mercadopago);mp.status.textContent='';}catch(err){mp.status.textContent=err.message||'No se pudo cargar Mercado Pago.'}}
mp.guardar.onclick=async()=>{
  mp.status.textContent='Guardando…';mp.guardar.disabled=true;
  try{
    const d=await mpRequest({accion:'GUARDAR',activo:mp.activo.checked,entorno:mp.entorno.value,public_key:mp.publicKey.value.trim(),access_token:mp.access.value.trim(),webhook_secret:mp.webhook.value.trim(),limpiar_access_token:mp.clearAccess.checked,limpiar_webhook_secret:mp.clearWebhook.checked});
    renderMp(d.mercadopago);mp.status.textContent='Configuración de Mercado Pago guardada.';
  }catch(err){mp.status.textContent=err.message||'No se pudo guardar Mercado Pago.'}finally{mp.guardar.disabled=false}
};
mp.probar.onclick=async()=>{
  mp.status.textContent='Probando conexión…';mp.probar.disabled=true;
  try{const d=await mpRequest({accion:'PROBAR'});mp.status.textContent=`${d.mensaje}${d.cuenta_id?` · Cuenta ${d.cuenta_id}`:''}`;}catch(err){mp.status.textContent=err.message||'No se pudo verificar Mercado Pago.'}finally{mp.probar.disabled=false}
};
document.getElementById('nuevo').onclick=()=>P.appendChild(planRow());
loadMp();
<?php endif; ?>
load();
</script>
</body>
</html>
