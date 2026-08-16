<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config=require __DIR__.'/../config/database.php';

function jsonOut(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;}
function monthData(PDO $pdo,int $mes,int $anio):array{
    $inicio=sprintf('%04d-%02d-01',$anio,$mes);
    $fin=(new DateTimeImmutable($inicio))->modify('+1 month')->format('Y-m-d');

    $stmt=$pdo->prepare("SELECT COUNT(*) alumnos,SUM(COALESCE(importe_cobrado,0)) total FROM mensualidades WHERE mes=:mes AND anio=:anio AND estado='PAGADA'");
    $stmt->execute([':mes'=>$mes,':anio'=>$anio]);
    $mp=$stmt->fetch();

    $stmt=$pdo->prepare("SELECT tipo,COUNT(*) cantidad,COUNT(DISTINCT alumno_id) alumnos,SUM(importe) total FROM pagos WHERE estado='VALIDO' AND fecha>=:inicio AND fecha<:fin GROUP BY tipo");
    $stmt->execute([':inicio'=>$inicio,':fin'=>$fin]);
    $porTipo=['INSCRIPCION'=>['cantidad'=>0,'alumnos'=>0,'total'=>0.0],'MENSUALIDAD'=>['cantidad'=>0,'alumnos'=>0,'total'=>0.0],'INTENSIVO'=>['cantidad'=>0,'alumnos'=>0,'total'=>0.0]];
    foreach($stmt->fetchAll() as $r)$porTipo[$r['tipo']]=['cantidad'=>(int)$r['cantidad'],'alumnos'=>(int)$r['alumnos'],'total'=>(float)$r['total']];

    $stmt=$pdo->prepare("SELECT metodo,COUNT(*) cantidad,SUM(importe) total FROM pagos WHERE estado='VALIDO' AND fecha>=:inicio AND fecha<:fin GROUP BY metodo ORDER BY total DESC");
    $stmt->execute([':inicio'=>$inicio,':fin'=>$fin]);
    $metodos=[];foreach($stmt->fetchAll() as $r)$metodos[]=['metodo'=>$r['metodo'],'cantidad'=>(int)$r['cantidad'],'total'=>(float)$r['total']];

    $stmt=$pdo->prepare("SELECT COUNT(*) cantidad,COUNT(DISTINCT alumno_id) alumnos,SUM(importe) total FROM pagos WHERE estado='VALIDO' AND fecha>=:inicio AND fecha<:fin");
    $stmt->execute([':inicio'=>$inicio,':fin'=>$fin]);$caja=$stmt->fetch();

    return ['periodo'=>['mes'=>$mes,'anio'=>$anio],'mensualidades_periodo'=>['alumnos'=>(int)($mp['alumnos']??0),'total'=>(float)($mp['total']??0)],'caja'=>['cantidad'=>(int)($caja['cantidad']??0),'alumnos'=>(int)($caja['alumnos']??0),'total'=>(float)($caja['total']??0)],'por_tipo'=>$porTipo,'metodos'=>$metodos];
}

try{
    $pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);

    if(isset($_GET['desde'],$_GET['hasta'])){
        if(!preg_match('/^\d{4}-\d{2}$/',(string)$_GET['desde'])||!preg_match('/^\d{4}-\d{2}$/',(string)$_GET['hasta']))jsonOut(['ok'=>false,'error'=>'Rango inválido'],422);
        $desde=new DateTimeImmutable($_GET['desde'].'-01');$hasta=new DateTimeImmutable($_GET['hasta'].'-01');
        if($desde>$hasta)jsonOut(['ok'=>false,'error'=>'El periodo inicial no puede ser posterior al final'],422);
        $diff=((int)$hasta->format('Y')-(int)$desde->format('Y'))*12+((int)$hasta->format('n')-(int)$desde->format('n'));
        if($diff>59)jsonOut(['ok'=>false,'error'=>'El historial admite un máximo de 60 meses por consulta'],422);
        $meses=[];$tot=['caja'=>0.0,'pagos'=>0,'inscripciones'=>0.0,'mensualidades'=>0.0,'intensivos'=>0.0,'mensualidades_periodo'=>0.0];
        for($d=$desde;$d<=$hasta;$d=$d->modify('+1 month')){
            $x=monthData($pdo,(int)$d->format('n'),(int)$d->format('Y'));$meses[]=$x;
            $tot['caja']+=$x['caja']['total'];$tot['pagos']+=$x['caja']['cantidad'];$tot['inscripciones']+=$x['por_tipo']['INSCRIPCION']['total'];$tot['mensualidades']+=$x['por_tipo']['MENSUALIDAD']['total'];$tot['intensivos']+=$x['por_tipo']['INTENSIVO']['total'];$tot['mensualidades_periodo']+=$x['mensualidades_periodo']['total'];
        }
        jsonOut(['ok'=>true,'rango'=>['desde'=>$_GET['desde'],'hasta'=>$_GET['hasta'],'meses'=>count($meses)],'totales'=>$tot,'detalle'=>$meses]);
    }

    $mes=(int)($_GET['mes']??date('n'));$anio=(int)($_GET['anio']??date('Y'));
    if($mes<1||$mes>12||$anio<2000||$anio>2100)jsonOut(['ok'=>false,'error'=>'Periodo inválido'],422);
    jsonOut(['ok'=>true]+monthData($pdo,$mes,$anio));
}catch(Throwable $e){jsonOut(['ok'=>false,'error'=>$e->getMessage()],500);}
