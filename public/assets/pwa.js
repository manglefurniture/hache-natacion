(()=>{
  if(!('serviceWorker' in navigator)) return;
  window.addEventListener('load',()=>{
    navigator.serviceWorker.register('/service-worker.js').catch(error=>{
      console.warn('No se pudo registrar la PWA de Hache:',error);
    });
  });
})();
