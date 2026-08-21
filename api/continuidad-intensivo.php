<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/reglas-acceso.php';
$config=require __DIR__.'/../config/database.php';

function continuidad_out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;}

try{
    $admin=auth_require(['ADMIN']);
    $pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    $sedeClave=auth_resolve_sede_clave($method==='GET'?(string)($_GET['sede']??''):null);
    $st=$pdo->prepare("SELECT id,clave,nombre FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");$st->execute([':c'=>$sedeClave]);$sede=$st->fetch();if(!$sede)continuidad_out(['ok'=>false,'error'=>'Sede inválida'],422);

    if($method==='GET'){
        $cursoId=trim((string)($_GET['curso_id']??''));$alumnoId=trim((string)($_GET['alumno_id']??''));
        if($cursoId===''||$alumnoId==='')continuidad_out(['ok'=>false,'error'=>'curso_id y alumno_id son obligatorios'],422);
        $st=$pdo->prepare("SELECT cia.id,cia.curso_intensivo_id,cia.alumno_id,a.nombre alumno_nombre,cia.continua_regular,cia.plan_continuidad_id,cia.importe_continuidad,cia.observacion_continuidad,a.plan_actual_id,a.horario_preferido_id,a.ciclo_pago,s.clave sede_clave FROM curso_intensivo_alumnos cia INNER JOIN alumnos a ON a.id=cia.alumno_id INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id INNER JOIN sedes s ON s.id=ci.sede_id WHERE cia.curso_intensivo_id=:c AND cia.alumno_id=:a AND ci.sede_id=:s LIMIT 1");
        $st->execute([':c'=>$cursoId,':a'=>$alumnoId,':s'=>$sede['id']]);$rel=$st->fetch();if(!$rel)continuidad_out(['ok'=>false,'error'=>'El alumno no pertenece a este intensivo de la sede activa'],404);
        $st=$pdo->prepare("SELECT id,nombre,sesiones_semana,precio FROM planes WHERE sede_id=:s AND activo=1 ORDER BY sesiones_semana,precio");$st->execute([':s'=>$sede['id']]);$planes=$st->fetchAll();
        $st=$pdo->prepare("SELECT id,hora_inicio,hora_fin FROM horarios WHERE sede_id=:s AND activo=1 AND regular=1 ORDER BY hora_inicio");$st->execute([':s'=>$sede['id']]);$horarios=$st->fetchAll();
        continuidad_out(['ok'=>true,'sede'=>$sede,'requiere_ciclo_pago'=>$sedeClave==='PALAPAS','relacion'=>$rel,'planes'=>$planes,'horarios'=>$horarios]);
    }

    if($method!=='POST')continuidad_out(['ok'=>false,'error'=>'Método no permitido'],405);
    $in=json_decode(file_get_contents('php://input'),true);if(!is_array($in))continuidad_out(['ok'=>false,'error'=>'JSON inválido'],400);
    $cursoId=trim((string)($in['curso_id']??''));$alumnoId=trim((string)($in['alumno_id']??''));$continua=filter_var($in['continua_regular']??null,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);$planId=trim((string)($in['plan_id']??''));$horarioId=trim((string)($in['horario_id']??''));$importe=$in['importe_continuidad']??null;$observacion=trim((string)($in['observacion_continuidad']??''));$ciclo=strtoupper(trim((string)($in['ciclo_pago']??'')));
    if($cursoId===''||$alumnoId===''||$continua===null)continuidad_out(['ok'=>false,'error'=>'Datos de continuidad incompletos'],422);
    $st=$pdo->prepare("SELECT cia.id FROM curso_intensivo_alumnos cia INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.curso_intensivo_id=:c AND cia.alumno_id=:a AND ci.sede_id=:s LIMIT 1");$st->execute([':c'=>$cursoId,':a'=>$alumnoId,':s'=>$sede['id']]);$relId=$st->fetchColumn();if(!$relId)continuidad_out(['ok'=>false,'error'=>'El alumno no pertenece a este intensivo de la sede activa'],404);

    if(!$continua){$st=$pdo->prepare("UPDATE curso_intensivo_alumnos SET continua_regular=0,plan_continuidad_id=NULL,importe_continuidad=NULL,observacion_continuidad=:o WHERE id=:id");$st->execute([':o'=>$observacion!==''?$observacion:null,':id'=>$relId]);continuidad_out(['ok'=>true,'mensaje'=>'Continuidad marcada como no']);}
    if($planId===''||$horarioId==='')continuidad_out(['ok'=>false,'error'=>'Selecciona plan y horario regular'],422);
    if($sedeClave==='PALAPAS'&&!in_array($ciclo,['P1','P15'],true))continuidad_out(['ok'=>false,'error'=>'Para continuar como regular en Palapas selecciona P1 o P15'],422);
    if($sedeClave!=='PALAPAS')$ciclo='';
    $st=$pdo->prepare("SELECT id,precio FROM planes WHERE id=:id AND sede_id=:s AND activo=1 LIMIT 1");$st->execute([':id'=>$planId,':s'=>$sede['id']]);$plan=$st->fetch();if(!$plan)continuidad_out(['ok'=>false,'error'=>'El plan seleccionado no pertenece a la sede activa'],422);
    $st=$pdo->prepare("SELECT id FROM horarios WHERE id=:id AND sede_id=:s AND activo=1 AND regular=1 LIMIT 1");$st->execute([':id'=>$horarioId,':s'=>$sede['id']]);if(!$st->fetch())continuidad_out(['ok'=>false,'error'=>'El horario seleccionado no pertenece a la sede activa'],422);
    $importeFinal=($importe!==null&&$importe!==''&&is_numeric($importe))?number_format((float)$importe,2,'.',''):number_format((float)$plan['precio'],2,'.','');

    $pdo->beginTransaction();
    $st=$pdo->prepare("UPDATE curso_intensivo_alumnos SET continua_regular=1,plan_continuidad_id=:p,importe_continuidad=:i,observacion_continuidad=:o WHERE id=:id");$st->execute([':p'=>$planId,':i'=>$importeFinal,':o'=>$observacion!==''?$observacion:null,':id'=>$relId]);
    $st=$pdo->prepare("UPDATE alumnos SET plan_actual_id=:p,horario_preferido_id=:h,ciclo_pago=:c,estado_administrativo='PENDIENTE',updated_at=NOW() WHERE id=:a AND sede_id=:s");$st->execute([':p'=>$planId,':h'=>$horarioId,':c'=>$ciclo!==''?$ciclo:null,':a'=>$alumnoId,':s'=>$sede['id']]);
    regla_crear_mensualidad_pendiente($pdo,$alumnoId,(string)$sede['id'],$sedeClave,$ciclo!==''?$ciclo:null,$planId,(float)$plan['precio'],(string)$admin['id']);
    $acceso=regla_recalcular_alumno_regular($pdo,$alumnoId);
    $pdo->commit();
    continuidad_out(['ok'=>true,'mensaje'=>$sedeClave==='PALAPAS'?'Continuidad regular creada. Requiere inscripción y mensualidad para activar acceso.':'Continuidad regular creada. Inscripción exenta por venir de intensivo; requiere mensualidad para activar acceso.','reglas'=>['inscripcion'=>$sedeClave==='PALAPAS'?'OBLIGATORIA':'EXENTA_POR_INTENSIVO','mensualidad'=>'OBLIGATORIA','ciclo_pago'=>$ciclo!==''?$ciclo:null],'acceso_regular'=>$acceso]);
}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();continuidad_out(['ok'=>false,'error'=>$e->getMessage()],500);}
