(function(){
const path=location.pathname;
if(path==='/alumnos.php') return; // Alumnos tiene búsqueda integrada con pestañas y filtros propios.

const configs={
  '/pagos.php':{
    anchor:'.top-bar',
    items:'#pagosBody tr',
    name:item=>item.children?.[1]?.textContent||''
  },
  '/sesiones.php':{
    anchor:'.fecha',
    items:'#lista .alumno',
    name:item=>item.querySelector('.nombre')?.childNodes?.[0]?.textContent||item.querySelector('.nombre')?.textContent||''
  },
  '/ausencias.php':{
    anchor:'.sub',
    items:'#avisos .row, #repos .repo',
    name:item=>item.querySelector('.name')?.textContent||item.querySelector('span')?.textContent||''
  },
  '/intensivo-detalle.php':{
    anchor:'.top-bar',
    items:'#alumnosBody tr',
    name:item=>item.children?.[0]?.textContent||''
  },
  '/usuarios.php':{
    anchor:'h1',
    items:'tbody tr',
    name:item=>{
      const cells=[...item.children];
      const table=item.closest('table');
      const heads=table?[...table.querySelectorAll('thead th')].map(x=>norm(x.textContent)):[];
      let i=heads.findIndex(x=>x.includes('NOMBRE'));
      if(i<0)i=heads.findIndex(x=>x.includes('USUARIO'));
      return cells[i>=0?i:0]?.textContent||'';
    }
  },
  '/comisiones-proa.php':{
    anchor:'.head, h1',
    items:'tbody tr',
    name:item=>{
      const cells=[...item.children];
      const table=item.closest('table');
      const heads=table?[...table.querySelectorAll('thead th')].map(x=>norm(x.textContent)):[];
      let i=heads.findIndex(x=>x.includes('ALUMNO')||x.includes('NOMBRE'));
      return cells[i>=0?i:0]?.textContent||'';
    }
  }
};
const cfg=configs[path];if(!cfg)return;

function norm(s){return String(s||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/\s+/g,' ').trim().toUpperCase()}
function cleanName(s){return String(s||'').replace(/\s+/g,' ').trim()}
function escapeHtml(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
function validName(s){const n=norm(s);return n&&n!=='CARGANDO'&&!n.startsWith('CARGANDO ')&&!n.startsWith('NO HAY ')&&!n.startsWith('SIN ')&&!n.startsWith('ERROR ')}

const style=document.createElement('style');style.textContent=`
.hache-person-search{position:relative;margin:10px 0 16px;z-index:40}.hache-person-box{display:grid;grid-template-columns:34px 1fr auto;align-items:center;background:#fff;border:1px solid #dce3eb;border-radius:13px;box-shadow:0 4px 16px rgba(15,23,42,.04);overflow:visible}.hache-person-icon{display:grid;place-items:center;color:#64748b;font-size:16px}.hache-person-input{width:100%;min-width:0;border:0;outline:0;background:transparent;padding:11px 4px;font:inherit;font-size:14px;color:#172033}.hache-person-input::placeholder{color:#94a3b8}.hache-person-clear{display:none;border:0;background:transparent;color:#64748b;font-size:20px;padding:8px 13px;cursor:pointer}.hache-person-search.has-value .hache-person-clear{display:block}.hache-person-suggest{display:none;position:absolute;left:0;right:0;top:calc(100% + 6px);max-height:280px;overflow:auto;background:#fff;border:1px solid #dce3eb;border-radius:13px;box-shadow:0 16px 35px rgba(15,23,42,.15);padding:5px}.hache-person-suggest.open{display:block}.hache-person-option{display:block;width:100%;border:0;background:#fff;text-align:left;padding:10px 11px;border-radius:9px;font:inherit;font-size:13px;color:#172033;cursor:pointer}.hache-person-option:hover,.hache-person-option.active{background:#eef3f7}.hache-person-empty{padding:12px;color:#64748b;font-size:12px;text-align:center}.hache-person-hidden{display:none!important}.hache-person-chip{display:none;margin-top:7px;width:max-content;max-width:100%;border:0;border-radius:999px;padding:7px 10px;background:#e8eef2;color:#334155;font-size:11px;font-weight:850;cursor:pointer}.hache-person-search.selected .hache-person-chip{display:block}@media(max-width:760px){.hache-person-search{margin:10px 0 14px}.hache-person-input{font-size:16px;padding:12px 4px}.hache-person-suggest{max-height:45vh}}
`;document.head.appendChild(style);

const anchor=document.querySelector(cfg.anchor);if(!anchor)return;
const shell=document.createElement('div');shell.className='hache-person-search';shell.innerHTML=`<div class="hache-person-box"><span class="hache-person-icon">⌕</span><input class="hache-person-input" type="search" autocomplete="off" placeholder="Buscar persona…" aria-label="Buscar persona"><button class="hache-person-clear" type="button" aria-label="Limpiar búsqueda">×</button></div><div class="hache-person-suggest"></div><button class="hache-person-chip" type="button"></button>`;
anchor.insertAdjacentElement('afterend',shell);
const input=shell.querySelector('.hache-person-input'),clear=shell.querySelector('.hache-person-clear'),suggest=shell.querySelector('.hache-person-suggest'),chip=shell.querySelector('.hache-person-chip');
let selected='';let activeIndex=-1;let options=[];

function getItems(){return [...document.querySelectorAll(cfg.items)].filter(x=>validName(cfg.name(x)))}
function names(){const map=new Map();getItems().forEach(item=>{const n=cleanName(cfg.name(item));const k=norm(n);if(k&&!map.has(k))map.set(k,n)});return [...map.values()].sort((a,b)=>a.localeCompare(b,'es',{sensitivity:'base'}))}
function apply(){const key=norm(selected);getItems().forEach(item=>item.classList.toggle('hache-person-hidden',!!key&&norm(cfg.name(item))!==key));shell.classList.toggle('selected',!!selected);chip.textContent=selected?'Persona: '+selected+' ×':'';}
function reset(){selected='';input.value='';shell.classList.remove('has-value','selected');suggest.classList.remove('open');suggest.innerHTML='';getItems().forEach(x=>x.classList.remove('hache-person-hidden'));chip.textContent='';}
function choose(name){selected=cleanName(name);input.value=selected;shell.classList.add('has-value','selected');suggest.classList.remove('open');apply();input.blur();}
function render(){const q=norm(input.value);shell.classList.toggle('has-value',!!input.value);if(!q){suggest.classList.remove('open');suggest.innerHTML='';return}options=names().filter(n=>norm(n).includes(q)).slice(0,10);activeIndex=-1;suggest.innerHTML=options.length?options.map((n,i)=>`<button type="button" class="hache-person-option" data-i="${i}">${escapeHtml(n)}</button>`).join(''):'<div class="hache-person-empty">Sin coincidencias</div>';suggest.classList.add('open')}
input.addEventListener('input',()=>{if(selected&&norm(input.value)!==norm(selected)){selected='';shell.classList.remove('selected');getItems().forEach(x=>x.classList.remove('hache-person-hidden'))}render()});
input.addEventListener('keydown',e=>{const btns=[...suggest.querySelectorAll('.hache-person-option')];if(e.key==='ArrowDown'&&btns.length){e.preventDefault();activeIndex=(activeIndex+1)%btns.length}else if(e.key==='ArrowUp'&&btns.length){e.preventDefault();activeIndex=(activeIndex-1+btns.length)%btns.length}else if(e.key==='Enter'&&btns.length){e.preventDefault();choose(options[activeIndex>=0?activeIndex:0]);return}else if(e.key==='Escape'){suggest.classList.remove('open');return}else{return}btns.forEach((b,i)=>b.classList.toggle('active',i===activeIndex));btns[activeIndex]?.scrollIntoView({block:'nearest'})});
suggest.addEventListener('click',e=>{const b=e.target.closest('.hache-person-option');if(!b)return;choose(options[Number(b.dataset.i)]||b.textContent)});
clear.onclick=reset;chip.onclick=reset;document.addEventListener('click',e=>{if(!shell.contains(e.target))suggest.classList.remove('open')});

let timer;new MutationObserver(()=>{clearTimeout(timer);timer=setTimeout(()=>{if(selected)apply();else if(document.activeElement===input&&input.value)render()},80)}).observe(document.body,{childList:true,subtree:true});
})();