<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config=require __DIR__.'/../config/database.php';

try{
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);

// Aplica cambios de plan cuyo mes efectivo ya comenzó.
$pdo->exec("UPDATE alumnos SET plan_actual_id=plan_programado_id,plan_programado_id=NULL,plan_programado_desde=NULL,updated_at=NOW() WHERE plan_programado_id IS NOT NULL AND plan_programado_desde IS NOT NULL AND plan_programado_desde<=CURDATE()");

$input=json_decode(file_get_contents('php://input'),true);if(!is_array($input)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'JSON inválido']);exit;}
$alumnoId=trim((string)($input['alumno_id']??''));$tipo=strtoupper(trim((string)($input['tipo']??'')));$importe=$input['importe']??null;$metodo=strtoupper(trim((string)($input['metodo']??'')));$fecha=trim((string)($input['fecha']??''));$observacion=trim((string)($input['observacion']??''));$createdBy=trim((string)($input['created_by']??''));$cursoId=trim((string)($input['curso_intensivo_id']??''));$cambiarPlanId=trim((string)($input['cambiar_plan_id']??''));
if($alumnoId===''||$fecha===''||$createdBy===''||!in_array($tipo,['INSCRIPCION','MENSUALIDAD','INTENSIVO'],true)||!is_numeric($importe)||(float)$importe<0||!in_array($metodo,['EFECTIVO','TRANSFERENCIA','MERCADO_PAGO'],true)){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Datos de pago incompletos o inválidos'],JSON_UNESCAPED_UNICODE);exit;}

try{$fechaPago=new DateTimeImmutable($fecha);}catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Fecha inválida']);exit;}
$fechaSql=$fechaPago->format('Y-m-d H:i:s');$fechaDia=$fechaPago->format('Y-m-d');$importeDecimal=number_format((float)$importe,2,'.','');
$periodoMes=(int)($input['periodo_mes']??$fechaPago->format('n'));$periodoAnio=(int)($input['periodo_anio']??$fechaPago->format('Y'));
if($periodoMes<1||$periodoMes>12||$periodoAnio<2000||$periodoAnio>2100){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Periodo de mensualidad inválido']);exit;}
$periodoInicio=new DateTimeImmutable(sprintf('%04d-%02d-01',$periodoAnio,$periodoMes));$mesActualInicio=new DateTimeImmutable(date('Y-m-01'));

$stmt=$pdo->prepare("SELECT id FROM usuarios WHERE id=:id LIMIT 1");$stmt->execute([':id'=>$createdBy]);if(!$stmt->fetch()){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Usuario administrativo inválido']);exit;}
$stmt=$pdo->prepare("SELECT a.id,a.nombre,a.plan_actual_id,a.plan_programado_id,a.plan_programado_desde,a.horario_preferido_id,p.nombre plan_nombre,p.precio plan_precio,pp.nombre plan_programado_nombre,pp.precio plan_programado_precio FROM alumnos a LEFT JOIN planes p ON p.id=a.plan_actual_id LEFT JOIN planes pp ON pp.id=a.plan_programado_id WHERE a.id=:id LIMIT 1");$stmt->execute([':id'=>$alumnoId]);$alumno=$stmt->fetch();if(!$alumno){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Alumno no encontrado']);exit;}

$pdo->beginTransaction();$inscripcionId=null;$mensualidadId=null;$intensivoId=null;$cambioPlanProgramado=false;

if($tipo==='INSCRIPCION'){
 $stmt=$pdo->prepare("SELECT p.fecha,p.folio FROM pagos p WHERE p.alumno_id=:id AND p.tipo='INSCRIPCION' AND p.estado='VALIDO' ORDER BY p.fecha DESC LIMIT 1");$stmt->execute([':id'=>$alumnoId]);$ult=$stmt->fetch();
 if($ult){$u=new DateTimeImmutable(substr($ult['fecha'],0,10));$permitida=$u->modify('first day of this month')->modify('+3 months');if($fechaPago<$permitida)throw new RuntimeException('No corresponde nueva inscripción todavía. Próxima fecha posible: '.$permitida->format('d/m/Y'));}
 $inscripcionId=$pdo->query("SELECT UUID()")->fetchColumn();$stmt=$pdo->prepare("INSERT INTO inscripciones(id,alumno_id,fecha,origen,importe,observacion,created_by) VALUES(:id,:alumno,:fecha,'REGULAR',:importe,:obs,:uid)");$stmt->execute([':id'=>$inscripcionId,':alumno'=>$alumnoId,':fecha'=>$fechaDia,':importe'=>$importeDecimal,':obs'=>$observacion!==''?$observacion:null,':uid'=>$createdBy]);
}
elseif($tipo==='MENSUALIDAD'){
 $stmt=$pdo->prepare("SELECT id,estado FROM mensualidades WHERE alumno_id=:id AND mes=:mes AND anio=:anio LIMIT 1");$stmt->execute([':id'=>$alumnoId,':mes'=>$periodoMes,':anio'=>$periodoAnio]);$exist=$stmt->fetch();if($exist&&$exist['estado']==='PAGADA')throw new RuntimeException('La mensualidad de '.sprintf('%02d/%d',$periodoMes,$periodoAnio).' ya está pagada');

 $planPagoId=$alumno['plan_actual_id'];$planPagoNombre=$alumno['plan_nombre'];$planPagoPrecio=$alumno['plan_precio'];
 if(!empty($alumno['plan_programado_id'])&&!empty($alumno['plan_programado_desde'])){$desde=new DateTimeImmutable($alumno['plan_programado_desde']);if($periodoInicio>=$desde){$planPagoId=$alumno['plan_programado_id'];$planPagoNombre=$alumno['plan_programado_nombre'];$planPagoPrecio=$alumno['plan_programado_precio'];}}

 if($cambiarPlanId!==''){
   $stmt=$pdo->prepare("SELECT id,nombre,precio FROM planes WHERE id=:id AND activo=1 LIMIT 1");$stmt->execute([':id'=>$cambiarPlanId]);$nuevo=$stmt->fetch();if(!$nuevo)throw new RuntimeException('El plan seleccionado no está disponible');if(abs((float)$nuevo['precio']-(float)$importe)>0.009)throw new RuntimeException('El importe no coincide con el plan seleccionado');
   $planPagoId=$nuevo['id'];$planPagoNombre=$nuevo['nombre'];$planPagoPrecio=$nuevo['precio'];
   if($periodoInicio>$mesActualInicio){$stmt=$pdo->prepare("UPDATE alumnos SET plan_programado_id=:plan,plan_programado_desde=:desde,updated_at=NOW() WHERE id=:alumno");$stmt->execute([':plan'=>$nuevo['id'],':desde'=>$periodoInicio->format('Y-m-d'),':alumno'=>$alumnoId]);$cambioPlanProgramado=true;}
   else{$stmt=$pdo->prepare("UPDATE alumnos SET plan_actual_id=:plan,plan_programado_id=NULL,plan_programado_desde=NULL,updated_at=NOW() WHERE id=:alumno");$stmt->execute([':plan'=>$nuevo['id'],':alumno'=>$alumnoId]);}
 }
 if(empty($planPagoId))throw new RuntimeException('El alumno no tiene un plan aplicable a este periodo');
 $estandar=number_format((float)$planPagoPrecio,2,'.','');if((float)$importeDecimal!==(float)$estandar&&$observacion==='')throw new RuntimeException('El importe es distinto al plan del periodo; agrega una observación o confirma el cambio de plan');

 if($exist){$mensualidadId=$exist['id'];$stmt=$pdo->prepare("UPDATE mensualidades SET plan_id=:plan,importe_estandar=:estandar,importe_a_cobrar=:importe_a_cobrar,importe_cobrado=:importe_cobrado,estado='PAGADA',fecha_pago=:fecha,observacion=:obs,updated_at=NOW() WHERE id=:id");$stmt->execute([':plan'=>$planPagoId,':estandar'=>$estandar,':importe_a_cobrar'=>$importeDecimal,':importe_cobrado'=>$importeDecimal,':fecha'=>$fechaSql,':obs'=>$observacion!==''?$observacion:null,':id'=>$mensualidadId]);}
 else{$mensualidadId=$pdo->query("SELECT UUID()")->fetchColumn();$stmt=$pdo->prepare("INSERT INTO mensualidades(id,alumno_id,mes,anio,plan_id,importe_estandar,importe_a_cobrar,importe_cobrado,estado,observacion,fecha_pago,created_by) VALUES(:id,:alumno,:mes,:anio,:plan,:estandar,:importe_a_cobrar,:importe_cobrado,'PAGADA',:obs,:fecha,:uid)");$stmt->execute([':id'=>$mensualidadId,':alumno'=>$alumnoId,':mes'=>$periodoMes,':anio'=>$periodoAnio,':plan'=>$planPagoId,':estandar'=>$estandar,':importe_a_cobrar'=>$importeDecimal,':importe_cobrado'=>$importeDecimal,':obs'=>$observacion!==''?$observacion:null,':fecha'=>$fechaSql,':uid'=>$createdBy]);}
}
else{
 if($cursoId!==''){$stmt=$pdo->prepare("SELECT ci.id FROM curso_intensivo_alumnos cia INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.alumno_id=:alumno AND ci.id=:curso AND ci.estado IN ('PROGRAMADO','EN_CURSO') LIMIT 1");$stmt->execute([':alumno'=>$alumnoId,':curso'=>$cursoId]);}else{$stmt=$pdo->prepare("SELECT ci.id FROM curso_intensivo_alumnos cia INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.alumno_id=:alumno AND ci.estado IN ('PROGRAMADO','EN_CURSO') ORDER BY ci.fecha_inicio DESC LIMIT 1");$stmt->execute([':alumno'=>$alumnoId]);}$curso=$stmt->fetch();if(!$curso)throw new RuntimeException('El alumno no está inscrito en un intensivo activo');$intensivoId=$curso['id'];$stmt=$pdo->prepare("SELECT id FROM pagos WHERE intensivo_id=:curso AND alumno_id=:alumno AND tipo='INTENSIVO' AND estado='VALIDO' LIMIT 1");$stmt->execute([':curso'=>$intensivoId,':alumno'=>$alumnoId]);if($stmt->fetch())throw new RuntimeException('Este alumno ya pagó este curso intensivo');
}

$stmt=$pdo->prepare("INSERT INTO pagos(alumno_id,inscripcion_id,mensualidad_id,intensivo_id,tipo,importe,metodo,fecha,estado,observacion,created_by) VALUES(:alumno,:ins,:men,:int,:tipo,:importe,:metodo,:fecha,'VALIDO',:obs,:uid)");$stmt->execute([':alumno'=>$alumnoId,':ins'=>$inscripcionId,':men'=>$mensualidadId,':int'=>$intensivoId,':tipo'=>$tipo,':importe'=>$importeDecimal,':metodo'=>$metodo,':fecha'=>$fechaSql,':obs'=>$observacion!==''?$observacion:null,':uid'=>$createdBy]);$folio=(int)$pdo->lastInsertId();

// El primer pago válido activa al alumno. BAJA nunca se reactiva automáticamente.
$stmt=$pdo->prepare("UPDATE alumnos SET estado_administrativo='ACTIVO',updated_at=NOW() WHERE id=:id AND estado_administrativo='PENDIENTE'");$stmt->execute([':id'=>$alumnoId]);
$pdo->commit();
$stmt=$pdo->prepare("SELECT p.*,a.nombre alumno_nombre FROM pagos p INNER JOIN alumnos a ON a.id=p.alumno_id WHERE p.folio=:folio LIMIT 1");$stmt->execute([':folio'=>$folio]);echo json_encode(['ok'=>true,'mensaje'=>'Pago registrado correctamente','periodo_mensualidad'=>$tipo==='MENSUALIDAD'?sprintf('%02d/%d',$periodoMes,$periodoAnio):null,'cambio_plan_programado'=>$cambioPlanProgramado,'pago'=>$stmt->fetch()],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
