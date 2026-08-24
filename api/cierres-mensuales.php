<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
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

function validMonth(string $period):string
{
    if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$period))throw new InvalidArgumentException('Periodo inválido');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$period.'-01');
    if(!$date||$date->format('Y-m')!==$period)throw new InvalidArgumentException('Periodo inválido');
    return $period;
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
    [$year,$month]=array_map('intval',explode('-',$period));
    $start=$period.'-01';
    $end=(new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
    $sql="SELECT COUNT(*) pagos,COALESCE(SUM(p.importe),0) total,COALESCE(SUM(CASE WHEN p.tipo='MENSUALIDAD' THEN p.importe ELSE 0 END),0) mens,COALESCE(SUM(CASE WHEN p.tipo='INSCRIPCION' THEN p.importe ELSE 0 END),0) ins,COALESCE(SUM(CASE WHEN p.tipo='INTENSIVO' THEN p.importe ELSE 0 END),0) ints FROM pagos p LEFT JOIN mensualidades m ON m.id=p.mensualidad_id LEFT JOIN cursos_intensivos ci ON ci.id=p.intensivo_id LEFT JOIN inscripciones i ON i.id=p.inscripcion_id WHERE p.estado='VALIDO' AND ((p.tipo='MENSUALIDAD' AND m.sede_id=:s_m AND m.mes=:mes AND m.anio=:anio) OR (p.tipo='INTENSIVO' AND ci.sede_id=:s_ci AND ci.fecha_inicio BETWEEN :d_ci AND :h_ci) OR (p.tipo='INSCRIPCION' AND i.sede_id=:s_i AND i.fecha BETWEEN :d_i AND :h_i))";
    $stmt=$pdo->prepare($sql);
    $stmt->execute([
        ':s_m'=>$site['id'],':mes'=>$month,':anio'=>$year,
        ':s_ci'=>$site['id'],':d_ci'=>$start,':h_ci'=>$end,
        ':s_i'=>$site['id'],':d_i'=>$start,':h_i'=>$end,
    ]);
    $row=$stmt->fetch();
    $monthly=(float)$row['mens'];
    $intensives=(float)$row['ints'];
    $enrollments=(float)$row['ins'];
    $monthlyShare=(float)$site['porcentaje_mensualidad_socio']/100;
    $intensiveShare=(float)$site['porcentaje_intensivo_socio']/100;
    $enrollmentShare=(float)$site['porcentaje_inscripcion_socio']/100;
    $partner=$monthly*$monthlyShare+$intensives*$intensiveShare+$enrollments*$enrollmentShare;
    $hache=$monthly*(1-$monthlyShare)+$intensives*(1-$intensiveShare)+$enrollments*(1-$enrollmentShare);
    $minimum=$site['minimo_mensual_socio']!==null?(float)$site['minimo_mensual_socio']:0.0;
    return [
        'periodo'=>$period,
        'sede'=>$site['clave'],
        'socio_nombre'=>$site['socio'],
        'total'=>(float)$row['total'],
        'pagos'=>(int)$row['pagos'],
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

try{
    $site=activeSite($pdo);
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if($method==='GET'){
        $period=validMonth(trim((string)($_GET['periodo']??date('Y-m'))));
        $current=calculateClose($pdo,$period,$site);
        $stmt=$pdo->prepare('SELECT c.*,u.usuario cerrado_por_usuario FROM cierres_mensuales c JOIN usuarios u ON u.id=c.cerrado_por WHERE c.sede_id=:site AND c.periodo=:period LIMIT 1');
        $stmt->execute([':site'=>$site['id'],':period'=>$period.'-01']);
        out(['ok'=>true,'sede'=>['clave'=>$site['clave'],'nombre'=>$site['nombre'],'socio'=>$site['socio']],'actual'=>$current,'cierre'=>$stmt->fetch()?:null]);
    }
    if($method!=='POST')out(['ok'=>false,'error'=>'Método no permitido'],405);
    $input=json_decode(file_get_contents('php://input'),true);
    if(!is_array($input))out(['ok'=>false,'error'=>'JSON inválido'],400);
    $period=validMonth(trim((string)($input['periodo']??'')));
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
    if($pdo->inTransaction())$pdo->rollBack();
    out(['ok'=>false,'error'=>$e->getMessage()],422);
}catch(PDOException $e){
    if($pdo->inTransaction())$pdo->rollBack();
    if((string)$e->getCode()==='23000')out(['ok'=>false,'error'=>'Ese mes ya está cerrado para esta sede.'],409);
    error_log('[cierres-mensuales] '.$e->getMessage());
    out(['ok'=>false,'error'=>'No se pudo guardar el cierre mensual'],500);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('[cierres-mensuales] '.$e->getMessage());
    out(['ok'=>false,'error'=>'No se pudo procesar el cierre mensual'],500);
}
