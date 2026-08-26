<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
$me=auth_require(['ADMIN']);
require_once __DIR__.'/../config/reglas-acceso.php';
require_once __DIR__.'/../config/intensivos-estado.php';
$config=require __DIR__.'/../config/database.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);header('Allow: POST');echo json_encode(['ok'=>false,'error'=>'Método no permitido'],JSON_UNESCAPED_UNICODE);exit;}

function periodoMensualidad(string $sedeClave,?string $ciclo,int $anio,int $mes):array{
    $base=sprintf('%04d-%02d-01',$anio,$mes);
    if($sedeClave==='PALAPAS'&&$ciclo==='P15'){
        $inicio=new DateTimeImmutable(sprintf('%04d-%02d-15',$anio,$mes));
        $fin=$inicio->modify('+1 month')->modify('-1 day');
        return ['inicio'=>$inicio,'fin'=>$fin,'etiqueta'=>$inicio->format('d/m/Y').' al '.$fin->format('d/m/Y')];
    }
    $inicio=new DateTimeImmutable($base);$fin=$inicio->modify('last day of this month');
    return ['inicio'=>$inicio,'fin'=>$fin,'etiqueta'=>$inicio->format('d/m/Y').' al '.$fin->format('d/m/Y')];
}
function periodoActualInicio(string $sedeClave,?string $ciclo):DateTimeImmutable{
    $hoy=new DateTimeImmutable('today');
    if($sedeClave==='PALAPAS'&&$ciclo==='P15'){
        return ((int)$hoy->format('j')>=15)?new DateTimeImmutable($hoy->format('Y-m-15')):new DateTimeImmutable($hoy->modify('-1 month')->format('Y-m-15'));
    }
    return new DateTimeImmutable($hoy->format('Y-m-01'));
}
function etiquetaPeriodo(DateTimeImmutable $inicio,DateTimeImmutable $fin):string{return $inicio->format('d/m/Y').' al '.$fin->format('d/m/Y');}
function fechaPagoExacta(string $value):DateTimeImmutable{
    foreach(['Y-m-d H:i:s','Y-m-d\TH:i'] as $format){$date=DateTimeImmutable::createFromFormat('!'.$format,$value);if($date&&$date->format($format)===$value)return $date;}
    throw new InvalidArgumentException('Fecha de pago inválida');
}

try{
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$input=json_decode(file_get_contents('php://input'),true);if(!is_array($input)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'JSON inválido']);exit;}
$alumnoId=trim((string)($input['alumno_id']??''));$tipo=strtoupper(trim((string)($input['tipo']??'')));$importe=$input['importe']??null;$metodo=strtoupper(trim((string)($input['metodo']??'')));$fecha=trim((string)($input['fecha']??''));$observacion=trim((string)($input['observacion']??''));$createdBy=(string)$me['id'];$cursoId=trim((string)($input['curso_intensivo_id']??''));$cambiarPlanId=trim((string)($input['cambiar_plan_id']??''));$sedeClave=auth_resolve_sede_clave((string)($input['sede']??'MONTEVERDE'));
if($alumnoId===''||$fecha===''||!in_array($tipo,['INSCRIPCION','MENSUALIDAD','INTENSIVO'],true)||!is_numeric($importe)||(float)$importe<=0||(float)$importe>9999999.99||!in_array($metodo,['EFECTIVO','TRANSFERENCIA','MERCADO_PAGO'],true)){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Datos de pago incompletos o inválidos'],JSON_UNESCAPED_UNICODE);exit;}if(mb_strlen($observacion)>1000){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'La observación no puede exceder 1000 caracteres'],JSON_UNESCAPED_UNICODE);exit;}
try{$fechaPago=fechaPagoExacta($fecha);}catch(InvalidArgumentException $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);exit;}
$fechaSql=$fechaPago->format('Y-m-d H:i:s');$fechaDia=$fechaPago->format('Y-m-d');$importeDecimal=number_format((float)$importe,2,'.','');
$periodoExplicito=array_key_exists('periodo_mes',$input)&&array_key_exists('periodo_anio',$input);$periodoMes=(int)($input['periodo_mes']??$fechaPago->format('n'));$periodoAnio=(int)($input['periodo_anio']??$fechaPago->format('Y'));
if($periodoMes<1||$periodoMes>12||$periodoAnio<2000||$periodoAnio>2100){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Periodo de mensualidad inválido']);exit;}
$stmt=$pdo->prepare("SELECT id FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");$stmt->execute([':c'=>$sedeClave]);$sedeId=(string)$stmt->fetchColumn();if($sedeId===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Sede activa inválida']);exit;}
regla_promover_planes_programados_sede($pdo,$sedeId);
$pdo->beginTransaction();
$stmt=$pdo->prepare("SELECT a.id,a.sede_id,s.clave sede_clave,a.ciclo_pago,a.nombre,a.plan_actual_id,a.plan_programado_id,a.plan_programado_desde,a.horario_preferido_id,p.nombre plan_nombre,p.precio plan_precio,pp.nombre plan_programado_nombre,pp.precio plan_programado_precio FROM alumnos a JOIN sedes s ON s.id=a.sede_id LEFT JOIN planes p ON p.id=a.plan_actual_id AND p.sede_id=a.sede_id LEFT JOIN planes pp ON pp.id=a.plan_programado_id AND pp.sede_id=a.sede_id WHERE a.id=:id AND a.sede_id=:s LIMIT 1 FOR UPDATE");$stmt->execute([':id'=>$alumnoId,':s'=>$sedeId]);$alumno=$stmt->fetch();if(!$alumno){$pdo->rollBack();http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Alumno no encontrado en la sede seleccionada']);exit;}
if($tipo==='INTENSIVO')intensivos_reconciliar_estados_sede($pdo,$sedeId);
$ciclo=$alumno['ciclo_pago']!==null?strtoupper((string)$alumno['ciclo_pago']):null;
if($sedeClave==='PALAPAS'&&$tipo==='MENSUALIDAD'&&!in_array($ciclo,['P1','P15'],true))throw new RuntimeException('El alumno regular de Palapas no tiene ciclo P1/P15 definido');
if($tipo==='MENSUALIDAD'&&!$periodoExplicito){$actual=regla_periodo_regular_actual($sedeClave,$ciclo,$fechaPago);$periodoMes=(int)$actual['mes'];$periodoAnio=(int)$actual['anio'];}
$periodo=periodoMensualidad($sedeClave,$ciclo,$periodoAnio,$periodoMes);$periodoInicio=$periodo['inicio'];$periodoFin=$periodo['fin'];$periodoActual=periodoActualInicio($sedeClave,$ciclo);
$inscripcionId=null;$mensualidadId=null;$intensivoId=null;$cambioPlanProgramado=false;
if($tipo==='INSCRIPCION'){
 $stmt=$pdo->prepare("SELECT p.fecha,p.folio FROM pagos p INNER JOIN inscripciones i ON i.id=p.inscripcion_id WHERE p.alumno_id=:id AND i.sede_id=:s AND p.tipo='INSCRIPCION' AND p.estado='VALIDO' ORDER BY p.fecha DESC LIMIT 1");$stmt->execute([':id'=>$alumnoId,':s'=>$sedeId]);$ult=$stmt->fetch();
 if($ult){$u=new DateTimeImmutable(substr($ult['fecha'],0,10));$permitida=$u->modify('first day of this month')->modify('+3 months');if($fechaPago<$permitida)throw new RuntimeException('No corresponde nueva inscripción todavía. Próxima fecha posible: '.$permitida->format('d/m/Y'));}
 $inscripcionId=$pdo->query("SELECT UUID()")->fetchColumn();$stmt=$pdo->prepare("INSERT INTO inscripciones(id,sede_id,alumno_id,fecha,origen,importe,observacion,created_by) VALUES(:id,:sede,:alumno,:fecha,'REGULAR',:importe,:obs,:uid)");$stmt->execute([':id'=>$inscripcionId,':sede'=>$sedeId,':alumno'=>$alumnoId,':fecha'=>$fechaDia,':importe'=>$importeDecimal,':obs'=>$observacion!==''?$observacion:null,':uid'=>$createdBy]);
}elseif($tipo==='MENSUALIDAD'){
 $stmt=$pdo->prepare("SELECT m.id,m.estado,m.periodo_inicio,m.periodo_fin,m.plan_id,m.importe_estandar,m.importe_a_cobrar,EXISTS(SELECT 1 FROM pagos p WHERE p.mensualidad_id=m.id AND p.estado='VALIDO') tiene_pago_valido FROM mensualidades m WHERE m.alumno_id=:id AND m.sede_id=:sede AND m.mes=:mes AND m.anio=:anio LIMIT 1 FOR UPDATE");$stmt->execute([':id'=>$alumnoId,':sede'=>$sedeId,':mes'=>$periodoMes,':anio'=>$periodoAnio]);$exist=$stmt->fetch()?:null;
 if($exist){$periodoInicio=new DateTimeImmutable($exist['periodo_inicio']);$periodoFin=new DateTimeImmutable($exist['periodo_fin']);$periodo=['inicio'=>$periodoInicio,'fin'=>$periodoFin,'etiqueta'=>etiquetaPeriodo($periodoInicio,$periodoFin)];}
 if($exist&&($exist['estado']==='PAGADA'||(int)$exist['tiene_pago_valido']===1))throw new RuntimeException('La mensualidad del periodo '.$periodo['etiqueta'].' ya está pagada');
 $planPagoId=$exist?$exist['plan_id']:$alumno['plan_actual_id'];$planPagoPrecio=$exist?$exist['importe_estandar']:$alumno['plan_precio'];$importeEsperado=$exist?$exist['importe_a_cobrar']:null;
 if(!$exist&&!empty($alumno['plan_programado_id'])&&!empty($alumno['plan_programado_desde'])){$desde=new DateTimeImmutable($alumno['plan_programado_desde']);if($periodoInicio>=$desde){$planPagoId=$alumno['plan_programado_id'];$planPagoPrecio=$alumno['plan_programado_precio'];}}
 if($cambiarPlanId!==''){$stmt=$pdo->prepare("SELECT id,nombre,precio FROM planes WHERE id=:id AND sede_id=:s AND activo=1 LIMIT 1 FOR UPDATE");$stmt->execute([':id'=>$cambiarPlanId,':s'=>$sedeId]);$nuevo=$stmt->fetch();if(!$nuevo)throw new RuntimeException('El plan seleccionado no está disponible en la sede del alumno');if(abs((float)$nuevo['precio']-(float)$importe)>0.009)throw new RuntimeException('El importe no coincide con el plan seleccionado');$planPagoId=$nuevo['id'];$planPagoPrecio=$nuevo['precio'];$importeEsperado=null;if($periodoInicio>$periodoActual){$stmt=$pdo->prepare("UPDATE alumnos SET plan_programado_id=:plan,plan_programado_desde=:desde,updated_at=NOW() WHERE id=:alumno AND sede_id=:sede");$stmt->execute([':plan'=>$nuevo['id'],':desde'=>$periodoInicio->format('Y-m-d'),':alumno'=>$alumnoId,':sede'=>$sedeId]);$cambioPlanProgramado=true;}elseif($periodoInicio==$periodoActual){$stmt=$pdo->prepare("UPDATE alumnos SET plan_actual_id=:plan,plan_programado_id=NULL,plan_programado_desde=NULL,updated_at=NOW() WHERE id=:alumno AND sede_id=:sede");$stmt->execute([':plan'=>$nuevo['id'],':alumno'=>$alumnoId,':sede'=>$sedeId]);}}
 if(empty($planPagoId))throw new RuntimeException('El alumno no tiene un plan aplicable a este periodo');$estandar=number_format((float)$planPagoPrecio,2,'.','');$importeReferencia=$importeEsperado!==null?number_format((float)$importeEsperado,2,'.',''):$estandar;if((float)$importeDecimal!==(float)$importeReferencia&&$observacion==='')throw new RuntimeException('El importe es distinto al plan del periodo; agrega una observación o confirma el cambio de plan');
 if($exist){$mensualidadId=$exist['id'];$stmt=$pdo->prepare("UPDATE mensualidades SET periodo_inicio=:pinicio,periodo_fin=:pfin,plan_id=:plan,importe_estandar=:estandar,importe_a_cobrar=:iac,importe_cobrado=:ic,estado='PAGADA',fecha_pago=:fecha,observacion=COALESCE(:obs,observacion),updated_at=NOW() WHERE id=:id");$stmt->execute([':pinicio'=>$periodoInicio->format('Y-m-d'),':pfin'=>$periodoFin->format('Y-m-d'),':plan'=>$planPagoId,':estandar'=>$estandar,':iac'=>$importeDecimal,':ic'=>$importeDecimal,':fecha'=>$fechaSql,':obs'=>$observacion!==''?$observacion:null,':id'=>$mensualidadId]);}
 else{$mensualidadId=$pdo->query("SELECT UUID()")->fetchColumn();$stmt=$pdo->prepare("INSERT INTO mensualidades(id,sede_id,alumno_id,mes,anio,periodo_inicio,periodo_fin,plan_id,importe_estandar,importe_a_cobrar,importe_cobrado,estado,observacion,fecha_pago,created_by) VALUES(:id,:sede,:alumno,:mes,:anio,:pinicio,:pfin,:plan,:estandar,:iac,:ic,'PAGADA',:obs,:fecha,:uid)");$stmt->execute([':id'=>$mensualidadId,':sede'=>$sedeId,':alumno'=>$alumnoId,':mes'=>$periodoMes,':anio'=>$periodoAnio,':pinicio'=>$periodoInicio->format('Y-m-d'),':pfin'=>$periodoFin->format('Y-m-d'),':plan'=>$planPagoId,':estandar'=>$estandar,':iac'=>$importeDecimal,':ic'=>$importeDecimal,':obs'=>$observacion!==''?$observacion:null,':fecha'=>$fechaSql,':uid'=>$createdBy]);}
}else{
 if($cursoId!==''){$stmt=$pdo->prepare("SELECT ci.id FROM curso_intensivo_alumnos cia INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.alumno_id=:alumno AND ci.id=:curso AND ci.sede_id=:sede AND ci.estado IN ('PROGRAMADO','EN_CURSO') LIMIT 1");$stmt->execute([':alumno'=>$alumnoId,':curso'=>$cursoId,':sede'=>$sedeId]);}else{$stmt=$pdo->prepare("SELECT ci.id FROM curso_intensivo_alumnos cia INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.alumno_id=:alumno AND ci.sede_id=:sede AND ci.estado IN ('PROGRAMADO','EN_CURSO') ORDER BY ci.fecha_inicio DESC LIMIT 1");$stmt->execute([':alumno'=>$alumnoId,':sede'=>$sedeId]);}$curso=$stmt->fetch();if(!$curso)throw new RuntimeException('El alumno no está inscrito en un intensivo activo de esta sede');$intensivoId=$curso['id'];$stmt=$pdo->prepare("SELECT id FROM pagos WHERE intensivo_id=:curso AND alumno_id=:alumno AND tipo='INTENSIVO' AND estado='VALIDO' LIMIT 1");$stmt->execute([':curso'=>$intensivoId,':alumno'=>$alumnoId]);if($stmt->fetch())throw new RuntimeException('Este alumno ya pagó este curso intensivo');
}
$stmt=$pdo->prepare("INSERT INTO pagos(alumno_id,inscripcion_id,mensualidad_id,intensivo_id,tipo,importe,metodo,fecha,estado,observacion,created_by) VALUES(:alumno,:ins,:men,:int,:tipo,:importe,:metodo,:fecha,'VALIDO',:obs,:uid)");$stmt->execute([':alumno'=>$alumnoId,':ins'=>$inscripcionId,':men'=>$mensualidadId,':int'=>$intensivoId,':tipo'=>$tipo,':importe'=>$importeDecimal,':metodo'=>$metodo,':fecha'=>$fechaSql,':obs'=>$observacion!==''?$observacion:null,':uid'=>$createdBy]);$folio=(int)$pdo->lastInsertId();
regla_recalcular_alumno($pdo,$alumnoId);$pdo->commit();
$stmt=$pdo->prepare("SELECT p.*,a.nombre alumno_nombre FROM pagos p INNER JOIN alumnos a ON a.id=p.alumno_id WHERE p.folio=:folio LIMIT 1");$stmt->execute([':folio'=>$folio]);echo json_encode(['ok'=>true,'mensaje'=>'Pago registrado correctamente','periodo_mensualidad'=>$tipo==='MENSUALIDAD'?['mes'=>$periodoMes,'anio'=>$periodoAnio,'inicio'=>$periodoInicio->format('Y-m-d'),'fin'=>$periodoFin->format('Y-m-d'),'etiqueta'=>$periodo['etiqueta'],'ciclo'=>$ciclo]:null,'cambio_plan_programado'=>$cambioPlanProgramado,'pago'=>$stmt->fetch()],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
}catch(PDOException $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();error_log('pagos-smart: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'No se pudo registrar el pago'],JSON_UNESCAPED_UNICODE);}catch(RuntimeException|InvalidArgumentException $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();error_log('pagos-smart: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'No se pudo registrar el pago'],JSON_UNESCAPED_UNICODE);}
