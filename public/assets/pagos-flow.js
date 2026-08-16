(function(){
    if (window.location.pathname !== '/pagos.php') return;

    const params = new URLSearchParams(window.location.search);
    const alumnoId = params.get('alumno_id');
    const tipo = params.get('tipo');
    const cursoId = params.get('curso_id');
    if (!alumnoId && !tipo && !cursoId) return;

    const originalFetch = window.fetch.bind(window);
    window.fetch = async function(input, init){
        const url = typeof input === 'string' ? input : (input && input.url) || '';
        if (cursoId && url.includes('/api/pagos.php') && init && String(init.method || 'GET').toUpperCase() === 'POST' && init.body) {
            try { const body=JSON.parse(init.body); if(body.tipo==='INTENSIVO'){body.curso_intensivo_id=cursoId;init=Object.assign({},init,{body:JSON.stringify(body)});} } catch(e){}
        }
        return originalFetch(input,init);
    };

    function aplicarPrefill(){
        const alumno=document.getElementById('alumno_id');
        const tipoSelect=document.getElementById('tipo');
        const importe=document.getElementById('importe');
        const modal=document.getElementById('modalPago');
        if(!alumno||!tipoSelect||!modal)return false;
        if(alumnoId)alumno.value=alumnoId;
        if(tipo)tipoSelect.value=tipo;
        if(alumnoId&&alumno.value!==alumnoId)return false;

        // Dispara la lógica nativa de la pantalla para cargar el precio del plan.
        alumno.dispatchEvent(new Event('change',{bubbles:true}));
        tipoSelect.dispatchEvent(new Event('change',{bubbles:true}));
        if(tipo==='INTENSIVO'&&importe&&!importe.value)importe.value='1200';
        modal.style.display='flex';
        return true;
    }

    let intentos=0; const timer=setInterval(function(){intentos++;if(aplicarPrefill()||intentos>50)clearInterval(timer);},120);
})();
