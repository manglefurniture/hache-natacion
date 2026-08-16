<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function usuario(PDO $pdo):string{$id=(string)$pdo->query("SELECT id FROM usuarios WHERE activo=1 ORDER BY CASE WHEN rol='ADMIN' THEN 0 ELSE 1 END,created_at LIMIT 1")->fetchColumn();if(!$id)throw new RuntimeException('No hay usuario activo.');return $id;}
function generarSesiones(PDO $pdo,string $fecha,string $uid):int{
 $sql="SELECT DISTINCT h.id,h.hora_inicio FROM horarios h WHERE h.activo=1 AND (
 EXISTS(SELECT 1 FROM alumnos a JOIN mensualidades m ON m.alumno_id=a.id AND m.mes=MONTH(:f1) AND m.anio=YEAR(:f2) AND m.estado='PAGADA' WHERE a.horario_preferido_id=h.id AND a.estado_administrativo='ACTIVO')
 OR EXISTS(SELECT 1 FROM curso_intensivo_alumnos cia JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.horario_id=h.id AND ci.estado IN ('PROGRAMADO','EN_CURSO') AND :f3 BETWEEN ci.fecha_inicio AND ci.fecha_fin)
 ) ORDER BY h.hora_inicio";
 $st=$pdo->prepare($sql);$st->execute([':f1'=>$fecha,':f2'=>$fecha,':f3'=>$fecha]);$horarios=$st->fetchAll();$n=0;
 foreach($horarios as $h){$q=$pdo->prepare("SELECT id FROM sesiones WHERE fecha=:f AND horario_id=:h LIMIT 1");$q->execute([':f'=>$fecha,':h'=>$h['id']]);if($q->fetch())continue;$bloque=((int)substr((string)$h['hora_inicio'],0,2)<12)?'AM':'PM';$q=$pdo->prepare("INSERT INTO sesiones(fecha,bloque,horario_id,created_by) VALUES(:f,:b,:h,:u)");$q->execute([':f'=>$fecha,':b'=>$bloque,':h'=>$h['id'],':u'=>$uid]);$n++;}
 return $n;
}
function alumnosSesion(PDO $pdo,array $s,string $fecha):array{
 if(!$s['horario_id'])return [];
 $q=$pdo->prepare("SELECT a.id,a.nombre,a.estado_administrativo,p.sesiones_semana,m.estado mensualidad_estado,aa.estado asistencia_estado,aa.observacion,
 EXISTS(SELECT 1 FROM curso_intensivo_alumnos cia JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.alumno_id=a.id AND cia.horario_id=:h1 AND ci.estado IN ('PROGRAMADO','EN_CURSO') AND :f1 BETWEEN ci.fecha_inicio AND ci.fecha_fin) intensivo_activo,
 (SELECT cia.curso_intensivo_id FROM curso_intensivo_alumnos cia JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.alumno_id=a.id AND cia.horario_id=:h2 AND ci.estado IN ('PROGRAMADO','EN_CURSO') AND :f2 BETWEEN ci.fecha_inicio AND ci.fecha_fin LIMIT 1) curso_intensivo_id
 FROM alumnos a LEFT JOIN planes p ON p.id=a.plan_actual_id LEFT JOIN mensualidades m ON m.alumno_id=a.id AND m.mes=MONTH(:f3) AND m.anio=YEAR(:f4)
 LEFT JOIN asistencias aa ON aa.sesion_id=:sid AND aa.alumno_id=a.id
 WHERE (a.horario_preferido_id=:h3 AND a.estado_administrativo='ACTIVO') OR EXISTS(SELECT 1 FROM curso_intensivo_alumnos cia2 JOIN cursos_intensivos ci2 ON ci2.id=cia2.curso_intensivo_id WHERE cia2.alumno_id=a.id AND cia2.horario_id=:h4 AND ci2.estado IN ('PROGRAMADO','EN_CURSO') AND :f5 BETWEEN ci2.fecha_inicio AND ci2.fecha_fin)
 ORDER BY a.nombre");
 $q->execute([':h1'=>$s['horario_id'],':f1'=>$fecha,':h2'=>$s['horario_id'],':f2'=>$fecha,':f3'=>$fecha,':f4'=>$fecha,':sid'=>$s['id'],':h3'=>$s['horario_id'],':h4'=>$s['horario_id'],':f5'=>$fecha]);$rows=$q->fetchAll();
 foreach($rows as &$a){$a['puede_tomar_clase']=((int)$a['intensivo_activo']===1)||($a['estado_administrativo']==='ACTIVO'&&$a['mensualidad_estado']==='PAGADA');}unset($a);return $rows;
}
function aplicarReposicion(PDO $pdo,string $sid,string $aid,string $estado,string $uid):array{
 if($estado!=='AUSENTE_JUSTIFICADA')return ['generada'=>false];
 $st=$pdo->prepare("SELECT s.fecha,a.plan_actual_id,p.sesiones_semana,
 (SELECT cia.id FROM curso_intensivo_alumnos cia JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.alumno_id=a.id AND ci.estado IN ('PROGRAMADO','EN_CURSO') AND s.fecha BETWEEN ci.fecha_inicio AND ci.fecha_fin LIMIT 1) cia_id
 FROM sesiones s JOIN alumnos a ON a.id=:a LEFT JOIN planes p ON p.id=a.plan_actual_id WHERE s.id=:s LIMIT 1");$st->execute([':a'=>$aid,':s'=>$sid]);$x=$st->fetch();if(!$x)return ['generada'=>false];
 if($x['cia_id']){$st=$pdo->prepare("UPDATE curso_intensivo_alumnos SET reposiciones_justificadas=LEAST(reposiciones_justificadas+1,5) WHERE id=:id");$st->execute([':id'=>$x['cia_id']]);return ['generada'=>true,'tipo'=>'INTENSIVO','limite'=>5];}
 if((int)$x['sesiones_semana']!==3)return ['generada'=>false,'motivo'=>'El plan no genera reposiciones'];
 $st=$pdo->prepare("SELECT COUNT(*) FROM reposiciones_regulares rr JOIN asistencias aa ON aa.id=rr.ausencia_asistencia_id JOIN sesiones s ON s.id=aa.sesion_id WHERE rr.alumno_id=:a AND YEAR(s.fecha)=YEAR(:f1) AND MONTH(s.fecha)=MONTH(:f2) AND rr.estado IN ('DISPONIBLE','USADA')");$st->execute([':a'=>$aid,':f1'=>$x['fecha'],':f2'=>$x['fecha']]);if((int)$st->fetchColumn()>=2)return ['generada'=>false,'motivo'=>'Ya alcanzó 2 reposiciones del mes'];
 $st=$pdo->prepare("SELECT id FROM asistencias WHERE sesion_id=:s AND alumno_id=:a LIMIT 1");$st->execute([':s'=>$sid,':a'=>$aid]);$asistencia=$st->fetchColumn();if(!$asistencia)return ['generada'=>false];
 $id=(string)$pdo->query("SELECT UUID()")->fetchColumn();$st=$pdo->prepare("INSERT IGNORE INTO reposiciones_regulares(id,alumno_id,ausencia_asistencia_id,created_by) VALUES(:id,:a,:aus,:u)");$st->execute([':id'=>$id,':a'=>$aid,':aus'=>$asistencia,':u'=>$uid]);return ['generada'=>$st->rowCount()>0,'tipo'=>'REGULAR','limite'=>2];
}
try{
 $uid=usuario($pdo);
 if($_SERVER['REQUEST_METHOD']==='GET'){$fecha=(string)($_GET['fecha']??date('Y-m-d'));$creadas=generarSesiones($pdo,$fecha,$uid);$stmt=$pdo->prepare("SELECT s.id,s.fecha,s.bloque,s.horario_id,s.estado,s.cerrada,s.motivo_cancelacion,h.hora_inicio,h.hora_fin FROM sesiones s LEFT JOIN horarios h ON h.id=s.horario_id WHERE s.fecha=:f ORDER BY h.hora_inicio,s.bloque");$stmt->execute([':f'=>$fecha]);$sesiones=$stmt->fetchAll();foreach($sesiones as &$s)$s['alumnos']=alumnosSesion($pdo,$s,$fecha);unset($s);out(['ok'=>true,'fecha'=>$fecha,'sesiones_creadas'=>$creadas,'sesiones'=>$sesiones]);}
 if($_SERVER['REQUEST_METHOD']!=='POST')out(['ok'=>false,'error'=>'Método no permitido'],405);
 $in=json_decode(file_get_contents('php://input'),true)?:[];$accion=strtoupper((string)($in['accion']??''));
 if($accion==='GENERAR'){$fecha=(string)($in['fecha']??date('Y-m-d'));out(['ok'=>true,'creadas'=>generarSesiones($pdo,$fecha,$uid)]);}
 if($accion==='ASISTENCIA'){$sid=(string)($in['sesion_id']??'');$aid=(string)($in['alumno_id']??'');$estado=(string)($in['estado']??'');if(!in_array($estado,['PRESENTE','AUSENTE_JUSTIFICADA','AUSENTE_NO_JUSTIFICADA'],true))out(['ok'=>false,'error'=>'Estado inválido'],422);$pdo->beginTransaction();$st=$pdo->prepare("INSERT INTO asistencias(sesion_id,alumno_id,estado,observacion,created_by) VALUES(:s,:a,:e,:o,:u) ON DUPLICATE KEY UPDATE estado=VALUES(estado),observacion=VALUES(observacion),updated_at=NOW()");$st->execute([':s'=>$sid,':a'=>$aid,':e'=>$estado,':o'=>($in['observacion']??null),':u'=>$uid]);$repo=aplicarReposicion($pdo,$sid,$aid,$estado,$uid);$pdo->commit();out(['ok'=>true,'reposicion'=>$repo]);}
 if($accion==='CERRAR'){$sid=(string)($in['sesion_id']??'');$pend=$pdo->prepare("SELECT COUNT(*) FROM (SELECT a.id FROM alumnos a JOIN sesiones s ON s.id=:sid WHERE s.horario_id=a.horario_preferido_id AND a.estado_administrativo='ACTIVO' UNION SELECT cia.alumno_id FROM curso_intensivo_alumnos cia JOIN sesiones s2 ON s2.id=:sid2 JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.horario_id=s2.horario_id AND s2.fecha BETWEEN ci.fecha_inicio AND ci.fecha_fin AND ci.estado IN ('PROGRAMADO','EN_CURSO')) x LEFT JOIN asistencias aa ON aa.sesion_id=:sid3 AND aa.alumno_id=x.id WHERE aa.id IS NULL");$pend->execute([':sid'=>$sid,':sid2'=>$sid,':sid3'=>$sid]);$faltan=(int)$pend->fetchColumn();if($faltan>0)out(['ok'=>false,'error'=>'Faltan '.$faltan.' alumnos por marcar antes de cerrar'],422);$st=$pdo->prepare("UPDATE sesiones SET estado='REALIZADA',cerrada=1,fecha_cierre=NOW(),cerrada_por=:u WHERE id=:s AND cerrada=0");$st->execute([':u'=>$uid,':s'=>$sid]);out(['ok'=>true]);}
 if($accion==='CANCELAR'){$sid=(string)($in['sesion_id']??'');$mot=trim((string)($in['motivo']??''));if(!$mot)out(['ok'=>false,'error'=>'Indica el motivo'],422);$pdo->beginTransaction();$st=$pdo->prepare("SELECT fecha,horario_id FROM sesiones WHERE id=:s LIMIT 1");$st->execute([':s'=>$sid]);$ses=$st->fetch();if(!$ses)throw new RuntimeException('Sesión no encontrada');$st=$pdo->prepare("UPDATE sesiones SET estado='CANCELADA',motivo_cancelacion=:m,cerrada=1,fecha_cierre=NOW(),cerrada_por=:u WHERE id=:s");$st->execute([':m'=>$mot,':u'=>$uid,':s'=>$sid]);$st=$pdo->prepare("UPDATE curso_intensivo_alumnos cia JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id SET cia.reposiciones_cancelacion=cia.reposiciones_cancelacion+1 WHERE cia.horario_id=:h AND ci.estado IN ('PROGRAMADO','EN_CURSO') AND :f BETWEEN ci.fecha_inicio AND ci.fecha_fin");$st->execute([':h'=>$ses['horario_id'],':f'=>$ses['fecha']]);$pdo->commit();out(['ok'=>true]);}
 out(['ok'=>false,'error'=>'Acción inválida'],422);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();out(['ok'=>false,'error'=>$e->getMessage()],500);}
