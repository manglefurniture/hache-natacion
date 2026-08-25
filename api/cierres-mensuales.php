<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/periodos-financieros.php';
$user=auth_require(['ADMIN']);
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO(
    "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
    $config['user'],
    $config['password'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
);

function out(array $data,int $status=200):never
{
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function exactDate(string $value):string
{
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if(!$date||$date->format('Y-m-d')!==$value)throw new InvalidArgumentException('Fecha de cierre inválida');
    return $value;
}

function activeSite(PDO $pdo):array
{
    $key=auth_active_sede_clave();
    $stmt=$pdo->prepare('SELECT * FROM sedes WHERE clave=:clave AND activo=1 LIMIT 1');
    $stmt->execute([':clave'=>$key]);
    $site=$stmt->fetch();
    if(!$site)out(['ok'=>false,'error'=>'Sede activa inválida'],422);
    return $site;
}

function calculateClose(PDO $pdo,string $period,array $site):array
{
    $totals=financiero_totales($pdo,$site,$period);
    $monthly=(float)$totals['mensualidades_total'];
    $intensives=(float)$totals['intensivos_total'];
    $enrollments=(float)$totals['inscripciones_total'];
    $monthlyShare=(float)$site['porcentaje_mensualidad_socio']/100;
    $intensiveShare=(float)$site['porcentaje_intensivo_socio']/100;
    $enrollmentShare=(float)$site['porcentaje_inscripcion_socio']/100;
    $partner=$monthly*$monthlyShare+$intensives*$intensiveShare+$enrollments*$enrollmentShare;
    $hache=$monthly*(1-$monthlyShare)+$intensives*(1-$intensiveShare)+$enrollments*(1-$enrollmentShare);
    $minimum=$site['minimo_mensual_socio']!==null?(float)$site['minimo_mensual_socio']:0.0;
    return [
        'periodo'=>$period,
        'rango'=>$totals['rango'],
        'sede'=>$site['clave'],
        'socio_nombre'=>$site['socio'],
        'total'=>(float)$totals['total'],
        'pagos'=>(int)$totals['pagos_count'],
        'mensualidades'=>$monthly,
        'inscripciones'=>$enrollments,
        'intensivos'=>$intensives,
        'hache'=>$hache,
        'proa'=>$partner,
        'socio'=>$partner,
        'minimo'=>$minimum,
        'alcanzado'=>$minimum>0?$partner>=$minimum:null,
    ];
}

function closedPeriod(PDO $pdo,string $siteId,string $period):array|false
{
    $stmt=$pdo->prepare('SELECT c.*,u.usuario cerrado_por_usuario FROM cierres_mensuales c JOIN usuarios u ON u.id=c.cerrado_por WHERE c.sede_id=:site AND c.periodo=:period LIMIT 1');
    $stmt->execute([':site'=>$siteId,':period'=>$period.'-01']);
    return $stmt->fetch();
}

try{
    $site=activeSite($pdo);
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if($method==='GET'){
        $period=financiero_validar_periodo(trim((string)($_GET['periodo']??date('Y-m'))));
        $current=calculateClose($pdo,$period,$site);
        out(['ok'=>true,'sede'=>['clave'=>$site['clave'],'nombre'=>$site['nombre'],'socio'=>$site['socio']],'actual'=>$current,'cierre'=>closedPeriod($pdo,(string)$site['id'],$period)?:null]);
    }
    if($method!=='POST')out(['ok'=>false,'error'=>'Método no permitido'],405);
    $input=json_decode(file_get_contents('php://input'),true);
    if(!is_array($input))out(['ok'=>false,'error'=>'JSON inválido'],400);
    $period=financiero_validar_periodo(trim((string)($input['periodo']??'')));
    $action=strtoupper(trim((string)($input['accion']??'CERRAR')));

    if($action==='PERIODO'){
        if(closedPeriod($pdo,(string)$site['id'],$period))out(['ok'=>false,'error'=>'No se puede modificar el rango de un mes ya cerrado.'],409);
        $close=exactDate(trim((string)($input['fecha_cierre']??'')));
        $calendarEnd=(new DateTimeImmutable($period.'-01'))->modify('last day of this month')->format('Y-m-d');
        $currentRange=financiero_rango($pdo,(string)$site['id'],$period);
        if($close<$currentRange['inicio']||$close>$calendarEnd)out(['ok'=>false,'error'=>'La fecha de cierre debe estar entre el inicio del periodo y el último día de su mes nominal.'],422);
        $nextPeriod=financiero_periodo_siguiente($period);
        $nextRange=financiero_rango($pdo,(string)$site['id'],$nextPeriod);
        $nextStart=(new DateTimeImmutable($close))->modify('+1 day')->format('Y-m-d');
        if($nextStart>$nextRange['cierre'])out(['ok'=>false,'error'=>'El cierre dejaría inválido el periodo siguiente.'],422);

        $pdo->beginTransaction();
        if(closedPeriod($pdo,(string)$site['id'],$nextPeriod)){$pdo->rollBack();out(['ok'=>false,'error'=>'No se puede modificar este periodo porque cambiaría el inicio de un mes siguiente ya cerrado.'],409);}
        $stmt=$pdo->prepare('INSERT INTO periodos_financieros(sede_id,periodo,fecha_inicio,fecha_cierre,updated_by) VALUES(:s,:p,:i,:c,:u) ON DUPLICATE KEY UPDATE fecha_inicio=VALUES(fecha_inicio),fecha_cierre=VALUES(fecha_cierre),updated_by=VALUES(updated_by),updated_at=NOW()');
        $stmt->execute([':s'=>$site['id'],':p'=>$period.'-01',':i'=>$currentRange['inicio'],':c'=>$close,':u'=>$user['id']]);
        $stmt->execute([':s'=>$site['id'],':p'=>$nextPeriod.'-01',':i'=>$nextStart,':c'=>$nextRange['cierre'],':u'=>$user['id']]);
        $pdo->commit();
        out(['ok'=>true,'mensaje'=>'Periodo financiero actualizado','rango'=>financiero_rango($pdo,(string)$site['id'],$period),'siguiente'=>financiero_rango($pdo,(string)$site['id'],$nextPeriod)]);
    }

    if($action!=='CERRAR')out(['ok'=>false,'error'=>'Acción inválida'],422);
    $observation=trim((string)($input['observacion']??''));
    if(mb_strlen($observation)>1000)out(['ok'=>false,'error'=>'La observación no puede exceder 1000 caracteres'],422);
    $pdo->beginTransaction();
    $stmt=$pdo->prepare('SELECT 1 FROM cierres_mensuales WHERE sede_id=:site AND periodo=:period FOR UPDATE');
    $stmt->execute([':site'=>$site['id'],':period'=>$period.'-01']);
    if($stmt->fetchColumn()){
        $pdo->rollBack();
        out(['ok'=>false,'error'=>'Ese mes ya está cerrado para esta sede.'],409);
    }
    $current=calculateClose($pdo,$period,$site);
    $stmt=$pdo->prepare('INSERT INTO cierres_mensuales(sede_id,periodo,total_cobrado,mensualidades,inscripciones,intensivos,participacion_hache,participacion_proa,minimo_proa,minimo_alcanzado,total_pagos,observacion,cerrado_por) VALUES(:site,:period,:total,:monthly,:enrollments,:intensives,:hache,:partner,:minimum,:reached,:payments,:observation,:user)');
    $stmt->execute([
        ':site'=>$site['id'],':period'=>$period.'-01',':total'=>$current['total'],
        ':monthly'=>$current['mensualidades'],':enrollments'=>$current['inscripciones'],':intensives'=>$current['intensivos'],
        ':hache'=>$current['hache'],':partner'=>$current['socio'],':minimum'=>$current['minimo'],
        ':reached'=>$current['alcanzado']===true?1:0,':payments'=>$current['pagos'],
        ':observation'=>$observation!==''?$observation:null,':user'=>$user['id'],
    ]);
    $pdo->commit();
    out(['ok'=>true,'mensaje'=>'Mes cerrado correctamente para '.$site['nombre'],'cierre'=>$current],201);
}catch(InvalidArgumentException $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    out(['ok'=>false,'error'=>$e->getMessage()],422);
}catch(PDOException $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    if((string)$e->getCode()==='23000')out(['ok'=>false,'error'=>'Ese mes ya está cerrado para esta sede.'],409);
    error_log('[cierres-mensuales] '.$e->getMessage());
    out(['ok'=>false,'error'=>'No se pudo guardar el cierre mensual'],500);
}catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    error_log('[cierres-mensuales] '.$e->getMessage());
    out(['ok'=>false,'error'=>'No se pudo procesar el cierre mensual'],500);
}
