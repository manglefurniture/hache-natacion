(()=>{
  if(!('serviceWorker' in navigator)) return;
  window.addEventListener('load',async()=>{
    try{
      const registration=await navigator.serviceWorker.register('/service-worker.js',{updateViaCache:'none'});
      registration.update().catch(()=>{});
    }catch(error){
      console.warn('No se pudo registrar la PWA de Hache:',error);
    }
  });
})();
