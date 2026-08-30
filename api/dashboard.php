<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/periodos-financieros.php';
require_once __DIR__.'/../config/dashboard-tiempo.php';
auth_require(['ADMIN','VERIFICADOR']);
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
 $clave=auth_resolve_sede_clave((string)($_GET['sede']??'MONTEVERDE'));$st=$pdo->prepare("SELECT id,nombre FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");$st->execute([':c'=>$clave]);$s=$st->fetch();if(!$s)out(['ok'=>false,'error'=>'Sede inválida'],422);$sid=(string)$s['id'];
 $tiempo=dashboard_contexto_temporal($sid,static fn(string $sedeId,string $fecha):string=>financiero_periodo_para_fecha($pdo,$sedeId,$fecha));$hoy=$tiempo['fecha'];$periodoVigente=$tiempo['periodo_vigente'];
 $facturacion=financiero_totales($pdo,$s,$periodoVigente);$rangoPeriodo=$facturacion['rango']??financiero_rango($pdo,$sid,$periodoVigente);
 $sqlActivos="SELECT COUNT(*) FROM (
   SELECT m.alumno_id
   FROM mensualidades m
   INNER JOIN alumnos a ON a.id=m.alumno_id AND a.sede_id=m.sede_id
   WHERE m.sede_id=:sm AND m.estado='PAGADA' AND :hoy_m BETWEEN m.periodo_inicio AND m.periodo_fin AND a.estado_administrativo<>'BAJA'
   UNION
   SELECT cia.alumno_id
   FROM curso_intensivo_alumnos cia
   INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id
   INNER JOIN alumnos a ON a.id=cia.alumno_id AND a.sede_id=ci.sede_id
   WHERE ci.sede_id=:si AND :hoy_i BETWEEN ci.fecha_inicio AND ci.fecha_fin AND a.estado_administrativo<>'BAJA'
     AND EXISTS(SELECT 1 FROM pagos p WHERE p.alumno_id=cia.alumno_id AND p.intensivo_id=ci.id AND p.tipo='INTENSIVO' AND p.estado='VALIDO')
 ) activos";
 $st=$pdo->prepare($sqlActivos);$st->execute([':sm'=>$sid,':hoy_m'=>$hoy,':si'=>$sid,':hoy_i'=>$hoy]);$alumnos=(int)$st->fetchColumn();
 $st=$pdo->prepare("SELECT COUNT(*) FROM alumnos WHERE sede_id=:s AND estado_administrativo='PENDIENTE'");$st->execute([':s'=>$sid]);$pend=(int)$st->fetchColumn();
 $st=$pdo->prepare("SELECT COUNT(*) c,COALESCE(SUM(importe_cobrado),0) total FROM mensualidades WHERE sede_id=:s AND :hoy BETWEEN periodo_inicio AND periodo_fin AND estado='PAGADA'");$st->execute([':s'=>$sid,':hoy'=>$hoy]);$mens=$st->fetch();
 $st=$pdo->prepare("SELECT COUNT(*) FROM cursos_intensivos WHERE sede_id=:s AND estado IN ('PROGRAMADO','EN_CURSO')");$st->execute([':s'=>$sid]);$intensivos=(int)$st->fetchColumn();
 $st=$pdo->prepare("SELECT COUNT(*) FROM avisos_ausencia aa JOIN alumnos a ON a.id=aa.alumno_id WHERE a.sede_id=:s AND aa.estado='ACTIVO' AND :hoy BETWEEN aa.fecha_desde AND aa.fecha_hasta");$st->execute([':s'=>$sid,':hoy'=>$hoy]);$avisos=(int)$st->fetchColumn();
 $st=$pdo->prepare("SELECT COUNT(*) FROM reposiciones_regulares rr JOIN alumnos a ON a.id=rr.alumno_id WHERE a.sede_id=:s AND rr.estado='DISPONIBLE'");$st->execute([':s'=>$sid]);$repos=(int)$st->fetchColumn();
 $sqlSinPago="SELECT COUNT(*) FROM alumnos a WHERE a.sede_id=:sede AND a.estado_administrativo='ACTIVO' AND a.plan_actual_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM mensualidades m WHERE m.alumno_id=a.id AND m.sede_id=a.sede_id AND m.estado='PAGADA' AND :hoy BETWEEN m.periodo_inicio AND m.periodo_fin)";
 $st=$pdo->prepare($sqlSinPago);$st->execute([':sede'=>$sid,':hoy'=>$hoy]);$sinPago=(int)$st->fetchColumn();
 $alertas=($sinPago>0?1:0)+($avisos>0?1:0)+($repos>0?1:0);
 $st=$pdo->prepare("SELECT h.hora_inicio,h.hora_fin,COUNT(DISTINCT a.id) alumnos FROM horarios h LEFT JOIN alumnos a ON a.horario_preferido_id=h.id AND a.sede_id=:sa AND a.estado_administrativo='ACTIVO' WHERE h.sede_id=:sh AND h.activo=1 GROUP BY h.id,h.hora_inicio,h.hora_fin HAVING alumnos>0 ORDER BY h.hora_inicio");$st->execute([':sa'=>$sid,':sh'=>$sid]);$horarios=$st->fetchAll();
 out(['ok'=>true,'sede'=>['clave'=>$clave,'nombre'=>$s['nombre']],'fecha'=>$hoy,'periodo_vigente'=>$periodoVigente,'rango_periodo'=>['inicio'=>$rangoPeriodo['inicio'],'cierre'=>$rangoPeriodo['cierre']],'facturacion'=>['cantidad'=>(int)($facturacion['pagos_count']??0),'total'=>(float)($facturacion['total']??0)],'alumnos_activos'=>$alumnos,'pendientes'=>$pend,'mensualidades'=>['cantidad'=>(int)$mens['c'],'total'=>(float)$mens['total']],'intensivos'=>$intensivos,'avisos_hoy'=>$avisos,'reposiciones'=>$repos,'sin_pago_mes'=>$sinPago,'alertas'=>$alertas,'horarios'=>$horarios]);
}catch(Throwable $e){error_log('[dashboard] '.$e->getMessage());out(['ok'=>false,'error'=>'No se pudo cargar el dashboard'],500);}
