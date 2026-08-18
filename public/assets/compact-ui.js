(function(){
const MOBILE=()=>innerWidth<=900||/Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent||'');
const path=location.pathname;
const skipPaths=new Set(['/','/index.php','/mi-cuenta.php','/cambiar-password.php']);
if(skipPaths.has(path))return;
const css=`
.hache-compact-toggle{display:none}
@media(max-width:900px){
  .hache-compact-panel{position:relative;overflow:hidden;transition:max-height .28s ease,box-shadow .2s ease,border-color .2s ease}
  .hache-compact-panel>.hache-compact-toggle{display:flex;width:100%;min-height:52px;align-items:center;justify-content:space-between;gap:12px;border:0;background:#fff;color:#172033;padding:13px 15px;font:inherit;font-weight:900;text-align:left;cursor:pointer;border-radius:14px}
  .hache-compact-toggle-title{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .hache-compact-toggle-meta{display:flex;align-items:center;gap:8px;color:#64748b;font-size:11px;font-weight:750;white-space:nowrap}
  .hache-compact-chevron{font-size:18px;line-height:1;transition:transform .22s ease;color:#456078}
  .hache-compact-panel.hache-compact-open>.hache-compact-toggle .hache-compact-chevron{transform:rotate(180deg)}
  .hache-compact-panel.hache-compact-ready:not(.hache-compact-open)>:not(.hache-compact-toggle){display:none!important}
  .hache-compact-panel.hache-compact-ready:not(.hache-compact-open){padding:0!important;min-height:52px!important;background:#fff!important;border:1px solid #e2e8f0!important;border-radius:14px!important;box-shadow:0 6px 20px rgba(15,23,42,.04)!important}
  .hache-compact-panel.hache-compact-open>.hache-compact-toggle{border-bottom:1px solid #edf1f5;border-radius:14px 14px 0 0;margin-bottom:10px}
  .hache-compact-panel.hache-compact-open{scroll-margin-top:78px}
}
`;
const style=document.createElement('style');style.id='hache-compact-ui-style';style.textContent=css;document.head.appendChild(style);
const protectedSelectors=['.modal','.modal-box','dialog','[role="dialog"]','#hache-menu-panel','.hache-menu-group','.hache-filter-shell','.hache-filter-suggestions','.hache-search-suggestions','.hache-proa-quick-entry','.hache-proa-entry','[data-hache-no-collapse]'];
function protectedNode(el){return protectedSelectors.some(s=>el.matches?.(s)||el.closest?.(s));}
function directHeading(el){return el.querySelector(':scope > .section-head h2,:scope > .section-head h3,:scope > .panel-head h2,:scope > .panel-head h3,:scope > .card-head h2,:scope > .card-head h3,:scope > h2,:scope > h3,:scope > header h2,:scope > header h3');}
function titleFor(el){const h=directHeading(el);if(h)return h.textContent.trim();const aria=el.getAttribute('aria-label');if(aria)return aria.trim();return'';}
function metaFor(el){const n=el.querySelectorAll('tbody tr:not(.hache-filter-hidden),.row,.item,.alerta,.slot').length;if(n>0)return n+' '+(n===1?'elemento':'elementos');const span=el.querySelector(':scope > .section-head span,:scope > .panel-head span');return span?span.textContent.trim().slice(0,32):'';}
function eligible(el){if(!MOBILE()||el.dataset.hacheCompactDone==='1'||protectedNode(el))return false;const title=titleFor(el);if(!title)return false;if(el.closest('nav,header,.tabs,.toolbar,.top-bar'))return false;if(el.classList.contains('card')&&!el.querySelector('form,table,.list,.history,.schedule,.grid,.section-head,h2,h3'))return false;const children=[...el.children].filter(x=>x.tagName!=='SCRIPT'&&x.tagName!=='STYLE');return children.length>=2;}
function setOpen(el,open,focus=false){el.classList.toggle('hache-compact-open',open);const b=el.querySelector(':scope > .hache-compact-toggle');if(b)b.setAttribute('aria-expanded',String(open));if(open&&focus)setTimeout(()=>el.scrollIntoView({behavior:'smooth',block:'start'}),40);}
function enhance(el){if(!eligible(el))return;el.dataset.hacheCompactDone='1';el.classList.add('hache-compact-panel','hache-compact-ready');const title=titleFor(el),meta=metaFor(el);const b=document.createElement('button');b.type='button';b.className='hache-compact-toggle';b.setAttribute('aria-expanded','false');b.innerHTML='<span class="hache-compact-toggle-title"></span><span class="hache-compact-toggle-meta"><span></span><span class="hache-compact-chevron">⌄</span></span>';b.querySelector('.hache-compact-toggle-title').textContent=title;b.querySelector('.hache-compact-toggle-meta span').textContent=meta;b.addEventListener('click',()=>setOpen(el,!el.classList.contains('hache-compact-open'),true));el.insertBefore(b,el.firstChild);setOpen(el,false);
}
function candidates(root=document){const selectors=['section.panel','section.section','.panel','.builder','.form-card','.formulario','.filters','.filtros','.history-card','.summary-card','.content-card','.card'];root.querySelectorAll?.(selectors.join(',')).forEach(enhance)}
let timer=0;function scan(root=document){clearTimeout(timer);timer=setTimeout(()=>candidates(root),35)}
scan();new MutationObserver(ms=>{if(!MOBILE())return;for(const m of ms){for(const n of m.addedNodes){if(n.nodeType===1){if(n.matches?.('.hache-compact-toggle'))continue;scan(n)}}}}).observe(document.body,{childList:true,subtree:true});
let lastY=scrollY,acc=0;addEventListener('scroll',()=>{if(!MOBILE())return;const y=scrollY,d=y-lastY;lastY=y;if(d>0)acc+=d;else acc=0;if(acc<85)return;acc=0;document.querySelectorAll('.hache-compact-panel.hache-compact-open').forEach(el=>{if(el.contains(document.activeElement))return;const r=el.getBoundingClientRect();if(r.top<90||r.bottom>innerHeight+120)setOpen(el,false)});},{passive:true});
addEventListener('resize',()=>{if(MOBILE())scan();else document.querySelectorAll('.hache-compact-panel').forEach(el=>el.classList.add('hache-compact-open'))});
})();