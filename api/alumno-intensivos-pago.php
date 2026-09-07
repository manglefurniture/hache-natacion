<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/intensivos-estado.php';
$config=require __DIR__.'/../config/database.php';

function hache_intensive_payment_courses_out(array $data,int $status=200): never
{
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){
        header('Allow: GET');
        hache_intensive_payment_courses_out(['ok'=>false,'error'=>'Método no permitido'],405);
    }

    auth_require(['ADMIN','VERIFICADOR']);
    $studentId=trim((string)($_GET['alumno_id']??''));
    if($studentId==='')hache_intensive_payment_courses_out(['ok'=>false,'error'=>'Alumno obligatorio'],422);

    $pdo=new PDO(
        "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
        $config['user'],
        $config['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
    );

    $siteKey=auth_active_sede_clave();
    $st=$pdo->prepare("SELECT id FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");
    $st->execute([':c'=>$siteKey]);
    $siteId=(string)$st->fetchColumn();
    if($siteId==='')hache_intensive_payment_courses_out(['ok'=>false,'error'=>'Sede activa inválida'],422);

    $st=$pdo->prepare(
        "SELECT ci.id,ci.fecha_inicio,ci.fecha_fin,ci.precio,ci.estado,
                h.hora_inicio,h.hora_fin,
                EXISTS(
                    SELECT 1 FROM pagos p
                    WHERE p.alumno_id=cia.alumno_id
                      AND p.intensivo_id=ci.id
                      AND p.tipo='INTENSIVO'
                      AND p.estado='VALIDO'
                ) pagado
         FROM curso_intensivo_alumnos cia
         INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id
         INNER JOIN alumnos a ON a.id=cia.alumno_id AND a.sede_id=ci.sede_id
         LEFT JOIN horarios h ON h.id=cia.horario_id
         WHERE cia.alumno_id=:a AND ci.sede_id=:s
         ORDER BY ci.fecha_inicio DESC"
    );
    $st->execute([':a'=>$studentId,':s'=>$siteId]);
    $courses=$st->fetchAll(PDO::FETCH_ASSOC);
    $today=intensivo_hoy_operativo()->format('Y-m-d');

    foreach($courses as &$course){
        $course['pagado']=(int)$course['pagado']===1;
        $course['historico']=(string)$course['fecha_fin']<$today;
    }
    unset($course);

    hache_intensive_payment_courses_out(['ok'=>true,'cursos'=>$courses]);
}catch(Throwable $e){
    error_log('[alumno-intensivos-pago] '.$e->getMessage());
    hache_intensive_payment_courses_out(['ok'=>false,'error'=>'No se pudieron cargar los cursos intensivos del alumno'],500);
}
