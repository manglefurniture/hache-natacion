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

$sql = "
    SELECT
        a.id,
        a.nombre,
        a.fecha_nacimiento,
        a.whatsapp,
        a.correo,
        a.fecha_inicio,
        a.estado_administrativo,
        a.observaciones,
        h.hora_inicio,
        h.hora_fin,
        p.nombre AS plan_nombre,
        p.sesiones_semana,
        p.precio
    FROM alumnos a
    LEFT JOIN horarios h ON h.id = a.horario_preferido_id
    LEFT JOIN planes p ON p.id = a.plan_actual_id
    ORDER BY a.nombre ASC
";

$alumnos = $pdo->query($sql)->fetchAll();

function e(?string $valor): string
{
    return htmlspecialchars($valor ?? '', ENT_QUOTES, 'UTF-8');
}

function hora(string $hora): string
{
    return date('H:i', strtotime($hora));
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control de Alumnos — Hache Natación</title>

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
            max-width: 1400px;
            margin: 0 auto;
            padding: 35px 25px;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 32px;
        }

        .subtitulo {
            color: #64748b;
            margin-bottom: 30px;
        }

        .tabla-contenedor {
            background: white;
            border-radius: 18px;
            padding: 20px;
            overflow-x: auto;
            box-shadow: 0 4px 18px rgba(0,0,0,.06);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        th {
            text-align: left;
            padding: 14px;
            background: #f8fafc;
            color: #475569;
            font-size: 13px;
            border-bottom: 1px solid #e2e8f0;
        }

        td {
            padding: 15px 14px;
            border-bottom: 1px solid #edf2f7;
            vertical-align: middle;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .nombre {
            font-weight: bold;
        }

        .secundario {
            color: #64748b;
            font-size: 13px;
            margin-top: 4px;
        }

        .estado {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: bold;
        }

        .activo {
            background: #dcfce7;
            color: #166534;
        }

        .baja {
            background: #fee2e2;
            color: #991b1b;
        }

        .plan {
            font-weight: bold;
        }

        .precio {
            color: #64748b;
            font-size: 13px;
            margin-top: 3px;
        }

        .vacio {
            text-align: center;
            padding: 50px;
            color: #64748b;
        }

        .contador {
            margin-bottom: 15px;
            color: #64748b;
            font-size: 14px;
        }
    </style>
</head>

<body>

<div class="contenedor">

    <h1>Control de Alumnos</h1>

    <div class="subtitulo">
        Hache Natación — alumnos registrados
    </div>

    <div class="contador">
        <?= count($alumnos) ?> alumno<?= count($alumnos) === 1 ? '' : 's' ?> registrado<?= count($alumnos) === 1 ? '' : 's' ?>
    </div>

    <div class="tabla-contenedor">

        <?php if (!$alumnos): ?>

            <div class="vacio">
                No hay alumnos registrados todavía.
            </div>

        <?php else: ?>

            <table>

                <thead>
                    <tr>
                        <th>Alumno</th>
                        <th>WhatsApp</th>
                        <th>Fecha de inicio</th>
                        <th>Horario</th>
                        <th>Plan</th>
                        <th>Estado</th>
            <th>Acciones</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($alumnos as $alumno): ?>

                    <tr>

                        <td>
                            <div class="nombre">
                                <?= e($alumno['nombre']) ?>
                            </div>

                            <?php if (!empty($alumno['correo'])): ?>
                                <div class="secundario">
                                    <?= e($alumno['correo']) ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?= e($alumno['whatsapp']) ?>
                        </td>

                        <td>
                            <?= !empty($alumno['fecha_inicio'])
                                ? date('d/m/Y', strtotime($alumno['fecha_inicio']))
                                : '—' ?>
                        </td>

                        <td>
                            <?php if ($alumno['hora_inicio'] && $alumno['hora_fin']): ?>
                                <?= hora($alumno['hora_inicio']) ?>
                                –
                                <?= hora($alumno['hora_fin']) ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($alumno['plan_nombre']): ?>

                                <div class="plan">
                                    <?= e($alumno['plan_nombre']) ?>
                                </div>

                                <div class="precio">
                                    <?= (int)$alumno['sesiones_semana'] ?> sesiones/semana ·
                                    $<?= number_format((float)$alumno['precio'], 0) ?>
                                </div>

                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>

                        <td>

                            <?php if ($alumno['estado_administrativo'] === 'ACTIVO'): ?>

                                <span class="estado activo">
                                    ACTIVO
                                </span>

                            <?php else: ?>

                                <span class="estado baja">
                                    BAJA
                                </span>

                            <?php endif; ?>

                        </td>
            <td><a href="ficha-alumno.php?id=<?= urlencode($alumno["id"]) ?>">Ver ficha</a></td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
