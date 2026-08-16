(function(){
    if (window.location.pathname !== '/intensivo-detalle.php') return;

    const params = new URLSearchParams(window.location.search);
    const cursoId = params.get('id');
    const alumnoNuevoId = params.get('alumno_nuevo');

    if (!cursoId) return;

    async function cargarDatos() {
        const [cursoResp, alumnosResp] = await Promise.all([
            fetch('/api/intensivo-alumnos.php?curso_id=' + encodeURIComponent(cursoId)),
            fetch('/api/alumnos.php')
        ]);

        const cursoData = await cursoResp.json();
        const alumnosData = await alumnosResp.json();

        if (!cursoData.ok || !alumnosData.ok) return;

        const inscritos = new Set((cursoData.alumnos || []).map(a => a.alumno_id));
        const candidatos = (alumnosData.alumnos || []).filter(a => !inscritos.has(a.id));
        const select = document.getElementById('alumno_id');

        if (!select) return;

        const valorActual = alumnoNuevoId || select.value;

        select.innerHTML = '<option value="">Seleccionar alumno...</option>';

        candidatos.forEach(alumno => {
            const option = document.createElement('option');
            option.value = alumno.id;
            option.textContent = alumno.nombre;
            select.appendChild(option);
        });

        if (valorActual && candidatos.some(a => a.id === valorActual)) {
            select.value = valorActual;
        }

        let ayuda = document.getElementById('hache-intensivo-candidatos');
        if (!ayuda) {
            ayuda = document.createElement('div');
            ayuda.id = 'hache-intensivo-candidatos';
            ayuda.style.marginTop = '8px';
            ayuda.style.fontSize = '12px';
            ayuda.style.color = '#64748b';
            select.insertAdjacentElement('afterend', ayuda);
        }

        ayuda.textContent = candidatos.length === 1
            ? '1 alumno disponible para agregar.'
            : candidatos.length + ' alumnos disponibles para agregar.';

        if (alumnoNuevoId && select.value === alumnoNuevoId) {
            const modal = document.getElementById('modalAlumno');
            if (modal) modal.style.display = 'flex';
        }
    }

    function iniciar() {
        let intentos = 0;
        const timer = setInterval(() => {
            intentos++;
            if (document.getElementById('alumno_id')) {
                clearInterval(timer);
                cargarDatos().catch(console.error);
            } else if (intentos > 40) {
                clearInterval(timer);
            }
        }, 100);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar, { once: true });
    } else {
        iniciar();
    }
})();
