(function(){
  const mobile=window.matchMedia('(max-width: 750px)');

  function headers(table){
    return [...table.querySelectorAll('thead th')].map(th=>th.textContent.trim());
  }

  function prepararTabla(table){
    const labels=headers(table);
    if(!labels.length) return;
    table.querySelectorAll('tbody tr').forEach(tr=>{
      [...tr.children].forEach((td,i)=>{
        if(td.tagName==='TD') td.dataset.label=labels[i]||'';
      });
    });
    table.dataset.hacheResponsive='1';
    table.classList.toggle('hache-responsive-table',mobile.matches);
  }

  function preparar(root=document){
    root.querySelectorAll?.('table').forEach(prepararTabla);
  }

  preparar();
  mobile.addEventListener?.('change',()=>preparar());

  const obs=new MutationObserver(muts=>{
    muts.forEach(m=>m.addedNodes.forEach(n=>{
      if(n.nodeType!==1) return;
      if(n.matches?.('table')) prepararTabla(n);
      preparar(n);
    }));
  });
  obs.observe(document.body,{childList:true,subtree:true});
})();