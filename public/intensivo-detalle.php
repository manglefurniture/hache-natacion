<?php
declare(strict_types=1);
require_once __DIR__.'/../config/auth.php';
page_require(['ADMIN','VERIFICADOR']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Detalle intensivo - Hache Natación</title>

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
    max-width: 1150px;
    margin: 0 auto;
    padding: 25px 20px;
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

.course-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 18px rgba(0,0,0,.06);
    padding: 20px;
    margin-bottom: 20px;
}

.course-card h2 {
    margin: 0 0 15px;
    color: #123b5d;
}

.course-info {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
}

.info-item {
    background: #f7f9fa;
    border-radius: 9px;
    padding: 12px;
}

.info-label {
    display: block;
    color: #777;
    font-size: 12px;
    margin-bottom: 5px;
}

.info-value {
    font-weight: bold;
    color: #234;
}

.top-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    margin-bottom: 20px;
}

.top-bar h3 {
    margin: 0;
    color: #123b5d;
    font-size: 20px;
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

.badge {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
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

.link-nuevo {
    display: inline-block;
    margin-top: 8px;
    color: #1976a8;
    text-decoration: none;
    font-size: 13px;
    font-weight: bold;
}

.link-nuevo:hover {
    text-decoration: underline;
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

@media (max-width: 750px) {
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

    .course-info {
        grid-template-columns: 1fr 1fr;
    }

    .top-bar {
        align-items: stretch;
        flex-direction: column;
    }

    .top-bar .btn {
        width: 100%;
    }
}
</style>
</head>

<body>

<header class="header">

    <div>
        <h1>Hache Natación</h1>
        <span>Detalle de curso intensivo</span>
    </div>

    <div class="header-actions">

        <button
            class="btn btn-secondary"
            onclick="window.location.href='/intensivos.php'"
        >
            Intensivos
        </button>

        <button
            class="btn btn-secondary"
            onclick="window.location.href='/pagos.php'"
        >
            Pagos
        </button>

    </div>

</header>

<main class="container">

    <div id="message" class="message"></div>

    <div class="course-card">

        <h2 id="tituloCurso">
            Curso intensivo
        </h2>

        <div class="course-info">

            <div class="info-item">
                <span class="info-label">Inicio</span>
                <span class="info-value" id="cursoInicio">-</span>
            </div>

            <div class="info-item">
                <span class="info-label">Fin</span>
                <span class="info-value" id="cursoFin">-</span>
            </div>

            <div class="info-item">
                <span class="info-label">Precio</span>
                <span class="info-value" id="cursoPrecio">-</span>
            </div>

            <div class="info-item">
                <span class="info-label">Estado</span>
                <span class="info-value" id="cursoEstado">-</span>
            </div>

        </div>

    </div>

    <div class="top-bar">

        <h3>Alumnos del curso</h3>

        <button class="btn btn-primary" id="btnAgregar">
            + Agregar alumno
        </button>

    </div>

    <div class="card">

        <div class="table-container">

            <table>

                <thead>
                    <tr>
                        <th>Alumno</th>
                        <th>Horario</th>
                        <th>Reposiciones</th>
                        <th>Cancelaciones</th>
                    </tr>
                </thead>

                <tbody id="alumnosBody">

                    <tr>
                        <td colspan="4" class="empty">
                            Cargando alumnos...
                        </td>
                    </tr>

                </tbody>

            </table>

        </div>

    </div>

</main>


<div class="modal" id="modalAlumno">

    <div class="modal-box">

        <div class="modal-header">

            <h3>Agregar alumno al intensivo</h3>

            <button class="close" id="btnCerrar">
                ×
            </button>

        </div>

        <div id="formMessage" class="message"></div>

        <form id="formAlumno">

            <div class="form-group">

                <label for="alumno_id">
                    Alumno
                </label>

                <select id="alumno_id" required>
                    <option value="">
                        Seleccionar alumno...
                    </option>
                </select>

                <a
                    href="#"
                    id="linkNuevoAlumno"
                    class="link-nuevo"
                >
                    + El alumno no está en la lista
                </a>

            </div>

            <div class="form-group">

                <label for="horario_id">
                    Horario
                </label>

                <select id="horario_id" required>
                    <option value="">
                        Seleccionar horario...
                    </option>
                </select>

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
                    Agregar alumno
                </button>

            </div>

        </form>

    </div>

</div>


<script>

const params =
    new URLSearchParams(
        window.location.search
    );

const cursoId =
    params.get('id');

const alumnoNuevoId =
    params.get('alumno_nuevo');

const alumnosBody =
    document.getElementById('alumnosBody');

const alumnoSelect =
    document.getElementById('alumno_id');

const horarioSelect =
    document.getElementById('horario_id');

const modal =
    document.getElementById('modalAlumno');

const form =
    document.getElementById('formAlumno');

const message =
    document.getElementById('message');

const formMessage =
    document.getElementById('formMessage');

const linkNuevoAlumno =
    document.getElementById('linkNuevoAlumno');

function escaparHtml(valor) {
    return String(valor ?? '').replace(/[&<>'"]/g, caracter => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    })[caracter]);
}


// --------------------------------------------------
// ENLACE A NUEVO ALUMNO
// --------------------------------------------------

if (cursoId) {

    linkNuevoAlumno.href =
        '/agregar-alumno.php?origen=intensivo&curso_id='
        +
        encodeURIComponent(cursoId);

} else {

    linkNuevoAlumno.href =
        '/agregar-alumno.php';
}


// --------------------------------------------------
// MENSAJES
// --------------------------------------------------

function mostrarMensaje(
    elemento,
    texto,
    tipo
) {

    elemento.textContent =
        texto;

    elemento.className =
        'message ' + tipo;

    elemento.style.display =
        'block';
}


function ocultarMensaje(elemento) {

    elemento.style.display =
        'none';
}


// --------------------------------------------------
// FORMATO
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


function formatearHorario(
    inicio,
    fin
) {

    return (
        (inicio || '').substring(0, 5)
        +
        ' - '
        +
        (fin || '').substring(0, 5)
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
// CARGAR CATÁLOGO DE ALUMNOS
// --------------------------------------------------

async function cargarCatalogoAlumnos() {

    const response =
        await fetch('/api/alumnos.php');

    const data =
        await response.json();

    if (!data.ok) {

        throw new Error(
            data.error ||
            'No se pudieron cargar los alumnos'
        );
    }

    alumnoSelect.innerHTML = `
        <option value="">
            Seleccionar alumno...
        </option>
    `;

    (data.alumnos || [])
        .forEach(alumno => {

            const option =
                document.createElement(
                    'option'
                );

            option.value =
                alumno.id;

            option.textContent =
                alumno.nombre;

            alumnoSelect.appendChild(
                option
            );
        });


    // --------------------------------------------------
    // SI VENIMOS DE CREAR UN ALUMNO NUEVO
    // LO PRESELECCIONAMOS Y ABRIMOS EL MODAL
    // --------------------------------------------------

    if (alumnoNuevoId) {

        alumnoSelect.value =
            alumnoNuevoId;

        ocultarMensaje(
            formMessage
        );

        modal.style.display =
            'flex';
    }
}


// --------------------------------------------------
// CARGAR CURSO
// --------------------------------------------------

async function cargarCurso() {

    if (!cursoId) {

        mostrarMensaje(
            message,
            'No se especificó el curso intensivo.',
            'error'
        );

        return;
    }

    alumnosBody.innerHTML = `
        <tr>
            <td colspan="4" class="empty">
                Cargando alumnos...
            </td>
        </tr>
    `;

    try {

        const response =
            await fetch(
                '/api/intensivo-alumnos.php?curso_id='
                +
                encodeURIComponent(cursoId)
            );

        const data =
            await response.json();

        if (!data.ok) {

            throw new Error(
                data.error ||
                'No se pudo cargar el curso'
            );
        }

        const curso =
            data.curso;

        document
            .getElementById('cursoInicio')
            .textContent =
                curso.fecha_inicio || '-';

        document
            .getElementById('cursoFin')
            .textContent =
                curso.fecha_fin || '-';

        document
            .getElementById('cursoPrecio')
            .textContent =
                formatearPrecio(
                    curso.precio
                );

        document
            .getElementById('cursoEstado')
            .innerHTML =
                formatearEstado(
                    curso.estado
                );


        // --------------------------------------------------
        // HORARIOS
        // --------------------------------------------------

        horarioSelect.innerHTML = `
            <option value="">
                Seleccionar horario...
            </option>
        `;

        (data.horarios || [])
            .forEach(horario => {

                const option =
                    document.createElement(
                        'option'
                    );

                option.value =
                    horario.id;

                option.textContent =
                    formatearHorario(
                        horario.hora_inicio,
                        horario.hora_fin
                    );

                horarioSelect.appendChild(
                    option
                );
            });


        // --------------------------------------------------
        // ALUMNOS DEL CURSO
        // --------------------------------------------------

        const alumnos =
            data.alumnos || [];

        if (alumnos.length === 0) {

            alumnosBody.innerHTML = `
                <tr>
                    <td colspan="4" class="empty">
                        Este curso todavía no tiene alumnos.
                    </td>
                </tr>
            `;

            return;
        }

        alumnosBody.innerHTML =
            '';

        alumnos.forEach(alumno => {

            const tr =
                document.createElement('tr');

            tr.innerHTML = `
                <td>
                    ${escaparHtml(alumno.alumno_nombre)}
                </td>

                <td>
                    ${escaparHtml(formatearHorario(
                        alumno.hora_inicio,
                        alumno.hora_fin
                    ))}
                </td>

                <td>
                    ${Number(
                        alumno.reposiciones_justificadas || 0
                    )}
                </td>

                <td>
                    ${Number(
                        alumno.reposiciones_cancelacion || 0
                    )}
                </td>
            `;

            alumnosBody.appendChild(
                tr
            );
        });

    } catch (error) {

        console.error(error);

        mostrarMensaje(
            message,
            error.message ||
            'Error al cargar el curso.',
            'error'
        );
    }
}


// --------------------------------------------------
// MODAL
// --------------------------------------------------

document
    .getElementById('btnAgregar')
    .addEventListener(
        'click',
        function() {

            form.reset();

            ocultarMensaje(
                formMessage
            );

            modal.style.display =
                'flex';
        }
    );


function cerrarModal() {

    modal.style.display =
        'none';
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
// AGREGAR ALUMNO
// --------------------------------------------------

form.addEventListener(
    'submit',
    async function(e) {

        e.preventDefault();

        ocultarMensaje(
            formMessage
        );

        const datos = {

            curso_intensivo_id:
                cursoId,

            alumno_id:
                alumnoSelect.value,

            horario_id:
                horarioSelect.value,

            observaciones:
                document
                    .getElementById(
                        'observaciones'
                    )
                    .value
                    .trim()
                || null
        };

        if (
            !datos.curso_intensivo_id ||
            !datos.alumno_id ||
            !datos.horario_id
        ) {

            mostrarMensaje(
                formMessage,
                'Selecciona alumno y horario.',
                'error'
            );

            return;
        }

        try {

            const response =
                await fetch(
                    '/api/intensivo-alumnos.php',
                    {
                        method: 'POST',

                        headers: {
                            'Content-Type':
                                'application/json'
                        },

                        body:
                            JSON.stringify(
                                datos
                            )
                    }
                );

            const data =
                await response.json();

            if (!data.ok) {

                throw new Error(
                    data.error ||
                    'No se pudo agregar el alumno'
                );
            }

            cerrarModal();

            mostrarMensaje(
                message,
                'Alumno agregado al curso intensivo.',
                'success'
            );

            /*
             * Limpiamos alumno_nuevo de la URL
             * para que al refrescar no vuelva a abrir el modal.
             */
            const nuevaUrl =
                '/intensivo-detalle.php?id='
                +
                encodeURIComponent(cursoId);

            window.history.replaceState(
                {},
                '',
                nuevaUrl
            );

            await cargarCurso();

        } catch (error) {

            mostrarMensaje(
                formMessage,
                error.message ||
                'Error al agregar el alumno.',
                'error'
            );
        }
    }
);


// --------------------------------------------------
// INICIO
// --------------------------------------------------

Promise.all([
    cargarCurso(),
    cargarCatalogoAlumnos()
]).catch(error => {

    console.error(error);

    mostrarMensaje(
        message,
        error.message ||
        'Error al cargar la información.',
        'error'
    );
});

</script>

</body>
</html>
