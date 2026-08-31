(function(){
  'use strict';

  const STORAGE_KEY='hache-backend-theme';
  const VALUES=new Set(['light','dark','system']);
  const root=document.documentElement;
  const systemDark=window.matchMedia?window.matchMedia('(prefers-color-scheme: dark)'):null;
  let preference='system';

  try{
    const stored=localStorage.getItem(STORAGE_KEY);
    if(VALUES.has(stored)) preference=stored;
  }catch(_){/* storage puede estar bloqueado */}

  function resolvedTheme(value){
    if(value==='light'||value==='dark') return value;
    return systemDark&&systemDark.matches?'dark':'light';
  }

  function updateButtons(){
    document.querySelectorAll('[data-hache-theme-value]').forEach(button=>{
      const active=button.dataset.hacheThemeValue===preference;
      button.setAttribute('aria-pressed',String(active));
      button.classList.toggle('is-active',active);
    });
  }

  function apply(value,{persist=true}={}){
    preference=VALUES.has(value)?value:'system';
    root.dataset.themePreference=preference;
    root.dataset.theme=resolvedTheme(preference);
    if(persist){
      try{localStorage.setItem(STORAGE_KEY,preference)}catch(_){/* preferencia válida para esta página */}
    }
    updateButtons();
    try{root.dispatchEvent(new CustomEvent('hache:theme-change',{detail:{preference,theme:root.dataset.theme}}))}catch(_){}
    return root.dataset.theme;
  }

  function mountSwitcher(){
    const footer=document.querySelector('.hache-menu-footer');
    const inlineHost=footer?null:document.querySelector('main.wrap');
    const host=footer||inlineHost;
    if(!host||document.getElementById('hache-theme-switcher')) return !!host;

    const block=document.createElement('div');
    block.id='hache-theme-switcher';
    block.className='hache-theme-switcher';
    block.innerHTML='<div class="hache-theme-label">Apariencia</div><div class="hache-theme-options" role="group" aria-label="Apariencia del backend"><button type="button" data-hache-theme-value="light" aria-pressed="false">Claro</button><button type="button" data-hache-theme-value="dark" aria-pressed="false">Oscuro</button><button type="button" data-hache-theme-value="system" aria-pressed="false">Sistema</button></div>';
    if(footer) footer.insertBefore(block,footer.firstChild);
    else{
      block.classList.add('hache-theme-switcher-inline');
      host.insertBefore(block,host.firstChild);
    }
    block.querySelectorAll('[data-hache-theme-value]').forEach(button=>button.addEventListener('click',()=>apply(button.dataset.hacheThemeValue)));
    updateButtons();
    return true;
  }

  apply(preference,{persist:false});

  if(systemDark){
    const syncSystem=()=>{if(preference==='system') apply('system',{persist:false})};
    if(typeof systemDark.addEventListener==='function') systemDark.addEventListener('change',syncSystem);
    else if(typeof systemDark.addListener==='function') systemDark.addListener(syncSystem);
  }

  const startMount=()=>{
    if(mountSwitcher()) return;
    const observer=new MutationObserver(()=>{if(mountSwitcher()) observer.disconnect()});
    observer.observe(document.body,{childList:true,subtree:true});
  };
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',startMount,{once:true});
  else startMount();

  window.HacheBackendTheme={getPreference:()=>preference,getTheme:()=>root.dataset.theme,set:apply};
})();
