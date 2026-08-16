(function(){
  function prepararTabla(table){
    if(table.dataset.hacheResponsive==='1') return;
    const headers=[...table.querySelectorAll('thead th')].map(th=>th.textContent.trim());
    if(!headers.length) return;
    table.dataset.hacheResponsive='1';
    table.classList.add('hache-responsive-table');
    table.querySelectorAll('tbody tr').forEach(tr=>{
      [...tr.children].forEach((td,i)=>{
        if(td.tagName!=='TD') return;
        td.dataset.label=headers[i]||'';
      });
    });
  }

  function preparar(root=document){
    root.querySelectorAll?.('table').forEach(prepararTabla);
  }

  preparar();
  const obs=new MutationObserver(muts=>{
    muts.forEach(m=>m.addedNodes.forEach(n=>{
      if(n.nodeType!==1) return;
      if(n.matches?.('table')) prepararTabla(n);
      preparar(n);
    }));
  });
  obs.observe(document.body,{childList:true,subtree:true});
})();