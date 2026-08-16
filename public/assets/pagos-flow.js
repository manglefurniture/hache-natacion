(function(){
    if (window.location.pathname !== '/pagos.php') return;

    const params = new URLSearchParams(window.location.search);
    const alumnoId = params.get('alumno_id');
    const tipo = params.get('tipo');
    const cursoId = params.get('curso_id');

    const originalFetch = window.fetch.bind(window);
    window.fetch = async function(input, init){
        const url = typeof input === 'string' ? input : (input && input.url) || '';
        if (cursoId && url.includes('/api/pagos.php') && init && String(init.method || 'GET').toUpperCase() === 'POST' && init.body) {
            try {
                const body = JSON.parse(init.body);
                if (body.tipo === 'INTENSIVO') {
                    body.curso_intensivo_id = cursoId;
                    init = Object.assign({}, init, { body: JSON.stringify(body) });
                }
            } catch(e) {}
        }
        return originalFetch(input, init);
    };

    async function obtenerAlumno(id){
        const r = await originalFetch('/api/alumnos.php');
        const d = await r.json();
        if(!d.ok) throw new Error(d.error || 'No se pudo cargar el alumno');
        return (d.alumnos || []).find(a => a.id === id) || null;
    }

    async function obtenerPlanes(){
        const r = await originalFetch('/api/planes.php');
        const d = await r.json();
        if(!d.ok) throw new Error(d.error || 'No se pudieron cargar los planes');
        return d.planes || [];
    }

    async function sugerirPlanSiHaceFalta(){
        const alumnoSelect = document.getElementById('alumno_id');
        const tipoSelect = document.getElementById('tipo');
        const importe = document.getElementById('importe');
        if(!alumnoSelect || !tipoSelect) return;

        const id = alumnoSelect.value;
        const esMensualidad = tipoSelect.value === 'MENSUALIDAD';
        const existente = document.getElementById('hache-asignar-plan');

        if(!id || !esMensualidad){ if(existente) existente.remove(); return; }

        const alumno = await obtenerAlumno(id);
        if(!alumno || alumno.plan_actual_id){ if(existente) existente.remove(); return; }
        if(existente) return;

        const planes = await obtenerPlanes();
        const grupo = document.createElement('div');
        grupo.id = 'hache-asignar-plan';
        grupo.className = 'form-group';
        grupo.innerHTML = '<label for="hache_plan_sugerido">Este alumno no tiene plan activo. Asignar plan</label>' +
          '<select id="hache_plan_sugerido"><option value="">Seleccionar plan...</option>' +
          planes.map(p => `<option value="${p.id}" data-precio="${p.precio}">${p.nombre} · ${p.sesiones_semana} sesiones/semana · $${Number(p.precio).toLocaleString('es-MX')}</option>`).join('') +
          '</select><div style="margin-top:7px;font-size:12px;color:#64748b">El plan se asignará al alumno y se usará para esta mensualidad.</div>';

        const tipoGroup = tipoSelect.closest('.form-group');
        if(tipoGroup) tipoGroup.insertAdjacentElement('afterend', grupo);

        document.getElementById('hache_plan_sugerido').addEventListener('change', async function(){
            if(!this.value) return;
            this.disabled = true;
            try{
                const r = await originalFetch('/api/asignar-plan.php', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({alumno_id:id, plan_id:this.value})
                });
                const d = await r.json();
                if(!r.ok || !d.ok) throw new Error(d.error || 'No se pudo asignar el plan');

                const opt = alumnoSelect.selectedOptions[0];
                if(opt) opt.dataset.planPrecio = d.plan.precio;
                if(importe) importe.value = d.plan.precio;
                grupo.innerHTML = `<div style="padding:11px 12px;border-radius:8px;background:#e5f6ec;color:#18733b;font-weight:700">Plan asignado: ${d.plan.nombre}</div>`;
            }catch(e){
                this.disabled = false;
                alert(e.message || 'No se pudo asignar el plan');
            }
        });
    }

    function aplicarPrefill(){
        const alumno=document.getElementById('alumno_id');
        const tipoSelect=document.getElementById('tipo');
        const importe=document.getElementById('importe');
        const modal=document.getElementById('modalPago');
        if(!alumno||!tipoSelect||!modal)return false;

        if(alumnoId)alumno.value=alumnoId;
        if(tipo)tipoSelect.value=tipo;
        if(alumnoId&&alumno.value!==alumnoId)return false;

        alumno.dispatchEvent(new Event('change',{bubbles:true}));
        tipoSelect.dispatchEvent(new Event('change',{bubbles:true}));
        if(tipo==='INTENSIVO'&&importe&&!importe.value)importe.value='1200';
        modal.style.display='flex';
        sugerirPlanSiHaceFalta().catch(console.error);
        return true;
    }

    function enganchar(){
        const alumno=document.getElementById('alumno_id');
        const tipoSelect=document.getElementById('tipo');
        if(!alumno||!tipoSelect)return false;
        alumno.addEventListener('change',()=>sugerirPlanSiHaceFalta().catch(console.error));
        tipoSelect.addEventListener('change',()=>sugerirPlanSiHaceFalta().catch(console.error));
        return true;
    }

    let intentos=0; const timer=setInterval(function(){
        intentos++;
        if(enganchar()){
            clearInterval(timer);
            if(alumnoId||tipo||cursoId) aplicarPrefill();
        } else if(intentos>50) clearInterval(timer);
    },120);
})();
