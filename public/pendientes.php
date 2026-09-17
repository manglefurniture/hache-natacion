<?php
declare(strict_types=1);
require_once __DIR__.'/../config/auth.php';
page_require(['ADMIN','VERIFICADOR']);
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pendientes — Hache Natación</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f6fa;color:#172033;font-family:Manrope,Arial,sans-serif}.wrap{max-width:1060px;margin:auto;padding:82px 14px 32px}h1{margin:0;font-size:30px}.sub{color:#64748b;margin:6px 0 18px}.filters{display:flex;flex-wrap:wrap;gap:9px;background:#fff;border:1px solid #e5eaf0;border-radius:14px;padding:12px;margin-bottom:12px}.filters label{font-size:11px;font-weight:900;color:#64748b;display:grid;gap:4px}.filters select{min-width:190px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#172033;font:inherit;padding:8px}.list{display:grid;gap:10px}.card{background:#fff;border:1px solid #e5eaf0;border-radius:15px;padding:15px;box-shadow:0 4px 14px #0f172a08}.top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.title{font-size:16px;font-weight:900;margin:7px 0 3px}.tag,.state{display:inline-block;font-size:10px;font-weight:900;letter-spacing:.06em;text-transform:uppercase;border-radius:999px;padding:5px 8px}.tag{background:#eef2f7;color:#334155}.state{white-space:nowrap}.state-pendiente{background:#fff7ed;color:#9a3412}.state-atendido{background:#eff6ff;color:#1d4ed8}.state-resuelto{background:#ecfdf5;color:#047857}.meta,.trace{font-size:12px;color:#64748b;line-height:1.5;margin-top:7px}.trace{border-top:1px solid #eef2f7;padding-top:8px}.actions{display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin-top:12px}.link,.btn{border:0;border-radius:8px;padding:9px 11px;font:inherit;font-size:12px;font-weight:900;text-decoration:none;cursor:pointer}.link{background:#e8eef3;color:#20364d}.btn{background:#172033;color:#fff}.btn.resolve{background:#047857}.empty{background:#fff;border-radius:14px;padding:25px;text-align:center;color:#64748b}.error{color:#9f1239;background:#fff1f2}.deferred{margin-top:16px;font-size:12px;color:#64748b;line-height:1.5}@media(max-width:620px){.wrap{padding:78px 12px 28px}.top{align-items:flex-start}.filters label,.filters select{width:100%}.state{font-size:9px}}
</style>
</head>
<body>
<main class="wrap">
  <h1>Pendientes</h1>
  <div class="sub">Asuntos administrativos con causa verificable. Gestionarlos aquí no modifica su registro de origen.</div>
  <section class="filters" aria-label="Filtros de pendientes">
    <label>Estado<select id="estado"><option value="">Todos</option><option value="PENDIENTE">Pendiente</option><option value="ATENDIDO">Atendido</option><option value="RESUELTO">Resuelto</option></select></label>
    <label>Tipo<select id="tipo"><option value="">Todos los tipos</option></select></label>
  </section>
  <div id="list" class="list"><div class="empty">Cargando pendientes…</div></div>
  <div class="deferred">Los prospectos sin seguimiento son pendientes globales visibles solo para ADMIN. Si F4 no ha confirmado sede, se muestran como “Sin sede confirmada” sin asignarles una sede artificial.</div>
</main>
<script>
const list=document.getElementById('list'),estado=document.getElementById('estado'),tipo=document.getElementById('tipo');
let datos=[],csrf='',puedeGestionar=false;
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const estados=new Set(['PENDIENTE','ATENDIDO','RESUELTO']);
const fecha=v=>{const d=String(v??'');return d?esc(d.slice(0,10).split('-').reverse().join('/')):'—'};
const periodo=x=>x.periodo_inicio?`${fecha(x.periodo_inicio)} – ${fecha(x.periodo_fin)}`:fecha(x.fecha_referencia);
function llenarTipos(){const elegido=tipo.value,tipos=[...new Map(datos.map(x=>[x.tipo,x.tipo_nombre])).entries()];tipo.innerHTML='<option value="">Todos los tipos</option>'+tipos.map(([id,nombre])=>`<option value="${esc(id)}">${esc(nombre)}</option>`).join('');tipo.value=tipos.some(([id])=>id===elegido)?elegido:'';}
function traza(x){const partes=[];if(x.atendido_at)partes.push(`Atendido por ${esc(x.atendido_por||'usuario no disponible')} · ${fecha(x.atendido_at)}${x.atencion_nota?` · ${esc(x.atencion_nota)}`:''}`);if(x.resuelto_at)partes.push(`Resuelto por ${esc(x.resuelto_por||'usuario no disponible')} · ${fecha(x.resuelto_at)}${x.resolucion_nota?` · ${esc(x.resolucion_nota)}`:''}`);if(x.resuelto_por_fuente)partes.push('La fuente original ya no hace aplicable este asunto.');return partes.length?`<div class="trace">${partes.join('<br>')}</div>`:'';}
function render(){const e=estado.value,t=tipo.value,items=datos.filter(x=>(!e||x.estado===e)&&(!t||x.tipo===t));if(!items.length){list.innerHTML='<div class="empty">No hay pendientes para estos filtros.</div>';return;}list.innerHTML=items.map(x=>{const s=estados.has(x.estado)?x.estado:'PENDIENTE',persona=x.alumno_nombre?esc(x.alumno_nombre):(x.tipo==='PROSPECTO_SIN_SEGUIMIENTO'?'Prospecto':'Sin persona disponible'),acciones=[`<a class="link" href="${esc(x.href)}">Abrir registro original</a>`];if(puedeGestionar&&x.causa_activa&&s==='PENDIENTE')acciones.push(`<button class="btn" data-action="ATENDER" data-id="${esc(x.identidad)}">Marcar atendido</button>`);if(puedeGestionar&&!x.causa_activa&&x.estado_gestion!=='RESUELTO')acciones.push(`<button class="btn resolve" data-action="RESOLVER" data-id="${esc(x.identidad)}">Confirmar resolución</button>`);return `<article class="card"><div class="top"><div><span class="tag">${esc(x.tipo_nombre)}</span><div class="title">${persona}</div><div class="meta">${esc(x.sede_nombre||'Sede no disponible')} · ${periodo(x)}</div></div><span class="state state-${s.toLowerCase()}">${esc(s)}</span></div><div class="meta">${esc(x.explicacion)}</div>${traza(x)}<div class="actions">${acciones.join('')}</div></article>`;}).join('');}
async function cargar(){try{const r=await fetch('/api/pendientes.php',{cache:'no-store'}),d=await r.json();if(!r.ok||!d.ok)throw Error(d.error||'No se pudieron cargar los pendientes');datos=Array.isArray(d.pendientes)?d.pendientes:[];csrf=String(d.csrf||'');puedeGestionar=d.puede_gestionar===true;llenarTipos();render();}catch(e){list.innerHTML=`<div class="empty error">${esc(e.message||'No se pudieron cargar los pendientes.')}</div>`;}}
async function gestionar(accion,identidad){const verbo=accion==='ATENDER'?'registrar la atención':'confirmar la resolución';if(!confirm(`¿Deseas ${verbo} de este pendiente?`))return;const nota=prompt('Nota breve (opcional):','');if(nota===null)return;try{const r=await fetch('/api/pendientes.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({accion,identidad,nota,csrf})}),d=await r.json();if(!r.ok||!d.ok)throw Error(d.error||'No se pudo actualizar el pendiente');await cargar();}catch(e){alert(e.message||'No se pudo actualizar el pendiente.');}}
list.addEventListener('click',e=>{const b=e.target.closest('[data-action]');if(b)gestionar(b.dataset.action,b.dataset.id);});estado.addEventListener('change',render);tipo.addEventListener('change',render);cargar();
</script>
</body>
</html>