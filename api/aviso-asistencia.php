<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
auth_require(['ADMIN','VERIFICADOR']);
$config=require __DIR__.'/../config/database.php';

function aviso_out(array $data,int $status=200): never
{
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')aviso_out(['ok'=>false,'error'=>'Método no permitido'],405);
    $sid=trim((string)($_GET['sesion_id']??''));$aid=trim((string)($_GET['alumno_id']??''));
    if($sid===''||$aid==='')aviso_out(['ok'=>false,'error'=>'Sesión y alumno son obligatorios'],422);
    $pdo=new PDO(
        "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
        $config['user'],$config['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
    );
    $sedeClave=auth_active_sede_clave();$st=$pdo->prepare("SELECT id FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");$st->execute([':c'=>$sedeClave]);$sedeId=(string)$st->fetchColumn();
    if($sedeId==='')aviso_out(['ok'=>false,'error'=>'Sede activa inválida'],422);
    $st=$pdo->prepare("SELECT aa.id,aa.motivo,aa.fecha_desde,aa.fecha_hasta FROM sesiones s
        INNER JOIN horarios h ON h.id=s.horario_id AND h.sede_id=:sh
        INNER JOIN alumnos a ON a.id=:a AND a.sede_id=:sa
        INNER JOIN avisos_ausencia aa ON aa.alumno_id=a.id AND aa.estado='ACTIVO' AND s.fecha BETWEEN aa.fecha_desde AND aa.fecha_hasta
        WHERE s.id=:s ORDER BY aa.created_at DESC LIMIT 1");
    $st->execute([':sh'=>$sedeId,':a'=>$aid,':sa'=>$sedeId,':s'=>$sid]);$aviso=$st->fetch();
    aviso_out(['ok'=>true,'justificada'=>(bool)$aviso,'aviso'=>$aviso?:null]);
}catch(Throwable $e){
    error_log('aviso-asistencia: '.$e->getMessage());
    aviso_out(['ok'=>false,'error'=>'No se pudo revisar el aviso de ausencia'],500);
}
