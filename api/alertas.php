<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
 $hoy=new DateTimeImmutable('today');$mes=(int)$hoy->format('n');$anio=(int)$hoy->format('Y');$alertas=[];
 $st=$pdo->prepare("SELECT COUNT(*) FROM alumnos a WHERE a.estado_administrativo='ACTIVO' AND a.plan_actual_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM mensualidades m WHERE m.alumno_id=a.id AND m.mes=:m AND m.anio=:a AND m.estado='PAGADA')");$st->execute([':m'=>$mes,':a'=>$anio]);$sinPago=(int)$st->fetchColumn();
 if($sinPago>0)$alertas[]=['tipo'=>'PAGO','nivel'=>'ALTA','titulo'=>"{$sinPago} alumno".($sinPago===1?'':'s')." sin mensualidad del mes",'detalle'=>'Revisar pagos pendientes antes de permitir acceso a clase.','href'=>'/alumnos.php?alerta=sin_pago'];
 $manana=$hoy->modify('+1 day')->format('Y-m-d');
 $st=$pdo->prepare("SELECT COUNT(*) FROM avisos_ausencia WHERE estado='ACTIVO' AND :f BETWEEN fecha_desde AND fecha_hasta");$st->execute([':f'=>$manana]);$aus=(int)$st->fetchColumn();
 if($aus>0)$alertas[]=['tipo'=>'AUSENCIA','nivel'=>'MEDIA','titulo'=>"{$aus} aviso".($aus===1?'':'s')." de ausencia para mañana",'detalle'=>'La asistencia los marcará como justificadas si no se presentan.','href'=>'/ausencias.php?alerta=manana'];
 $repos=(int)$pdo->query("SELECT COUNT(*) FROM reposiciones_regulares WHERE estado='DISPONIBLE'")->fetchColumn();
 if($repos>0)$alertas[]=['tipo'=>'REPOSICION','nivel'=>'BAJA','titulo'=>"{$repos} reposición".($repos===1?'':'es')." disponible".($repos===1?'':'s'),'detalle'=>'Hay reposiciones pendientes de asignar o utilizar.','href'=>'/ausencias.php?alerta=reposiciones'];
 $dias=(int)($pdo->query("SELECT valor FROM configuracion WHERE clave='alerta_dias_fin_intensivo' LIMIT 1")->fetchColumn()?:7);
 $limite=$hoy->modify("+{$dias} days")->format('Y-m-d');
 $st=$pdo->prepare("SELECT id,fecha_fin FROM cursos_intensivos WHERE estado='EN_CURSO' AND fecha_fin BETWEEN :h AND :l ORDER BY fecha_fin,fecha_inicio");$st->execute([':h'=>$hoy->format('Y-m-d'),':l'=>$limite]);
 foreach($st as $r)$alertas[]=['tipo'=>'INTENSIVO','nivel'=>'MEDIA','titulo'=>'Intensivo termina el '.date('d/m',strtotime($r['fecha_fin'])),'detalle'=>'Revisar continuidad de participantes y reposiciones.','href'=>'/intensivo-detalle.php?id='.rawurlencode((string)$r['id'])];
 $periodo=$hoy->format('Y-m');$desde=$periodo.'-01';$hasta=$hoy->modify('last day of this month')->format('Y-m-d');
 $st=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN tipo='MENSUALIDAD' THEN importe*.5 WHEN tipo='INTENSIVO' THEN importe*.5 WHEN tipo='INSCRIPCION' THEN importe ELSE 0 END),0) FROM pagos WHERE estado='VALIDO' AND DATE(fecha) BETWEEN :d AND :h");$st->execute([':d'=>$desde,':h'=>$hasta]);$proa=(float)$st->fetchColumn();
 $minimo=(float)($pdo->query("SELECT valor FROM configuracion WHERE clave='minimo_proa_mensual' LIMIT 1")->fetchColumn()?:28000);
 $alertas[]=['tipo'=>'PROA','nivel'=>$proa>=$minimo?'OK':'BAJA','titulo'=>$proa>=$minimo?'Mínimo PROA alcanzado ✓':'Mínimo PROA en progreso','detalle'=>$proa>=$minimo?'Las comisiones del próximo mes quedan habilitadas.':'Aporte actual: $'.number_format($proa,0).' de $'.number_format($minimo,0).'.','href'=>'/reportes.php?periodo='.rawurlencode($periodo)];
 out(['ok'=>true,'total'=>count($alertas),'alertas'=>$alertas]);
}catch(Throwable $e){out(['ok'=>false,'error'=>$e->getMessage()],500);}
