<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config = require __DIR__ . '/../config/database.php';

function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;}
function slug_usuario(string $nombre):string{
    $ascii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$nombre) ?: $nombre;
    $ascii=strtolower($ascii);$ascii=preg_replace('/[^a-z0-9 ]+/',' ',$ascii)??'';
    $parts=array_values(array_filter(preg_split('/\s+/',trim($ascii))?:[]));
    if(!$parts)return 'alumno';
    $base=$parts[0];if(count($parts)>1)$base.='.'.end($parts);
    return substr($base,0,40);
}
function usuario_unico(PDO $pdo,string $nombre):string{
    $base=slug_usuario($nombre);$candidate=$base;$n=2;
    $st=$pdo->prepare("SELECT 1 FROM usuarios WHERE usuario=:u LIMIT 1");
    while(true){$st->execute([':u'=>$candidate]);if(!$st->fetchColumn())return $candidate;$candidate=$base.$n;$n++;}
}

try {
    $pdo = new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec("UPDATE alumnos SET plan_actual_id=plan_programado_id, plan_programado_id=NULL, plan_programado_desde=NULL, updated_at=NOW() WHERE plan_programado_id IS NOT NULL AND plan_programado_desde IS NOT NULL AND plan_programado_desde<=CURDATE()");
    $method=$_SERVER['REQUEST_METHOD'];
    if($method==='GET'){
        $stmt=$pdo->query("SELECT a.id,a.nombre,a.fecha_nacimiento,a.whatsapp,a.correo,a.fecha_inicio,a.horario_preferido_id,a.plan_actual_id,a.plan_programado_id,a.plan_programado_desde,p.nombre AS plan_nombre,p.precio AS plan_precio,pp.nombre AS plan_programado_nombre,pp.precio AS plan_programado_precio,a.estado_administrativo,a.observaciones,a.created_at,a.updated_at FROM alumnos a LEFT JOIN planes p ON p.id=a.plan_actual_id LEFT JOIN planes pp ON pp.id=a.plan_programado_id ORDER BY a.nombre ASC");
        $alumnos=$stmt->fetchAll();out(['ok'=>true,'total'=>count($alumnos),'alumnos'=>$alumnos]);
    }
    if($method!=='POST')out(['ok'=>false,'error'=>'Método no permitido'],405);
    $input=json_decode(file_get_contents('php://input'),true);if(!is_array($input))out(['ok'=>false,'error'=>'JSON inválido'],400);
    $nombre=trim((string)($input['nombre']??''));$fechaNacimiento=trim((string)($input['fecha_nacimiento']??''));$whatsapp=trim((string)($input['whatsapp']??''));$correo=trim((string)($input['correo']??''));$fechaInicio=trim((string)($input['fecha_inicio']??''));$tipoIngreso=strtoupper(trim((string)($input['tipo_ingreso']??'REGULAR')));$horarioId=$input['horario_preferido_id']??null;$planId=$input['plan_actual_id']??null;$observaciones=$input['observaciones']??null;
    if($nombre==='')out(['ok'=>false,'error'=>'El nombre es obligatorio'],422);if($whatsapp==='')out(['ok'=>false,'error'=>'El WhatsApp es obligatorio'],422);if($fechaInicio==='')out(['ok'=>false,'error'=>'La fecha de inicio es obligatoria'],422);if(!in_array($tipoIngreso,['REGULAR','INTENSIVO'],true))out(['ok'=>false,'error'=>'Tipo de ingreso inválido'],422);
    if($tipoIngreso==='REGULAR'){
        if(empty($horarioId)||empty($planId))out(['ok'=>false,'error'=>'Plan y horario son obligatorios para alumnos regulares'],422);
        $stmt=$pdo->prepare("SELECT id FROM horarios WHERE id=:id AND activo=1 AND regular=1 LIMIT 1");$stmt->execute([':id'=>$horarioId]);if(!$stmt->fetch())out(['ok'=>false,'error'=>'Horario regular inválido'],422);
        $stmt=$pdo->prepare("SELECT id FROM planes WHERE id=:id AND activo=1 LIMIT 1");$stmt->execute([':id'=>$planId]);if(!$stmt->fetch())out(['ok'=>false,'error'=>'Plan inválido'],422);
    }else{$horarioId=null;$planId=null;}
    $pdo->beginTransaction();
    $id=(string)$pdo->query('SELECT UUID()')->fetchColumn();
    $stmt=$pdo->prepare("INSERT INTO alumnos(id,nombre,fecha_nacimiento,whatsapp,correo,fecha_inicio,horario_preferido_id,plan_actual_id,estado_administrativo,observaciones) VALUES(:id,:nombre,:nac,:wa,:correo,:inicio,:horario,:plan,'PENDIENTE',:obs)");
    $stmt->execute([':id'=>$id,':nombre'=>$nombre,':nac'=>$fechaNacimiento!==''?$fechaNacimiento:null,':wa'=>$whatsapp,':correo'=>$correo!==''?$correo:null,':inicio'=>$fechaInicio,':horario'=>$horarioId,':plan'=>$planId,':obs'=>$observaciones]);
    $usuario=usuario_unico($pdo,$nombre);$temporal='123456';$uid=(string)$pdo->query('SELECT UUID()')->fetchColumn();
    $stmt=$pdo->prepare("INSERT INTO usuarios(id,usuario,password_hash,rol,activo,debe_cambiar_password,alumno_id) VALUES(:id,:u,:p,'ALUMNO',1,1,:a)");
    $stmt->execute([':id'=>$uid,':u'=>$usuario,':p'=>password_hash($temporal,PASSWORD_DEFAULT),':a'=>$id]);
    $pdo->commit();
    $stmt=$pdo->prepare("SELECT a.*,p.nombre plan_nombre,p.precio plan_precio FROM alumnos a LEFT JOIN planes p ON p.id=a.plan_actual_id WHERE a.id=:id LIMIT 1");$stmt->execute([':id'=>$id]);
    out(['ok'=>true,'mensaje'=>'Alumno creado correctamente. Queda pendiente hasta registrar su primer pago válido.','tipo_ingreso'=>$tipoIngreso,'alumno'=>$stmt->fetch(),'acceso_portal'=>['usuario'=>$usuario,'password_temporal'=>$temporal,'debe_cambiar_password'=>true]],201);
} catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();out(['ok'=>false,'error'=>$e->getMessage()],500);}
