<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function out(array $d):never{echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
$desde=(string)($_GET['desde']??date('Y-m-01'));$hasta=(string)($_GET['hasta']??date('Y-m-t'));
if($hasta<$desde){[$desde,$hasta]=[$hasta,$desde];}
$st=$pdo->prepare("SELECT COUNT(*) pagos_count,COALESCE(SUM(importe),0) total,COALESCE(SUM(tipo='INSCRIPCION'),0) inscripciones_count,COALESCE(SUM(CASE WHEN tipo='INSCRIPCION' THEN importe ELSE 0 END),0) inscripciones_total,COALESCE(SUM(tipo='MENSUALIDAD'),0) mensualidades_count,COALESCE(SUM(CASE WHEN tipo='MENSUALIDAD' THEN importe ELSE 0 END),0) mensualidades_total,COALESCE(SUM(tipo='INTENSIVO'),0) intensivos_count,COALESCE(SUM(CASE WHEN tipo='INTENSIVO' THEN importe ELSE 0 END),0) intensivos_total FROM pagos WHERE estado='VALIDO' AND DATE(fecha) BETWEEN :d AND :h");$st->execute([':d'=>$desde,':h'=>$hasta]);$fin=$st->fetch();
$st=$pdo->prepare("SELECT DATE_FORMAT(fecha,'%Y-%m') periodo,COUNT(*) movimientos,SUM(importe) total,SUM(CASE WHEN tipo='INSCRIPCION' THEN importe ELSE 0 END) inscripciones,SUM(CASE WHEN tipo='MENSUALIDAD' THEN importe ELSE 0 END) mensualidades,SUM(CASE WHEN tipo='INTENSIVO' THEN importe ELSE 0 END) intensivos FROM pagos WHERE estado='VALIDO' AND DATE(fecha) BETWEEN :d AND :h GROUP BY DATE_FORMAT(fecha,'%Y-%m') ORDER BY periodo");$st->execute([':d'=>$desde,':h'=>$hasta]);$meses=$st->fetchAll();
$st=$pdo->prepare("SELECT COUNT(DISTINCT alumno_id) alumnos_con_asistencia,SUM(estado='PRESENTE') presentes,SUM(estado='AUSENTE_JUSTIFICADA') justificadas,SUM(estado='AUSENTE_NO_JUSTIFICADA') no_justificadas FROM asistencias a JOIN sesiones s ON s.id=a.sesion_id WHERE s.fecha BETWEEN :d AND :h");$st->execute([':d'=>$desde,':h'=>$hasta]);$asis=$st->fetch();
out(['ok'=>true,'desde'=>$desde,'hasta'=>$hasta,'finanzas'=>$fin,'meses'=>$meses,'asistencia'=>$asis]);
