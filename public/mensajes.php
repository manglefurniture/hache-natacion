<?php
declare(strict_types=1);

require_once __DIR__.'/../config/auth.php';
$me=page_require(['ADMIN','VERIFICADOR']);
$admin=$me['rol']==='ADMIN';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Mensajes — Hache Natación</title>
  <style>
    *{box-sizing:border-box}
    body{margin:0;background:#f3f6fa;font-family:Manrope,Arial,sans-serif;color:#172033}
    .wrap{max-width:920px;margin:auto;padding:82px 14px 32px}
    h1{margin:0 0 5px}
    .sub{color:#64748b;margin-bottom:18px}
    .panel,.msg{background:#fff;border-radius:15px;padding:15px;margin-bottom:10px}
    .form{display:grid;grid-template-columns:1fr 1fr;gap:8px}
    .form input,.form select,.form textarea{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:9px;font:inherit}
    .form textarea{grid-column:1/-1;min-height:90px}
    .btn{border:0;border-radius:9px;padding:10px 12px;font-weight:800;cursor:pointer;background:#123b5d;color:#fff}
    .msg.inactive{opacity:.55}
    .meta{font-size:11px;color:#64748b;margin-top:5px}
    .title{font-weight:900}
    .body{margin-top:7px;white-space:pre-wrap}
    .error{color:#991b1b;background:#fee2e2}
    @media(max-width:650px){.form{grid-template-columns:1fr}.form textarea{grid-column:auto}}
  </style>
</head>
<body>
<main class="wrap">
  <h1>Mensajes</h1>
  <div id="sub" class="sub">Avisos visibles para alumnos de la sede activa</div>
  <?php if($admin): ?>
  <div class="panel">
    <div class="form">
      <input id="titulo" maxlength="160" placeholder="Título" required>
      <select id="aud">
        <option value="TODOS">Todos</option>
        <option value="REGULARES">Regulares</option>
        <option value="INTENSIVOS">Intensivos</option>
        <option value="ALUMNO">Alumno específico</option>
      </select>
      <select id="alumno" disabled><option value="">Alumno...</option></select>
      <input id="desde" type="date">
      <input id="hasta" type="date">
      <textarea id="cuerpo" maxlength="5000" placeholder="Mensaje" required></textarea>
      <button id="crear" class="btn" type="button">Publicar mensaje</button>
    </div>
  </div>
  <?php endif; ?>
  <div id="lista"></div>
</main>
<script>
const admin=<?=json_encode($admin)?>;
const lista=document.getElementById('lista');
const esc=value=>String(value??'').replace(/[&<>'"]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));

async function jsonRequest(url,options={}){
  const response=await fetch(url,options);
  const data=await response.json().catch(()=>({}));
  if(!response.ok||!data.ok)throw new Error(data.error||'No se pudo completar la solicitud');
  return data;
}

async function post(body){
  return jsonRequest('/api/mensajes.php',{
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify(body)
  });
}

async function load(){
  try{
    const data=await jsonRequest('/api/mensajes.php');
    document.getElementById('sub').textContent=`Avisos visibles para alumnos · ${data.sede.nombre}`;
    lista.innerHTML=data.mensajes.map(message=>{
      const active=Number(message.activo)===1;
      const audience=[message.audiencia,message.alumno_nombre,message.vigencia_desde,message.vigencia_hasta?`→ ${message.vigencia_hasta}`:''].filter(Boolean).map(esc).join(' · ');
      const action=admin?`<button class="btn" data-id="${esc(message.id)}" data-active="${active?0:1}">${active?'Desactivar':'Activar'}</button>`:'';
      return `<article class="msg ${active?'':'inactive'}"><div class="title">${esc(message.titulo)}</div><div class="body">${esc(message.cuerpo)}</div><div class="meta">${audience}</div>${action}</article>`;
    }).join('')||'<div class="panel">No hay mensajes.</div>';
    if(admin){
      lista.querySelectorAll('button[data-id]').forEach(button=>{
        button.onclick=async()=>{
          try{
            button.disabled=true;
            await post({accion:'ESTADO',id:button.dataset.id,activo:Number(button.dataset.active)});
            await load();
          }catch(error){
            alert(error.message);
            button.disabled=false;
          }
        };
      });
    }
  }catch(error){
    lista.innerHTML=`<div class="panel error">${esc(error.message)}</div>`;
  }
}

<?php if($admin): ?>
const titulo=document.getElementById('titulo');
const audiencia=document.getElementById('aud');
const alumno=document.getElementById('alumno');
const desde=document.getElementById('desde');
const hasta=document.getElementById('hasta');
const cuerpo=document.getElementById('cuerpo');
const crear=document.getElementById('crear');

audiencia.onchange=()=>{
  alumno.disabled=audiencia.value!=='ALUMNO';
  if(alumno.disabled)alumno.value='';
};

jsonRequest('/api/alumnos.php').then(data=>{
  data.alumnos.forEach(student=>{
    const option=document.createElement('option');
    option.value=student.id;
    option.textContent=student.nombre;
    alumno.appendChild(option);
  });
}).catch(error=>alert(error.message));

crear.onclick=async()=>{
  try{
    crear.disabled=true;
    await post({
      accion:'CREAR',
      titulo:titulo.value,
      cuerpo:cuerpo.value,
      audiencia:audiencia.value,
      alumno_id:alumno.value||null,
      vigencia_desde:desde.value||null,
      vigencia_hasta:hasta.value||null
    });
    titulo.value='';
    cuerpo.value='';
    await load();
  }catch(error){
    alert(error.message);
  }finally{
    crear.disabled=false;
  }
};
<?php endif; ?>

load();
</script>
</body>
</html>
