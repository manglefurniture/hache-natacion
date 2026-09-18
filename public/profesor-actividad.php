<?php
declare(strict_types=1);
require_once __DIR__.'/../config/auth.php';
page_require(['ADMIN']);

$now=new DateTimeImmutable('now',new DateTimeZone('America/Cancun'));
$defaultDesde=$now->modify('first day of this month')->format('Y-m-d');
$defaultHasta=$now->format('Y-m-d');
$initialProfessorId=trim((string)($_GET['profesor_id']??''));
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Actividad de profesores — Hache Natación</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f3f6fa;font-family:Manrope,Arial,sans-serif;color:#172033}
.wrap{max-width:1120px;margin:auto;padding:82px 14px 36px}
.head,.head-actions,.summary,.session-head,.badges{display:flex;gap:10px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap}
h1,h2,h3{margin-top:0}.muted{color:#64748b}.small{font-size:13px}
.panel{background:#fff;border-radius:15px;padding:16px;margin:12px 0}
.filters{display:grid;grid-template-columns:1fr 1fr 1.4fr auto;gap:9px;align-items:end}
.field{display:grid;gap:5px}.field label{font-size:12px;font-weight:800;color:#475569}
input,select{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#172033}
.btn,.linkbtn{border:0;border-radius:9px;padding:10px 12px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-block}
.primary{background:#123b5d;color:#fff}.secondary{background:#e8eef2;color:#172033}
.btn:disabled{opacity:.55;cursor:not-allowed}
.msg{min-height:22px;font-weight:800;color:#b42318}
.coverage{padding:10px 12px;background:#f8fafc;border-radius:10px;color:#475569}
.professor{border-top:1px solid #e7edf4;padding:18px 0}.professor:first-child{border-top:0}
.professor-title{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.badge{font-size:12px;font-weight:900;padding:5px 8px;border-radius:999px;background:#e8eef2;color:#475569}
.badge.good{background:#ecfdf3;color:#067647}.badge.warn{background:#fff7ed;color:#b54708}.badge.off{background:#f2f4f7;color:#667085}
.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px;margin:12px 0}
.card{background:#f8fafc;border-radius:12px;padding:11px}
.card strong{display:block;font-size:20px;margin-top:4px}.card span{font-size:12px;color:#64748b}
.source{font-size:12px;color:#64748b;margin-top:5px;overflow-wrap:anywhere}
details{border-top:1px solid #e7edf4;padding-top:12px;margin-top:12px}
summary{font-weight:900;cursor:pointer}
.session{padding:12px 0;border-top:1px solid #e7edf4}.session:first-of-type{border-top:0}
.session-route{font-weight:900}.meta{font-size:13px;color:#64748b;margin-top:4px}
.badges{justify-content:flex-start;margin-top:7px}
.incident,.substitution,.note{margin-top:8px;padding:9px 10px;border-radius:10px;background:#f8fafc;font-size:13px}
.empty{padding:10px 0;color:#64748b}
@media(max-width:860px){.filters{grid-template-columns:1fr 1fr}.filters .wide,.filters .submit{grid-column:1/-1}.cards{grid-template-columns:1fr 1fr}}
@media(max-width:540px){.filters,.cards{grid-template-columns:1fr}.filters .wide,.filters .submit{grid-column:auto}.head-actions{width:100%}.head-actions .linkbtn{flex:1;text-align:center}.card strong{font-size:18px}}
</style>
</head>
<body>
<main class="wrap">
  <div class="head">
    <div>
      <h1>Actividad de profesores</h1>
      <p class="muted">Lectura operativa de carga, incidencias y sustituciones según la evidencia disponible. No reconstruye historia anterior a la cobertura F7.</p>
    </div>
    <div class="head-actions">
      <a class="linkbtn secondary" href="/profesores.php">← Profesores</a>
      <a class="linkbtn secondary" href="/profesor-sustituciones.php">Sustituciones</a>
    </div>
  </div>

  <div id="msg" class="msg" role="status" aria-live="polite"></div>

  <section class="panel">
    <h2>Periodo y profesor</h2>
    <div class="filters">
      <div class="field"><label for="desde">Desde</label><input id="desde" type="date" value="<?=htmlspecialchars($defaultDesde,ENT_QUOTES,'UTF-8')?>"></div>
      <div class="field"><label for="hasta">Hasta</label><input id="hasta" type="date" value="<?=htmlspecialchars($defaultHasta,ENT_QUOTES,'UTF-8')?>"></div>
      <div class="field wide"><label for="professorFilter">Profesor</label><select id="professorFilter"><option value="">Todos los profesores</option></select></div>
      <button id="refresh" class="btn primary submit" type="button">Actualizar</button>
    </div>
    <p id="coverage" class="coverage small">Cargando cobertura…</p>
  </section>

  <section class="panel">
    <h2>Resumen y sesiones</h2>
    <div id="results">Cargando…</div>
  </section>
</main>
<script>
const byId=x=>document.getElementById(x);
const msg=byId('msg'),results=byId('results'),professorFilter=byId('professorFilter');
const initialProfessorId=<?=json_encode($initialProfessorId,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
let activity={periodo:null,cobertura:null,profesores:[]};
let selectedProfessorId=initialProfessorId;
const esc=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const hm=x=>String(x??'').slice(0,5);
function note(text){msg.textContent=text}
function boolBadge(value,yes,no){
  return '<span class="badge '+(value?'good':'off')+'">'+esc(value?yes:no)+'</span>';
}
function drawCoverage(){
  const c=activity.cobertura||{};
  byId('coverage').textContent=
    'Asignaciones: '+String(c.asignaciones_desde_utc||'—')+' UTC · Sustituciones: '+String(c.sustituciones_desde_utc||'—')+
    ' UTC · Historia previa reconstruida: '+(c.historia_previa_reconstruida===false?'no':'no disponible');
}
function drawProfessorFilter(){
  const current=selectedProfessorId;
  professorFilter.innerHTML='<option value="">Todos los profesores</option>'+(activity.profesores||[]).map(p=>
    '<option value="'+esc(p.id)+'">'+esc(p.nombre)+(Number(p.activo)===1?'':' · inactivo')+'</option>'
  ).join('');
  if((activity.profesores||[]).some(p=>p.id===current))professorFilter.value=current;
  else{selectedProfessorId='';professorFilter.value=''}
}
function substitutionRows(rows){
  if(!Array.isArray(rows)||rows.length===0)return '';
  return rows.map(s=>
    '<div class="substitution"><strong>Sustitución '+esc(s.estado||'')+'</strong> · '+esc(s.rol||'')+
    '<div class="meta">'+esc(s.profesor_original_nombre||'')+' → '+esc(s.profesor_sustituto_nombre||'')+
    ' · '+esc(s.motivo||'')+'</div>'+
    (s.estado==='ANULADA'?'<div class="meta">Anulada: '+esc(s.motivo_anulacion||'sin motivo registrado')+'</div>':'')+
    '</div>'
  ).join('');
}
function sessionRows(sessions){
  if(!Array.isArray(sessions)||sessions.length===0)return '<div class="empty">No hay sesiones relacionadas con este profesor en el periodo.</div>';
  return sessions.map(s=>{
    const confirmed=s.imparticion_confirmada===true
      ?'<span class="badge good">Impartición confirmada</span>'
      :s.imparticion_confirmada===false
        ?'<span class="badge off">No impartida confirmada</span>'
        :'<span class="badge warn">Sin confirmación</span>';
    const incident=s.incidencia
      ?'<div class="incident"><strong>Incidencia</strong><div class="meta">'+esc(s.incidencia.tipo||'')+' · '+esc(s.incidencia.motivo||'')+' · fuente '+esc(s.incidencia.origen||'')+'</div></div>'
      :'';
    const noteBlock=s.nota_atribucion?'<div class="note">'+esc(s.nota_atribucion)+'</div>':'';
    return '<article class="session">'+
      '<div class="session-head"><div><div class="session-route">'+esc(s.fecha)+' · '+esc(s.sede_nombre)+' · '+esc(hm(s.hora_inicio))+'–'+esc(hm(s.hora_fin))+'</div>'+
      '<div class="meta">Sesión '+esc(s.estado)+' · '+esc(s.duracion_minutos)+' min</div></div></div>'+
      '<div class="badges">'+confirmed+
      boolBadge(s.asignacion_prevista===true,'Asignación prevista','Sin asignación prevista')+
      (s.docencia_compartida===true?'<span class="badge">Docencia compartida</span>':'')+
      '</div>'+
      (s.fuente_imparticion?'<div class="meta">Fuente de impartición: '+esc(s.fuente_imparticion)+'</div>':'')+
      '<div class="meta">Fuentes: '+esc((s.fuentes||[]).join(', ')||'—')+'</div>'+
      incident+substitutionRows(s.sustituciones)+noteBlock+
      '</article>';
  }).join('');
}
function professorCard(p){
  const planned=p.carga_prevista||{},done=p.carga_realizada||{},subs=p.sustituciones_activas||{};
  return '<article class="professor">'+
    '<div class="professor-title"><h3>'+esc(p.nombre)+'</h3>'+
    '<span class="badge '+(Number(p.activo)===1?'good':'off')+'">'+(Number(p.activo)===1?'Activo':'Inactivo')+'</span></div>'+
    '<div class="cards">'+
      '<div class="card"><span>Carga prevista</span><strong>'+esc(planned.sesiones??0)+' sesiones · '+esc(planned.horas??0)+' h</strong><div class="source">'+esc(planned.fuente||'—')+'</div></div>'+
      '<div class="card"><span>Carga confirmada</span><strong>'+esc(done.sesiones_confirmadas??0)+' sesiones · '+esc(done.horas_confirmadas??0)+' h</strong><div class="source">'+esc(done.fuente_confirmada||'—')+'</div></div>'+
      '<div class="card"><span>Realizadas sin atribución</span><strong>'+esc(done.sesiones_realizadas_sin_atribucion??0)+'</strong><div class="source">Se muestran sin reconstrucción ficticia.</div></div>'+
      '<div class="card"><span>Incidencias</span><strong>'+esc(p.incidencias??0)+'</strong><div class="source">No impartidas confirmadas: '+esc(done.sesiones_confirmadas_no_impartidas??0)+'</div></div>'+
      '<div class="card"><span>Sustituciones activas</span><strong>'+esc(subs.como_original??0)+' / '+esc(subs.como_sustituto??0)+'</strong><div class="source">Como original / como sustituto</div></div>'+
      '<div class="card"><span>Previstas canceladas</span><strong>'+esc(planned.canceladas??0)+'</strong><div class="source">Dato entregado por F7.4.</div></div>'+
    '</div>'+
    '<p class="muted small">'+esc(done.limitacion||'')+'</p>'+
    '<details><summary>Ver sesiones del periodo ('+esc((p.sesiones||[]).length)+')</summary>'+sessionRows(p.sesiones||[])+'</details>'+
    '</article>';
}
function draw(){
  const all=activity.profesores||[];
  const visible=selectedProfessorId?all.filter(p=>p.id===selectedProfessorId):all;
  results.innerHTML=visible.map(professorCard).join('')||'<div class="empty">No hay profesores disponibles para este filtro y periodo.</div>';
}
async function load(){
  const desde=byId('desde').value,hasta=byId('hasta').value;
  note('Cargando…');results.innerHTML='<div class="empty">Cargando actividad…</div>';
  try{
    const r=await fetch('/api/profesor-actividad.php?desde='+encodeURIComponent(desde)+'&hasta='+encodeURIComponent(hasta),{cache:'no-store'});
    const d=await r.json();
    if(!r.ok||!d.ok)throw new Error(d.error||'No se pudo cargar la actividad.');
    activity=d;
    drawCoverage();drawProfessorFilter();draw();note('');
  }catch(e){
    note(e.message||'No se pudo cargar la actividad.');
    results.innerHTML='<div class="empty">Sin datos disponibles.</div>';
    byId('coverage').textContent='Cobertura no disponible.';
  }
}
professorFilter.onchange=()=>{
  selectedProfessorId=professorFilter.value;
  const u=new URL(window.location.href);
  if(selectedProfessorId)u.searchParams.set('profesor_id',selectedProfessorId);else u.searchParams.delete('profesor_id');
  history.replaceState(null,'',u);
  draw();
};
byId('refresh').onclick=load;
load();
</script>
</body>
</html>
