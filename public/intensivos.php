<?php
declare(strict_types=1);
require_once __DIR__.'/../config/auth.php';
$me=page_require(['ADMIN','VERIFICADOR']);
$isAdmin=(string)($me['rol']??'')==='ADMIN';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Intensivos - Hache Natación</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Arial,sans-serif;background:#f4f7f9;color:#222}.header{background:#fff;border-bottom:1px solid #e1e6ea;padding:18px 24px;display:flex;align-items:center;justify-content:space-between;gap:15px}.header h1{margin:0;color:#123b5d;font-size:24px}.header span{color:#777;font-size:14px}.header-actions{display:flex;gap:10px}.container{width:100%;max-width:1100px;margin:0 auto;padding:25px 20px}.top-bar{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:20px}.top-bar h2{margin:0;color:#123b5d;font-size:22px}.btn{border:0;border-radius:8px;padding:11px 16px;font-size:14px;font-weight:700;cursor:pointer}.btn-primary{background:#1976a8;color:#fff}.btn-secondary{background:#e8eef2;color:#234}.btn-small{padding:8px 11px;font-size:12px}.card{background:#fff;border-radius:12px;box-shadow:0 4px 18px rgba(0,0,0,.06);overflow:hidden}.table-container{width:100%;overflow-x:auto}table{width:100%;border-collapse:collapse}th{background:#f1f5f7;color:#456;font-size:13px;text-align:left;padding:13px;white-space:nowrap}td{padding:13px;border-top:1px solid #edf0f2;font-size:14px;white-space:nowrap}.empty{text-align:center;padding:45px 20px;color:#777}.badge,.alumnos-count{display:inline-block;padding:5px 9px;border-radius:20px;font-size:12px;font-weight:700}.alumnos-count{min-width:34px;text-align:center;background:#eef2f5;color:#345}.badge-programado{background:#e7f1ff;color:#155b95}.badge-curso{background:#fff3d8;color:#956000}.badge-terminado{background:#e5f6ec;color:#18733b}.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);align-items:center;justify-content:center;padding:20px;z-index:1000}.modal-box{width:100%;max-width:540px;background:#fff;border-radius:14px;padding:25px;max-height:90vh;overflow:auto}.modal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px}.modal-header h3{margin:0;color:#123b5d}.close{border:0;background:transparent;font-size:25px;cursor:pointer;color:#777}.form-group{margin-bottom:16px}.form-group label{display:block;margin-bottom:7px;font-size:13px;font-weight:700;color:#444}.form-group input,.form-group select,.form-group textarea{width:100%;padding:11px 12px;border:1px solid #d5dce1;border-radius:8px;font-size:15px;background:#fff}.form-group textarea{min-height:80px;resize:vertical}.readonly-field{background:#f1f5f7!important;color:#555}.historico-box{display:none;background:#fff8e8;border:1px solid #f1c45b;color:#72520d;padding:12px;border-radius:10px;line-height:1.45;font-size:13px;margin-bottom:16px}.historico-box.activo{display:block}.ayuda{color:#667085;font-size:12px;line-height:1.4;margin-top:6px}.form-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:20px}.message{display:none;padding:11px;border-radius:8px;margin-bottom:15px;font-size:14px}.message.error{display:block;background:#ffe8e8;color:#a52222}.message.success{display:block;background:#e5f6ec;color:#18733b}.hidden{display:none!important}@media(max-width:650px){.header{padding:15px;align-items:flex-start;flex-direction:column}.header-actions{width:100%;flex-direction:column}.header-actions .btn{width:100%}.container{padding:18px 12px}.top-bar{align-items:stretch;flex-direction:column}.top-bar .btn{width:100%}th,td{padding:10px}.form-actions{flex-direction:column}.form-actions .btn{width:100%}}
</style>
</head>
<body>
<header class="header">
  <div><h1>Hache Natación</h1><span>Panel administrativo</span></div>
  <div class="header-actions">
    <button class="btn btn-secondary" onclick="location.href='/pagos.php'">Pagos</button>
    <button class="btn btn-secondary" onclick="location.href='/alumnos.php'">Control de alumnos</button>
  </div>
</header>
<main class="container">
  <div class="top-bar">
    <h2>Cursos intensivos</h2>
    <?php if($isAdmin):?><button class="btn btn-primary" id="btnNuevo">+ Crear curso intensivo</button><?php endif;?>
  </div>
  <div id="message" class="message"></div>
  <div class="card"><div class="table-container"><table>
    <thead><tr><th>Inicio</th><th>Fin</th><th>Precio</th><th>Alumnos</th><th>Estado</th><th>Acciones</th></tr></thead>
    <tbody id="intensivosBody"><tr><td colspan="6" class="empty">Cargando cursos intensivos...</td></tr></tbody>
  </table></div></div>
</main>

<?php if($isAdmin):?>
<div class="modal" id="modalIntensivo"><div class="modal-box">
  <div class="modal-header"><h3>Crear curso intensivo</h3><button class="close" id="btnCerrar">×</button></div>
  <div id="formMessage" class="message"></div>
  <form id="formIntensivo">
    <div class="form-group">
      <label for="tipo_creacion">Tipo de creación</label>
      <select id="tipo_creacion">
        <option value="NORMAL">Curso actual o futuro</option>
        <option value="HISTORICO">Corrección histórica de ADMIN</option>
      </select>
    </div>
    <div id="historicoBox" class="historico-box"><strong>Corrección histórica.</strong> Úsala para cargar cursos reales que quedaron fuera del sistema, por ejemplo el intensivo del 17/08/2026. No reabre inscripciones públicas y exige un motivo administrativo.</div>
    <div class="form-group" id="grupoFechaNormal">
      <label for="fecha_inicio">Fecha de inicio</label>
      <select id="fecha_inicio"><option value="">Seleccionar lunes...</option></select>
    </div>
    <div class="form-group hidden" id="grupoFechaHistorica">
      <label for="fecha_inicio_historica">Fecha de inicio histórica</label>
      <input type="date" id="fecha_inicio_historica">
      <div class="ayuda">Debe ser lunes. El sistema calculará automáticamente el viernes de la tercera semana.</div>
    </div>
    <div class="form-group"><label for="fecha_fin">Fecha final</label><input type="text" id="fecha_fin" class="readonly-field" readonly placeholder="Se calculará automáticamente"></div>
    <div class="form-group"><label for="precio">Precio</label><input type="number" id="precio" min="0" step="0.01" value="1200" required></div>
    <div class="form-group hidden" id="grupoMotivo"><label for="motivo_correccion">Motivo de la corrección histórica *</label><textarea id="motivo_correccion" placeholder="Ej. Importación atrasada de alumnos de Palapas."></textarea></div>
    <div class="form-group"><label for="observaciones">Observaciones</label><textarea id="observaciones" placeholder="Notas adicionales del curso..."></textarea></div>
    <div class="form-actions"><button type="button" class="btn btn-secondary" id="btnCancelar">Cancelar</button><button type="submit" class="btn btn-primary" id="btnGuardar">Crear curso</button></div>
  </form>
</div></div>
<?php endif;?>

<script>
const body=document.getElementById('intensivosBody'),message=document.getElementById('message');
function esc(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
function precio(v){return Number(v||0).toLocaleString('es-MX',{style:'currency',currency:'MXN'})}
function badge(e){if(e==='PROGRAMADO')return '<span class="badge badge-programado">Programado</span>';if(e==='EN_CURSO')return '<span class="badge badge-curso">En curso</span>';return '<span class="badge badge-terminado">Terminado</span>'}
function msg(el,text,tipo){el.textContent=text;el.className='message '+tipo}
async function cargar(){body.innerHTML='<tr><td colspan="6" class="empty">Cargando cursos intensivos...</td></tr>';try{const r=await fetch('/api/intensivos.php'),d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'No se pudieron cargar los cursos');const cursos=d.intensivos||[];if(!cursos.length){body.innerHTML='<tr><td colspan="6" class="empty">No hay cursos intensivos registrados.</td></tr>';return}body.innerHTML='';for(const c of cursos){const tr=document.createElement('tr');tr.innerHTML=`<td>${esc(c.fecha_inicio)}</td><td>${esc(c.fecha_fin)}</td><td>${precio(c.precio)}</td><td><span class="alumnos-count">${Number(c.total_alumnos||0)}</span></td><td>${badge(c.estado)}</td><td><button class="btn btn-secondary btn-small">Ver alumnos</button></td>`;tr.querySelector('button').onclick=()=>location.href='/intensivo-detalle.php?id='+encodeURIComponent(c.id);body.appendChild(tr)}}catch(e){body.innerHTML='<tr><td colspan="6" class="empty">Error al cargar los cursos intensivos.</td></tr>';msg(message,e.message||'Error al cargar cursos','error')}}
cargar();
</script>

<?php if($isAdmin):?>
<script>
const modal=document.getElementById('modalIntensivo'),form=document.getElementById('formIntensivo'),formMessage=document.getElementById('formMessage'),tipo=document.getElementById('tipo_creacion'),fechaNormal=document.getElementById('fecha_inicio'),fechaHist=document.getElementById('fecha_inicio_historica'),fechaFin=document.getElementById('fecha_fin'),grupoNormal=document.getElementById('grupoFechaNormal'),grupoHist=document.getElementById('grupoFechaHistorica'),grupoMotivo=document.getElementById('grupoMotivo'),motivo=document.getElementById('motivo_correccion'),histBox=document.getElementById('historicoBox'),btnGuardar=document.getElementById('btnGuardar');
function iso(d){return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0')}
function fechaHumana(d){return d.toLocaleDateString('es-MX',{weekday:'long',day:'numeric',month:'long',year:'numeric'})}
function generarLunes(){fechaNormal.innerHTML='<option value="">Seleccionar lunes...</option>';const hoy=new Date();hoy.setHours(12,0,0,0);let diff=(8-hoy.getDay())%7;if(hoy.getDay()===1)diff=0;const primero=new Date(hoy);primero.setDate(hoy.getDate()+diff);for(let i=0;i<53;i++){const l=new Date(primero);l.setDate(primero.getDate()+i*7);const o=document.createElement('option');o.value=iso(l);o.textContent=fechaHumana(l);fechaNormal.appendChild(o)}}
function fechaElegida(){return tipo.value==='HISTORICO'?fechaHist.value:fechaNormal.value}
function actualizarFin(){const v=fechaElegida();if(!v){fechaFin.value='';return}const d=new Date(v+'T12:00:00'),f=new Date(d);f.setDate(d.getDate()+18);fechaFin.value=fechaHumana(f)}
function actualizarModo(){const hist=tipo.value==='HISTORICO';grupoNormal.classList.toggle('hidden',hist);grupoHist.classList.toggle('hidden',!hist);grupoMotivo.classList.toggle('hidden',!hist);histBox.classList.toggle('activo',hist);fechaNormal.required=!hist;fechaHist.required=hist;motivo.required=hist;actualizarFin()}
document.getElementById('btnNuevo').onclick=()=>{form.reset();document.getElementById('precio').value='1200';generarLunes();actualizarModo();fechaFin.value='';formMessage.className='message';modal.style.display='flex'};
document.getElementById('btnCerrar').onclick=()=>modal.style.display='none';document.getElementById('btnCancelar').onclick=()=>modal.style.display='none';modal.onclick=e=>{if(e.target===modal)modal.style.display='none'};tipo.onchange=actualizarModo;fechaNormal.onchange=actualizarFin;fechaHist.onchange=actualizarFin;
form.onsubmit=async e=>{e.preventDefault();formMessage.className='message';const hist=tipo.value==='HISTORICO',fecha=fechaElegida(),mot=motivo.value.trim(),obs=document.getElementById('observaciones').value.trim();if(!fecha){msg(formMessage,'Selecciona la fecha de inicio.','error');return}const d=new Date(fecha+'T12:00:00');if(d.getDay()!==1){msg(formMessage,'La fecha de inicio debe ser lunes.','error');return}if(hist&&!mot){msg(formMessage,'Escribe el motivo de la corrección histórica.','error');return}btnGuardar.disabled=true;btnGuardar.textContent='Creando...';try{const r=await fetch('/api/intensivos.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({fecha_inicio:fecha,precio:document.getElementById('precio').value,observaciones:obs,correccion_historica:hist,motivo_correccion:hist?mot:null})}),data=await r.json();if(!r.ok||!data.ok)throw new Error(data.error||'No se pudo crear el curso intensivo');modal.style.display='none';if(hist&&data.intensivo?.id){location.href='/intensivo-detalle.php?id='+encodeURIComponent(data.intensivo.id);return}msg(message,data.mensaje||'Curso intensivo creado correctamente.','success');await cargar()}catch(err){msg(formMessage,err.message||'Error al crear el curso intensivo.','error')}finally{btnGuardar.disabled=false;btnGuardar.textContent='Crear curso'}};
generarLunes();actualizarModo();
</script>
<?php endif;?>
</body>
</html>
