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

<title>Pagos - Hache Natación</title>

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
    background: #ffffff;
    border-bottom: 1px solid #e1e6ea;
    padding: 18px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
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
    color: #ffffff;
}

.btn-secondary {
    background: #e8eef2;
    color: #234;
}

.card {
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 4px 18px rgba(0,0,0,0.06);
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

.badge-valid {
    background: #e5f6ec;
    color: #18733b;
}

.badge-invalid {
    background: #ffe7e7;
    color: #a52222;
}

.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.45);
    align-items: center;
    justify-content: center;
    padding: 20px;
    z-index: 1000;
}

.modal-box {
    width: 100%;
    max-width: 500px;
    background: #ffffff;
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
    background: #ffffff;
}

.form-group textarea {
    min-height: 80px;
    resize: vertical;
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
    }

    .header h1 {
        font-size: 20px;
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

    <button class="btn btn-secondary" onclick="window.location.href='/alumnos.php'">
        Control de alumnos
    </button>
</header>

<main class="container">

    <div class="top-bar">
        <h2>Pagos</h2>

        <button class="btn btn-primary" id="btnNuevoPago">
            + Registrar pago
        </button>
    </div>

    <div id="message" class="message"></div>

    <div class="card">
        <div class="table-container">

            <table>
                <thead>
                    <tr>
                        <th>Folio</th>
                        <th>Alumno</th>
                        <th>Tipo</th>
                        <th>Importe</th>
                        <th>Método</th>
                        <th>Fecha</th>
                        <th>Estado</th>
                    </tr>
                </thead>

                <tbody id="pagosBody">
                    <tr>
                        <td colspan="7" class="empty">
                            Cargando pagos...
                        </td>
                    </tr>
                </tbody>

            </table>

        </div>
    </div>

</main>

<div class="modal" id="modalPago">

    <div class="modal-box">

        <div class="modal-header">
            <h3>Registrar pago</h3>

            <button class="close" id="btnCerrarModal">
                ×
            </button>
        </div>

        <div id="formMessage" class="message"></div>

        <form id="formPago">

            <div class="form-group">
                <label for="alumno_id">Alumno</label>

                <select id="alumno_id" required>
                    <option value="">Seleccionar alumno...</option>
                </select>
            </div>

            <div class="form-group">
                <label for="tipo">Tipo de pago</label>

                <select id="tipo" required>
                    <option value="">Seleccionar...</option>
                    <option value="INSCRIPCION">Inscripción</option>
                    <option value="MENSUALIDAD">Mensualidad</option>
                    <option value="INTENSIVO">Curso intensivo</option>
                </select>
            </div>

            <div class="form-group">
                <label for="importe">Importe</label>

                <input
                    type="number"
                    id="importe"
                    min="0.01"
                    step="0.01"
                    required
                >
            </div>

            <div class="form-group">
                <label for="metodo">Método de pago</label>

                <select id="metodo" required>
                    <option value="">Seleccionar...</option>
                    <option value="EFECTIVO">Efectivo</option>
                    <option value="TRANSFERENCIA">Transferencia</option>
                    <option value="MERCADO_PAGO">Mercado Pago</option>
                </select>
            </div>

            <div class="form-group">
                <label for="fecha">Fecha</label>

                <input
                    type="datetime-local"
                    id="fecha"
                    required
                >
            </div>

            <div class="form-group">
                <label for="observacion">Observación</label>

                <textarea id="observacion"></textarea>
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
                    Registrar pago
                </button>

            </div>

        </form>

    </div>

</div>

<script>
const pagosBody = document.getElementById('pagosBody');
const alumnoSelect = document.getElementById('alumno_id');
const tipoSelect = document.getElementById('tipo');
const importeInput = document.getElementById('importe');
const metodoSelect = document.getElementById('metodo');
const fechaInput = document.getElementById('fecha');

const modal = document.getElementById('modalPago');
const btnNuevoPago = document.getElementById('btnNuevoPago');
const btnCerrarModal = document.getElementById('btnCerrarModal');
const btnCancelar = document.getElementById('btnCancelar');

const formPago = document.getElementById('formPago');
const message = document.getElementById('message');
const formMessage = document.getElementById('formMessage');

function escaparHtml(valor) {
    return String(valor ?? '').replace(/[&<>'"]/g, caracter => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    })[caracter]);
}


// --------------------------------------------------
// FECHA ACTUAL
// --------------------------------------------------

function establecerFechaActual() {
    const ahora = new Date();

    const offset = ahora.getTimezoneOffset();
    const local = new Date(ahora.getTime() - offset * 60000);

    fechaInput.value = local.toISOString().slice(0, 16);
}


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
// CARGAR PAGOS
// --------------------------------------------------

async function cargarPagos() {

    pagosBody.innerHTML = `
        <tr>
            <td colspan="7" class="empty">
                Cargando pagos...
            </td>
        </tr>
    `;

    try {

        const response = await fetch('/api/pagos.php');

        const data = await response.json();

        if (!data.ok) {
            throw new Error(data.error || 'No se pudieron cargar los pagos');
        }

        if (!data.pagos || data.pagos.length === 0) {

            pagosBody.innerHTML = `
                <tr>
                    <td colspan="7" class="empty">
                        No hay pagos registrados.
                    </td>
                </tr>
            `;

            return;
        }

        pagosBody.innerHTML = '';

        data.pagos.forEach(pago => {

            const tr = document.createElement('tr');

            const fecha = pago.fecha
                ? new Date(pago.fecha.replace(' ', 'T')).toLocaleString('es-MX')
                : '';

            const importe = Number(pago.importe || 0).toLocaleString(
                'es-MX',
                {
                    style: 'currency',
                    currency: 'MXN'
                }
            );

            const estado = pago.estado === 'VALIDO'
                ? '<span class="badge badge-valid">Válido</span>'
                : '<span class="badge badge-invalid">Invalidado</span>';

            tr.innerHTML = `
                <td>${escaparHtml(pago.folio)}</td>
                <td>${escaparHtml(pago.alumno_nombre ?? pago.alumno_id)}</td>
                <td>${escaparHtml(formatearTipo(pago.tipo))}</td>
                <td>${escaparHtml(importe)}</td>
                <td>${escaparHtml(formatearMetodo(pago.metodo))}</td>
                <td>${escaparHtml(fecha)}</td>
                <td>${estado}</td>
            `;

            pagosBody.appendChild(tr);
        });

    } catch (error) {

        pagosBody.innerHTML = `
            <tr>
                <td colspan="7" class="empty">
                    Error al cargar los pagos.
                </td>
            </tr>
        `;

        console.error(error);
    }
}


// --------------------------------------------------
// CARGAR ALUMNOS
// --------------------------------------------------

async function cargarAlumnos() {

    try {

        const response = await fetch('/api/alumnos.php');

        const data = await response.json();

        if (!data.ok) {
            throw new Error(data.error || 'No se pudieron cargar los alumnos');
        }

        const alumnos = data.alumnos || [];

        alumnoSelect.innerHTML = `
            <option value="">Seleccionar alumno...</option>
        `;

        alumnos.forEach(alumno => {

            const option = document.createElement('option');

            option.value = alumno.id;
            option.dataset.planPrecio = alumno.plan_precio ?? '';

            option.textContent =
                alumno.nombre ||
                alumno.nombre_completo ||
                alumno.id;

            alumnoSelect.appendChild(option);
        });

    } catch (error) {

        console.error(error);

        alumnoSelect.innerHTML = `
            <option value="">
                No se pudieron cargar los alumnos
            </option>
        `;
    }
}


// --------------------------------------------------
// FORMATEAR TIPO
// --------------------------------------------------

function formatearTipo(tipo) {

    const tipos = {
        INSCRIPCION: 'Inscripción',
        MENSUALIDAD: 'Mensualidad',
        INTENSIVO: 'Curso intensivo'
    };

    return tipos[tipo] || tipo || '';
}


// --------------------------------------------------
// FORMATEAR MÉTODO
// --------------------------------------------------

function formatearMetodo(metodo) {

    const metodos = {
        EFECTIVO: 'Efectivo',
        TRANSFERENCIA: 'Transferencia',
        MERCADO_PAGO: 'Mercado Pago'
    };

    return metodos[metodo] || metodo || '';
}


// --------------------------------------------------
// ABRIR MODAL
// --------------------------------------------------

btnNuevoPago.addEventListener('click', function() {

    ocultarMensaje(formMessage);

    formPago.reset();

    establecerFechaActual();

    modal.style.display = 'flex';
});


// --------------------------------------------------
// CERRAR MODAL
// --------------------------------------------------

function cerrarModal() {
    modal.style.display = 'none';
}

btnCerrarModal.addEventListener('click', cerrarModal);

btnCancelar.addEventListener('click', cerrarModal);

modal.addEventListener('click', function(e) {

    if (e.target === modal) {
        cerrarModal();
    }

});


// --------------------------------------------------
// IMPORTE AUTOMÁTICO
// --------------------------------------------------

tipoSelect.addEventListener('change', function() {

    if (this.value === 'INSCRIPCION') {
        importeInput.value = '500';
    }

    else if (this.value === 'INTENSIVO') {
        importeInput.value = '1200';
    }

    else if (this.value === 'MENSUALIDAD') {
        importeInput.value = alumnoSelect.selectedOptions[0]?.dataset.planPrecio || '';
    }

});


// --------------------------------------------------
alumnoSelect.addEventListener('change', function() {
    if (tipoSelect.value === 'MENSUALIDAD') {
        importeInput.value = this.selectedOptions[0]?.dataset.planPrecio || '';
    }
});

// REGISTRAR PAGO
// --------------------------------------------------

formPago.addEventListener('submit', async function(e) {

    e.preventDefault();

    ocultarMensaje(formMessage);

    const alumnoId = alumnoSelect.value;
    const tipo = tipoSelect.value;
    const importe = importeInput.value;
    const metodo = metodoSelect.value;
    const fecha = fechaInput.value;
    const observacion =
        document.getElementById('observacion').value.trim();

    if (!alumnoId || !tipo || !importe || !metodo || !fecha) {

        mostrarMensaje(
            formMessage,
            'Completa todos los campos obligatorios.',
            'error'
        );

        return;
    }

    const datos = {
        alumno_id: alumnoId,
        tipo: tipo,
        importe: importe,
        metodo: metodo,
        fecha: fecha.replace('T', ' ') + ':00',
        observacion: observacion || null
    };

    try {

        const response = await fetch('/api/pagos.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(datos)
        });

        const data = await response.json();

        if (!data.ok) {
            throw new Error(
                data.error || 'No se pudo registrar el pago'
            );
        }

        mostrarMensaje(
            message,
            'Pago registrado correctamente.',
            'success'
        );

        cerrarModal();

        await cargarPagos();

    } catch (error) {

        mostrarMensaje(
            formMessage,
            error.message || 'Error al registrar el pago.',
            'error'
        );
    }

});


// --------------------------------------------------
// INICIO
// --------------------------------------------------

establecerFechaActual();

cargarPagos();

cargarAlumnos();

</script>
