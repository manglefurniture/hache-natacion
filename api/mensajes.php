<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$me = auth_require($method === 'GET' ? ['ADMIN','VERIFICADOR'] : ['ADMIN']);
$config = require __DIR__.'/../config/database.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
    $config['user'],
    $config['password'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
);

function out(array $data, int $status=200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function exactOptionalDate(mixed $value): ?string
{
    $date=trim((string)$value);
    if($date==='')return null;
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new InvalidArgumentException('Fecha inválida');
    return $date;
}

try {
    $clave=auth_active_sede_clave();
    $stmt=$pdo->prepare('SELECT id,nombre FROM sedes WHERE clave=:clave AND activo=1 LIMIT 1');
    $stmt->execute([':clave'=>$clave]);
    $sede=$stmt->fetch();
    if(!$sede)out(['ok'=>false,'error'=>'Sede activa inválida'],422);
    $sedeId=(string)$sede['id'];

    if($method==='GET'){
        $stmt=$pdo->prepare("SELECT m.id,m.titulo,m.cuerpo,m.audiencia,m.alumno_id,m.activo,m.vigencia_desde,m.vigencia_hasta,m.created_at,a.nombre alumno_nombre FROM mensajes m LEFT JOIN alumnos a ON a.id=m.alumno_id AND a.sede_id=m.sede_id WHERE m.sede_id=:sede ORDER BY m.created_at DESC LIMIT 100");
        $stmt->execute([':sede'=>$sedeId]);
        out(['ok'=>true,'sede'=>['clave'=>$clave,'nombre'=>$sede['nombre']],'mensajes'=>$stmt->fetchAll()]);
    }
    if($method!=='POST')out(['ok'=>false,'error'=>'Método no permitido'],405);
    $input=json_decode(file_get_contents('php://input'),true);
    if(!is_array($input))out(['ok'=>false,'error'=>'Solicitud JSON inválida'],400);
    $accion=strtoupper(trim((string)($input['accion']??'')));

    if($accion==='CREAR'){
        $titulo=trim((string)($input['titulo']??''));
        $cuerpo=trim((string)($input['cuerpo']??''));
        $audiencia=strtoupper(trim((string)($input['audiencia']??'TODOS')));
        $alumnoId=trim((string)($input['alumno_id']??''));
        $desde=exactOptionalDate($input['vigencia_desde']??null);
        $hasta=exactOptionalDate($input['vigencia_hasta']??null);
        if($titulo===''||mb_strlen($titulo)>160||$cuerpo===''||mb_strlen($cuerpo)>5000||!in_array($audiencia,['TODOS','REGULARES','INTENSIVOS','ALUMNO'],true))out(['ok'=>false,'error'=>'Datos del mensaje inválidos'],422);
        if($desde!==null&&$hasta!==null&&$hasta<$desde)out(['ok'=>false,'error'=>'La vigencia final no puede ser anterior a la inicial'],422);
        if($audiencia==='ALUMNO'){
            if($alumnoId==='')out(['ok'=>false,'error'=>'Selecciona el alumno'],422);
            $stmt=$pdo->prepare('SELECT 1 FROM alumnos WHERE id=:alumno AND sede_id=:sede LIMIT 1');
            $stmt->execute([':alumno'=>$alumnoId,':sede'=>$sedeId]);
            if(!$stmt->fetchColumn())out(['ok'=>false,'error'=>'El alumno no pertenece a la sede activa'],422);
        }else{$alumnoId='';}
        $stmt=$pdo->prepare("INSERT INTO mensajes(sede_id,titulo,cuerpo,audiencia,alumno_id,vigencia_desde,vigencia_hasta,created_by) VALUES(:sede,:titulo,:cuerpo,:audiencia,:alumno,:desde,:hasta,:usuario)");
        $stmt->execute([':sede'=>$sedeId,':titulo'=>$titulo,':cuerpo'=>$cuerpo,':audiencia'=>$audiencia,':alumno'=>$alumnoId!==''?$alumnoId:null,':desde'=>$desde,':hasta'=>$hasta,':usuario'=>$me['id']]);
        out(['ok'=>true],201);
    }
    if($accion==='ESTADO'){
        $id=trim((string)($input['id']??''));
        if($id==='')out(['ok'=>false,'error'=>'Mensaje inválido'],422);
        $stmt=$pdo->prepare('UPDATE mensajes SET activo=:activo,updated_at=NOW() WHERE id=:id AND sede_id=:sede');
        $stmt->execute([':activo'=>!empty($input['activo'])?1:0,':id'=>$id,':sede'=>$sedeId]);
        if($stmt->rowCount()===0){$check=$pdo->prepare('SELECT 1 FROM mensajes WHERE id=:id AND sede_id=:sede');$check->execute([':id'=>$id,':sede'=>$sedeId]);if(!$check->fetchColumn())out(['ok'=>false,'error'=>'Mensaje no encontrado en la sede activa'],404);}
        out(['ok'=>true]);
    }
    out(['ok'=>false,'error'=>'Acción inválida'],422);
} catch(InvalidArgumentException $e) {
    out(['ok'=>false,'error'=>$e->getMessage()],422);
} catch(Throwable $e) {
    error_log('[mensajes] '.$e->getMessage());
    out(['ok'=>false,'error'=>'No se pudo procesar la solicitud'],500);
}
