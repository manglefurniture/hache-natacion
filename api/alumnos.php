<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/reglas-acceso.php';
require_once __DIR__.'/../config/telefono.php';
require_once __DIR__.'/../config/passwords.php';
require_once __DIR__.'/../config/intensivos-estado.php';
$config = require __DIR__ . '/../config/database.php';

function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;}
function slug_usuario(string $nombre):string{$ascii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$nombre) ?: $nombre;$ascii=strtolower($ascii);$ascii=preg_replace('/[^a-z0-9 ]+/',' ',$ascii)??'';$parts=array_values(array_filter(preg_split('/\s+/',trim($ascii))?:[]));if(!$parts)return 'alumno';$base=$parts[0];if(count($parts)>1)$base.='.'.end($parts);return substr($base,0,40);}
function usuario_unico(PDO $pdo,string $nombre):string{$base=slug_usuario($nombre);$candidate=$base;$n=2;$st=$pdo->prepare("SELECT 1 FROM usuarios WHERE usuario=:u LIMIT 1");while(true){$st->execute([':u'=>$candidate]);if(!$st->fetchColumn())return $candidate;$candidate=$base.$n;$n++;}}
function sede(PDO $pdo,string $clave):array{$st=$pdo->prepare("SELECT id,clave,nombre FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");$st->execute([':c'=>$clave]);$s=$st->fetch();if(!$s)out(['ok'=>false,'error'=>'Sede inválida'],422);return $s;}
function fecha_exacta(string $fecha,string $mensaje):DateTimeImmutable{$d=DateTimeImmutable::createFromFormat('!Y-m-d',$fecha);if(!$d||$d->format('Y-m-d')!==$fecha)out(['ok'=>false,'error'=>$mensaje],422);return $d;}

try {
    $pdo = new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $method=$_SERVER['REQUEST_METHOD'];
    if($method==='GET'){
        $me=auth_require(['ADMIN','VERIFICADOR']);
        $clave=auth_resolve_sede_clave((string)($_GET['sede']??''));
        $s=sede($pdo,$clave);
        if($me['rol']==='ADMIN')regla_reconciliar_sede_una_vez($pdo,(string)$s['id'],(string)$s['clave']);
        $stmt=$pdo->prepare("SELECT a.id,a.sede_id,s.clave sede_clave,s.nombre sede_nombre,a.ciclo_pago,a.nombre,a.fecha_nacimiento,a.whatsapp,a.correo,a.fecha_inicio,a.horario_preferido_id,a.plan_actual_id,a.plan_programado_id,a.plan_programado_desde,p.nombre AS plan_nombre,p.precio AS plan_precio,pp.nombre AS plan_programado_nombre,pp.precio AS plan_programado_precio,a.estado_administrativo,a.observaciones,a.created_at,a.updated_at FROM alumnos a INNER JOIN sedes s ON s.id=a.sede_id LEFT JOIN planes p ON p.id=a.plan_actual_id LEFT JOIN planes pp ON pp.id=a.plan_programado_id WHERE a.sede_id=:sede ORDER BY a.nombre ASC");
        $stmt->execute([':sede'=>$s['id']]);$alumnos=$stmt->fetchAll();out(['ok'=>true,'total'=>count($alumnos),'alumnos'=>$alumnos]);
    }

    $admin=auth_require(['ADMIN']);
    if($method!=='POST')out(['ok'=>false,'error'=>'Método no permitido'],405);
    $input=json_decode(file_get_contents('php://input'),true);if(!is_array($input))out(['ok'=>false,'error'=>'JSON inválido'],400);

    $nombre=trim((string)($input['nombre']??''));
    $fechaNacimiento=trim((string)($input['fecha_nacimiento']??''));
    $whatsappNacional=trim((string)($input['whatsapp']??''));
    $whatsappPais=strtoupper(trim((string)($input['whatsapp_pais']??'MX')));
    $correo=trim((string)($input['correo']??''));
    $fechaInicio=trim((string)($input['fecha_inicio']??''));
    $tipoIngreso=strtoupper(trim((string)($input['tipo_ingreso']??'REGULAR')));
    $horarioId=trim((string)($input['horario_preferido_id']??''));
    $planId=trim((string)($input['plan_actual_id']??''));
    $cursoId=trim((string)($input['curso_intensivo_id']??''));
    $horarioIntensivoId=trim((string)($input['horario_intensivo_id']??''));
    $observaciones=trim((string)($input['observaciones']??''));
    $sedeClave=auth_resolve_sede_clave((string)($input['sede']??''));
    $s=sede($pdo,$sedeClave);
    $cicloPago=strtoupper(trim((string)($input['ciclo_pago']??'')));

    if(mb_strlen($nombre)<2||mb_strlen($nombre)>180)out(['ok'=>false,'error'=>'El nombre debe tener entre 2 y 180 caracteres'],422);
    if($whatsappNacional==='')out(['ok'=>false,'error'=>'El WhatsApp es obligatorio'],422);
    if(!in_array($tipoIngreso,['REGULAR','INTENSIVO'],true))out(['ok'=>false,'error'=>'Tipo de ingreso inválido'],422);
    if($fechaNacimiento!==''){$nacimiento=fecha_exacta($fechaNacimiento,'La fecha de nacimiento no es válida');if($nacimiento>new DateTimeImmutable('today'))out(['ok'=>false,'error'=>'La fecha de nacimiento no es válida'],422);}
    if($correo!==''&&!filter_var($correo,FILTER_VALIDATE_EMAIL))out(['ok'=>false,'error'=>'El correo no es válido'],422);
    if(mb_strlen($observaciones)>2000)out(['ok'=>false,'error'=>'Las observaciones no pueden exceder 2000 caracteres'],422);
    try{$whatsapp=telefono_normalizar($whatsappPais,$whatsappNacional);}catch(InvalidArgumentException $e){out(['ok'=>false,'error'=>$e->getMessage()],422);}

    $planPrecio=null;$curso=null;
    if($s['clave']==='PALAPAS'&&$tipoIngreso==='REGULAR'){
        if(!in_array($cicloPago,['P1','P15'],true))out(['ok'=>false,'error'=>'Selecciona si el alumno regular de Palapas es P1 o P15'],422);
    }else{$cicloPago=null;}

    if($tipoIngreso==='REGULAR'){
        if($fechaInicio==='')out(['ok'=>false,'error'=>'La fecha de inicio es obligatoria'],422);
        fecha_exacta($fechaInicio,'La fecha de inicio no es válida');
        if($horarioId===''||$planId==='')out(['ok'=>false,'error'=>'Plan y horario son obligatorios para alumnos regulares'],422);
        $stmt=$pdo->prepare("SELECT id FROM horarios WHERE id=:id AND sede_id=:sede AND activo=1 AND regular=1 LIMIT 1");$stmt->execute([':id'=>$horarioId,':sede'=>$s['id']]);if(!$stmt->fetch())out(['ok'=>false,'error'=>'Horario regular inválido para la sede seleccionada'],422);
        $stmt=$pdo->prepare("SELECT id,precio FROM planes WHERE id=:id AND sede_id=:sede AND activo=1 LIMIT 1");$stmt->execute([':id'=>$planId,':sede'=>$s['id']]);$plan=$stmt->fetch();if(!$plan)out(['ok'=>false,'error'=>'Plan inválido para la sede seleccionada'],422);$planPrecio=(float)$plan['precio'];
        $cursoId='';$horarioIntensivoId='';
    }else{
        if($cursoId===''||$horarioIntensivoId==='')out(['ok'=>false,'error'=>'Selecciona el curso intensivo y el horario'],422);
        intensivos_reconciliar_estados_sede($pdo,(string)$s['id']);
        $stmt=$pdo->prepare("SELECT id,fecha_inicio,fecha_fin,estado FROM cursos_intensivos WHERE id=:id AND sede_id=:s LIMIT 1");$stmt->execute([':id'=>$cursoId,':s'=>$s['id']]);$curso=$stmt->fetch();
        if(!$curso)out(['ok'=>false,'error'=>'El curso intensivo no existe en la sede seleccionada'],422);
        if(!intensivo_inscripcion_abierta((string)$curso['fecha_inicio']))out(['ok'=>false,'error'=>'La ventana de inscripción de este intensivo ya cerró'],422);
        $stmt=$pdo->prepare("SELECT id FROM horarios WHERE id=:id AND sede_id=:s AND activo=1 AND intensivo=1 LIMIT 1");$stmt->execute([':id'=>$horarioIntensivoId,':s'=>$s['id']]);if(!$stmt->fetch())out(['ok'=>false,'error'=>'Horario intensivo inválido para la sede seleccionada'],422);
        $fechaInicio=(string)$curso['fecha_inicio'];$horarioId='';$planId='';
    }

    $dup=$pdo->prepare("SELECT id,nombre FROM alumnos WHERE whatsapp=:w LIMIT 1");$dup->execute([':w'=>$whatsapp]);if($exist=$dup->fetch())out(['ok'=>false,'error'=>'Ese WhatsApp ya pertenece a '.$exist['nombre'].'.'],409);

    $temporal=password_temporal_segura();$passwordHash=password_hash($temporal,PASSWORD_DEFAULT);
    $pdo->beginTransaction();regla_bloquear_identidades_alumnos($pdo);
    $dup=$pdo->prepare("SELECT id,nombre FROM alumnos WHERE whatsapp=:w LIMIT 1");$dup->execute([':w'=>$whatsapp]);if($exist=$dup->fetch()){$pdo->rollBack();out(['ok'=>false,'error'=>'Ese WhatsApp ya pertenece a '.$exist['nombre'].'.'],409);}

    if($tipoIngreso==='REGULAR'){
        $stmt=$pdo->prepare("SELECT id FROM horarios WHERE id=:id AND sede_id=:sede AND activo=1 AND regular=1 LIMIT 1 FOR UPDATE");$stmt->execute([':id'=>$horarioId,':sede'=>$s['id']]);if(!$stmt->fetch()){$pdo->rollBack();out(['ok'=>false,'error'=>'El horario regular dejó de estar disponible en la sede seleccionada'],409);}
        $stmt=$pdo->prepare("SELECT id,precio FROM planes WHERE id=:id AND sede_id=:sede AND activo=1 LIMIT 1 FOR UPDATE");$stmt->execute([':id'=>$planId,':sede'=>$s['id']]);$plan=$stmt->fetch();if(!$plan){$pdo->rollBack();out(['ok'=>false,'error'=>'El plan dejó de estar disponible en la sede seleccionada'],409);}$planPrecio=(float)$plan['precio'];
    }else{
        $stmt=$pdo->prepare("SELECT id,fecha_inicio,fecha_fin,estado FROM cursos_intensivos WHERE id=:id AND sede_id=:s LIMIT 1 FOR UPDATE");$stmt->execute([':id'=>$cursoId,':s'=>$s['id']]);$curso=$stmt->fetch();
        if(!$curso||!intensivo_inscripcion_abierta((string)$curso['fecha_inicio'])){$pdo->rollBack();out(['ok'=>false,'error'=>'El curso ya no está disponible para nuevas inscripciones'],409);}
        $stmt=$pdo->prepare("SELECT id FROM horarios WHERE id=:id AND sede_id=:s AND activo=1 AND intensivo=1 LIMIT 1 FOR UPDATE");$stmt->execute([':id'=>$horarioIntensivoId,':s'=>$s['id']]);if(!$stmt->fetch()){$pdo->rollBack();out(['ok'=>false,'error'=>'El horario intensivo dejó de estar disponible'],409);}
        $fechaInicio=(string)$curso['fecha_inicio'];
    }

    $id=(string)$pdo->query('SELECT UUID()')->fetchColumn();
    $stmt=$pdo->prepare("INSERT INTO alumnos(id,sede_id,ciclo_pago,nombre,fecha_nacimiento,whatsapp,correo,fecha_inicio,horario_preferido_id,plan_actual_id,estado_administrativo,observaciones) VALUES(:id,:sede,:ciclo,:nombre,:nac,:wa,:correo,:inicio,:horario,:plan,'PENDIENTE',:obs)");
    $stmt->execute([':id'=>$id,':sede'=>$s['id'],':ciclo'=>$cicloPago,':nombre'=>$nombre,':nac'=>$fechaNacimiento!==''?$fechaNacimiento:null,':wa'=>$whatsapp,':correo'=>$correo!==''?$correo:null,':inicio'=>$fechaInicio,':horario'=>$tipoIngreso==='REGULAR'?$horarioId:null,':plan'=>$tipoIngreso==='REGULAR'?$planId:null,':obs'=>$observaciones!==''?$observaciones:null]);

    if($tipoIngreso==='REGULAR'){
        regla_crear_mensualidad_pendiente($pdo,$id,(string)$s['id'],(string)$s['clave'],$cicloPago,(string)$planId,(float)$planPrecio,(string)$admin['id'],new DateTimeImmutable($fechaInicio));
    }else{
        $relId=(string)$pdo->query('SELECT UUID()')->fetchColumn();
        $stmt=$pdo->prepare("INSERT INTO curso_intensivo_alumnos(id,curso_intensivo_id,alumno_id,horario_id,observaciones,created_by) VALUES(:id,:curso,:alumno,:horario,:obs,:uid)");
        $stmt->execute([':id'=>$relId,':curso'=>$cursoId,':alumno'=>$id,':horario'=>$horarioIntensivoId,':obs'=>'Alta desde administración. Pendiente de pago.',':uid'=>$admin['id']]);
    }

    $usuario=usuario_unico($pdo,$nombre);$uid=(string)$pdo->query('SELECT UUID()')->fetchColumn();
    $stmt=$pdo->prepare("INSERT INTO usuarios(id,usuario,password_hash,rol,activo,debe_cambiar_password,alumno_id) VALUES(:id,:u,:p,'ALUMNO',1,1,:a)");$stmt->execute([':id'=>$uid,':u'=>$usuario,':p'=>$passwordHash,':a'=>$id]);
    $pdo->commit();

    $stmt=$pdo->prepare("SELECT a.*,s.clave sede_clave,s.nombre sede_nombre,p.nombre plan_nombre,p.precio plan_precio FROM alumnos a INNER JOIN sedes s ON s.id=a.sede_id LEFT JOIN planes p ON p.id=a.plan_actual_id WHERE a.id=:id LIMIT 1");$stmt->execute([':id'=>$id]);
    out(['ok'=>true,'mensaje'=>$tipoIngreso==='REGULAR'?'Alumno creado. Queda pendiente de inscripción y primera mensualidad antes de poder tomar clase.':'Alumno creado y agregado al curso intensivo. Queda pendiente de pago.','tipo_ingreso'=>$tipoIngreso,'obligaciones'=>$tipoIngreso==='REGULAR'?['inscripcion'=>'PENDIENTE','mensualidad'=>'PENDIENTE']:['intensivo'=>'PENDIENTE'],'curso_intensivo_id'=>$tipoIngreso==='INTENSIVO'?$cursoId:null,'alumno'=>$stmt->fetch(),'acceso_portal'=>['usuario'=>$usuario,'password_temporal'=>$temporal,'debe_cambiar_password'=>true]],201);
} catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('[alumnos] '.$e->getMessage());out(['ok'=>false,'error'=>'No se pudo procesar el alumno'],500);}
