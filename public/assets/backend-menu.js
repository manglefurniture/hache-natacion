(async function(){
const fix=document.createElement('link');fix.rel='stylesheet';fix.href='/assets/responsive-fixes.css?v=20260816-5';document.head.appendChild(fix);
const path=location.pathname;let data={};
try{const r=await fetch('/api/sesion.php',{credentials:'same-origin'}),d=await r.json();if(d.autenticado&&d.usuario){data=d.usuario;sessionStorage.setItem('hache_usuario',JSON.stringify(data));}}catch(e){try{data=JSON.parse(sessionStorage.getItem('hache_usuario')||'{}')}catch(_){}}
const role=data.rol||'',user=data.nombre||data.usuario||'';
if(!role){location.href='/';return}
if(data.debe_cambiar_password&&path!=='/cambiar-password.php'){location.href='/cambiar-password.php';return}
if(role==='ALUMNO'){if(path!=='/mi-cuenta.php'&&path!=='/cambiar-password.php')location.href='/mi-cuenta.php';return}

const groups=[
 {id:'inicio',label:'Inicio',icon:'⌂',items:[['/dashboard.php','⌂','Dashboard']]},
 {id:'alumnos',label:'Alumnos',icon:'👥',items:[['/alumnos.php','👥','Control de alumnos'],['/agregar-alumno.php','➕','Nuevo alumno','ADMIN']]},
 {id:'operacion',label:'Operación',icon:'✓',items:[['/sesiones.php','✓','Asistencia'],['/ausencias.php','↺','Ausencias y reposiciones'],['/horarios.php','🕒','Horarios'],['/intensivos.php','🏊','Cursos intensivos']]},
 {id:'finanzas',label:'Finanzas',icon:'💳',items:[['/pagos.php','💳','Pagos'],['/resumen-financiero.php','📊','Resumen financiero'],['/reportes.php','▤','Reportes']]},
 {id:'sistema',label:'Sistema',icon:'⚙',items:[['/mensajes.php','✉','Mensajes'],['/configuracion.php','⚙','Configuración'],['/usuarios.php','♟','Usuarios','ADMIN']]}
];

function itemPermitido(x){return !x[3]||x[3]===role}
function grupoHtml(g){
 const items=g.items.filter(itemPermitido);if(!items.length)return'';
 const active=items.some(x=>x[0]===path);const saved=sessionStorage.getItem('hache_menu_group');const open=active||saved===g.id||g.id==='inicio';
 const links=items.map(([href,icon,label])=>`<a class="hache-menu-link ${path===href?'is-active':''}" href="${href}"><span class="hache-menu-icon">${icon}</span><span>${label}</span></a>`).join('');
 return `<section class="hache-menu-group ${open?'is-open':''}" data-group="${g.id}"><button class="hache-menu-group-toggle" type="button" aria-expanded="${open?'true':'false'}"><span class="hache-menu-group-title"><span class="hache-menu-icon">${g.icon}</span><span>${g.label}</span></span><span class="hache-menu-chevron">⌄</span></button><div class="hache-menu-group-items">${links}</div></section>`;
}
const navHtml=groups.map(grupoHtml).join('');
document.body.insertAdjacentHTML('beforeend',`<button id="hache-menu-toggle" type="button" aria-label="Abrir menú" aria-expanded="false">☰</button><div id="hache-menu-overlay"></div><aside id="hache-menu-panel"><div class="hache-menu-head"><div class="hache-menu-brand">Hache Natación</div><div class="hache-menu-sub">${role==='VERIFICADOR'?'Modo consulta':'Panel administrativo'}</div><div class="hache-menu-user">${user}${user&&role?' · ':''}${role}</div></div><nav class="hache-menu-nav">${navHtml}</nav><div class="hache-menu-footer"><a class="hache-menu-link hache-menu-password" href="/cambiar-password.php"><span class="hache-menu-icon">🔑</span><span>Cambiar contraseña</span></a><button id="hache-menu-logout" class="hache-menu-logout" type="button">↪ Cerrar sesión</button></div></aside>`);

const toggle=document.getElementById('hache-menu-toggle'),overlay=document.getElementById('hache-menu-overlay');
const close=()=>{document.body.classList.remove('hache-menu-open');toggle.setAttribute('aria-expanded','false')};
toggle.onclick=()=>{const o=document.body.classList.toggle('hache-menu-open');toggle.setAttribute('aria-expanded',String(o))};overlay.onclick=close;document.addEventListener('keydown',e=>{if(e.key==='Escape')close()});

document.querySelectorAll('.hache-menu-group-toggle').forEach(btn=>btn.addEventListener('click',()=>{const group=btn.closest('.hache-menu-group');const opening=!group.classList.contains('is-open');document.querySelectorAll('.hache-menu-group').forEach(g=>{if(g!==group)g.classList.remove('is-open')});group.classList.toggle('is-open',opening);btn.setAttribute('aria-expanded',String(opening));if(opening)sessionStorage.setItem('hache_menu_group',group.dataset.group||'');else sessionStorage.removeItem('hache_menu_group')}));

document.getElementById('hache-menu-logout').onclick=async()=>{try{await fetch('/api/sesion.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({accion:'LOGOUT'})})}catch(e){}sessionStorage.removeItem('hache_usuario');sessionStorage.removeItem('hache_menu_group');location.href='/'};

if(role==='VERIFICADOR'){document.documentElement.classList.add('hache-readonly');const bloquear=()=>{document.querySelectorAll('.mini-edit,.quick-pay,[data-quick-edit],[data-quick-pay],#btnNuevoPago,.btn-danger,.bloque-cancel,.cerrar,.cancelar,[data-cancel-class],[data-close],#crear,#nuevo,.primary[type="submit"]').forEach(el=>el.style.display='none')};bloquear();new MutationObserver(bloquear).observe(document.body,{childList:true,subtree:true})}
function esMovil(){return innerWidth<=900||/Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent||'')}function prepararTablas(){const mobile=esMovil();document.documentElement.classList.toggle('hache-touch-ui',mobile);document.querySelectorAll('table').forEach(table=>{const headers=[...table.querySelectorAll('thead th')].map(th=>th.textContent.trim());if(!headers.length)return;table.querySelectorAll('tbody tr').forEach(tr=>[...tr.children].forEach((td,i)=>{if(td.tagName==='TD')td.dataset.label=headers[i]||''}));table.classList.toggle('hache-responsive-table',mobile)})}prepararTablas();addEventListener('resize',prepararTablas);addEventListener('orientationchange',()=>setTimeout(prepararTablas,100));new MutationObserver(prepararTablas).observe(document.body,{childList:true,subtree:true});
const helpers={'/intensivo-detalle.php':['/assets/intensivo-flow.js'],'/pagos.php':['/assets/pagos-flow-v2.js','/assets/filtros-pagos.js'],'/ficha-alumno.php':['/assets/ficha-alumno-flow.js'],'/sesiones.php':['/assets/asistencia-laborables.js','/assets/asistencia-avisos.js'],'/alumnos.php':['/assets/alumnos-quick-plan.js','/assets/filtros-alumnos.js']};(helpers[path]||[]).forEach(src=>{const s=document.createElement('script');s.src=src;s.defer=true;document.body.appendChild(s)});
})();