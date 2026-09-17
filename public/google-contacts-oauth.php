<?php

declare(strict_types=1);
require_once __DIR__.'/../config/auth.php';
page_require(['ADMIN']);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Google Contacts OAuth — Hache Natación</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f6fa;font-family:Manrope,Arial,sans-serif;color:#172033}.wrap{max-width:760px;margin:auto;padding:82px 14px 32px}.panel{background:#fff;border-radius:15px;padding:18px;margin-bottom:12px}.sub{color:#64748b}.status{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:14px 0}.card{padding:12px;border-radius:12px;background:#eef3f7}.value{font-weight:900;font-size:1.2rem}.field{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:9px;font:inherit}.btn{border:0;border-radius:9px;padding:11px 14px;font-weight:800;cursor:pointer;background:#123b5d;color:#fff}.btn:disabled{opacity:.55;cursor:not-allowed}.msg{min-height:24px;font-weight:800;margin-top:10px}.ok{color:#087443}.bad{color:#b42318}.links a{color:#123b5d;font-weight:800;text-decoration:none}@media(max-width:560px){.status{grid-template-columns:1fr}}
</style>
</head>
<body>
<main class="wrap">
<h1>Google Contacts OAuth</h1>
<p class="sub">Renueva la autorización sin mostrar ni guardar el Client Secret o el refresh token en el navegador.</p>
<div class="panel">
<div class="links"><a href="/configuracion.php">← Volver a Configuración</a></div>
<div class="status">
<div class="card"><div>OAuth</div><div id="oauth" class="value">Comprobando…</div></div>
<div class="card"><div>Pendientes</div><div id="pending" class="value">—</div></div>
</div>
</div>
<div class="panel">
<h2>Renovar autorización</h2>
<p>1. En OAuth Playground autoriza <code>https://www.googleapis.com/auth/contacts</code>.</p>
<p>2. Copia el <strong>Authorization code</strong> y pégalo aquí. No uses el botón de intercambio del Playground.</p>
<input id="code" class="field" type="password" autocomplete="off" spellcheck="false" placeholder="Authorization code" maxlength="4096">
<p><button id="submit" class="btn">Renovar y sincronizar</button></p>
<div id="msg" class="msg" role="status"></div>
</div>
</main>
<script>
let csrf='';
const oauth=document.getElementById('oauth'),pending=document.getElementById('pending'),msg=document.getElementById('msg'),btn=document.getElementById('submit'),code=document.getElementById('code');
async function load(){
  try{const r=await fetch('/api/google-contacts-oauth.php',{cache:'no-store'}),d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'No se pudo cargar');csrf=d.csrf;oauth.textContent=d.token_refresh_ok?'Operativo':'Requiere renovación';oauth.className='value '+(d.token_refresh_ok?'ok':'bad');pending.textContent=String(d.counts?.PENDING??0)}catch(e){oauth.textContent='Error';oauth.className='value bad';msg.textContent=e.message||'No se pudo cargar';msg.className='msg bad'}
}
btn.onclick=async()=>{
  const authorization_code=code.value.trim();if(!authorization_code){msg.textContent='Pega primero el Authorization code.';msg.className='msg bad';return}
  btn.disabled=true;msg.textContent='Renovando autorización y sincronizando…';msg.className='msg';
  try{const r=await fetch('/api/google-contacts-oauth.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf,authorization_code})}),d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'No se pudo renovar');code.value='';oauth.textContent='Operativo';oauth.className='value ok';pending.textContent=String(d.counts?.PENDING??0);msg.textContent=`OAuth renovado. Sincronizados: ${Number(d.sync?.synced??0)} · Fallidos: ${Number(d.sync?.failed??0)}.`;msg.className='msg ok'}catch(e){msg.textContent=e.message||'No se pudo renovar';msg.className='msg bad'}finally{btn.disabled=false}
};
load();
</script>
</body>
</html>
