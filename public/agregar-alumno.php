<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/database.php';

$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
    $config['user'],
    $config['password'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$horarios = $pdo->query("
    SELECT id, hora_inicio, hora_fin
    FROM horarios
    WHERE activo = 1
    ORDER BY hora_inicio
")->fetchAll();

$planes = $pdo->query("
    SELECT id, nombre, sesiones_semana, precio
    FROM planes
    WHERE activo = 1
    ORDER BY sesiones_semana
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Agregar alumno — Hache Natación</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    background: #f4f7fb;
    color: #172033;
}

.contenedor {
    width: min(760px, 92%);
    margin: 40px auto;
}

.encabezado {
    margin-bottom: 25px;
}

.encabezado h1 {
    margin: 0 0 8px;
    font-size: 30px;
}

.encabezado p {
    margin: 0;
    color: #667085;
}

.card {
    background: white;
    border-radius: 18px;
    padding: 30px;
    box-shadow: 0 8px 30px rgba(0,0,0,.08);
}

.grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
}

.campo {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.campo.completo {
    grid-column: 1 / -1;
}

label {
    font-weight: 600;
    font-size: 14px;
}

input,
select,
textarea {
    width: 100%;
    padding: 13px 14px;
    border: 1px solid #d0d5dd;
    border-radius: 10px;
    font-size: 15px;
    background: white;
    outline: none;
}

input:focus,
select:focus,
textarea:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,.12);
}

textarea {
    min-height: 100px;
    resize: vertical;
}

.acciones {
    margin-top: 25px;
    display: flex;
    justify-content: flex-end;
}

button {
    border: 0;
    border-radius: 10px;
    padding: 14px 24px;
    background: #2563eb;
    color: white;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
}

button:hover {
    background: #1d4ed8;
}

button:disabled {
    opacity: .6;
    cursor: wait;
}

#mensaje {
    display: none;
    margin-bottom: 20px;
    padding: 14px;
    border-radius: 10px;
    font-weight: 600;
}

.exito {
    display: block !important;
    background: #dcfce7;
    color: #166534;
}

.error {
    display: block !important;
    background: #fee2e2;
    color: #991b1b;
}

@media (max-width: 650px) {

    .grid {
        grid-template-columns: 1fr;
    }

    .campo.completo {
        grid-column: auto;
    }

    .card {
        padding: 22px;
    }

    .encabezado h1 {
        font-size: 25px;
    }
}
</style>
</head>

<body>

<div class="contenedor">

    <div class="encabezado">
        <h1>Agregar alumno</h1>
        <p>Registra un nuevo alumno en Hache Natación.</p>
    </div>

    <div id="mensaje"></div>

    <div class="card">

        <form id="formAlumno">

            <div class="grid">

                <div class="campo completo">
                    <label for="nombre">Nombre completo *</label>

                    <input
                        type="text"
                        id="nombre"
                        name="nombre"
                        required
                        placeholder="Nombre y apellidos"
                    >
                </div>

                <div class="campo">
                    <label for="fecha_nacimiento">
                        Fecha de nacimiento *
                    </label>

                    <input
                        type="date"
                        id="fecha_nacimiento"
                        name="fecha_nacimiento"
                        required
                    >
                </div>

                <div class="campo">
                    <label for="whatsapp">
                        WhatsApp *
                    </label>

                    <input
                        type="text"
                        id="whatsapp"
                        name="whatsapp"
                        required
                        placeholder="Ej. 9981234567"
                    >
                </div>

                <div class="campo completo">
                    <label for="correo">
                        Correo electrónico
                    </label>

                    <input
                        type="email"
                        id="correo"
                        name="correo"
                        placeholder="correo@ejemplo.com"
                    >
                </div>

                <div class="campo">
                    <label for="fecha_inicio">
                        Fecha de inicio *
                    </label>

                    <input
                        type="date"
                        id="fecha_inicio"
                        name="fecha_inicio"
                        required
                    >
                </div>

                <div class="campo">
                    <label for="horario_preferido_id">
                        Horario *
                    </label>

                    <select
                        id="horario_preferido_id"
                        name="horario_preferido_id"
                        required
                    >
                        <option value="">
                            Seleccionar horario...
                        </option>

                        <?php foreach ($horarios as $horario): ?>

                            <option value="<?= htmlspecialchars($horario['id']) ?>">

                                <?= htmlspecialchars(
                                    substr($horario['hora_inicio'], 0, 5)
                                ) ?>

                                —

                                <?= htmlspecialchars(
                                    substr($horario['hora_fin'], 0, 5)
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="campo">
                    <label for="plan_actual_id">
                        Plan *
                    </label>

                    <select
                        id="plan_actual_id"
                        name="plan_actual_id"
                        required
                    >
                        <option value="">
                            Seleccionar plan...
                        </option>

                        <?php foreach ($planes as $plan): ?>

                            <option value="<?= htmlspecialchars($plan['id']) ?>">

                                <?= htmlspecialchars($plan['nombre']) ?>

                                — $<?= number_format(
                                    (float)$plan['precio'],
                                    0
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="campo">
                    <label for="estado_administrativo">
                        Estado administrativo
                    </label>

                    <select
                        id="estado_administrativo"
                        name="estado_administrativo"
                    >
                        <option value="ACTIVO">
                            Activo
                        </option>

                        <option value="BAJA">
                            Baja
                        </option>
                    </select>
                </div>

                <div class="campo completo">
                    <label for="observaciones">
                        Observaciones
                    </label>

                    <textarea
                        id="observaciones"
                        name="observaciones"
                        placeholder="Notas del alumno..."
                    ></textarea>
                </div>

            </div>

            <div class="acciones">

                <button
                    type="submit"
                    id="btnGuardar"
                >
                    Guardar alumno
                </button>

            </div>

        </form>

    </div>
</div>


<script>

// --------------------------------------------------
// ORIGEN / RETORNO
// --------------------------------------------------

const parametros =
    new URLSearchParams(
        window.location.search
    );

const origen =
    parametros.get('origen');

const cursoId =
    parametros.get('curso_id');


// --------------------------------------------------
// ELEMENTOS
// --------------------------------------------------

const form =
    document.getElementById('formAlumno');

const mensaje =
    document.getElementById('mensaje');

const boton =
    document.getElementById('btnGuardar');


// --------------------------------------------------
// GUARDAR ALUMNO
// --------------------------------------------------

form.addEventListener(
    'submit',
    async function(e) {

        e.preventDefault();

        mensaje.className = '';
        mensaje.style.display = 'none';

        boton.disabled = true;
        boton.textContent = 'Guardando...';

        const datos = {

            nombre:
                document
                    .getElementById('nombre')
                    .value
                    .trim(),

            fecha_nacimiento:
                document
                    .getElementById('fecha_nacimiento')
                    .value,

            whatsapp:
                document
                    .getElementById('whatsapp')
                    .value
                    .trim(),

            correo:
                document
                    .getElementById('correo')
                    .value
                    .trim(),

            fecha_inicio:
                document
                    .getElementById('fecha_inicio')
                    .value,

            horario_preferido_id:
                document
                    .getElementById('horario_preferido_id')
                    .value,

            plan_actual_id:
                document
                    .getElementById('plan_actual_id')
                    .value,

            estado_administrativo:
                document
                    .getElementById('estado_administrativo')
                    .value,

            observaciones:
                document
                    .getElementById('observaciones')
                    .value
                    .trim()
        };

        try {

            const respuesta =
                await fetch(
                    '/api/alumnos.php',
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

            const resultado =
                await respuesta.json();

            if (
                !respuesta.ok ||
                !resultado.ok
            ) {

                throw new Error(
                    resultado.error ||
                    'No se pudo crear el alumno'
                );
            }

            mensaje.className =
                'exito';

            mensaje.textContent =
                '✓ Alumno creado correctamente.';


            // --------------------------------------------------
            // SI VENIMOS DE UN CURSO INTENSIVO
            // VOLVER AL MISMO CURSO
            // --------------------------------------------------

            if (
                origen === 'intensivo' &&
                cursoId
            ) {

                /*
                 * La API puede devolver el ID como:
                 *
                 * resultado.alumno.id
                 * o resultado.id
                 *
                 * Admitimos ambos formatos.
                 */

                const alumnoNuevoId =
                    resultado.alumno?.id ||
                    resultado.id ||
                    '';

                let destino =
                    '/intensivo-detalle.php?id='
                    +
                    encodeURIComponent(cursoId);

                if (alumnoNuevoId) {

                    destino +=
                        '&alumno_nuevo='
                        +
                        encodeURIComponent(
                            alumnoNuevoId
                        );
                }

                window.location.href =
                    destino;

                return;
            }


            // --------------------------------------------------
            // COMPORTAMIENTO NORMAL
            // --------------------------------------------------

            form.reset();

        } catch (error) {

            mensaje.className =
                'error';

            mensaje.textContent =
                'Error: '
                +
                error.message;

        } finally {

            boton.disabled =
                false;

            boton.textContent =
                'Guardar alumno';
        }
    }
);

</script>

</body>
</html>
