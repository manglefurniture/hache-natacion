(()=>{
  if(!('serviceWorker' in navigator)) return;
  window.addEventListener('load',async()=>{
    try{
      const registration=await navigator.serviceWorker.register('/service-worker.js',{updateViaCache:'none'});
      const key='hache_sw_last_update_check';
      const now=Date.now();
      const last=Number(localStorage.getItem(key)||0);
      if(!last||now-last>12*60*60*1000){
        localStorage.setItem(key,String(now));
        registration.update().catch(()=>{});
      }
    }catch(error){
      console.warn('No se pudo registrar la PWA de Hache:',error);
    }
  });
})();
