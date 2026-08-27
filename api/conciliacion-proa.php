<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/periodos-financieros.php';
auth_require(['ADMIN','VERIFICADOR']);
$config=require __DIR__.'/../config/database.php';
function jsonOut(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;}
function site(PDO $pdo,string $clave):array{$st=$pdo->prepare("SELECT id,clave,nombre,socio,porcentaje_mensualidad_socio,porcentaje_intensivo_socio,porcentaje_inscripcion_socio,minimo_mensual_socio FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");$st->execute([':c'=>$clave]);$s=$st->fetch();if(!$s)jsonOut(['ok'=>false,'error'=>'Sede inválida'],422);return $s;}
function split(array $s,array $porTipo):array{
    $mens=(float)$porTipo['MENSUALIDAD']['total'];$inte=(float)$porTipo['INTENSIVO']['total'];$ins=(float)$porTipo['INSCRIPCION']['total'];
    $pm=(float)$s['porcentaje_mensualidad_socio'];$pi=(float)$s['porcentaje_intensivo_socio'];$pn=(float)$s['porcentaje_inscripcion_socio'];
    $sm=$mens*$pm/100;$si=$inte*$pi/100;$sn=$ins*$pn/100;$socio=$sm+$si+$sn;$total=$mens+$inte+$ins;
    return ['socio'=>$s['socio'],'reglas'=>['mensualidad_socio'=>$pm,'intensivo_socio'=>$pi,'inscripcion_socio'=>$pn],'por_tipo'=>['MENSUALIDAD'=>['total'=>$mens,'socio'=>$sm,'hache'=>$mens-$sm],'INTENSIVO'=>['total'=>$inte,'socio'=>$si,'hache'=>$inte-$si],'INSCRIPCION'=>['total'=>$ins,'socio'=>$sn,'hache'=>$ins-$sn]],'total_cobrado'=>$total,'total_socio'=>$socio,'total_hache'=>$total-$socio,'minimo_mensual_socio'=>$s['minimo_mensual_socio']!==null?(float)$s['minimo_mensual_socio']:null];
}
function obligation(PDO $pdo,string $period):array
{
    $site=site($pdo,'MONTEVERDE');
    $totals=financiero_totales($pdo,$site,$period);
    $monthly=(float)$totals['mensualidades_total'];
    $intensives=(float)$totals['intensivos_total'];
    $enrollments=(float)$totals['inscripciones_total'];
    $monthlyPartner=$monthly*((float)$site['porcentaje_mensualidad_socio']/100);
    $intensivePartner=$intensives*((float)$site['porcentaje_intensivo_socio']/100);
    $enrollmentPartner=$enrollments*((float)$site['porcentaje_inscripcion_socio']/100);
    return [
        'mensualidades'=>$monthly,
        'intensivos'=>$intensives,
        'inscripciones'=>$enrollments,
        'proa_mensualidades'=>$monthlyPartner,
        'proa_intensivos'=>$intensivePartner,
        'proa_inscripciones'=>$enrollmentPartner,
        'total_proa'=>$monthlyPartner+$intensivePartner+$enrollmentPartner,
        'rango'=>$totals['rango'],
    ];
}
function automaticCommissions(PDO $pdo,string $period):array{
    $stmt=$pdo->prepare('SELECT id,periodo,alumno_proa_nombre,importe,observacion,created_at FROM comisiones_proa WHERE periodo=:period ORDER BY alumno_proa_nombre,created_at');
    $stmt->execute([':period'=>$period.'-01']);
    $rows=$stmt->fetchAll();
    $total=0.0;
    foreach($rows as &$row){$row['importe']=(float)$row['importe'];$total+=(float)$row['importe'];}
    unset($row);
    return ['rows'=>$rows,'total'=>$total];
}
function movementEffect(string $type,float $amount):float{return $type==='COMISION_RECIBIDA_PROA'?0.0:-$amount;}
function exactMovementDate(string $value):string{
    foreach(['Y-m-d\TH:i','Y-m-d H:i:s'] as $format){$date=DateTimeImmutable::createFromFormat('!'.$format,$value);if($date&&$date->format($format)===$value)return $date->format('Y-m-d H:i:s');}
    throw new InvalidArgumentException('Fecha inválida');
}
try{
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    $config=require __DIR__.'/../config/database.php';
    $pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $clave=auth_resolve_sede_clave((string)($_GET['sede']??'MONTEVERDE'));
    $s=site($pdo,$clave);
    if($method==='GET'){
        $period=financiero_validar_periodo(trim((string)($_GET['periodo']??date('Y-m'))));
        $base=obligation($pdo,$period);
        $rango=$base['rango'];
        $start=$rango['inicio'].' 00:00:00';
        $end=(new DateTimeImmutable($rango['cierre']))->modify('+1 day')->format('Y-m-d').' 00:00:00';
        $automatic=automaticCommissions($pdo,$period);
        $stmt=$pdo->prepare('SELECT m.id,m.fecha,m.tipo,m.importe,m.alumno_nombre,m.referencia,m.observacion,m.estado,m.created_at,m.anulado_at,m.motivo_anulacion,u.usuario created_by_usuario,ua.usuario anulado_by_usuario FROM conciliacion_proa_movimientos m LEFT JOIN usuarios u ON u.id=m.created_by LEFT JOIN usuarios ua ON ua.id=m.anulado_by WHERE m.fecha>=:start AND m.fecha<:end ORDER BY m.fecha DESC,m.created_at DESC');
        $stmt->execute([':start'=>$start,':end'=>$end]);
        $rows=$stmt->fetchAll();
        $delivered=$direct=0.0;$cash=$transfers=0.0;
        foreach($rows as &$row){
            $row['importe']=(float)$row['importe'];
            $row['impacto']=$row['estado']==='ACTIVO'?movementEffect((string)$row['tipo'],(float)$row['importe']):0.0;
            if($row['estado']!=='ACTIVO')continue;
            if($row['tipo']==='EFECTIVO_A_PROA'){$cash+=(float)$row['importe'];$delivered+=(float)$row['importe'];}
            elseif($row['tipo']==='TRANSFERENCIA_A_PROA'){$transfers+=(float)$row['importe'];$delivered+=(float)$row['importe'];}
            elseif($row['tipo']==='PAGO_DIRECTO_PROA')$direct+=(float)$row['importe'];
        }
        unset($row);
        $commissions=(float)$automatic['total'];
        $balance=$base['total_proa']-$commissions-$delivered-$direct;
        $situation=abs($balance)<.005?'CUADRADO':($balance>0?'HACHE_DEBE_PROA':'PROA_DEBE_HACHE');
        jsonOut(['ok'=>true,'periodo'=>$period,'sede'=>'MONTEVERDE','rango_financiero'=>$rango,'base'=>$base,'comisiones_automaticas'=>$automatic['rows'],'resumen'=>['efectivo_a_proa'=>$cash,'transferencias_a_proa'=>$transfers,'entregado_a_proa'=>$delivered,'pagos_directos_proa'=>$direct,'comisiones_recibidas'=>$commissions,'saldo'=>$balance,'situacion'=>$situation],'movimientos'=>$rows]);
    }
    $me=auth_require(['ADMIN']);
    if($method!=='POST')jsonOut(['ok'=>false,'error'=>'Método no permitido'],405);
    $input=json_decode(file_get_contents('php://input'),true);
    if(!is_array($input))jsonOut(['ok'=>false,'error'=>'Solicitud JSON inválida'],400);
    $action=strtoupper(trim((string)($input['accion']??'CREAR')));
    if($action==='ANULAR'){
        $id=trim((string)($input['id']??''));$reason=trim((string)($input['motivo']??''));
        if($id===''||$reason==='')jsonOut(['ok'=>false,'error'=>'ID y motivo de anulación son obligatorios'],422);
        if(mb_strlen($reason)>255)jsonOut(['ok'=>false,'error'=>'El motivo de anulación no puede exceder 255 caracteres'],422);
        $stmt=$pdo->prepare("UPDATE conciliacion_proa_movimientos SET estado='ANULADO',anulado_by=:user,anulado_at=NOW(),motivo_anulacion=:reason WHERE id=:id AND estado='ACTIVO'");
        $stmt->execute([':user'=>$me['id'],':reason'=>$reason,':id'=>$id]);
        if($stmt->rowCount()!==1)jsonOut(['ok'=>false,'error'=>'Movimiento no encontrado o ya anulado'],404);
        jsonOut(['ok'=>true]);
    }
    if($action!=='CREAR')jsonOut(['ok'=>false,'error'=>'Acción inválida'],422);
    $allowed=['EFECTIVO_A_PROA','TRANSFERENCIA_A_PROA','PAGO_DIRECTO_PROA'];
    $type=strtoupper(trim((string)($input['tipo']??'')));$amount=filter_var($input['importe']??null,FILTER_VALIDATE_FLOAT);$date=trim((string)($input['fecha']??''));$student=trim((string)($input['alumno_nombre']??''));$studentId=trim((string)($input['alumno_id']??''));$reference=trim((string)($input['referencia']??''));$observation=trim((string)($input['observacion']??''));
    if(!in_array($type,$allowed,true))jsonOut(['ok'=>false,'error'=>'Tipo de movimiento inválido. Las comisiones PROA se cargan automáticamente.'],422);
    if($amount===false||$amount<=0||$amount>9999999.99)jsonOut(['ok'=>false,'error'=>'El importe debe ser mayor que cero y estar dentro del límite permitido'],422);
    if(mb_strlen($student)>180||mb_strlen($reference)>180||mb_strlen($observation)>2000)jsonOut(['ok'=>false,'error'=>'Uno de los textos excede la longitud permitida'],422);
    $date=$date===''?date('Y-m-d H:i:s'):exactMovementDate($date);
    if($type==='PAGO_DIRECTO_PROA'){
        if($studentId==='')jsonOut(['ok'=>false,'error'=>'Selecciona un alumno de la lista'],422);
        $stmt=$pdo->prepare("SELECT a.id,a.nombre FROM alumnos a INNER JOIN sedes s ON s.id=a.sede_id WHERE a.id=:id AND s.clave='MONTEVERDE' LIMIT 1");$stmt->execute([':id'=>$studentId]);$real=$stmt->fetch();
        if(!$real)jsonOut(['ok'=>false,'error'=>'El alumno seleccionado no existe en Monteverde'],422);
        $student=(string)$real['nombre'];
    }else{$student='';}
    $id=(string)$pdo->query('SELECT UUID()')->fetchColumn();
    $stmt=$pdo->prepare('INSERT INTO conciliacion_proa_movimientos(id,fecha,tipo,importe,alumno_nombre,referencia,observacion,created_by) VALUES(:id,:date,:type,:amount,:student,:reference,:observation,:user)');
    $stmt->execute([':id'=>$id,':date'=>$date,':type'=>$type,':amount'=>$amount,':student'=>$student!==''?$student:null,':reference'=>$reference!==''?$reference:null,':observation'=>$observation!==''?$observation:null,':user'=>$me['id']]);
    jsonOut(['ok'=>true,'id'=>$id],201);
}catch(InvalidArgumentException $e){jsonOut(['ok'=>false,'error'=>$e->getMessage()],422);
}catch(PDOException $e){if(str_contains($e->getMessage(),'conciliacion_proa_movimientos'))jsonOut(['ok'=>false,'error'=>'Falta instalar la tabla de conciliación PROA. Ejecuta el migrador incluido en el despliegue.'],503);error_log('[conciliacion-proa] '.$e->getMessage());jsonOut(['ok'=>false,'error'=>'Error de base de datos'],500);
}catch(Throwable $e){error_log('[conciliacion-proa] '.$e->getMessage());jsonOut(['ok'=>false,'error'=>'No se pudo procesar la conciliación'],500);}
