<?php
declare(strict_types=1);
require_once __DIR__.'/../config/auth.php';
page_require(['ADMIN','VERIFICADOR']);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>Moderación de Historias — Hache Natación</title>
  <style>
    :root{--ink:#172033;--muted:#6b778b;--line:#e3e9ef;--paper:#fff;--bg:#f3f6fa;--brand:#0b6fe8;--ok:#166534;--bad:#9f1239;--warn:#92400e}*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink)}.wrap{max-width:1040px;margin:auto;padding:86px 16px 42px}.eyebrow{font-size:11px;font-weight:900;letter-spacing:.13em;color:#567089}.head h1{margin:7px 0 8px;font-size:32px;letter-spacing:-.035em}.head p{margin:0 0 24px;color:var(--muted);line-height:1.55}.tabs{display:flex;gap:8px;overflow:auto;padding-bottom:6px;margin-bottom:18px}.tab{white-space:nowrap;border:1px solid var(--line);background:var(--paper);border-radius:999px;padding:10px 13px;font:inherit;font-size:13px;font-weight:800;cursor:pointer}.tab.is-active{background:var(--ink);color:#fff;border-color:var(--ink)}.tab span{opacity:.68;margin-left:5px}.queue{display:grid;gap:12px}.card{background:var(--paper);border:1px solid var(--line);border-radius:17px;padding:18px;box-shadow:0 7px 24px rgba(15,23,42,.04)}.card-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start}.author{font-weight:900}.meta{margin-top:4px;color:var(--muted);font-size:11px}.state{font-size:10px;font-weight:900;letter-spacing:.08em;padding:6px 8px;border-radius:999px;background:#edf2f7}.comment{margin:16px 0;color:#34495b;line-height:1.65;white-space:pre-wrap;overflow-wrap:anywhere}.flags{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px}.flag{font-size:10px;padding:5px 7px;border-radius:999px;background:#fff7ed;color:var(--warn);font-weight:800}.blocked{background:#fff1f2;color:var(--bad)}.actions{display:flex;gap:8px;flex-wrap:wrap}.action-button{border:1px solid #ccd8e1;background:#fff;border-radius:10px;padding:9px 11px;font:inherit;font-size:12px;font-weight:850;cursor:pointer}.action-button.approve{background:#ecfdf3;border-color:#bbf7d0;color:var(--ok)}.action-button.reject,.action-button.hide{background:#fff7ed;border-color:#fed7aa;color:var(--warn)}.action-button.delete,.action-button.block{background:#fff1f2;border-color:#fecdd3;color:var(--bad)}.action-button:disabled{opacity:.5;cursor:wait}.empty,.notice{padding:20px;border:1px dashed #cbd5e1;border-radius:15px;background:#fff;color:var(--muted);text-align:center}.notice{display:none;margin-bottom:14px;text-align:left;border-style:solid}.notice.error{display:block;background:#fff1f2;border-color:#fecdd3;color:var(--bad)}.notice.success{display:block;background:#ecfdf3;border-color:#bbf7d0;color:var(--ok)}@media(max-width:600px){.wrap{padding:78px 12px 30px}.head h1{font-size:28px}.card-head{display:block}.state{display:inline-block;margin-top:10px}.actions{display:grid;grid-template-columns:1fr 1fr}.action-button{min-height:42px}}
  </style>
</head>
<body>
<main class="wrap">
  <header class="head">
    <div class="eyebrow">HISTORIAS HACHE · COMUNIDAD</div>
    <h1>Moderación de comentarios</h1>
    <p>Los comentarios públicos nunca aparecen automáticamente: primero pasan por esta cola. Admin y supervisores pueden aprobar, rechazar, ocultar, eliminar o bloquear abuso.</p>
  </header>
  <div id="notice" class="notice" role="status" aria-live="polite"></div>
  <nav class="tabs" aria-label="Estados de comentarios" id="tabs"></nav>
  <section class="queue" id="queue"><div class="empty">Cargando comentarios…</div></section>
</main>
<script>
const states=[['PENDIENTE','Pendientes'],['APROBADO','Aprobados'],['RECHAZADO','Rechazados'],['OCULTO','Ocultos'],['ELIMINADO','Eliminados']];
const queue=document.getElementById('queue'),tabs=document.getElementById('tabs'),notice=document.getElementById('notice');let current='PENDIENTE',csrf='';
const text=(tag,value,className='')=>{const el=document.createElement(tag);el.textContent=value??'';if(className)el.className=className;return el};
function flash(msg,type='success'){notice.textContent=msg;notice.className=`notice ${type}`;setTimeout(()=>{notice.className='notice'},3500)}
function renderTabs(counts={}){tabs.replaceChildren();for(const [state,label] of states){const b=document.createElement('button');b.type='button';b.className=`tab ${current===state?'is-active':''}`;b.append(document.createTextNode(label));const c=text('span',String(Number(counts[state]||0)));b.append(c);b.onclick=()=>{current=state;load()};tabs.append(b)}}
function actionButton(label,action,id,className=''){const b=text('button',label,`action-button ${className}`);b.type='button';b.onclick=()=>mutate(action,id,b);return b}
function render(items){queue.replaceChildren();if(!Array.isArray(items)||!items.length){queue.append(text('div','No hay comentarios en este estado.','empty'));return}for(const item of items){const card=document.createElement('article');card.className='card';const head=document.createElement('div');head.className='card-head';const left=document.createElement('div');left.append(text('div',item.autor_nombre||'Visitante','author'));left.append(text('div',`${item.historia_slug||''} · ${item.created_at||''}`,'meta'));head.append(left,text('span',item.estado||'','state'));card.append(head,text('p',item.comentario||'','comment'));const flags=document.createElement('div');flags.className='flags';for(const flag of String(item.flags||'').split(',').filter(Boolean))flags.append(text('span',flag.replaceAll('_',' '),'flag'));if(Number(item.origen_bloqueado)===1)flags.append(text('span','origen bloqueado','flag blocked'));if(flags.children.length)card.append(flags);const actions=document.createElement('div');actions.className='actions';if(item.estado!=='APROBADO')actions.append(actionButton('Aprobar','APROBAR',item.id,'approve'));if(item.estado!=='RECHAZADO'&&item.estado!=='ELIMINADO')actions.append(actionButton('Rechazar','RECHAZAR',item.id,'reject'));if(item.estado==='APROBADO')actions.append(actionButton('Ocultar','OCULTAR',item.id,'hide'));if(item.estado!=='ELIMINADO')actions.append(actionButton('Eliminar','ELIMINAR',item.id,'delete'));if(Number(item.origen_bloqueado)===1)actions.append(actionButton('Desbloquear origen','DESBLOQUEAR_ORIGEN',item.id));else actions.append(actionButton('Bloquear origen','BLOQUEAR_ORIGEN',item.id,'block'));card.append(actions);queue.append(card)}}
async function load(){queue.innerHTML='<div class="empty">Cargando comentarios…</div>';try{const r=await fetch(`/historias-moderacion-api.php?estado=${encodeURIComponent(current)}`,{cache:'no-store',credentials:'same-origin'}),d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'No se pudo cargar');csrf=d.csrf||'';renderTabs(d.conteos);render(d.comentarios)}catch(e){queue.replaceChildren(text('div',e.message||'No se pudo cargar la moderación.','empty'));renderTabs({})}}
async function mutate(action,id,button){if(action==='ELIMINAR'&&!confirm('¿Marcar este comentario como eliminado?'))return;if(action==='BLOQUEAR_ORIGEN'&&!confirm('¿Bloquear este origen y rechazar sus comentarios pendientes?'))return;button.disabled=true;try{const r=await fetch('/historias-moderacion-api.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({accion:action,id,csrf})}),d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'No se pudo completar la acción');flash('Moderación guardada.');await load()}catch(e){flash(e.message||'No se pudo completar la acción','error')}finally{button.disabled=false}}
load();
</script>
</body>
</html>
