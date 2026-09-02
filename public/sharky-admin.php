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
<title>Sharky — Hache Natación</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f6fa;font-family:Manrope,Arial,sans-serif;color:#172033}.wrap{max-width:1100px;margin:auto;padding:74px 14px 40px}.top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}.top a{color:#123b5d;font-weight:800;text-decoration:none}.sub{color:#64748b;margin:5px 0 18px}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.card,.panel{background:#fff;border-radius:16px;padding:15px;box-shadow:0 2px 10px rgba(15,23,42,.04)}.card strong{display:block;font-size:25px}.card span{color:#64748b;font-size:13px}.panel{margin-top:12px}.panel h2{margin:0 0 12px;font-size:19px}.msg{min-height:22px;font-weight:800;color:#b42318}.takeover{display:grid;grid-template-columns:120px 150px 1fr 165px;gap:10px;align-items:center;padding:12px 0;border-bottom:1px solid #edf1f5}.takeover:last-child{border-bottom:0}.pill{display:inline-block;padding:5px 8px;border-radius:999px;background:#fff3cd;color:#7a4c00;font-size:12px;font-weight:800}.summary{color:#475569;font-size:13px;line-height:1.35}.btn{border:0;border-radius:9px;padding:9px 11px;font-weight:800;cursor:pointer}.primary{background:#123b5d;color:#fff}.config-row{display:grid;grid-template-columns:260px 1fr 110px;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid #edf1f5}.config-row small{display:block;color:#64748b;margin-top:3px}.config-row input{width:100%;padding:9px 10px;border:1px solid #cbd5e1;border-radius:9px}.metrics{width:100%;border-collapse:collapse}.metrics th,.metrics td{text-align:left;padding:8px;border-bottom:1px solid #edf1f5;font-size:13px}.empty{color:#64748b;padding:8px 0}@media(max-width:850px){.grid{grid-template-columns:repeat(2,1fr)}.takeover{grid-template-columns:1fr 1fr}.takeover .summary{grid-column:1/-1}.config-row{grid-template-columns:1fr 110px}.config-row .label{grid-column:1/-1}}@media(max-width:520px){.grid{grid-template-columns:1fr 1fr}.takeover{grid-template-columns:1fr}.config-row{grid-template-columns:1fr}.config-row .label{grid-column:auto}}
</style>
</head>
<body>
<main class="wrap">
  <div class="top"><div><h1 style="margin:0">Sharky</h1><div class="sub">Conversaciones, atención humana, datos comerciales y métricas.</div></div><div><a href="/configuracion.php">← Configuración</a></div></div>
  <div id="msg" class="msg" role="status"></div>
  <section class="grid" id="cards"></section>
  <section class="panel"><h2>Conversaciones en atención humana</h2><div id="takeovers"></div></section>
  <section class="panel"><h2>Fuente de verdad comercial</h2><p class="sub">Estos valores alimentan a Sharky en web y WhatsApp. Los horarios y cursos vigentes se leen directamente del backend.</p><div id="config"></div></section>
  <section class="panel"><h2>Últimos 7 días</h2><div style="overflow:auto"><table class="metrics"><thead><tr><th>Fecha</th><th>Texto</th><th>Audios</th><th>Respuestas WA</th><th>Respuestas web</th><th>Takeovers</th><th>Llamadas IA</th></tr></thead><tbody id="metrics"></tbody></table></div></section>
</main>
<script>
const M=document.getElementById('msg'),T=document.getElementById('takeovers'),C=document.getElementById('config'),K=document.getElementById('cards'),R=document.getElementById('metrics');
const esc=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const reason={manual:'Toma manual',requested_human:'Pidió humano',frustration:'Frustración',unresolved:'Sin resolver',manual_legacy:'Toma manual'};
async function api(body){const r=await fetch('/api/sharky-admin.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}),d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'Error');return d}
function n(v){return Number(v||0).toLocaleString('es-MX')}
function cards(t){K.innerHTML=`<div class="card"><strong>${n(t.inbound_text)}</strong><span>mensajes de texto / 7 días</span></div><div class="card"><strong>${n(t.inbound_audio)}</strong><span>notas de voz / 7 días</span></div><div class="card"><strong>${n(t.takeovers)}</strong><span>pases a humano / 7 días</span></div><div class="card"><strong>${n(t.openai_calls)}</strong><span>llamadas de respuesta IA / 7 días</span></div>`}
function takeoverRows(rows){if(!rows.length){T.innerHTML='<div class="empty">No hay conversaciones pausadas para Sharky.</div>';return}T.innerHTML=rows.map(x=>`<div class="takeover"><div><strong>•••• ${esc(x.phone_last4||'----')}</strong><br><span class="pill">${esc(reason[x.reason]||x.reason||'Humano')}</span></div><div><small>${esc(x.activated_at?new Date(x.activated_at).toLocaleString('es-MX'):'Sin fecha')}</small></div><div class="summary">${esc(x.summary||'Sin resumen disponible.')}</div><div><button class="btn primary" data-resume="${esc(x.contact_hash)}">Reactivar Sharky</button></div></div>`).join('');T.querySelectorAll('[data-resume]').forEach(b=>b.onclick=async()=>{if(!confirm('¿Devolver esta conversación a Sharky?'))return;try{await api({accion:'RESUME',contact_hash:b.dataset.resume});M.textContent='Sharky reactivado para esa conversación.';await load()}catch(e){M.textContent=e.message}})}
function configRows(rows){C.innerHTML=rows.map(x=>`<div class="config-row"><div class="label"><strong>${esc(x.clave.replace(/^sharky_/,'').replaceAll('_',' '))}</strong><small>${esc(x.descripcion)}</small></div><input data-key="${esc(x.clave)}" value="${esc(x.valor)}"><button class="btn primary">Guardar</button></div>`).join('');C.querySelectorAll('button').forEach(b=>b.onclick=async()=>{const i=b.parentElement.querySelector('input');try{await api({accion:'CONFIG',clave:i.dataset.key,valor:i.value});M.textContent='Dato actualizado. Sharky usará el nuevo valor desde la siguiente respuesta.';await load()}catch(e){M.textContent=e.message}})}
function metricRows(rows){R.innerHTML=rows.map(x=>{const c=x.counters||{};return `<tr><td>${esc(x.date)}</td><td>${n(c.inbound_text)}</td><td>${n(c.inbound_audio)}</td><td>${n(c.answers_whatsapp)}</td><td>${n(c.answers_web)}</td><td>${n(c.takeovers)}</td><td>${n(c.openai_calls)}</td></tr>`}).join('')}
async function load(){try{const r=await fetch('/api/sharky-admin.php',{cache:'no-store'}),d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'No se pudo cargar Sharky');cards(d.totals||{});takeoverRows(d.takeovers||[]);configRows(d.configuracion||[]);metricRows(d.metrics||[])}catch(e){M.textContent=e.message||'No se pudo cargar Sharky'}}
load();
</script>
</body>
</html>
