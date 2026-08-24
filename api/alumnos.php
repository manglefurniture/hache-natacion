<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/reglas-acceso.php';
require_once __DIR__.'/../config/telefono.php';
require_once __DIR__.'/../config/passwords.php';
$config = require __DIR__ . '/../config/database.php';

function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;}
function slug_usuario(string $nombre):string{$ascii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$nombre) ?: $nombre;$ascii=strtolower($ascii);$ascii=preg_replace('/[^a-z0-9 ]+/',' ',$ascii)??'';$parts=array_values(array_filter(preg_split('/\s+/',trim($ascii))?:[]));if(!$parts)return 'alumno';$base=$parts[0];if(count($parts)>1)$base.='.'.end($parts);return substr($base,0,40);}
function usuario_unico(PDO $pdo,string $nombre):string{$base=slug_usuario($nombre);$candidate=$base;$n=2;$st=$pdo->prepare("SELECT 1 FROM usuarios WHERE usuario=:u LIMIT 1");while(true){$st->execute([':u'=>$candidate]);if(!$st->fetchColumn())return $candidate;$candidate=$base.$n;$n++;}}
function sede(PDO $pdo,string $clave):array{$st=$pdo->prepare("SELECT id,clave,nombre FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");$st->execute([':c'=>$clave]);$s=$st->fetch();if(!$s)out(['ok'=>false,'error'=>'Sede inválida'],422);return $s;}
try {
    $pdo = new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $method=$_SERVER['REQUEST_METHOD'];
    if($method==='GET'){
        $me=auth_require(['ADMIN','VERIFICADOR']);
        $clave=auth_resolve_sede_clave((string)($_GET['sede']??''));
        $s=sede($pdo,$clave);
        if($me['rol']==='ADMIN'){
            regla_reconciliar_sede_una_vez($pdo,(string)$s['id'],(string)$s['clave']);
        }
        $where=' WHERE a.sede_id=:sede ';$params=[':sede'=>$s['id']];
        $stmt=$pdo->prepare("SELECT a.id,a.sede_id,s.clave sede_clave,s.nombre sede_nombre,a.ciclo_pago,a.nombre,a.fecha_nacimiento,a.whatsapp,a.correo,a.fecha_inicio,a.horario_preferido_id,a.plan_actual_id,a.plan_programado_id,a.plan_programado_desde,p.nombre AS plan_nombre,p.precio AS plan_precio,pp.nombre AS plan_programado_nombre,pp.precio AS plan_programado_precio,a.estado_administrativo,a.observaciones,a.created_at,a.updated_at FROM alumnos a INNER JOIN sedes s ON s.id=a.sede_id LEFT JOIN planes p ON p.id=a.plan_actual_id LEFT JOIN planes pp ON pp.id=a.plan_programado_id {$where} ORDER BY a.nombre ASC");$stmt->execute($params);$alumnos=$stmt->fetchAll();out(['ok'=>true,'total'=>count($alumnos),'alumnos'=>$alumnos]);
    }
    $admin=auth_require(['ADMIN']);
    if($method!=='POST')out(['ok'=>false,'error'=>'Método no permitido'],405);
    $input=json_decode(file_get_contents('php://input'),true);if(!is_array($input))out(['ok'=>false,'error'=>'JSON inválido'],400);
    $nombre=trim((string)($input['nombre']??''));$fechaNacimiento=trim((string)($input['fecha_nacimiento']??''));$whatsappNacional=trim((string)($input['whatsapp']??''));$whatsappPais=strtoupper(trim((string)($input['whatsapp_pais']??'MX')));$correo=trim((string)($input['correo']??''));$fechaInicio=trim((string)($input['fecha_inicio']??''));$tipoIngreso=strtoupper(trim((string)($input['tipo_ingreso']??'REGULAR')));$horarioId=$input['horario_preferido_id']??null;$planId=$input['plan_actual_id']??null;$observaciones=$input['observaciones']??null;$sedeClave=auth_resolve_sede_clave((string)($input['sede']??''));$s=sede($pdo,$sedeClave);$cicloPago=strtoupper(trim((string)($input['ciclo_pago']??'')));
    if(mb_strlen($nombre)<2||mb_strlen($nombre)>180)out(['ok'=>false,'error'=>'El nombre debe tener entre 2 y 180 caracteres'],422);if($whatsappNacional==='')out(['ok'=>false,'error'=>'El WhatsApp es obligatorio'],422);if($fechaInicio==='')out(['ok'=>false,'error'=>'La fecha de inicio es obligatoria'],422);if(!in_array($tipoIngreso,['REGULAR','INTENSIVO'],true))out(['ok'=>false,'error'=>'Tipo de ingreso inválido'],422);
    $inicio=DateTimeImmutable::createFromFormat('!Y-m-d',$fechaInicio);if(!$inicio||$inicio->format('Y-m-d')!==$fechaInicio)out(['ok'=>false,'error'=>'La fecha de inicio no es válida'],422);
    if($fechaNacimiento!==''){$nacimiento=DateTimeImmutable::createFromFormat('!Y-m-d',$fechaNacimiento);if(!$nacimiento||$nacimiento->format('Y-m-d')!==$fechaNacimiento||$nacimiento>new DateTimeImmutable('today'))out(['ok'=>false,'error'=>'La fecha de nacimiento no es válida'],422);}
    if($correo!==''&&!filter_var($correo,FILTER_VALIDATE_EMAIL))out(['ok'=>false,'error'=>'El correo no es válido'],422);if($observaciones!==null&&mb_strlen((string)$observaciones)>2000)out(['ok'=>false,'error'=>'Las observaciones no pueden exceder 2000 caracteres'],422);
    try{$whatsapp=telefono_normalizar($whatsappPais,$whatsappNacional);}catch(InvalidArgumentException $e){out(['ok'=>false,'error'=>$e->getMessage()],422);}
    $dup=$pdo->prepare("SELECT id,nombre FROM alumnos WHERE whatsapp=:w LIMIT 1");$dup->execute([':w'=>$whatsapp]);if($exist=$dup->fetch())out(['ok'=>false,'error'=>'Ese WhatsApp ya pertenece a '.$exist['nombre'].'.'],409);
    $planPrecio=null;
    if($s['clave']==='PALAPAS'&&$tipoIngreso==='REGULAR'){if(!in_array($cicloPago,['P1','P15'],true))out(['ok'=>false,'error'=>'Selecciona si el alumno regular de Palapas es P1 o P15'],422);}else{$cicloPago=null;}
    if($tipoIngreso==='REGULAR'){
        if(empty($horarioId)||empty($planId))out(['ok'=>false,'error'=>'Plan y horario son obligatorios para alumnos regulares'],422);
        $stmt=$pdo->prepare("SELECT id FROM horarios WHERE id=:id AND sede_id=:sede AND activo=1 AND regular=1 LIMIT 1");$stmt->execute([':id'=>$horarioId,':sede'=>$s['id']]);if(!$stmt->fetch())out(['ok'=>false,'error'=>'Horario regular inválido para la sede seleccionada'],422);
        $stmt=$pdo->prepare("SELECT id,precio FROM planes WHERE id=:id AND sede_id=:sede AND activo=1 LIMIT 1");$stmt->execute([':id'=>$planId,':sede'=>$s['id']]);$plan=$stmt->fetch();if(!$plan)out(['ok'=>false,'error'=>'Plan inválido para la sede seleccionada'],422);$planPrecio=(float)$plan['precio'];
    }else{$horarioId=null;$planId=null;}
    $temporal=password_temporal_segura();$passwordHash=password_hash($temporal,PASSWORD_DEFAULT);
    $pdo->beginTransaction();regla_bloquear_identidades_alumnos($pdo);
    $dup=$pdo->prepare("SELECT id,nombre FROM alumnos WHERE whatsapp=:w LIMIT 1");$dup->execute([':w'=>$whatsapp]);if($exist=$dup->fetch()){$pdo->rollBack();out(['ok'=>false,'error'=>'Ese WhatsApp ya pertenece a '.$exist['nombre'].'.'],409);}
    if($tipoIngreso==='REGULAR'){
        $stmt=$pdo->prepare("SELECT id FROM horarios WHERE id=:id AND sede_id=:sede AND activo=1 AND regular=1 LIMIT 1 FOR UPDATE");$stmt->execute([':id'=>$horarioId,':sede'=>$s['id']]);if(!$stmt->fetch()){$pdo->rollBack();out(['ok'=>false,'error'=>'El horario regular dejó de estar disponible en la sede seleccionada'],409);}
        $stmt=$pdo->prepare("SELECT id,precio FROM planes WHERE id=:id AND sede_id=:sede AND activo=1 LIMIT 1 FOR UPDATE");$stmt->execute([':id'=>$planId,':sede'=>$s['id']]);$plan=$stmt->fetch();if(!$plan){$pdo->rollBack();out(['ok'=>false,'error'=>'El plan dejó de estar disponible en la sede seleccionada'],409);}$planPrecio=(float)$plan['precio'];
    }
    $id=(string)$pdo->query('SELECT UUID()')->fetchColumn();$stmt=$pdo->prepare("INSERT INTO alumnos(id,sede_id,ciclo_pago,nombre,fecha_nacimiento,whatsapp,correo,fecha_inicio,horario_preferido_id,plan_actual_id,estado_administrativo,observaciones) VALUES(:id,:sede,:ciclo,:nombre,:nac,:wa,:correo,:inicio,:horario,:plan,'PENDIENTE',:obs)");$stmt->execute([':id'=>$id,':sede'=>$s['id'],':ciclo'=>$cicloPago,':nombre'=>$nombre,':nac'=>$fechaNacimiento!==''?$fechaNacimiento:null,':wa'=>$whatsapp,':correo'=>$correo!==''?$correo:null,':inicio'=>$fechaInicio,':horario'=>$horarioId,':plan'=>$planId,':obs'=>$observaciones]);
    if($tipoIngreso==='REGULAR')regla_crear_mensualidad_pendiente($pdo,$id,(string)$s['id'],(string)$s['clave'],$cicloPago,(string)$planId,(float)$planPrecio,(string)$admin['id'],new DateTimeImmutable($fechaInicio));
    $usuario=usuario_unico($pdo,$nombre);$uid=(string)$pdo->query('SELECT UUID()')->fetchColumn();$stmt=$pdo->prepare("INSERT INTO usuarios(id,usuario,password_hash,rol,activo,debe_cambiar_password,alumno_id) VALUES(:id,:u,:p,'ALUMNO',1,1,:a)");$stmt->execute([':id'=>$uid,':u'=>$usuario,':p'=>$passwordHash,':a'=>$id]);$pdo->commit();
    $stmt=$pdo->prepare("SELECT a.*,s.clave sede_clave,s.nombre sede_nombre,p.nombre plan_nombre,p.precio plan_precio FROM alumnos a INNER JOIN sedes s ON s.id=a.sede_id LEFT JOIN planes p ON p.id=a.plan_actual_id WHERE a.id=:id LIMIT 1");$stmt->execute([':id'=>$id]);out(['ok'=>true,'mensaje'=>$tipoIngreso==='REGULAR'?'Alumno creado. Queda pendiente de inscripción y primera mensualidad antes de poder tomar clase.':'Alumno intensivo creado. Su acceso depende del pago del curso.','tipo_ingreso'=>$tipoIngreso,'obligaciones'=>$tipoIngreso==='REGULAR'?['inscripcion'=>'PENDIENTE','mensualidad'=>'PENDIENTE']:['intensivo'=>'PENDIENTE'],'alumno'=>$stmt->fetch(),'acceso_portal'=>['usuario'=>$usuario,'password_temporal'=>$temporal,'debe_cambiar_password'=>true]],201);
} catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('[alumnos] '.$e->getMessage());out(['ok'=>false,'error'=>'No se pudo procesar el alumno'],500);}
