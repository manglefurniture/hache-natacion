<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Intensivos - Hache Natación</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f4f7f9;
    color: #222;
}

.header {
    background: #fff;
    border-bottom: 1px solid #e1e6ea;
    padding: 18px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
}

.header h1 {
    margin: 0;
    color: #123b5d;
    font-size: 24px;
}

.header span {
    color: #777;
    font-size: 14px;
}

.header-actions {
    display: flex;
    gap: 10px;
}

.container {
    width: 100%;
    max-width: 1100px;
    margin: 0 auto;
    padding: 25px 20px;
}

.top-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    margin-bottom: 20px;
}

.top-bar h2 {
    margin: 0;
    color: #123b5d;
    font-size: 22px;
}

.btn {
    border: none;
    border-radius: 8px;
    padding: 11px 16px;
    font-size: 14px;
    font-weight: bold;
    cursor: pointer;
}

.btn-primary {
    background: #1976a8;
    color: #fff;
}

.btn-secondary {
    background: #e8eef2;
    color: #234;
}

.btn-small {
    padding: 8px 11px;
    font-size: 12px;
}

.card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 18px rgba(0,0,0,.06);
    overflow: hidden;
}

.table-container {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th {
    background: #f1f5f7;
    color: #456;
    font-size: 13px;
    text-align: left;
    padding: 13px;
    white-space: nowrap;
}

td {
    padding: 13px;
    border-top: 1px solid #edf0f2;
    font-size: 14px;
    white-space: nowrap;
}

.empty {
    text-align: center;
    padding: 45px 20px;
    color: #777;
}

.badge,
.alumnos-count {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
}

.alumnos-count {
    min-width: 34px;
    text-align: center;
    background: #eef2f5;
    color: #345;
}

.badge-programado {
    background: #e7f1ff;
    color: #155b95;
}

.badge-curso {
    background: #fff3d8;
    color: #956000;
}

.badge-terminado {
    background: #e5f6ec;
    color: #18733b;
}

.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    align-items: center;
    justify-content: center;
    padding: 20px;
    z-index: 1000;
}

.modal-box {
    width: 100%;
    max-width: 500px;
    background: #fff;
    border-radius: 14px;
    padding: 25px;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
}

.modal-header h3 {
    margin: 0;
    color: #123b5d;
}

.close {
    border: none;
    background: transparent;
    font-size: 25px;
    cursor: pointer;
    color: #777;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    margin-bottom: 7px;
    font-size: 13px;
    font-weight: bold;
    color: #444;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 11px 12px;
    border: 1px solid #d5dce1;
    border-radius: 8px;
    font-size: 15px;
    background: #fff;
}

.form-group textarea {
    min-height: 80px;
    resize: vertical;
}

.readonly-field {
    background: #f1f5f7 !important;
    color: #555;
}

.form-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 20px;
}

.message {
    display: none;
    padding: 11px;
    border-radius: 8px;
    margin-bottom: 15px;
    font-size: 14px;
}

.message.error {
    background: #ffe8e8;
    color: #a52222;
}

.message.success {
    background: #e5f6ec;
    color: #18733b;
}

@media (max-width: 650px) {
    .header {
        padding: 15px;
        align-items: flex-start;
        flex-direction: column;
    }

    .header-actions {
        width: 100%;
        flex-direction: column;
    }

    .header-actions .btn {
        width: 100%;
    }

    .container {
        padding: 18px 12px;
    }

    .top-bar {
        align-items: stretch;
        flex-direction: column;
    }

    .top-bar .btn {
        width: 100%;
    }

    th,
    td {
        padding: 10px;
    }
}
</style>
</head>

<body>

<header class="header">
    <div>
        <h1>Hache Natación</h1>
        <span>Panel administrativo</span>
    </div>

    <div class="header-actions">
        <button
            class="btn btn-secondary"
            onclick="window.location.href='/pagos.php'"
        >
            Pagos
        </button>

        <button
            class="btn btn-secondary"
            onclick="window.location.href='/alumnos.php'"
        >
            Control de alumnos
        </button>
    </div>
</header>

<main class="container">

    <div class="top-bar">
        <h2>Cursos intensivos</h2>

        <button class="btn btn-primary" id="btnNuevo">
            + Crear curso intensivo
        </button>
    </div>

    <div id="message" class="message"></div>

    <div class="card">
        <div class="table-container">

            <table>
                <thead>
                    <tr>
                        <th>Inicio</th>
                        <th>Fin</th>
                        <th>Precio</th>
                        <th>Alumnos</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>

                <tbody id="intensivosBody">
                    <tr>
                        <td colspan="6" class="empty">
                            Cargando cursos intensivos...
                        </td>
                    </tr>
                </tbody>
            </table>

        </div>
    </div>

</main>

<div class="modal" id="modalIntensivo">

    <div class="modal-box">

        <div class="modal-header">
            <h3>Crear curso intensivo</h3>

            <button class="close" id="btnCerrar">
                ×
            </button>
        </div>

        <div id="formMessage" class="message"></div>

        <form id="formIntensivo">

            <div class="form-group">
                <label for="fecha_inicio">
                    Fecha de inicio
                </label>

                <select id="fecha_inicio" required>
                    <option value="">
                        Seleccionar lunes...
                    </option>
                </select>
            </div>

            <div class="form-group">
                <label for="fecha_fin">
                    Fecha final
                </label>

                <input
                    type="text"
                    id="fecha_fin"
                    class="readonly-field"
                    readonly
                    placeholder="Se calculará automáticamente"
                >
            </div>

            <div class="form-group">
                <label for="precio">
                    Precio
                </label>

                <input
                    type="number"
                    id="precio"
                    min="0"
                    step="0.01"
                    value="1200"
                    required
                >
            </div>

            <div class="form-group">
                <label for="observaciones">
                    Observaciones
                </label>

                <textarea id="observaciones"></textarea>
            </div>

            <div class="form-actions">

                <button
                    type="button"
                    class="btn btn-secondary"
                    id="btnCancelar"
                >
                    Cancelar
                </button>

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Crear curso
                </button>

            </div>

        </form>

    </div>

</div>

<script>

const intensivosBody =
    document.getElementById('intensivosBody');

const fechaInicioSelect =
    document.getElementById('fecha_inicio');

const fechaFinInput =
    document.getElementById('fecha_fin');

const modal =
    document.getElementById('modalIntensivo');

const form =
    document.getElementById('formIntensivo');

const message =
    document.getElementById('message');

const formMessage =
    document.getElementById('formMessage');


// --------------------------------------------------
// MENSAJES
// --------------------------------------------------

function mostrarMensaje(elemento, texto, tipo) {
    elemento.textContent = texto;
    elemento.className = 'message ' + tipo;
    elemento.style.display = 'block';
}

function ocultarMensaje(elemento) {
    elemento.style.display = 'none';
}


// --------------------------------------------------
// FECHAS
// --------------------------------------------------

function fechaISO(fecha) {

    const year =
        fecha.getFullYear();

    const month =
        String(fecha.getMonth() + 1)
            .padStart(2, '0');

    const day =
        String(fecha.getDate())
            .padStart(2, '0');

    return `${year}-${month}-${day}`;
}


function formatearFechaHumana(fecha) {

    return fecha.toLocaleDateString(
        'es-MX',
        {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric'
        }
    );
}


function generarLunesDisponibles() {

    fechaInicioSelect.innerHTML = `
        <option value="">
            Seleccionar lunes...
        </option>
    `;

    const hoy =
        new Date();

    hoy.setHours(12, 0, 0, 0);

    const diaSemana =
        hoy.getDay();

    let diasHastaLunes =
        (8 - diaSemana) % 7;

    if (diaSemana === 1) {
        diasHastaLunes = 0;
    }

    const primerLunes =
        new Date(hoy);

    primerLunes.setDate(
        hoy.getDate() + diasHastaLunes
    );

    /*
     * Próximos 53 lunes:
     * aproximadamente un año.
     */
    for (let i = 0; i < 53; i++) {

        const lunes =
            new Date(primerLunes);

        lunes.setDate(
            primerLunes.getDate() + (i * 7)
        );

        const option =
            document.createElement('option');

        option.value =
            fechaISO(lunes);

        option.textContent =
            formatearFechaHumana(lunes);

        fechaInicioSelect.appendChild(
            option
        );
    }
}


function actualizarFechaFinal() {

    if (!fechaInicioSelect.value) {

        fechaFinInput.value = '';

        return;
    }

    const inicio =
        new Date(
            fechaInicioSelect.value +
            'T12:00:00'
        );

    const fin =
        new Date(inicio);

    /*
     * Lunes semana 1
     * hasta viernes semana 3.
     */
    fin.setDate(
        inicio.getDate() + 18
    );

    fechaFinInput.value =
        formatearFechaHumana(fin);
}


fechaInicioSelect.addEventListener(
    'change',
    actualizarFechaFinal
);


// --------------------------------------------------
// FORMATOS
// --------------------------------------------------

function formatearPrecio(valor) {

    return Number(valor || 0)
        .toLocaleString(
            'es-MX',
            {
                style: 'currency',
                currency: 'MXN'
            }
        );
}


function formatearEstado(estado) {

    if (estado === 'PROGRAMADO') {

        return `
            <span class="badge badge-programado">
                Programado
            </span>
        `;
    }

    if (estado === 'EN_CURSO') {

        return `
            <span class="badge badge-curso">
                En curso
            </span>
        `;
    }

    return `
        <span class="badge badge-terminado">
            Terminado
        </span>
    `;
}


// --------------------------------------------------
// CARGAR CURSOS
// --------------------------------------------------

async function cargarIntensivos() {

    intensivosBody.innerHTML = `
        <tr>
            <td colspan="6" class="empty">
                Cargando cursos intensivos...
            </td>
        </tr>
    `;

    try {

        const response =
            await fetch('/api/intensivos.php');

        const data =
            await response.json();

        if (!data.ok) {

            throw new Error(
                data.error ||
                'No se pudieron cargar los cursos intensivos'
            );
        }

        const cursos =
            data.intensivos || [];

        if (cursos.length === 0) {

            intensivosBody.innerHTML = `
                <tr>
                    <td colspan="6" class="empty">
                        No hay cursos intensivos registrados.
                    </td>
                </tr>
            `;

            return;
        }

        intensivosBody.innerHTML = '';

        cursos.forEach(curso => {

            const tr =
                document.createElement('tr');

            tr.innerHTML = `
                <td>
                    ${curso.fecha_inicio ?? ''}
                </td>

                <td>
                    ${curso.fecha_fin ?? ''}
                </td>

                <td>
                    ${formatearPrecio(curso.precio)}
                </td>

                <td>
                    <span class="alumnos-count">
                        ${Number(curso.total_alumnos || 0)}
                    </span>
                </td>

                <td>
                    ${formatearEstado(curso.estado)}
                </td>

                <td>
                    <button
                        class="btn btn-secondary btn-small"
                        onclick="abrirCurso('${curso.id}')"
                    >
                        Ver alumnos
                    </button>
                </td>
            `;

            intensivosBody.appendChild(tr);
        });

    } catch (error) {

        console.error(error);

        intensivosBody.innerHTML = `
            <tr>
                <td colspan="6" class="empty">
                    Error al cargar los cursos intensivos.
                </td>
            </tr>
        `;

        mostrarMensaje(
            message,
            error.message ||
            'Error al cargar los cursos intensivos.',
            'error'
        );
    }
}


// --------------------------------------------------
// VER ALUMNOS
// --------------------------------------------------

function abrirCurso(id) {

    window.location.href =
        '/intensivo-detalle.php?id=' +
        encodeURIComponent(id);
}


// --------------------------------------------------
// MODAL
// --------------------------------------------------

document
    .getElementById('btnNuevo')
    .addEventListener(
        'click',
        function() {

            form.reset();

            document
                .getElementById('precio')
                .value = '1200';

            fechaFinInput.value = '';

            generarLunesDisponibles();

            ocultarMensaje(formMessage);

            modal.style.display = 'flex';
        }
    );


function cerrarModal() {
    modal.style.display = 'none';
}


document
    .getElementById('btnCerrar')
    .addEventListener(
        'click',
        cerrarModal
    );


document
    .getElementById('btnCancelar')
    .addEventListener(
        'click',
        cerrarModal
    );


modal.addEventListener(
    'click',
    function(e) {

        if (e.target === modal) {
            cerrarModal();
        }
    }
);


// --------------------------------------------------
// CREAR CURSO
// --------------------------------------------------

form.addEventListener(
    'submit',
    async function(e) {

        e.preventDefault();

        ocultarMensaje(formMessage);

        const usuarioGuardado =
            sessionStorage.getItem(
                'hache_usuario'
            );

        if (!usuarioGuardado) {

            mostrarMensaje(
                formMessage,
                'La sesión administrativa no está disponible.',
                'error'
            );

            return;
        }

        let usuario;

        try {

            usuario =
                JSON.parse(usuarioGuardado);

        } catch (error) {

            mostrarMensaje(
                formMessage,
                'La sesión administrativa no es válida.',
                'error'
            );

            return;
        }

        if (!usuario.id) {

            mostrarMensaje(
                formMessage,
                'La sesión administrativa no contiene un usuario válido.',
                'error'
            );

            return;
        }

        const datos = {

            fecha_inicio:
                fechaInicioSelect.value,

            precio:
                document
                    .getElementById('precio')
                    .value,

            observaciones:
                document
                    .getElementById('observaciones')
                    .value
                    .trim() || null,

            created_by:
                usuario.id
        };

        if (
            !datos.fecha_inicio ||
            !datos.precio
        ) {

            mostrarMensaje(
                formMessage,
                'Completa todos los campos obligatorios.',
                'error'
            );

            return;
        }

        try {

            const response =
                await fetch(
                    '/api/intensivos.php',
                    {
                        method: 'POST',

                        headers: {
                            'Content-Type':
                                'application/json'
                        },

                        body:
                            JSON.stringify(datos)
                    }
                );

            const data =
                await response.json();

            if (!data.ok) {

                throw new Error(
                    data.error ||
                    'No se pudo crear el curso intensivo'
                );
            }

            cerrarModal();

            mostrarMensaje(
                message,
                'Curso intensivo creado correctamente.',
                'success'
            );

            await cargarIntensivos();

        } catch (error) {

            mostrarMensaje(
                formMessage,
                error.message ||
                'Error al crear el curso intensivo.',
                'error'
            );
        }
    }
);


// --------------------------------------------------
// INICIO
// --------------------------------------------------

generarLunesDisponibles();
cargarIntensivos();

</script>

</body>
</html>
