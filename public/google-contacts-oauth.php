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
*{box-sizing:border-box}body{margin:0;background:#f3f6fa;font-family:Manrope,Arial,sans-serif;color:#172033}.wrap{max-width:780px;margin:auto;padding:82px 14px 32px}.panel{background:#fff;border-radius:15px;padding:18px;margin-bottom:12px}.sub{color:#64748b}.status{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:14px 0}.card{padding:12px;border-radius:12px;background:#eef3f7}.value{font-weight:900;font-size:1.2rem}.field{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:9px;font:inherit}.btn,.linkbtn{display:inline-block;border:0;border-radius:9px;padding:11px 14px;font-weight:800;cursor:pointer;background:#123b5d;color:#fff;text-decoration:none}.btn:disabled{opacity:.55;cursor:not-allowed}.secondary{background:#e8eef2;color:#172033}.msg{min-height:24px;font-weight:800;margin-top:10px}.ok{color:#087443}.bad{color:#b42318}.links a{color:#123b5d;font-weight:800;text-decoration:none}.steps{padding-left:20px}.steps li{margin:10px 0}.note{padding:10px 12px;border-radius:10px;background:#fff7e8;color:#7a4b00}@media(max-width:560px){.status{grid-template-columns:1fr}}
</style>
</head>
<body>
<main class="wrap">
<h1>Google Contacts OAuth</h1>
<p class="sub">Renueva la autorización sin enviar el Client Secret ni los tokens por chat.</p>
<div class="panel">
<div class="links"><a href="/configuracion.php">← Volver a Configuración</a></div>
<div class="status">
<div class="card"><div>OAuth actual</div><div id="oauth" class="value">Comprobando…</div></div>
<div class="card"><div>Contactos pendientes</div><div id="pending" class="value">—</div></div>
</div>
</div>
<div class="panel">
<h2>Renovar credenciales</h2>
<div class="note">Si un secreto o token se mostró en una captura, primero restablece el Client Secret en Google Cloud y revoca el acceso anterior de la app.</div>
<ol class="steps">
<li>Copia el <strong>nuevo Client Secret</strong> de <em>Sharky Google Contact</em>.</li>
<li>Pulsa <strong>Autorizar con Google</strong>. Ese enlace usa directamente el Client ID configurado por Hache.</li>
<li>Acepta el permiso de contactos. Google te regresará al OAuth Playground con un <strong>Authorization code</strong>.</li>
<li>Pega aquí el nuevo Client Secret y el Authorization code. El servidor verificará ambos y dejará la renovación preparada para su instalación privilegiada.</li>
</ol>
<p><a id="authorize" class="linkbtn" href="#" rel="noopener">Autorizar con Google</a></p>
<label><strong>Nuevo Client Secret</strong></label>
<input id="secret" class="field" type="password" autocomplete="new-password" spellcheck="false" maxlength="1024" placeholder="Client Secret nuevo">
<p><label><strong>Authorization code</strong></label></p>
<input id="code" class="field" type="password" autocomplete="off" spellcheck="false" maxlength="4096" placeholder="Authorization code">
<p><button id="submit" class="btn">Validar y preparar renovación</button></p>
<div id="msg" class="msg" role="status"></div>
</div>
</main>
<script>
let csrf='';
const oauth=document.getElementById('oauth'),pending=document.getElementById('pending'),msg=document.getElementById('msg'),btn=document.getElementById('submit'),code=document.getElementById('code'),secret=document.getElementById('secret'),authorize=document.getElementById('authorize');
async function load(){
  try{
    const r=await fetch('/api/google-contacts-oauth.php',{cache:'no-store'}),d=await r.json();
    if(!r.ok||!d.ok)throw new Error(d.error||'No se pudo cargar');
    csrf=d.csrf;authorize.href=d.authorize_url;
    oauth.textContent=d.token_refresh_ok?'Operativo':'Requiere renovación';oauth.className='value '+(d.token_refresh_ok?'ok':'bad');
    pending.textContent=String(d.counts?.PENDING??0);
  }catch(e){oauth.textContent='Error';oauth.className='value bad';msg.textContent=e.message||'No se pudo cargar';msg.className='msg bad'}
}
btn.onclick=async()=>{
  const client_secret=secret.value.trim(),authorization_code=code.value.trim();
  if(!client_secret||!authorization_code){msg.textContent='Pega el nuevo Client Secret y el Authorization code.';msg.className='msg bad';return}
  btn.disabled=true;msg.textContent='Validando con Google…';msg.className='msg';
  try{
    const r=await fetch('/api/google-contacts-oauth.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf,client_secret,authorization_code})}),d=await r.json();
    if(!r.ok||!d.ok)throw new Error(d.error||'No se pudo preparar la renovación');
    secret.value='';code.value='';pending.textContent=String(d.counts?.PENDING??0);
    msg.textContent='Autorización validada y preparada. Avísame en el chat para completar la instalación segura en el servidor.';msg.className='msg ok';
  }catch(e){msg.textContent=e.message||'No se pudo preparar la renovación';msg.className='msg bad'}finally{btn.disabled=false}
};
load();
</script>
</body>
</html>
