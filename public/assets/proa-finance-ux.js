(function(){
'use strict';
function cleanEmbeddedShell(frame){
  let d,w;
  try{d=frame.contentDocument;w=frame.contentWindow;}catch(e){return;}
  if(!d||!w)return;
  const candidates=[...d.querySelectorAll('button,a,[role="button"],div')];
  candidates.forEach(el=>{
    let cs,r;
    try{cs=w.getComputedStyle(el);r=el.getBoundingClientRect();}catch(e){return;}
    const label=((el.getAttribute('aria-label')||'')+' '+(el.getAttribute('title')||'')+' '+(el.textContent||'')).toLowerCase();
    const menuLike=/menú|menu|navegaci/.test(label);
    const geometric=(cs.position==='fixed'||cs.position==='absolute')&&r.left>=0&&r.left<120&&r.top>=0&&r.top<190&&r.width>=40&&r.width<=115&&r.height>=40&&r.height<=115;
    const bars=el.querySelectorAll(':scope > span').length>=3;
    if(menuLike||(geometric&&bars))el.style.setProperty('display','none','important');
  });
  if(!d.getElementById('finance-embedded-shell-style')){
    const s=d.createElement('style');
    s.id='finance-embedded-shell-style';
    s.textContent=`
      body{padding-top:0!important}
      .wrap{padding-top:20px!important}
      .menu-btn,.menu-button,.hamburger,.nav-toggle,.sidebar-toggle,[aria-label*="menú" i],[aria-label*="menu" i]{display:none!important}
      @media(max-width:760px){.wrap{padding-top:18px!important}}
    `;
    d.head.appendChild(s);
  }
}
function enhanceProa(frame){
  let d,w;
  try{d=frame.contentDocument;w=frame.contentWindow;}catch(e){return;}
  if(!d)return;
  cleanEmbeddedShell(frame);
  if(d.getElementById('proa-quick-entry-style'))return;
  const panels=[...d.querySelectorAll('section.panel')];
  const entry=panels.find(p=>p.querySelector('h2')?.textContent.includes('Registrar conciliación'));
  const matrix=panels.find(p=>p.querySelector('h2')?.textContent.includes('Cómo se forma la obligación'));
  const cards=d.querySelector('.cards');
  if(!entry||!cards)return;
  cards.insertAdjacentElement('afterend',entry);
  entry.classList.add('quick-entry','collapsed');
  const head=entry.querySelector('.section-head');
  const form=entry.querySelector('.form');
  const msg=entry.querySelector('.msg');
  if(head){
    head.insertAdjacentHTML('beforeend','<button type="button" class="entry-toggle" aria-expanded="false"><span>＋</span> Agregar movimiento</button>');
    head.addEventListener('click',function(e){if(e.target.closest('.entry-toggle')||e.target===head||e.target.closest('h2'))toggle();});
  }
  function toggle(force){
    const open=typeof force==='boolean'?force:entry.classList.contains('collapsed');
    entry.classList.toggle('collapsed',!open);
    const b=entry.querySelector('.entry-toggle');
    if(b){b.setAttribute('aria-expanded',open?'true':'false');b.innerHTML=open?'<span>−</span> Cerrar':'<span>＋</span> Agregar movimiento';}
    if(open)setTimeout(()=>entry.scrollIntoView({behavior:'smooth',block:'start'}),40);
  }
  const style=d.createElement('style');style.id='proa-quick-entry-style';style.textContent=`
    .quick-entry{position:sticky;top:8px;z-index:20;border-color:#bfd3e3!important;box-shadow:0 12px 30px rgba(18,59,93,.12)!important;transition:.2s ease;background:#fff!important}
    .quick-entry .section-head{margin-bottom:12px;cursor:pointer;align-items:center}
    .quick-entry .entry-toggle{border:0;background:#123b5d;color:#fff;border-radius:10px;padding:10px 12px;font-weight:850;cursor:pointer;white-space:nowrap}
    .quick-entry.collapsed{padding:12px 16px!important}
    .quick-entry.collapsed .section-head{margin-bottom:0!important}
    .quick-entry.collapsed .form,.quick-entry.collapsed .msg{display:none!important}
    @media(max-width:520px){.quick-entry{top:6px}.quick-entry .section-head{align-items:center!important;flex-direction:row!important}.quick-entry .section-head h2{font-size:16px!important}.quick-entry .entry-toggle{padding:9px 10px;font-size:12px}.quick-entry:not(.collapsed){position:relative;top:auto}.quick-entry:not(.collapsed) .section-head{align-items:flex-start!important;flex-direction:column!important;width:100%}.quick-entry:not(.collapsed) .entry-toggle{width:100%}}
  `;d.head.appendChild(style);
  let lastY=0;w.addEventListener('scroll',function(){const y=w.scrollY||0;if(y>lastY+35&&y>220&&!entry.classList.contains('collapsed'))toggle(false);lastY=y;},{passive:true});
  if(form)form.addEventListener('focusin',()=>{entry.dataset.editing='1'});
  if(matrix&&entry.nextElementSibling!==matrix)entry.insertAdjacentElement('afterend',matrix);
}
function process(frame){
  cleanEmbeddedShell(frame);
  setTimeout(()=>cleanEmbeddedShell(frame),150);
  setTimeout(()=>cleanEmbeddedShell(frame),700);
  if(frame.classList.contains('proa-frame'))enhanceProa(frame);
}
function wire(){document.querySelectorAll('iframe.frame').forEach(f=>{f.addEventListener('load',()=>process(f));if(f.contentDocument?.readyState==='complete')process(f);});}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',wire);else wire();
})();
