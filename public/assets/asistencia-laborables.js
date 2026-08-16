(function(){
    if (window.location.pathname !== '/sesiones.php') return;
    function siguienteLaborable(){
        const d=new Date();
        const day=d.getDay();
        if(day===6)d.setDate(d.getDate()+2);
        else if(day===0)d.setDate(d.getDate()+1);
        const y=d.getFullYear(),m=String(d.getMonth()+1).padStart(2,'0'),x=String(d.getDate()).padStart(2,'0');
        return `${y}-${m}-${x}`;
    }
    const campo=document.getElementById('fecha');
    if(campo){const v=siguienteLaborable();if(campo.value!==v){campo.value=v;campo.dispatchEvent(new Event('change'));}}
})();