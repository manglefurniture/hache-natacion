(function(){
if(location.pathname!=='/sesiones.php')return;
document.addEventListener('click',async e=>{
 const b=e.target.closest('.no-vino');if(!b)return;
 const g=b.closest('.pase');if(!g)return;
 try{
  const r=await fetch(`/api/aviso-asistencia.php?sesion_id=${encodeURIComponent(g.dataset.s)}&alumno_id=${encodeURIComponent(g.dataset.a)}`),d=await r.json();
  if(d.ok&&d.justificada){b.dataset.e='AUSENTE_JUSTIFICADA';b.title=d.aviso?.motivo?`Ausencia justificada: ${d.aviso.motivo}`:'Ausencia justificada por aviso previo';const nombre=g.closest('.alumno')?.querySelector('.nombre');if(nombre&&!nombre.querySelector('.justificada'))nombre.insertAdjacentHTML('beforeend','<span class="justificada">Aviso previo</span>');}
  else b.dataset.e='AUSENTE_NO_JUSTIFICADA';
 }catch(_){b.dataset.e='AUSENTE_NO_JUSTIFICADA';}
},true);
})();