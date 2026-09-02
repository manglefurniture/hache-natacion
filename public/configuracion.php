<?php
declare(strict_types=1);
require_once __DIR__.'/../config/auth.php';
$me = page_require(['ADMIN','VERIFICADOR']);
$admin = $me['rol'] === 'ADMIN';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Configuración — Hache Natación</title>
  <style>
    *{box-sizing:border-box}body{margin:0;background:#f3f6fa;font-family:Manrope,Arial,sans-serif;color:#172033}.wrap{max-width:900px;margin:auto;padding:82px 14px 32px}h1{margin:0 0 6px}.sub{color:#64748b;margin-bottom:12px}.panel{background:#fff;border-radius:15px;padding:15px;margin-bottom:12px}.row{display:grid;grid-template-columns:1fr 100px 120px 90px;gap:8px;align-items:center;padding:9px 0;border-bottom:1px solid #eef2f7}.row input{width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:8px}.btn{border:0;border-radius:9px;padding:9px 12px;font-weight:800;cursor:pointer}.primary{background:#123b5d;color:#fff}.links{display:flex;gap:8px;flex-wrap:wrap}.links a{padding:10px 12px;background:#e8eef2;color:#172033;border-radius:9px;text-decoration:none;font-weight:800}.msg{min-height:22px;margin-bottom:8px;color:#b42318;font-weight:700}@media(max-width:650px){.row{grid-template-columns:1fr 1fr}.row .name{grid-column:1/-1}}
  </style>
</head>
<body>
<main class="wrap">
  <h1>Configuración</h1>
  <div id="subtitle" class="sub">Planes y parámetros de Franky</div>
  <div id="msg" class="msg" role="status"></div>
  <div class="panel">
    <h3>Accesos de configuración</h3>
    <div class="links"><a class="hache-relation-action" href="/horarios.php">Horarios</a><?php if ($admin): ?><a class="hache-relation-action" href="/sharky-admin.php">Sharky</a><a class="hache-relation-action" href="/usuarios.php">Usuarios</a><?php endif; ?></div>
  </div>
  <div class="panel">
    <h3>Planes</h3>
    <div id="planes"></div>
    <?php if ($admin): ?><button id="nuevo" class="btn primary">+ Nuevo plan</button><?php endif; ?>
  </div>
  <div class="panel"><h3>Parámetros</h3><div id="cfg"></div></div>
</main>
<script>
const admin=<?=json_encode($admin)?>;
const P=document.getElementById('planes'),C=document.getElementById('cfg'),M=document.getElementById('msg'),S=document.getElementById('subtitle');
const esc=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
async function api(body){const r=await fetch('/api/configuracion.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}),d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'Error');return d}
function planRow(p={id:'',nombre:'',sesiones_semana:3,precio:0,activo:1}){
  const e=document.createElement('div');e.className='row';
  e.innerHTML=`<input class="name" maxlength="100" value="${esc(p.nombre)}" ${admin?'':'disabled'}><input type="number" min="1" max="7" value="${Number(p.sesiones_semana)||1}" ${admin?'':'disabled'}><input type="number" min="0" max="100000" step="0.01" value="${Number(p.precio)||0}" ${admin?'':'disabled'}><label><input type="checkbox" ${Number(p.activo)?'checked':''} ${admin?'':'disabled'}> Activo</label>${admin?'<button class="btn primary">Guardar</button>':''}`;
  if(admin)e.querySelector('button').onclick=async()=>{const i=e.querySelectorAll('input');try{await api({accion:'PLAN',id:p.id,nombre:i[0].value,sesiones_semana:i[1].value,precio:i[2].value,activo:i[3].checked});M.textContent='Plan guardado.';await load()}catch(err){M.textContent=err.message||'No se pudo guardar.'}};
  return e;
}
async function load(){
  try{
    const r=await fetch('/api/configuracion.php',{cache:'no-store'}),d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'No se pudo cargar.');
    S.textContent=`Planes de ${d.sede.nombre} · parámetros generales de Franky`;
    P.replaceChildren(...d.planes.map(planRow));
    C.innerHTML=d.configuracion.map(x=>`<div class="row"><strong class="name">${esc(x.clave)}<small style="display:block;color:#64748b">${esc(x.descripcion||'')}</small></strong><input style="grid-column:span 2" maxlength="500" data-key="${esc(x.clave)}" value="${esc(x.valor||'')}" ${admin?'':'disabled'}>${admin?'<button class="btn primary">Guardar</button>':''}</div>`).join('');
    if(admin)C.querySelectorAll('button').forEach(b=>b.onclick=async()=>{const i=b.parentElement.querySelector('input');try{await api({accion:'CONFIG',clave:i.dataset.key,valor:i.value});M.textContent='Parámetro guardado.';await load()}catch(err){M.textContent=err.message||'No se pudo guardar.'}});
  }catch(err){M.textContent=err.message||'No se pudo cargar la configuración.'}
}
<?php if ($admin): ?>document.getElementById('nuevo').onclick=()=>P.appendChild(planRow());<?php endif; ?>
load();
</script>
</body>
</html>
