(function(){
if(location.pathname!=='/pagos.php') return;

const style=document.createElement('style');
style.textContent=`
.hache-filters{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin:0 0 16px;padding:12px;background:#fff;border:1px solid #e5eaf0;border-radius:14px}
.hache-filter label{display:block;margin-bottom:5px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#64748b}
.hache-filter input,.hache-filter select{width:100%;min-width:0;border:1px solid #cbd5e1;border-radius:9px;background:#fff;padding:9px 10px;font-size:13px}
.hache-filter-count{grid-column:1/-1;font-size:12px;color:#64748b}
@media(max-width:760px){.hache-filters{grid-template-columns:1fr 1fr;padding:10px}.hache-filter-periodo,.hache-filter-alumno{grid-column:1/-1}.hache-filter input,.hache-filter select{font-size:16px}}
`;
document.head.appendChild(style);

const top=document.querySelector('.top-bar');
if(!top) return;
const box=document.createElement('div');box.className='hache-filters';box.innerHTML=`
<div class="hache-filter hache-filter-periodo"><label>Periodo</label><input id="fp-periodo" type="month"></div>
<div class="hache-filter hache-filter-alumno"><label>Alumno</label><select id="fp-alumno"><option value="">Todos</option></select></div>
<div class="hache-filter"><label>Tipo</label><select id="fp-tipo"><option value="">Todos</option><option value="Inscripción">Inscripción</option><option value="Mensualidad">Mensualidad</option><option value="Curso intensivo">Curso intensivo</option></select></div>
<div class="hache-filter"><label>Ordenar por</label><select id="fp-orden"><option value="fecha">Fecha</option><option value="alumno">Alumno</option><option value="importe">Importe</option></select></div>
<div class="hache-filter"><label>Orden</label><select id="fp-dir"><option value="desc">Descendente</option><option value="asc">Ascendente</option></select></div>
<div class="hache-filter-count" id="fp-count"></div>`;
top.insertAdjacentElement('afterend',box);

const periodo=box.querySelector('#fp-periodo'),alumno=box.querySelector('#fp-alumno'),tipo=box.querySelector('#fp-tipo'),orden=box.querySelector('#fp-orden'),dir=box.querySelector('#fp-dir'),count=box.querySelector('#fp-count');
const now=new Date();periodo.value=`${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}`;

function parseFecha(text){const d=new Date(text);return isNaN(d)?null:d;}
function parseImporte(text){return Number(String(text).replace(/[^0-9.-]/g,''))||0;}
function filas(){return [...document.querySelectorAll('#pagosBody tr')].filter(r=>r.children.length>=7&&!r.querySelector('.empty'));}
function llenarAlumnos(rows){const actual=alumno.value;const vals=[...new Set(rows.map(r=>r.children[1]?.textContent.trim()).filter(Boolean))].sort((a,b)=>a.localeCompare(b,'es',{sensitivity:'base'}));alumno.innerHTML='<option value="">Todos</option>'+vals.map(v=>`<option>${v.replace(/&/g,'&amp;').replace(/</g,'&lt;')}</option>`).join('');if(vals.includes(actual))alumno.value=actual;}
function aplicar(){const rows=filas();if(!rows.length){count.textContent='';return;}llenarAlumnos(rows);const p=periodo.value,a=alumno.value,t=tipo.value;
let visibles=rows.filter(r=>{const fecha=parseFecha(r.children[5]?.textContent.trim()||'');const ym=fecha?`${fecha.getFullYear()}-${String(fecha.getMonth()+1).padStart(2,'0')}`:'';const okP=!p||ym===p;const okA=!a||r.children[1]?.textContent.trim()===a;const okT=!t||r.children[2]?.textContent.trim()===t;return okP&&okA&&okT;});
rows.forEach(r=>r.style.display=visibles.includes(r)?'':'none');
const factor=dir.value==='asc'?1:-1;visibles.sort((x,y)=>{let vx,vy;if(orden.value==='alumno'){vx=x.children[1]?.textContent.trim()||'';vy=y.children[1]?.textContent.trim()||'';return vx.localeCompare(vy,'es',{sensitivity:'base'})*factor;}if(orden.value==='importe'){vx=parseImporte(x.children[3]?.textContent);vy=parseImporte(y.children[3]?.textContent);return (vx-vy)*factor;}vx=parseFecha(x.children[5]?.textContent.trim()||'')?.getTime()||0;vy=parseFecha(y.children[5]?.textContent.trim()||'')?.getTime()||0;return (vx-vy)*factor;});
const tbody=document.getElementById('pagosBody');visibles.forEach(r=>tbody.appendChild(r));count.textContent=`${visibles.length} pago${visibles.length===1?'':'s'} mostrado${visibles.length===1?'':'s'}`;
}
box.addEventListener('input',aplicar);box.addEventListener('change',aplicar);
let timer;new MutationObserver(()=>{clearTimeout(timer);timer=setTimeout(aplicar,80)}).observe(document.getElementById('pagosBody'),{childList:true,subtree:true});
setTimeout(aplicar,300);
})();