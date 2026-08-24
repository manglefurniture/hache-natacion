<?php
declare(strict_types=1);

require_once __DIR__.'/../config/auth.php';
auth_require(['ADMIN','VERIFICADOR']);
$config = require __DIR__.'/../config/database.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
    $config['user'],
    $config['password'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
);

function csvCell(mixed $value): mixed
{
    if (!is_string($value)) return $value;
    return preg_match('/^\s*[=+\-@]/u', $value) ? "'".$value : $value;
}

$clave = auth_active_sede_clave();
$stmt = $pdo->prepare('SELECT id,nombre FROM sedes WHERE clave=:clave AND activo=1 LIMIT 1');
$stmt->execute([':clave'=>$clave]);
$sede = $stmt->fetch();
if (!$sede) {
    http_response_code(422);
    exit('Sede activa inválida');
}

$filename = 'hache-alumnos-horarios-'.strtolower($clave).'-'.date('Ymd').'.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, ['Sede','Alumno','Estado','Plan','Precio','Horario','WhatsApp']);
$stmt = $pdo->prepare(
    "SELECT :sede AS sede,a.nombre,a.estado_administrativo,p.nombre AS plan,p.precio,
            CONCAT(TIME_FORMAT(h.hora_inicio,'%H:%i'),'–',TIME_FORMAT(h.hora_fin,'%H:%i')) AS horario,a.whatsapp
       FROM alumnos a
       LEFT JOIN planes p ON p.id=a.plan_actual_id AND p.sede_id=a.sede_id
       LEFT JOIN horarios h ON h.id=a.horario_preferido_id AND h.sede_id=a.sede_id
      WHERE a.sede_id=:sede_id
      ORDER BY h.hora_inicio,a.nombre"
);
$stmt->execute([':sede'=>$sede['nombre'],':sede_id'=>$sede['id']]);
foreach ($stmt as $row) fputcsv($out, array_map('csvCell', array_values($row)));
fclose($out);
