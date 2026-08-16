<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config=require __DIR__.'/../config/database.php';
try{
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$mes=(int)($_GET['mes']??date('n'));$anio=(int)($_GET['anio']??date('Y'));
if($mes<1||$mes>12||$anio<2000||$anio>2100){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Periodo inválido']);exit;}
$inicio=sprintf('%04d-%02d-01',$anio,$mes);$fin=(new DateTimeImmutable($inicio))->modify('+1 month')->format('Y-m-d');
$stmt=$pdo->prepare("SELECT COUNT(*) alumnos,SUM(COALESCE(importe_cobrado,0)) total FROM mensualidades WHERE mes=:mes AND anio=:anio AND estado='PAGADA'");$stmt->execute([':mes'=>$mes,':anio'=>$anio]);$mensualidadesPeriodo=$stmt->fetch();
$stmt=$pdo->prepare("SELECT tipo,COUNT(*) cantidad,COUNT(DISTINCT alumno_id) alumnos,SUM(importe) total FROM pagos WHERE estado='VALIDO' AND fecha>=:inicio AND fecha<:fin GROUP BY tipo");$stmt->execute([':inicio'=>$inicio,':fin'=>$fin]);$porTipo=['INSCRIPCION'=>['cantidad'=>0,'alumnos'=>0,'total'=>0.0],'MENSUALIDAD'=>['cantidad'=>0,'alumnos'=>0,'total'=>0.0],'INTENSIVO'=>['cantidad'=>0,'alumnos'=>0,'total'=>0.0]];foreach($stmt->fetchAll() as $r){$porTipo[$r['tipo']]=['cantidad'=>(int)$r['cantidad'],'alumnos'=>(int)$r['alumnos'],'total'=>(float)$r['total']];}
$stmt=$pdo->prepare("SELECT metodo,COUNT(*) cantidad,SUM(importe) total FROM pagos WHERE estado='VALIDO' AND fecha>=:inicio AND fecha<:fin GROUP BY metodo ORDER BY total DESC");$stmt->execute([':inicio'=>$inicio,':fin'=>$fin]);$metodos=[];foreach($stmt->fetchAll() as $r)$metodos[]=['metodo'=>$r['metodo'],'cantidad'=>(int)$r['cantidad'],'total'=>(float)$r['total']];
$stmt=$pdo->prepare("SELECT COUNT(*) cantidad,COUNT(DISTINCT alumno_id) alumnos,SUM(importe) total FROM pagos WHERE estado='VALIDO' AND fecha>=:inicio AND fecha<:fin");$stmt->execute([':inicio'=>$inicio,':fin'=>$fin]);$caja=$stmt->fetch();
$stmt=$pdo->prepare("SELECT DATE(fecha) fecha,SUM(importe) total FROM pagos WHERE estado='VALIDO' AND fecha>=:inicio AND fecha<:fin GROUP BY DATE(fecha) ORDER BY DATE(fecha)");$stmt->execute([':inicio'=>$inicio,':fin'=>$fin]);$diario=[];foreach($stmt->fetchAll() as $r)$diario[]=['fecha'=>$r['fecha'],'total'=>(float)$r['total']];
echo json_encode(['ok'=>true,'periodo'=>['mes'=>$mes,'anio'=>$anio],'mensualidades_periodo'=>['alumnos'=>(int)($mensualidadesPeriodo['alumnos']??0),'total'=>(float)($mensualidadesPeriodo['total']??0)],'caja'=>['cantidad'=>(int)($caja['cantidad']??0),'alumnos'=>(int)($caja['alumnos']??0),'total'=>(float)($caja['total']??0)],'por_tipo'=>$porTipo,'metodos'=>$metodos,'diario'=>$diario],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
}catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
