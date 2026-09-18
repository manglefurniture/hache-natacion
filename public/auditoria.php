<?php declare(strict_types=1);require_once __DIR__.'/../config/auth.php';page_require(['ADMIN']);?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Auditoría — Franky</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f4f7fb;color:#172033;font-family:Manrope,Arial,sans-serif}
.wrap{max-width:1080px;margin:auto;padding:82px 14px 32px}
h1{margin:0}
.sub{color:#64748b;margin:6px 0 18px;line-height:1.45}
.notice{background:#eef6ff;border:1px solid #cfe1f6;border-radius:14px;padding:12px 14px;margin-bottom:12px;font-size:13px;line-height:1.45;color:#35506c}
.filters,.panel{background:#fff;border:1px solid #e5eaf0;border-radius:16px;padding:14px}
.filters{display:grid;grid-template-columns:1fr 1fr 1.25fr .8fr auto;gap:8px;margin-bottom:12px}
.filters label{display:grid;gap:5px;font-size:11px;font-weight:800;color:#64748b}
.filters input,.filters select,.filters button{font:inherit;padding:10px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;color:#172033}
.filters button{background:#172033;color:#fff;font-weight:900;align-self:end}
.summary{font-size:12px;color:#64748b;margin:0 0 10px}
.event{padding:15px 0;border-bottom:1px solid #eef2f7}
.event:last-child{border:0}
.meta{display:flex;gap:7px;flex-wrap:wrap;font-size:11px;color:#64748b}
.tag{display:inline-flex;align-items:center;border-radius:999px;padding:3px 7px;background:#f1f5f9;color:#475569;font-weight:800}
.tag.confirmed{background:#eaf8ef;color:#176b36}
.tag.technical{background:#eef4ff;color:#315d9b}
.tag.pending{background:#fff7df;color:#8b6417}
.tag.failed,.tag.cancelled{background:#fff0f0;color:#9b3434}
.title{font-weight:900;margin-top:7px;font-size:15px}
.route{font-family:monospace;font-size:12px;color:#475569;margin-top:5px;overflow-wrap:anywhere}
.detail{font-size:12px;color:#64748b;margin-top:6px;line-height:1.45;white-space:pre-wrap;overflow-wrap:anywhere}
.evidence{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:9px}
.ev{background:#f8fafc;border:1px solid #e6ebf1;border-radius:10px;padding:9px}
.ev strong{display:block;font-size:11px;margin-bottom:4px}
.ev span{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;overflow-wrap:anywhere}
.unknown{color:#8a96a5;font-style:italic}
.empty{padding:20px;text-align:center;color:#64748b}
@media(max-width:800px){.filters{grid-template-columns:1fr 1fr}.filters button{grid-column:1/-1}.evidence{grid-template-columns:1fr}}
@media(max-width:520px){.filters{grid-template-columns:1fr}.filters button{grid-column:auto}.wrap{padding-left:12px;padding-right:12px}.meta{gap:5px}}
</style>
</head>
<body>
<main class="wrap">
  <h1>Auditoría</h1>
  <div class="sub">Lectura unificada de evidencias administrativas existentes.</div>
  <div class="notice">Un resultado técnico de una solicitud no equivale automáticamente a un cambio confirmado. Cuando actor, sede, estado anterior o estado posterior no fueron registrados por la fuente, se muestran como <strong>no registrados</strong>; esta vista no los reconstruye.</div>
  <div class="filters">
    <label>Desde<input id="desde" type="date"></label>
    <label>Hasta<input id="hasta" type="date"></label>
    <label>Fuente<select id="fuente"><option value="">Todas</option><option value="auditoria_eventos">Auditoría de eventos</option><option value="historial">Historial de alumno</option></select></label>
    <label>Límite<select id="limite"><option>50</option><option selected>100</option><option>150</option><option>300</option></select></label>
    <button id="b" type="button">Filtrar</button>
  </div>
  <div class="summary" id="summary">Cargando…</div>
  <section class="panel" id="list" aria-live="polite">Cargando…</section>
</main>
<script>
const l=document.getElementById('list'),summary=document.getElementById('summary');
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const pretty=v=>{if(v===null||v===undefined)return '';if(typeof v==='string')return v;try{return JSON.stringify(v)}catch{return String(v)}};
const resultLabel=level=>({confirmed:'Cambio confirmado',technical:'Resultado técnico',pending:'Pendiente',failed:'Fallido',cancelled:'Cancelado',unknown:'Resultado no registrado'}[level]||level||'Resultado no registrado');
const actorLabel=a=>{if(!a||a.known!==true)return 'Actor no registrado';if(a.type==='system')return a.name||'Sistema';return a.name||a.id||'Actor registrado'};
const evidence=(label,data)=>{const available=data&&data.available===true;return `<div class="ev"><strong>${esc(label)}</strong><span class="${available?'':'unknown'}">${esc(available?pretty(data.value):'No registrado')}</span></div>`};
const eventHtml=v=>{
  const result=v.result||{},actor=v.actor||{},time=v.occurred_at||{},entity=v.entity||{},scope=v.scope||{},coverage=v.coverage||{};
  const level=result.level||'unknown';
  const refs=[];
  if(entity.type)refs.push(entity.type);
  if(entity.id)refs.push(entity.id);
  if(entity.reference_type||entity.reference_id)refs.push([entity.reference_type,entity.reference_id].filter(Boolean).join(':'));
  const technical=[v.method,v.route].filter(Boolean).join(' ');
  const source=[v.source,v.source_id].filter(Boolean).join(':');
  const meta=[
    time.known===true&&time.value?time.value:'Fecha/hora no registrada',
    actorLabel(actor),
    source||'Fuente no registrada',
    scope.sede_known===true&&scope.sede_id?'Sede '+scope.sede_id:'Sede no registrada'
  ];
  const detail=[];
  if(refs.length)detail.push('Entidad/ref: '+refs.join(' · '));
  if(result.code!==null&&result.code!==undefined)detail.push('Código: '+result.code);
  if(result.detail)detail.push(result.detail);
  if(v.reason)detail.push('Motivo: '+v.reason);
  if(coverage.before_after==='textual')detail.push('Before/after: evidencia textual en la fuente; no convertida a estructura.');
  return `<article class="event">
    <div class="meta">${meta.map(x=>`<span>${esc(x)}</span>`).join('')}<span class="tag ${esc(level)}">${esc(resultLabel(level))}</span></div>
    <div class="title">${esc(v.action||'Acción no registrada')} · ${esc(v.module||'módulo no registrado')}</div>
    ${technical?`<div class="route">${esc(technical)}</div>`:''}
    ${detail.length?`<div class="detail">${esc(detail.join('\n'))}</div>`:''}
    <div class="evidence">${evidence('Antes',v.before)}${evidence('Después',v.after)}</div>
  </article>`;
};
async function load(){
  l.innerHTML='<div class="empty">Cargando…</div>';summary.textContent='Cargando…';
  const q=new URLSearchParams({limite:document.getElementById('limite').value});
  const desde=document.getElementById('desde').value,hasta=document.getElementById('hasta').value,fuente=document.getElementById('fuente').value;
  if(desde)q.set('desde',desde);if(hasta)q.set('hasta',hasta);if(fuente)q.set('fuente',fuente);
  try{
    const r=await fetch('/api/auditoria-unificada.php?'+q.toString(),{headers:{Accept:'application/json'}});
    const x=await r.json();
    if(!r.ok||!x.ok){throw new Error(x.error||'No se pudo cargar la auditoría')}
    const eventos=Array.isArray(x.eventos)?x.eventos:[];
    const fuentes=(x.meta&&x.meta.fuentes_devuelta)||{};
    summary.textContent=`${eventos.length} evidencia(s) · ${Object.entries(fuentes).map(([k,v])=>k+': '+v).join(' · ')||'sin resultados'}`;
    l.innerHTML=eventos.length?eventos.map(eventHtml).join(''):'<div class="empty">Sin evidencias para estos filtros.</div>';
  }catch(e){
    summary.textContent='Error de lectura';
    l.innerHTML=`<div class="empty">${esc(e instanceof Error?e.message:'No se pudo cargar la auditoría')}</div>`;
  }
}
document.getElementById('b').addEventListener('click',load);
load();
</script>
</body>
</html>
