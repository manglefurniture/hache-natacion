<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config=require __DIR__.'/../config/database.php';
try{
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function out(array $d,int $code=200):never{http_response_code($code);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function basePagos():string{return " FROM pagos p LEFT JOIN mensualidades m ON m.id=p.mensualidad_id LEFT JOIN cursos_intensivos ci ON ci.id=p.intensivo_id LEFT JOIN inscripciones i ON i.id=p.inscripcion_id ";}
function periodoEconomico():string{return "CASE WHEN p.tipo='MENSUALIDAD' AND m.id IS NOT NULL THEN CONCAT(m.anio,'-',LPAD(m.mes,2,'0')) WHEN p.tipo='INTENSIVO' AND ci.id IS NOT NULL THEN DATE_FORMAT(ci.fecha_inicio,'%Y-%m') WHEN p.tipo='INSCRIPCION' AND i.id IS NOT NULL THEN DATE_FORMAT(i.fecha,'%Y-%m') ELSE DATE_FORMAT(p.fecha,'%Y-%m') END";}
$periodo=(string)($_GET['periodo']??date('Y-m'));
if(!preg_match('/^\d{4}-\d{2}$/',$periodo)){$periodo=date('Y-m');}
$desde=$periodo.'-01';$hasta=(new DateTimeImmutable($desde))->modify('last day of this month')->format('Y-m-d');
$base=basePagos();$expr=periodoEconomico();
$st=$pdo->prepare("SELECT COUNT(*) pagos_count,COALESCE(SUM(p.importe),0) total,COALESCE(SUM(p.tipo='INSCRIPCION'),0) inscripciones_count,COALESCE(SUM(CASE WHEN p.tipo='INSCRIPCION' THEN p.importe ELSE 0 END),0) inscripciones_total,COALESCE(SUM(p.tipo='MENSUALIDAD'),0) mensualidades_count,COALESCE(SUM(CASE WHEN p.tipo='MENSUALIDAD' THEN p.importe ELSE 0 END),0) mensualidades_total,COALESCE(SUM(p.tipo='INTENSIVO'),0) intensivos_count,COALESCE(SUM(CASE WHEN p.tipo='INTENSIVO' THEN p.importe ELSE 0 END),0) intensivos_total {$base} WHERE p.estado='VALIDO' AND {$expr}=:periodo");$st->execute([':periodo'=>$periodo]);$fin=$st->fetch();
$mens=(float)$fin['mensualidades_total'];$int=(float)$fin['intensivos_total'];$ins=(float)$fin['inscripciones_total'];
$hache=($mens*.5)+($int*.5);$proa=($mens*.5)+($int*.5)+$ins;
$minimo=(float)($pdo->query("SELECT valor FROM configuracion WHERE clave='minimo_proa_mensual' LIMIT 1")->fetchColumn()?:28000);
$faltante=max(0,$minimo-$proa);$alcanzado=$proa>=$minimo;$progreso=$minimo>0?min(100,round(($proa/$minimo)*100,1)):100;
$inicioHist=(new DateTimeImmutable($desde))->modify('-11 months')->format('Y-m');
$st=$pdo->prepare("SELECT {$expr} periodo,COUNT(*) movimientos,SUM(p.importe) total,SUM(CASE WHEN p.tipo='INSCRIPCION' THEN p.importe ELSE 0 END) inscripciones,SUM(CASE WHEN p.tipo='MENSUALIDAD' THEN p.importe ELSE 0 END) mensualidades,SUM(CASE WHEN p.tipo='INTENSIVO' THEN p.importe ELSE 0 END) intensivos {$base} WHERE p.estado='VALIDO' AND {$expr} BETWEEN :d AND :h GROUP BY periodo ORDER BY periodo DESC");$st->execute([':d'=>$inicioHist,':h'=>$periodo]);$meses=$st->fetchAll();
foreach($meses as &$m){$mm=(float)$m['mensualidades'];$ii=(float)$m['intensivos'];$is=(float)$m['inscripciones'];$m['hache']=($mm*.5)+($ii*.5);$m['proa']=($mm*.5)+($ii*.5)+$is;$m['minimo_alcanzado']=$m['proa']>=$minimo;}unset($m);
$st=$pdo->prepare("SELECT COUNT(DISTINCT a.alumno_id) alumnos_con_asistencia,COALESCE(SUM(a.estado='PRESENTE'),0) presentes,COALESCE(SUM(a.estado='AUSENTE_JUSTIFICADA'),0) justificadas,COALESCE(SUM(a.estado='AUSENTE_NO_JUSTIFICADA'),0) no_justificadas FROM asistencias a JOIN sesiones s ON s.id=a.sesion_id WHERE s.fecha BETWEEN :d AND :h");$st->execute([':d'=>$desde,':h'=>$hasta]);$asis=$st->fetch();
out(['ok'=>true,'periodo'=>$periodo,'desde'=>$desde,'hasta'=>$hasta,'finanzas'=>$fin,'convenio_proa'=>['hache'=>$hache,'proa'=>$proa,'minimo'=>$minimo,'faltante'=>$faltante,'alcanzado'=>$alcanzado,'progreso'=>$progreso],'meses'=>$meses,'asistencia'=>$asis]);
}catch(Throwable $e){out(['ok'=>false,'error'=>$e->getMessage()],500);}
