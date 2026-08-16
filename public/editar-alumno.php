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

$id = $_GET['id'] ?? $_POST['id'] ?? '';

if (!preg_match('/^[0-9a-fA-F-]{36}$/', $id)) {
    http_response_code(400);
    exit('ID de alumno inválido.');
}

/* Guardar cambios */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nombre = trim($_POST['nombre'] ?? '');
    $fecha_nacimiento = $_POST['fecha_nacimiento'] ?? '';
    $whatsapp = trim($_POST['whatsapp'] ?? '');
    $correo = trim($_POST['correo'] ?? '');
    $fecha_inicio = $_POST['fecha_inicio'] ?: null;
    $horario_id = $_POST['horario_preferido_id'] ?: null;
    $plan_id = $_POST['plan_actual_id'] ?: null;
    $estado = $_POST['estado_administrativo'] ?? 'ACTIVO';
    $observaciones = trim($_POST['observaciones'] ?? '');

    if ($nombre === '' || $fecha_nacimiento === '') {
        exit('Nombre y fecha de nacimiento son obligatorios.');
    }

    $sql = "
        UPDATE alumnos
        SET
            nombre = ?,
            fecha_nacimiento = ?,
            whatsapp = ?,
            correo = ?,
            fecha_inicio = ?,
            horario_preferido_id = ?,
            plan_actual_id = ?,
            estado_administrativo = ?,
            observaciones = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $nombre,
        $fecha_nacimiento,
        $whatsapp,
        $correo !== '' ? $correo : null,
        $fecha_inicio,
        $horario_id,
        $plan_id,
        $estado,
        $observaciones !== '' ? $observaciones : null,
        $id
    ]);

    header('Location: ficha-alumno.php?id=' . urlencode($id));
    exit;
}

/* Obtener alumno */
$stmt = $pdo->prepare("
    SELECT *
    FROM alumnos
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$alumno = $stmt->fetch();

if (!$alumno) {
    http_response_code(404);
    exit('Alumno no encontrado.');
}

/* Obtener horarios */
$horarios = $pdo->query("
    SELECT id, hora_inicio, hora_fin
    FROM horarios
    ORDER BY hora_inicio ASC
")->fetchAll();

/* Obtener planes */
$planes = $pdo->query("
    SELECT id, nombre, sesiones_semana, precio
    FROM planes
    ORDER BY precio ASC
")->fetchAll();

function e(?string $valor): string
{
    return htmlspecialchars($valor ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Editar alumno</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f3f6fa;
    color: #172033;
}

.contenedor {
    max-width: 900px;
    margin: 0 auto;
    padding: 25px 20px 40px;
}

.encabezado {
    margin-bottom: 20px;
}

.encabezado a {
    color: #2563eb;
    text-decoration: none;
}

h1 {
    margin: 15px 0 5px;
    font-size: 30px;
}

.subtitulo {
    color: #64748b;
}

.tarjeta {
    background: white;
    border-radius: 18px;
    padding: 25px;
    margin-bottom: 20px;
    box-shadow: 0 4px 15px rgba(0,0,0,.05);
}

label {
    display: block;
    margin-bottom: 7px;
    font-weight: 600;
}

input,
select,
textarea {
    width: 100%;
    padding: 13px;
    border: 1px solid #d8dee8;
    border-radius: 10px;
    font-size: 16px;
    margin-bottom: 18px;
    background: #fff;
}

textarea {
    min-height: 120px;
    resize: vertical;
}

.grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
}

.botones {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

button,
.boton-cancelar {
    border: 0;
    border-radius: 10px;
    padding: 13px 20px;
    font-size: 16px;
    cursor: pointer;
    text-decoration: none;
}

button {
    background: #2563eb;
    color: white;
}

.boton-cancelar {
    background: #e5e7eb;
    color: #172033;
}

@media (max-width: 650px) {
    .grid {
        grid-template-columns: 1fr;
        gap: 0;
    }

    .tarjeta {
        padding: 20px;
    }
}
</style>
</head>

<body>

<div class="contenedor">

    <div class="encabezado">
        <a href="ficha-alumno.php?id=<?= urlencode($alumno['id']) ?>">
            ← Volver a ficha
        </a>

        <h1>Editar alumno</h1>
        <div class="subtitulo"><?= e($alumno['nombre']) ?></div>
    </div>

    <form method="post">

        <input type="hidden" name="id" value="<?= e($alumno['id']) ?>">

        <div class="tarjeta">
            <h2>Información personal</h2>

            <label>Nombre</label>
            <input
                type="text"
                name="nombre"
                value="<?= e($alumno['nombre']) ?>"
                required
            >

            <div class="grid">

                <div>
                    <label>Fecha de nacimiento</label>
                    <input
                        type="date"
                        name="fecha_nacimiento"
                        value="<?= e($alumno['fecha_nacimiento']) ?>"
                        required
                    >
                </div>

                <div>
                    <label>WhatsApp</label>
                    <input
                        type="text"
                        name="whatsapp"
                        value="<?= e($alumno['whatsapp']) ?>"
                    >
                </div>

            </div>

            <label>Correo</label>
            <input
                type="email"
                name="correo"
                value="<?= e($alumno['correo']) ?>"
            >
        </div>

        <div class="tarjeta">
            <h2>Información de natación</h2>

            <div class="grid">

                <div>
                    <label>Fecha de inicio</label>
                    <input
                        type="date"
                        name="fecha_inicio"
                        value="<?= e($alumno['fecha_inicio']) ?>"
                    >
                </div>

                <div>
                    <label>Estado</label>
                    <select name="estado_administrativo">
                        <option value="ACTIVO"
                            <?= $alumno['estado_administrativo'] === 'ACTIVO' ? 'selected' : '' ?>>
                            ACTIVO
                        </option>

                        <option value="BAJA"
                            <?= $alumno['estado_administrativo'] === 'BAJA' ? 'selected' : '' ?>>
                            BAJA
                        </option>
                    </select>
                </div>

            </div>

            <label>Horario</label>
            <select name="horario_preferido_id">
                <option value="">Sin horario</option>

                <?php foreach ($horarios as $horario): ?>
                    <option
                        value="<?= e($horario['id']) ?>"
                        <?= $alumno['horario_preferido_id'] === $horario['id'] ? 'selected' : '' ?>
                    >
                        <?= e($horario['hora_inicio']) ?>
                        — 
                        <?= e($horario['hora_fin']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Plan</label>
            <select name="plan_actual_id">
                <option value="">Sin plan</option>

                <?php foreach ($planes as $plan): ?>
                    <option
                        value="<?= e($plan['id']) ?>"
                        <?= $alumno['plan_actual_id'] === $plan['id'] ? 'selected' : '' ?>
                    >
                        <?= e($plan['nombre']) ?>
                        — <?= e((string)$plan['sesiones_semana']) ?> sesiones/semana
                        — $<?= number_format((float)$plan['precio'], 0) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="tarjeta">
            <h2>Observaciones</h2>

            <textarea name="observaciones"><?= e($alumno['observaciones']) ?></textarea>
        </div>

        <div class="botones">
            <button type="submit">Guardar cambios</button>

            <a
                class="boton-cancelar"
                href="ficha-alumno.php?id=<?= urlencode($alumno['id']) ?>"
            >
                Cancelar
            </a>
        </div>

    </form>

</div>

</body>
</html>
