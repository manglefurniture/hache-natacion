<?php
declare(strict_types=1);
require_once __DIR__.'/../config/auth.php';
page_require(['ADMIN']);
$now=new DateTimeImmutable('now',new DateTimeZone('America/Cancun'));
$defaultDesde=$now->format('Y-m-d');
$defaultHasta=$now->modify('+31 days')->format('Y-m-d');
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sustituciones de profesores — Hache Natación</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f3f6fa;font-family:Manrope,Arial,sans-serif;color:#172033}
.wrap{max-width:1080px;margin:auto;padding:82px 14px 36px}
.head{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap}
h1,h2{margin-top:0}.muted{color:#64748b}.small{font-size:13px}
.panel{background:#fff;border-radius:15px;padding:16px;margin:12px 0}
.grid{display:grid;grid-template-columns:1.3fr 1fr 1fr 1.4fr auto;gap:9px;align-items:end}
.field{display:grid;gap:5px}.field label{font-size:12px;font-weight:800;color:#475569}
input,select{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#172033}
.btn,.linkbtn{border:0;border-radius:9px;padding:10px 12px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-block}
.primary{background:#123b5d;color:#fff}.secondary{background:#e8eef2;color:#172033}.danger{background:#fff1f0;color:#b42318}
.btn:disabled{opacity:.55;cursor:not-allowed}
.msg{min-height:22px;font-weight:800;color:#b42318}
.coverage{padding:9px 11px;background:#f8fafc;border-radius:10px;color:#475569}
.item{border-top:1px solid #e7edf4;padding:13px 0}.item:first-child{border-top:0}
.itemtop{display:flex;gap:10px;justify-content:space-between;align-items:flex-start;flex-wrap:wrap}
.badge{font-size:12px;font-weight:900;padding:5px 8px;border-radius:999px;background:#e8eef2}
.badge.active{background:#ecfdf3;color:#067647}.badge.cancelled{background:#f2f4f7;color:#667085}
.route{font-weight:900}.meta{font-size:13px;color:#64748b;margin-top:4px}
.annul{display:grid;grid-template-columns:1fr auto;gap:8px;margin-top:9px}
.empty{padding:10px 0;color:#64748b}
.range{display:grid;grid-template-columns:1fr 1fr auto;gap:8px;align-items:end}
@media(max-width:820px){.grid{grid-template-columns:1fr 1fr}.grid .wide{grid-column:1/-1}.grid .submit{grid-column:1/-1}.range{grid-template-columns:1fr 1fr}.range .submit{grid-column:1/-1}.annul{grid-template-columns:1fr}}
@media(max-width:520px){.grid,.range{grid-template-columns:1fr}.grid .wide,.grid .submit,.range .submit{grid-column:auto}}
</style>
</head>
<body>
<main class="wrap">
  <div class="head">
    <div>
      <h1>Sustituciones de profesores</h1>
      <p class="muted">Registra quién cubrió a un profesor en una clase específica. La historia anterior al inicio de cobertura no se reconstruye.</p>
    </div>
    <a class="linkbtn secondary" href="/profesores.php">← Profesores</a>
  </div>
  <div id="msg" class="msg" role="status" aria-live="polite"></div>

  <section class="panel">
    <h2>Periodo</h2>
    <div class="range">
      <div class="field"><label for="desde">Desde</label><input id="desde" type="date" value="<?=htmlspecialchars($defaultDesde,ENT_QUOTES,'UTF-8')?>"></div>
      <div class="field"><label for="hasta">Hasta</label><input id="hasta" type="date" value="<?=htmlspecialchars($defaultHasta,ENT_QUOTES,'UTF-8')?>"></div>
      <button id="refresh" class="btn secondary submit" type="button">Actualizar</button>
    </div>
    <p id="coverage" class="coverage small">Cargando cobertura…</p>
  </section>

  <section class="panel">
    <h2>Registrar sustitución</h2>
    <div class="grid">
      <div class="field wide"><label for="session">Clase</label><select id="session"><option value="">Selecciona una clase…</option></select></div>
      <div class="field"><label for="original">Profesor original</label><select id="original" disabled><option value="">Selecciona…</option></select></div>
      <div class="field"><label for="substitute">Sustituto</label><select id="substitute" disabled><option value="">Selecciona…</option></select></div>
      <div class="field wide"><label for="reason">Motivo</label><input id="reason" maxlength="500" placeholder="Ej. cobertura por ausencia"></div>
      <button id="register" class="btn primary submit" type="button">Registrar</button>
    </div>
    <p class="muted small">La API valida cobertura, asignación durable del profesor original y que el sustituto sea válido. Una clase cancelada no admite sustitución.</p>
  </section>

  <section class="panel">
    <h2>Historial del periodo</h2>
    <div id="history">Cargando…</div>
  </section>
</main>
<script>
const byId=x=>document.getElementById(x);
const msg=byId('msg'),sessionSel=byId('session'),originalSel=byId('original'),subSel=byId('substitute'),history=byId('history');
let substitutions={csrf:'',cobertura_desde:'',sesiones:[],sustituciones:[]},professors={profesores:[]};
const esc=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const hm=x=>String(x??'').slice(0,5);
function note(text){msg.textContent=text}
function sessionLabel(s){return s.fecha+' · '+s.sede_nombre+' · '+hm(s.hora_inicio)+'–'+hm(s.hora_fin)+' · '+s.estado}
function selectedSession(){return substitutions.sesiones.find(s=>s.id===sessionSel.value)||null}
function drawSessions(){
  const previous=sessionSel.value;
  sessionSel.innerHTML='<option value="">Selecciona una clase…</option>'+substitutions.sesiones.map(s=>'<option value="'+esc(s.id)+'">'+esc(sessionLabel(s))+'</option>').join('');
  if(substitutions.sesiones.some(s=>s.id===previous))sessionSel.value=previous;
  drawProfessorOptions();
}
function drawProfessorOptions(){
  const s=selectedSession(),previousOriginal=originalSel.value,previousSub=subSel.value;
  const assigned=s?(s.profesores_asignados||[]):[];
  originalSel.disabled=!s;
  originalSel.innerHTML='<option value="">Selecciona…</option>'+assigned.map(p=>'<option value="'+esc(p.id)+'">'+esc(p.nombre)+'</option>').join('');
  if(assigned.some(p=>p.id===previousOriginal))originalSel.value=previousOriginal;
  const used=new Set(assigned.map(p=>p.id));const original=originalSel.value;
  const candidates=(professors.profesores||[]).filter(p=>Number(p.activo)===1&&!used.has(p.id)&&p.id!==original);
  subSel.disabled=!s||!original;
  subSel.innerHTML='<option value="">Selecciona…</option>'+candidates.map(p=>'<option value="'+esc(p.id)+'">'+esc(p.nombre)+'</option>').join('');
  if(candidates.some(p=>p.id===previousSub))subSel.value=previousSub;
}
function drawHistory(){
  const rows=substitutions.sustituciones||[];
  history.innerHTML=rows.map(x=>{
    const active=String(x.estado)==='ACTIVA';
    const badge='<span class="badge '+(active?'active':'cancelled')+'">'+esc(x.estado)+'</span>';
    const created='Registró '+esc(x.created_by_usuario||'sistema')+' · '+esc(x.created_at||'')+' UTC';
    const annulled=!active?'<div class="meta">Anulada '+esc(x.anulada_at||'')+' UTC por '+esc(x.anulada_by_usuario||'sistema')+' · '+esc(x.motivo_anulacion||'')+'</div>':'';
    const annulForm=active?'<div class="annul"><input maxlength="500" aria-label="Motivo de anulación" data-annul-reason="'+esc(x.id)+'" placeholder="Motivo de anulación"><button type="button" class="btn danger" data-annul="'+esc(x.id)+'">Anular</button></div>':'';
    return '<article class="item"><div class="itemtop"><div><div class="route">'+esc(x.profesor_original_nombre)+' → '+esc(x.profesor_sustituto_nombre)+'</div><div class="meta">'+esc(x.fecha)+' · '+esc(x.sede_nombre)+' · '+esc(hm(x.hora_inicio))+'–'+esc(hm(x.hora_fin))+'</div></div>'+badge+'</div><div class="meta">Motivo: '+esc(x.motivo)+'</div><div class="meta">'+created+'</div>'+annulled+annulForm+'</article>';
  }).join('')||'<div class="empty">No hay sustituciones registradas en este periodo.</div>';
  history.querySelectorAll('[data-annul]').forEach(button=>button.onclick=async()=>{
    const id=button.dataset.annul,input=history.querySelector('[data-annul-reason="'+CSS.escape(id)+'"]');
    const reason=String(input?.value||'').trim();
    if(!reason){note('Escribe el motivo de anulación.');input?.focus();return}
    button.disabled=true;
    try{await post({accion:'ANULAR',sustitucion_id:id,motivo:reason});note('Sustitución anulada.');await load()}catch(e){note(e.message||'No se pudo anular.')}finally{if(document.body.contains(button))button.disabled=false}
  });
}
async function post(body){
  const r=await fetch('/api/profesor-sustituciones.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...body,csrf:substitutions.csrf})});
  const d=await r.json();
  if(r.status===419){await load();throw new Error(d.error||'Sesión de seguridad vencida. Recarga la página.')}
  if(!r.ok||!d.ok)throw new Error(d.error||'No se pudo completar la operación.');
  return d;
}
async function load(){
  const desde=byId('desde').value,hasta=byId('hasta').value;
  note('Cargando…');
  try{
    const [sr,pr]=await Promise.all([
      fetch('/api/profesor-sustituciones.php?desde='+encodeURIComponent(desde)+'&hasta='+encodeURIComponent(hasta),{cache:'no-store'}),
      fetch('/api/profesores.php',{cache:'no-store'})
    ]);
    const [sd,pd]=await Promise.all([sr.json(),pr.json()]);
    if(!sr.ok||!sd.ok)throw new Error(sd.error||'No se pudieron cargar las sustituciones.');
    if(!pr.ok||!pd.ok)throw new Error(pd.error||'No se pudieron cargar los profesores.');
    substitutions=sd;professors=pd;
    byId('coverage').textContent='Cobertura forward-only desde '+String(sd.cobertura_desde||'—')+' UTC. No representa clases anteriores.';
    drawSessions();drawHistory();note('');
  }catch(e){note(e.message||'No se pudo cargar.');history.innerHTML='<div class="empty">Sin datos disponibles.</div>'}
}
sessionSel.onchange=drawProfessorOptions;
originalSel.onchange=drawProfessorOptions;
byId('refresh').onclick=load;
byId('register').onclick=async()=>{
  const sesion=sessionSel.value,original=originalSel.value,substitute=subSel.value,reason=byId('reason').value.trim();
  if(!sesion||!original||!substitute||!reason){note('Completa clase, profesor original, sustituto y motivo.');return}
  const button=byId('register');button.disabled=true;
  try{
    await post({accion:'REGISTRAR',sesion_id:sesion,profesor_original_id:original,profesor_sustituto_id:substitute,motivo:reason});
    byId('reason').value='';note('Sustitución registrada.');await load();
  }catch(e){note(e.message||'No se pudo registrar.')}finally{button.disabled=false}
};
load();
</script>
</body>
</html>
