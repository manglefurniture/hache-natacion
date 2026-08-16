<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
 $mes=(int)date('n');$anio=(int)date('Y');$hoy=date('Y-m-d');
 $alumnos=(int)$pdo->query("SELECT COUNT(*) FROM alumnos WHERE estado_administrativo<>'BAJA'")->fetchColumn();
 $pend=(int)$pdo->query("SELECT COUNT(*) FROM alumnos WHERE estado_administrativo='PENDIENTE'")->fetchColumn();
 $st=$pdo->prepare("SELECT COUNT(*) c,COALESCE(SUM(importe_cobrado),0) total FROM mensualidades WHERE mes=:m AND anio=:a AND estado='PAGADA'");$st->execute([':m'=>$mes,':a'=>$anio]);$mens=$st->fetch();
 $st=$pdo->prepare("SELECT COUNT(*) c,COALESCE(SUM(importe),0) total FROM pagos WHERE estado='VALIDO' AND fecha>=:i AND fecha<:f");$inicio=sprintf('%04d-%02d-01',$anio,$mes);$fin=(new DateTimeImmutable($inicio))->modify('+1 month')->format('Y-m-d');$st->execute([':i'=>$inicio,':f'=>$fin]);$caja=$st->fetch();
 $intensivos=(int)$pdo->query("SELECT COUNT(*) FROM cursos_intensivos WHERE estado IN ('PROGRAMADO','EN_CURSO')")->fetchColumn();
 $avisos=(int)$pdo->query("SELECT COUNT(*) FROM avisos_ausencia WHERE estado='ACTIVO' AND CURDATE() BETWEEN fecha_desde AND fecha_hasta")->fetchColumn();
 $repos=(int)$pdo->query("SELECT COUNT(*) FROM reposiciones_regulares WHERE estado='DISPONIBLE'")->fetchColumn();
 $sinPagoSt=$pdo->prepare("SELECT COUNT(*) FROM alumnos a WHERE a.estado_administrativo='ACTIVO' AND a.plan_actual_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM mensualidades m WHERE m.alumno_id=a.id AND m.mes=:m AND m.anio=:a AND m.estado='PAGADA')");$sinPagoSt->execute([':m'=>$mes,':a'=>$anio]);$sinPago=(int)$sinPagoSt->fetchColumn();
 $alertas=($sinPago>0?1:0)+($avisos>0?1:0)+($repos>0?1:0);
 $st=$pdo->prepare("SELECT h.hora_inicio,h.hora_fin,COUNT(DISTINCT a.id) alumnos FROM horarios h LEFT JOIN alumnos a ON a.horario_preferido_id=h.id AND a.estado_administrativo='ACTIVO' WHERE h.activo=1 GROUP BY h.id,h.hora_inicio,h.hora_fin HAVING alumnos>0 ORDER BY h.hora_inicio");$st->execute();$horarios=$st->fetchAll();
 out(['ok'=>true,'fecha'=>$hoy,'alumnos_activos'=>$alumnos,'pendientes'=>$pend,'mensualidades'=>['cantidad'=>(int)$mens['c'],'total'=>(float)$mens['total']],'caja'=>['cantidad'=>(int)$caja['c'],'total'=>(float)$caja['total']],'intensivos'=>$intensivos,'avisos_hoy'=>$avisos,'reposiciones'=>$repos,'sin_pago_mes'=>$sinPago,'alertas'=>$alertas,'horarios'=>$horarios]);
}catch(Throwable $e){out(['ok'=>false,'error'=>$e->getMessage()],500);}