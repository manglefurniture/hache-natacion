<?php
declare(strict_types=1);
require_once __DIR__.'/../config/auth.php';
page_require(['ADMIN','VERIFICADOR']);

$today=(new DateTimeImmutable('now',new DateTimeZone('America/Cancun')))->format('Y-m-d');
$initialDate=trim((string)($_GET['fecha']??''));
if(!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$initialDate))$initialDate=$today;
$initialSede=trim((string)($_GET['sede']??''));
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Resumen diario — Hache Natación</title>
<style>
*{box-sizing:border-box}
:root{--ink:#172033;--muted:#64748b;--line:#dfe7ef;--paper:#fff;--bg:#eef3f8;--brand:#123b5d;--soft:#f8fafc;--warn:#92400e;--warnbg:#fff7ed}
body{margin:0;background:var(--bg);font-family:Inter,Manrope,Arial,sans-serif;color:var(--ink)}
.wrap{max-width:1120px;margin:auto;padding:82px 14px 38px}
.head,.actions,.meta,.card-head,.inline,.detail-head{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}
h1,h2,h3{margin:0}.head p{margin:6px 0 0;color:var(--muted);line-height:1.45}
.btn{display:inline-block;border:0;border-radius:10px;padding:10px 12px;text-decoration:none;font-weight:850;cursor:pointer;font:inherit}
.primary{background:var(--brand);color:#fff}.secondary{background:#e7edf3;color:var(--ink)}
.panel,.notice,.card{background:var(--paper);border:1px solid var(--line);border-radius:16px}
.notice{margin:13px 0;padding:12px 14px;background:#eef6ff;color:#35506c;line-height:1.45;font-size:13px}
.filters{display:grid;grid-template-columns:1fr auto;gap:9px;align-items:end;padding:14px;margin:12px 0}
.field{display:grid;gap:5px}.field label{font-size:12px;font-weight:850;color:#475569}
input{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:var(--ink);font:inherit}
.msg{min-height:22px;color:#9f1239;font-weight:800}
.meta{justify-content:flex-start;color:var(--muted);font-size:12px;margin:10px 0 16px}
.pill{display:inline-flex;align-items:center;padding:5px 8px;border-radius:999px;background:#e8eef2;color:#475569;font-weight:850}
.pill.live{background:#eaf8ef;color:#176b36}.pill.warn{background:var(--warnbg);color:var(--warn)}
.section{margin-top:17px}.section-title{display:flex;justify-content:space-between;align-items:end;gap:10px;margin-bottom:8px}.section-title h2{font-size:19px}.section-title span{font-size:12px;color:var(--muted)}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.card{padding:14px;min-width:0}.card.unavailable{background:#f8fafc;color:#667085}
.card-head{align-items:flex-start}.card h3{font-size:15px}.metric{font-size:25px;font-weight:900;letter-spacing:-.03em;margin:8px 0 3px}.sub{color:var(--muted);font-size:12px;line-height:1.45}
.source{margin-top:10px;font-size:12px;color:var(--muted);overflow-wrap:anywhere}
.source a,.detail a{color:#174f78;font-weight:800;text-decoration:none}.source a:hover,.detail a:hover{text-decoration:underline}
details{margin-top:10px;border-top:1px solid #edf1f5;padding-top:9px}summary{cursor:pointer;font-size:12px;font-weight:850;color:#475569}
.detail{padding:9px 0;border-bottom:1px solid #edf1f5;font-size:12px;line-height:1.4}.detail:last-child{border-bottom:0}.detail-head{align-items:flex-start}.detail strong{font-size:13px}.detail .muted{color:var(--muted)}
.contracts{margin-top:17px;padding:14px}.contracts h2{font-size:17px;margin-bottom:8px}.contracts ul{margin:0;padding-left:20px;color:#475569;font-size:13px;line-height:1.55}
.empty{padding:18px;text-align:center;color:var(--muted)}
@media(max-width:720px){.wrap{padding:76px 12px 28px}.head{align-items:flex-start}.head .actions{width:100%}.head .actions .btn{flex:1;text-align:center}.grid{grid-template-columns:1fr}.filters{grid-template-columns:1fr}.metric{font-size:22px}}
</style>
</head>
<body>
<main class="wrap">
  <header class="head">
    <div>
      <h1>Resumen operativo diario</h1>
      <p>Apertura y cierre del día usando las autoridades existentes. Esta vista no crea sesiones, no concilia pagos y no cierra operaciones.</p>
    </div>
    <div class="actions"><a id="back" class="btn secondary" href="/dashboard.php">← Dashboard</a></div>
  </header>

  <div class="notice"><strong>Lectura viva y reconciliable.</strong> No es una instantánea histórica. Una corrección durable posterior puede cambiar lo que se muestra al volver a consultar el mismo día.</div>

  <section class="panel filters">
    <div class="field"><label for="fecha">Fecha operativa</label><input id="fecha" type="date" value="<?=htmlspecialchars($initialDate,ENT_QUOTES,'UTF-8')?>"></div>
    <button id="refresh" class="btn primary" type="button">Actualizar</button>
  </section>

  <div id="msg" class="msg" role="status" aria-live="polite"></div>
  <div id="meta" class="meta"></div>
  <div id="content"><div class="empty">Cargando resumen…</div></div>
</main>
<script>
const initialSede=<?=json_encode($initialSede,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
const fecha=document.getElementById('fecha'),msg=document.getElementById('msg'),meta=document.getElementById('meta'),content=document.getElementById('content'),back=document.getElementById('back');
let sede=initialSede||localStorage.getItem('hache_sede')||'';
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const num=v=>Number.isFinite(Number(v))?Number(v):0;
const money=v=>num(v).toLocaleString('es-MX',{style:'currency',currency:'MXN',minimumFractionDigits:0,maximumFractionDigits:2});
const safeHref=v=>{const s=String(v??'');return s.startsWith('/')&&!s.startsWith('//')?s:'#'};
const link=(href,label='Ver fuente')=>href?'<a href="'+esc(safeHref(href))+'">'+esc(label)+'</a>':'';
const detail=(title,text,href='')=>'<div class="detail"><div class="detail-head"><strong>'+esc(title)+'</strong>'+link(href)+'</div><div class="muted">'+esc(text||'')+'</div></div>';
const details=(label,rows,render)=>{
  if(!Array.isArray(rows)||rows.length===0)return '';
  return '<details><summary>'+esc(label)+' ('+rows.length+')</summary>'+rows.map(render).join('')+'</details>';
};
const unavailable=(title,reason)=>'<article class="card unavailable"><h3>'+esc(title)+'</h3><div class="metric">—</div><div class="sub">'+esc(reason||'Dato no disponible con la cobertura actual.')+'</div></article>';
const card=(title,value,sub='',body='',href='')=>'<article class="card"><div class="card-head"><h3>'+esc(title)+'</h3>'+link(href)+'</div><div class="metric">'+esc(value)+'</div><div class="sub">'+esc(sub)+'</div>'+body+'</article>';

function incidentCard(x){
  if(!x||x.disponible!==true)return unavailable('Incidencias',x?.motivo);
  const sc=x.sesiones_canceladas||{},pc=x.profesores||{},su=x.sustituciones_activas||{};
  const body=
    details('Sesiones canceladas',sc.rows,r=>detail(String(r.hora_inicio||'').slice(0,5)+'–'+String(r.hora_fin||'').slice(0,5),r.motivo||'Sin motivo registrado',r.href))+
    details('Incidencias de profesor',pc.rows,r=>detail(r.profesor||'Profesor',String(r.hora_inicio||'').slice(0,5)+' · '+(r.motivo||''),r.href))+
    details('Sustituciones activas',su.rows,r=>detail((r.profesor_original||'')+' → '+(r.profesor_sustituto||''),String(r.hora_inicio||'').slice(0,5)+' · '+(r.motivo||''),r.href));
  return card('Incidencias','Clase '+num(sc.total)+' · profesor '+num(pc.total)+' · sustitución '+num(su.total),x.alcance||'',body);
}
function pendingCard(x){
  if(!x||x.disponible!==true)return unavailable('Pendientes actuales',x?.motivo);
  const r=x.resumen||{};
  const body=details('Detalle',x.rows,row=>detail(row.nombre||row.tipo||'Pendiente',(row.estado||'')+(row.alumno?' · '+row.alumno:'')+(row.explicacion?' · '+row.explicacion:''),row.href));
  return card('Pendientes actuales',String(num(r.pendientes)),num(r.atendidos)+' atendidos con causa activa · '+num(r.financieros)+' financieros · '+num(r.reposiciones)+' reposiciones',body);
}
function correctionsCard(x){
  if(!x)return unavailable('Correcciones posteriores','No disponibles para esta fecha.');
  const p=x.ediciones_pago||{},i=x.invalidaciones_pago||{},a=x.correcciones_asistencia||{};
  const sub='Ediciones de pago '+(p.disponible===true?num(p.total):'—')+' · invalidaciones '+(i.disponible===true?num(i.total):'—')+' · asistencia '+(a.disponible===true?num(a.total):'—');
  const body=
    details('Ediciones de pago',p.rows,r=>detail('Pago #'+(r.folio??'—'),'Registrado '+(r.registrado_en||''),r.href))+
    details('Invalidaciones de pago',i.rows,r=>detail('Pago #'+(r.folio??'—'),'Registrado '+(r.registrado_en||''),r.href))+
    details('Correcciones de asistencia',a.rows,r=>detail('Sesión '+(r.sesion_id||'—'),'Registrado '+(r.registrado_en||''),r.href));
  return card('Correcciones posteriores',x.disponible===true?'Disponibles':'Cobertura parcial',sub,body);
}
function opening(d){
  const a=d.apertura||{},active=a.alumnos_activos||{},planned=a.clases_previstas||{};
  const activeCard=active.disponible===false?unavailable('Alumnos activos',active.motivo):card('Alumnos activos',String(num(active.total)),active.contrato||'',details('Detalle',active.rows,r=>detail(r.nombre||'Alumno',(r.fuentes||[]).join(' + '),r.href)),active.href);
  const plannedCard=planned.disponible===true
    ?card('Clases previstas',String(num(planned.total)),planned.alcance||'',details('Horarios',planned.rows,r=>detail(String(r.hora_inicio||'').slice(0,5)+'–'+String(r.hora_fin||'').slice(0,5),(r.fuentes||[]).join(' + '))))
    :unavailable('Clases previstas',planned.motivo);
  return '<section class="section"><div class="section-title"><h2>Apertura</h2><span>Qué debería atenderse al iniciar el día</span></div><div class="grid">'+activeCard+plannedCard+pendingCard(a.pendientes_actuales)+incidentCard(a.incidencias)+'</div></section>';
}
function closing(d){
  const c=d.cierre||{};
  if(c.disponible!==true)return '<section class="section"><div class="section-title"><h2>Cierre</h2><span>Hechos del día</span></div><div class="grid">'+unavailable('Cierre operativo',c.motivo)+'</div></section>';
  const s=c.sesiones||{},as=c.asistencia||{},co=c.cobros||{},al=c.altas||{},pn=c.pendientes_nuevos||{};
  const sessions=card('Sesiones registradas',String(num(s.sesiones_registradas)),num(s.realizadas)+' realizadas · '+num(s.canceladas)+' canceladas · '+num(s.cerradas)+' cerradas','',s.href);
  const attendance=as.disponible===true?card('Asistencia',String(as.porcentaje??'—')+'%',num(as.presentes)+'/'+num(as.esperados)+' presentes · '+num(as.sesiones_completas)+' sesiones con cobertura completa'):unavailable('Asistencia',as.motivo);
  const paymentBody=details('Cobros del día',co.rows,r=>detail('Folio '+(r.folio??'—'),(r.estado||'')+' · '+money(r.importe)+' · '+(r.tipo||''),r.href));
  const payments=co.disponible===true?card('Cobros válidos',money(co.total_valido),num(co.validos)+' válidos · '+num(co.invalidados)+' invalidados por '+money(co.total_invalidado),paymentBody):unavailable('Cobros',co.motivo);
  const enroll=card('Altas del día',String(num(al.total)),'Según fecha de inicio',details('Detalle',al.rows,r=>detail(r.nombre||'Alumno',(r.fecha_inicio||'')+' · '+(r.estado_administrativo||''),r.href)),al.href);
  const newPending=pn.disponible===true?card('Pendientes nuevos',String(num(pn.total))):unavailable('Pendientes nuevos',pn.motivo);
  return '<section class="section"><div class="section-title"><h2>Cierre</h2><span>Hechos registrados para la fecha</span></div><div class="grid">'+sessions+attendance+payments+enroll+pendingCard(c.pendientes_actuales)+newPending+incidentCard(c.incidencias)+correctionsCard(c.correcciones)+'</div></section>';
}
function contracts(d){
  const c=d.contratos||{};
  return '<section class="panel contracts"><h2>Límites de esta lectura</h2><ul>'+Object.values(c).map(v=>'<li>'+esc(v)+'</li>').join('')+'</ul></section>';
}
function draw(d){
  const state=String(d.estado_fecha||'');
  meta.innerHTML='<span class="pill live">'+esc(d.tipo_lectura||'')+'</span><span class="pill">'+esc(d.sede?.nombre||'')+'</span><span class="pill">'+esc(d.fecha||'')+'</span><span class="pill '+(state==='FUTURO'?'warn':'')+'">'+esc(state)+'</span><span>Actualizado '+esc(String(d.actualizado_en||'').replace('T',' ').slice(0,19))+'</span><span>Snapshot: '+(d.snapshot===false?'no':'no disponible')+'</span>';
  content.innerHTML=opening(d)+closing(d)+contracts(d);
}
async function load(){
  msg.textContent='Cargando…';content.innerHTML='<div class="empty">Cargando resumen…</div>';
  try{
    const q=new URLSearchParams({fecha:fecha.value});
    if(sede)q.set('sede',sede);
    const r=await fetch('/api/resumen-diario.php?'+q.toString(),{cache:'no-store',headers:{Accept:'application/json'}});
    const d=await r.json();
    if(!r.ok||!d.ok)throw new Error(d.error||'No se pudo cargar el resumen diario.');
    sede=String(d.sede?.clave||sede);
    if(sede)localStorage.setItem('hache_sede',sede);
    const u=new URL(window.location.href);u.searchParams.set('fecha',d.fecha);if(sede)u.searchParams.set('sede',sede);history.replaceState(null,'',u);
    back.href='/dashboard.php'+(sede?'?sede='+encodeURIComponent(sede):'');
    draw(d);msg.textContent='';
  }catch(e){
    msg.textContent=e instanceof Error?e.message:'No se pudo cargar el resumen diario.';
    meta.innerHTML='';
    content.innerHTML='<div class="empty">Sin datos disponibles.</div>';
  }
}
document.getElementById('refresh').addEventListener('click',load);
fecha.addEventListener('change',load);
load();
</script>
</body>
</html>
