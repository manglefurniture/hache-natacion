<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
auth_require(['ADMIN','VERIFICADOR']);
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
 $clave=auth_resolve_sede_clave((string)($_GET['sede']??'MONTEVERDE'));$st=$pdo->prepare("SELECT id,nombre FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");$st->execute([':c'=>$clave]);$s=$st->fetch();if(!$s)out(['ok'=>false,'error'=>'Sede inválida'],422);$sid=$s['id'];
 $mes=(int)date('n');$anio=(int)date('Y');$hoy=date('Y-m-d');$inicio=sprintf('%04d-%02d-01',$anio,$mes);$fin=(new DateTimeImmutable($inicio))->modify('+1 month')->format('Y-m-d');
 $st=$pdo->prepare("SELECT COUNT(*) FROM alumnos WHERE sede_id=:s AND estado_administrativo<>'BAJA'");$st->execute([':s'=>$sid]);$alumnos=(int)$st->fetchColumn();
 $st=$pdo->prepare("SELECT COUNT(*) FROM alumnos WHERE sede_id=:s AND estado_administrativo='PENDIENTE'");$st->execute([':s'=>$sid]);$pend=(int)$st->fetchColumn();
 $st=$pdo->prepare("SELECT COUNT(*) c,COALESCE(SUM(importe_cobrado),0) total FROM mensualidades WHERE sede_id=:s AND mes=:m AND anio=:a AND estado='PAGADA'");$st->execute([':s'=>$sid,':m'=>$mes,':a'=>$anio]);$mens=$st->fetch();
 $st=$pdo->prepare("SELECT COUNT(*) c,COALESCE(SUM(p.importe),0) total FROM pagos p JOIN alumnos a ON a.id=p.alumno_id WHERE a.sede_id=:s AND p.estado='VALIDO' AND p.fecha>=:i AND p.fecha<:f");$st->execute([':s'=>$sid,':i'=>$inicio,':f'=>$fin]);$caja=$st->fetch();
 $st=$pdo->prepare("SELECT COUNT(*) FROM cursos_intensivos WHERE sede_id=:s AND estado IN ('PROGRAMADO','EN_CURSO')");$st->execute([':s'=>$sid]);$intensivos=(int)$st->fetchColumn();
 $st=$pdo->prepare("SELECT COUNT(*) FROM avisos_ausencia aa JOIN alumnos a ON a.id=aa.alumno_id WHERE a.sede_id=:s AND aa.estado='ACTIVO' AND CURDATE() BETWEEN aa.fecha_desde AND aa.fecha_hasta");$st->execute([':s'=>$sid]);$avisos=(int)$st->fetchColumn();
 $st=$pdo->prepare("SELECT COUNT(*) FROM reposiciones_regulares rr JOIN alumnos a ON a.id=rr.alumno_id WHERE a.sede_id=:s AND rr.estado='DISPONIBLE'");$st->execute([':s'=>$sid]);$repos=(int)$st->fetchColumn();
 $st=$pdo->prepare("SELECT COUNT(*) FROM alumnos a WHERE a.sede_id=:s AND a.estado_administrativo='ACTIVO' AND a.plan_actual_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM mensualidades m WHERE m.alumno_id=a.id AND m.sede_id=:sm AND m.mes=:m AND m.anio=:a AND m.estado='PAGADA')");$st->execute([':s'=>$sid,':sm'=>$sid,':m'=>$mes,':a'=>$anio]);$sinPago=(int)$st->fetchColumn();
 $alertas=($sinPago>0?1:0)+($avisos>0?1:0)+($repos>0?1:0);
 $st=$pdo->prepare("SELECT h.hora_inicio,h.hora_fin,COUNT(DISTINCT a.id) alumnos FROM horarios h LEFT JOIN alumnos a ON a.horario_preferido_id=h.id AND a.sede_id=:sa AND a.estado_administrativo='ACTIVO' WHERE h.sede_id=:sh AND h.activo=1 GROUP BY h.id,h.hora_inicio,h.hora_fin HAVING alumnos>0 ORDER BY h.hora_inicio");$st->execute([':sa'=>$sid,':sh'=>$sid]);$horarios=$st->fetchAll();
 out(['ok'=>true,'sede'=>['clave'=>$clave,'nombre'=>$s['nombre']],'fecha'=>$hoy,'alumnos_activos'=>$alumnos,'pendientes'=>$pend,'mensualidades'=>['cantidad'=>(int)$mens['c'],'total'=>(float)$mens['total']],'caja'=>['cantidad'=>(int)$caja['c'],'total'=>(float)$caja['total']],'intensivos'=>$intensivos,'avisos_hoy'=>$avisos,'reposiciones'=>$repos,'sin_pago_mes'=>$sinPago,'alertas'=>$alertas,'horarios'=>$horarios]);
}catch(Throwable $e){out(['ok'=>false,'error'=>$e->getMessage()],500);}