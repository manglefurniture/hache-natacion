(function(){
if(location.pathname!=='/alumnos.php') return;

const style=document.createElement('style');
style.textContent=`
.hache-filters{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin:0 0 16px;padding:12px;background:#fff;border:1px solid #e5eaf0;border-radius:14px}
.hache-filter label{display:block;margin-bottom:5px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#64748b}
.hache-filter select{width:100%;min-width:0;border:1px solid #cbd5e1;border-radius:9px;background:#fff;padding:9px 10px;font-size:13px}
.hache-filter-count{grid-column:1/-1;font-size:12px;color:#64748b}
@media(max-width:760px){.hache-filters{grid-template-columns:1fr 1fr;padding:10px}.hache-filter select{font-size:16px}.hache-filter-horario,.hache-filter-plan,.hache-filter-estado{grid-column:span 1}}
`;
document.head.appendChild(style);

const tabs=document.querySelector('.tabs');
if(!tabs) return;
const box=document.createElement('div');box.className='hache-filters';box.innerHTML=`
<div class="hache-filter hache-filter-horario"><label>Horario</label><select id="fa-horario"><option value="">Todos</option></select></div>
<div class="hache-filter hache-filter-plan"><label>Plan</label><select id="fa-plan"><option value="">Todos</option></select></div>
<div class="hache-filter hache-filter-estado"><label>Estado</label><select id="fa-estado"><option value="">Todos</option></select></div>
<div class="hache-filter"><label>Ordenar por</label><select id="fa-orden"><option value="nombre">Nombre</option><option value="horario">Horario</option><option value="plan">Plan</option><option value="estado">Estado</option></select></div>
<div class="hache-filter"><label>Orden</label><select id="fa-dir"><option value="asc">Ascendente</option><option value="desc">Descendente</option></select></div>
<div class="hache-filter-count" id="fa-count"></div>`;
tabs.insertAdjacentElement('afterend',box);
const horario=box.querySelector('#fa-horario'),plan=box.querySelector('#fa-plan'),estado=box.querySelector('#fa-estado'),orden=box.querySelector('#fa-orden'),dir=box.querySelector('#fa-dir'),count=box.querySelector('#fa-count');

function panelActivo(){return document.querySelector('.panel.activo');}
function filas(){const p=panelActivo();return p?[...p.querySelectorAll('tbody tr')].filter(r=>r.children.length>1):[];}
function colMap(table){const heads=[...table.querySelectorAll('thead th')].map(h=>h.textContent.trim().toLowerCase());const find=(...names)=>heads.findIndex(h=>names.some(n=>h.includes(n)));return{nombre:find('alumno'),horario:find('horario'),plan:find('plan','curso'),estado:find('estado','acceso')};}
function valores(rows,idx){return [...new Set(rows.map(r=>idx>=0?r.children[idx]?.textContent.trim():'').filter(Boolean))].sort((a,b)=>a.localeCompare(b,'es',{sensitivity:'base'}));}
function setOpts(sel,vals){const cur=sel.value;sel.innerHTML='<option value="">Todos</option>'+vals.map(v=>`<option>${v.replace(/&/g,'&amp;').replace(/</g,'&lt;')}</option>`).join('');if(vals.includes(cur))sel.value=cur;}
function aplicar(){const p=panelActivo();if(!p){count.textContent='';return;}const table=p.querySelector('table');if(!table){count.textContent='0 alumnos';return;}const rows=filas(),m=colMap(table);setOpts(horario,valores(rows,m.horario));setOpts(plan,valores(rows,m.plan));setOpts(estado,valores(rows,m.estado));const fh=horario.value,fp=plan.value,fe=estado.value;
let visibles=rows.filter(r=>{const h=m.horario>=0?r.children[m.horario]?.textContent.trim()||'':'';const pl=m.plan>=0?r.children[m.plan]?.textContent.trim()||'':'';const es=m.estado>=0?r.children[m.estado]?.textContent.trim()||'':'';return(!fh||h===fh)&&(!fp||pl===fp)&&(!fe||es===fe);});rows.forEach(r=>r.style.display=visibles.includes(r)?'':'none');
const idx=m[orden.value]??m.nombre;const factor=dir.value==='asc'?1:-1;visibles.sort((a,b)=>(a.children[idx]?.textContent.trim()||'').localeCompare(b.children[idx]?.textContent.trim()||'','es',{numeric:true,sensitivity:'base'})*factor);const tbody=table.querySelector('tbody');visibles.forEach(r=>tbody.appendChild(r));count.textContent=`${visibles.length} alumno${visibles.length===1?'':'s'} mostrado${visibles.length===1?'':'s'}`;
}
box.addEventListener('change',aplicar);
document.addEventListener('click',e=>{if(e.target.closest('.tab'))setTimeout(aplicar,0)});
setTimeout(aplicar,100);
})();