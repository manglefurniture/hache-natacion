<?php
declare(strict_types=1);
require_once __DIR__.'/../config/auth.php';
page_require(['ADMIN','VERIFICADOR']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pagos - Hache Natación</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Arial,sans-serif;background:#f4f7f9;color:#222}
.header{background:#fff;border-bottom:1px solid #e1e6ea;padding:18px 24px;display:flex;align-items:center;justify-content:space-between;gap:15px}
.header h1{margin:0;color:#123b5d;font-size:24px}.header span{color:#777;font-size:14px}
.container{width:100%;max-width:1100px;margin:0 auto;padding:25px 20px}.top-bar{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:20px}.top-bar h2{margin:0;color:#123b5d;font-size:22px}
.btn{border:none;border-radius:8px;padding:11px 16px;font-size:14px;font-weight:bold;cursor:pointer}.btn-primary{background:#1976a8;color:#fff}.btn-secondary{background:#e8eef2;color:#234}
.card{background:#fff;border-radius:12px;box-shadow:0 4px 18px rgba(0,0,0,.06);overflow:hidden}.table-container{width:100%;overflow-x:auto}table{width:100%;border-collapse:collapse}th{background:#f1f5f7;color:#456;font-size:13px;text-align:left;padding:13px;white-space:nowrap}td{padding:13px;border-top:1px solid #edf0f2;font-size:14px;white-space:nowrap}.empty{text-align:center;padding:45px 20px;color:#777}
.badge{display:inline-block;padding:5px 9px;border-radius:20px;font-size:12px;font-weight:bold}.badge-valid{background:#e5f6ec;color:#18733b}.badge-invalid{background:#ffe7e7;color:#a52222}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);align-items:center;justify-content:center;padding:20px;z-index:1000}.modal-box{width:100%;max-width:500px;background:#fff;border-radius:14px;padding:25px;max-height:90vh;overflow-y:auto}.modal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}.modal-header h3{margin:0;color:#123b5d}.close{border:none;background:transparent;font-size:25px;cursor:pointer;color:#777}
.form-group{margin-bottom:16px}.form-group label{display:block;margin-bottom:7px;font-size:13px;font-weight:bold;color:#444}.form-group input,.form-group select,.form-group textarea{width:100%;padding:11px 12px;border:1px solid #d5dce1;border-radius:8px;font-size:15px;background:#fff}.form-group textarea{min-height:80px;resize:vertical}.help{font-size:12px;color:#667085;line-height:1.4;margin-top:6px}.history-help{padding:10px 12px;border-radius:8px;background:#fff8e8;border:1px solid #f1c45b;color:#72520d}.hidden{display:none!important}.form-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:20px}
.message{display:none;padding:11px;border-radius:8px;margin-bottom:15px;font-size:14px}.message.error{background:#ffe8e8;color:#a52222}.message.success{background:#e5f6ec;color:#18733b}.message.warning{background:#fff8e8;color:#72520d;border:1px solid #f1c45b}
@media(max-width:650px){.header{padding:15px}.header h1{font-size:20px}.container{padding:18px 12px}.top-bar{align-items:stretch;flex-direction:column}.top-bar .btn{width:100%}th,td{padding:10px}.modal{padding:8px}.modal-box{padding:20px}}
</style>
</head>
<body>
<header class="header"><div><h1>Hache Natación</h1><span>Panel administrativo</span></div><button class="btn btn-secondary" onclick="window.location.href='/alumnos.php'">Control de alumnos</button></header>
<main class="container">
<div class="top-bar"><h2>Pagos</h2><button class="btn btn-primary" id="btnNuevoPago">+ Registrar pago</button></div>
<div id="message" class="message"></div>
<div class="card"><div class="table-container"><table><thead><tr><th>Folio</th><th>Alumno</th><th>Tipo</th><th>Importe</th><th>Método</th><th>Fecha</th><th>Estado</th></tr></thead><tbody id="pagosBody"><tr><td colspan="7" class="empty">Cargando pagos...</td></tr></tbody></table></div></div>
</main>

<div class="modal" id="modalPago"><div class="modal-box">
<div class="modal-header"><h3>Registrar pago</h3><button class="close" id="btnCerrarModal">×</button></div>
<div id="formMessage" class="message"></div>
<form id="formPago">
<div class="form-group"><label for="alumno_id">Alumno</label><select id="alumno_id" required><option value="">Seleccionar alumno...</option></select></div>
<div class="form-group"><label for="tipo">Tipo de pago</label><select id="tipo" required><option value="">Seleccionar...</option><option value="INSCRIPCION">Inscripción</option><option value="MENSUALIDAD">Mensualidad</option><option value="INTENSIVO">Curso intensivo</option></select></div>
<div class="form-group hidden" id="grupoCursoIntensivo"><label for="curso_intensivo_id">Curso intensivo</label><select id="curso_intensivo_id"><option value="">Seleccionar curso...</option></select><div id="cursoIntensivoAyuda" class="help">Se muestran también cursos históricos del alumno. El pago quedará vinculado al curso que selecciones.</div></div>
<div class="form-group"><label for="importe">Importe</label><input type="number" id="importe" min="0.01" step="0.01" required></div>
<div class="form-group"><label for="metodo">Método de pago</label><select id="metodo" required><option value="">Seleccionar...</option><option value="EFECTIVO">Efectivo</option><option value="TRANSFERENCIA">Transferencia</option><option value="MERCADO_PAGO">Mercado Pago</option></select></div>
<div class="form-group"><label for="fecha">Fecha</label><input type="datetime-local" id="fecha" required></div>
<div class="form-group"><label for="observacion">Observación</label><textarea id="observacion"></textarea><div class="help">En pagos históricos el sistema agrega automáticamente una nota de auditoría; aquí puedes añadir cualquier aclaración adicional.</div></div>
<div class="form-actions"><button type="button" class="btn btn-secondary" id="btnCancelar">Cancelar</button><button type="submit" class="btn btn-primary">Registrar pago</button></div>
</form>
</div></div>

<script>
const pagosBody=document.getElementById('pagosBody');
const alumnoSelect=document.getElementById('alumno_id');
const tipoSelect=document.getElementById('tipo');
const importeInput=document.getElementById('importe');
const metodoSelect=document.getElementById('metodo');
const fechaInput=document.getElementById('fecha');
const cursoGroup=document.getElementById('grupoCursoIntensivo');
const cursoSelect=document.getElementById('curso_intensivo_id');
const cursoHelp=document.getElementById('cursoIntensivoAyuda');
const modal=document.getElementById('modalPago');
const btnNuevoPago=document.getElementById('btnNuevoPago');
const btnCerrarModal=document.getElementById('btnCerrarModal');
const btnCancelar=document.getElementById('btnCancelar');
const formPago=document.getElementById('formPago');
const message=document.getElementById('message');
const formMessage=document.getElementById('formMessage');
const query=new URLSearchParams(location.search);
let intensiveCourses=[];

function escaparHtml(valor){return String(valor??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));}
function mostrarMensaje(elemento,texto,tipo){elemento.textContent=texto;elemento.className='message '+tipo;elemento.style.display='block';}
function ocultarMensaje(elemento){elemento.style.display='none';}
function establecerFechaActual(){const ahora=new Date();const offset=ahora.getTimezoneOffset();const local=new Date(ahora.getTime()-offset*60000);fechaInput.value=local.toISOString().slice(0,16);}
function formatearTipo(tipo){return ({INSCRIPCION:'Inscripción',MENSUALIDAD:'Mensualidad',INTENSIVO:'Curso intensivo'})[tipo]||tipo||'';}
function formatearMetodo(metodo){return ({EFECTIVO:'Efectivo',TRANSFERENCIA:'Transferencia',MERCADO_PAGO:'Mercado Pago'})[metodo]||metodo||'';}
function money(value){return Number(value||0).toLocaleString('es-MX',{style:'currency',currency:'MXN'});}
function fechaCorta(value){if(!value)return '';const d=new Date(value+'T12:00:00');return Number.isNaN(d.getTime())?value:d.toLocaleDateString('es-MX');}
function cursoSolicitado(){return query.get('curso_intensivo_id')||query.get('curso_id')||'';}
function estadoNotificacionPago(data){
    const notificacion=data?.notificacion_pago||null;
    const reason=String(notificacion?.reason||'').toUpperCase();
    if(data?.pago_intensivo_historico||reason==='HISTORICAL_PAYMENT')return {tipo:'success',texto:'Pago histórico registrado y auditado correctamente. No se envía confirmación de WhatsApp para pagos históricos.'};
    if(notificacion?.queued===true)return {tipo:'success',texto:'Pago registrado correctamente. Confirmación de WhatsApp preparada para envío.'};
    const detalles={
        SHARKY_DISABLED:'Sharky/WhatsApp está deshabilitado.',
        INVALID_PHONE:'el WhatsApp del alumno no tiene un formato válido.',
        INVALID_TEMPLATE:'la plantilla de WhatsApp no es válida.',
        OUTBOX_UNAVAILABLE:'la cola de salida no pudo aceptar el mensaje.',
        PAYMENT_CONTACT_INCOMPLETE:'faltan datos de contacto válidos del alumno.',
        PAYMENT_NOT_FOUND:'el pago no pudo recuperarse para preparar la notificación.',
        PAYMENT_NOT_VALID:'el pago no está en estado válido para notificar.',
        INVALID_FOLIO:'el folio del pago no es válido para notificar.',
        INTERNAL_ERROR:'ocurrió un error interno al preparar la notificación.',
    };
    const detalle=detalles[reason]||'no se recibió confirmación de que el mensaje haya entrado a la cola de salida.';
    const codigo=reason?` Código: ${reason}.`:'';
    return {tipo:'warning',texto:`Pago registrado correctamente, pero la confirmación de WhatsApp no se pudo preparar: ${detalle}${codigo}`};
}

async function cargarPagos(){
    pagosBody.innerHTML='<tr><td colspan="7" class="empty">Cargando pagos...</td></tr>';
    try{
        const response=await fetch('/api/pagos.php');const data=await response.json();if(!data.ok)throw new Error(data.error||'No se pudieron cargar los pagos');
        if(!data.pagos||data.pagos.length===0){pagosBody.innerHTML='<tr><td colspan="7" class="empty">No hay pagos registrados.</td></tr>';return;}
        pagosBody.innerHTML='';
        data.pagos.forEach(pago=>{const tr=document.createElement('tr');const fecha=pago.fecha?new Date(pago.fecha.replace(' ','T')).toLocaleString('es-MX'):'';const estado=pago.estado==='VALIDO'?'<span class="badge badge-valid">Válido</span>':'<span class="badge badge-invalid">Invalidado</span>';tr.innerHTML=`<td>${escaparHtml(pago.folio)}</td><td>${escaparHtml(pago.alumno_nombre??pago.alumno_id)}</td><td>${escaparHtml(formatearTipo(pago.tipo))}</td><td>${escaparHtml(money(pago.importe))}</td><td>${escaparHtml(formatearMetodo(pago.metodo))}</td><td>${escaparHtml(fecha)}</td><td>${estado}</td>`;pagosBody.appendChild(tr);});
    }catch(error){pagosBody.innerHTML='<tr><td colspan="7" class="empty">Error al cargar los pagos.</td></tr>';console.error(error);}
}

async function cargarAlumnos(){
    try{
        const response=await fetch('/api/alumnos.php');const data=await response.json();if(!data.ok)throw new Error(data.error||'No se pudieron cargar los alumnos');
        alumnoSelect.innerHTML='<option value="">Seleccionar alumno...</option>';
        (data.alumnos||[]).forEach(alumno=>{const option=document.createElement('option');option.value=alumno.id;option.dataset.planPrecio=alumno.plan_precio??'';option.textContent=alumno.nombre||alumno.nombre_completo||alumno.id;alumnoSelect.appendChild(option);});
        aplicarPreset();
    }catch(error){console.error(error);alumnoSelect.innerHTML='<option value="">No se pudieron cargar los alumnos</option>';}
}

async function leerCatalogoIntensivos(url){
    const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!response.ok)throw new Error('HTTP '+response.status);
    let data;
    try{data=await response.json();}catch(parseError){throw new Error('Respuesta inválida del catálogo de cursos');}
    if(!data||data.ok!==true||!Array.isArray(data.cursos))throw new Error(data?.error||'Respuesta incompleta del catálogo de cursos');
    return data.cursos;
}

async function cargarCursosIntensivosAlumno(preseleccionar=''){
    intensiveCourses=[];cursoSelect.innerHTML='<option value="">Seleccionar curso...</option>';cursoHelp.className='help';cursoHelp.textContent='Se muestran también cursos históricos del alumno. El pago quedará vinculado al curso que selecciones.';
    if(tipoSelect.value!=='INTENSIVO'||!alumnoSelect.value)return;
    cursoSelect.disabled=true;
    try{
        const params=new URLSearchParams({accion:'CURSOS_INTENSIVOS',alumno_id:alumnoSelect.value});
        try{
            intensiveCourses=await leerCatalogoIntensivos('/api/pagos.php?'+params.toString());
        }catch(primaryError){
            const legacyParams=new URLSearchParams({alumno_id:alumnoSelect.value});
            intensiveCourses=await leerCatalogoIntensivos('/api/alumno-intensivos-pago.php?'+legacyParams.toString());
        }
        intensiveCourses.forEach(curso=>{const option=document.createElement('option');option.value=curso.id;option.dataset.price=curso.precio??'';option.dataset.balance=curso.saldo??curso.precio??'';option.dataset.historico=curso.historico?'1':'0';option.disabled=curso.pagado===true;const pago=curso.pagado?'PAGADO':Number(curso.pagado_total||0)>0?'ANTICIPO '+money(curso.pagado_total)+' · SALDO '+money(curso.saldo):'PENDIENTE · SALDO '+money(curso.saldo??curso.precio);option.textContent=fechaCorta(curso.fecha_inicio)+' · '+(curso.historico?'HISTÓRICO':curso.estado)+' · '+pago+' · CURSO '+money(curso.precio);cursoSelect.appendChild(option);});
        const wanted=preseleccionar||cursoSolicitado();
        if(wanted&&[...cursoSelect.options].some(o=>o.value===wanted&&!o.disabled))cursoSelect.value=wanted;
        else{
            const unpaid=intensiveCourses.filter(c=>c.pagado!==true);
            if(unpaid.length===1)cursoSelect.value=unpaid[0].id;
        }
        actualizarCursoSeleccionado();
    }catch(error){cursoHelp.className='help history-help';cursoHelp.textContent='No se pudieron cargar los cursos. Recarga la página y vuelve a intentarlo.';console.error(error);}
    finally{cursoSelect.disabled=false;}
}

function actualizarCursoSeleccionado(){
    if(tipoSelect.value!=='INTENSIVO')return;
    const selected=cursoSelect.selectedOptions[0];
    if(selected?.dataset.balance)importeInput.value=selected.dataset.balance;else if(selected?.dataset.price)importeInput.value=selected.dataset.price;
    if(selected?.dataset.historico==='1'){
        cursoHelp.className='help history-help';
        cursoHelp.textContent='Corrección histórica de ADMIN: este pago se registrará en el curso seleccionado sin reabrirlo ni activar al alumno por una fecha pasada.';
    }else{
        cursoHelp.className='help';
        cursoHelp.textContent='El pago quedará vinculado al curso intensivo seleccionado.';
    }
    document.dispatchEvent(new CustomEvent('hache:intensivo-seleccionado',{detail:{cursoId:cursoSelect.value||''}}));
}

async function actualizarTipo(preseleccionarCurso=''){
    const intensive=tipoSelect.value==='INTENSIVO';
    cursoGroup.classList.toggle('hidden',!intensive);cursoSelect.required=intensive;
    if(!intensive){cursoSelect.value='';intensiveCourses=[];}
    if(tipoSelect.value==='INSCRIPCION')importeInput.value='500';
    else if(tipoSelect.value==='MENSUALIDAD')importeInput.value=alumnoSelect.selectedOptions[0]?.dataset.planPrecio||'';
    else if(intensive){importeInput.value='1200';await cargarCursosIntensivosAlumno(preseleccionarCurso);}
}

function abrirModal(){ocultarMensaje(formMessage);formPago.reset();cursoGroup.classList.add('hidden');cursoSelect.required=false;intensiveCourses=[];establecerFechaActual();modal.style.display='flex';}
function cerrarModal(){modal.style.display='none';}

async function aplicarPreset(){
    const alumno=query.get('alumno_id')||'';const tipo=(query.get('tipo')||'').toUpperCase();const curso=cursoSolicitado();
    if(!alumno&&!tipo&&!curso)return;
    abrirModal();
    if(alumno&&[...alumnoSelect.options].some(o=>o.value===alumno))alumnoSelect.value=alumno;
    if(['INSCRIPCION','MENSUALIDAD','INTENSIVO'].includes(tipo))tipoSelect.value=tipo;
    await actualizarTipo(curso);
}

btnNuevoPago.addEventListener('click',abrirModal);btnCerrarModal.addEventListener('click',cerrarModal);btnCancelar.addEventListener('click',cerrarModal);modal.addEventListener('click',e=>{if(e.target===modal)cerrarModal();});
tipoSelect.addEventListener('change',()=>actualizarTipo());
alumnoSelect.addEventListener('change',async()=>{if(tipoSelect.value==='MENSUALIDAD')importeInput.value=alumnoSelect.selectedOptions[0]?.dataset.planPrecio||'';if(tipoSelect.value==='INTENSIVO')await cargarCursosIntensivosAlumno();});
cursoSelect.addEventListener('change',actualizarCursoSeleccionado);

formPago.addEventListener('submit',async e=>{
    e.preventDefault();ocultarMensaje(formMessage);
    const alumnoId=alumnoSelect.value,tipo=tipoSelect.value,importe=importeInput.value,metodo=metodoSelect.value,fecha=fechaInput.value,observacion=document.getElementById('observacion').value.trim(),cursoId=cursoSelect.value;
    if(!alumnoId||!tipo||!importe||!metodo||!fecha||(tipo==='INTENSIVO'&&!cursoId)){mostrarMensaje(formMessage,'Completa todos los campos obligatorios.','error');return;}
    const datos={alumno_id:alumnoId,tipo,importe,metodo,fecha:fecha.replace('T',' ')+':00',observacion:observacion||null};
    if(tipo==='INTENSIVO')datos.curso_intensivo_id=cursoId;
    try{
        const response=await fetch('/api/pagos.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(datos)});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'No se pudo registrar el pago');
        const estado=estadoNotificacionPago(data);mostrarMensaje(message,estado.texto,estado.tipo);cerrarModal();await cargarPagos();
    }catch(error){mostrarMensaje(formMessage,error.message||'Error al registrar el pago.','error');}
});

establecerFechaActual();cargarPagos();cargarAlumnos();
</script>
</body>
</html>