<?php
declare(strict_types=1);
require_once __DIR__.'/../config/auth.php';
auth_require(['ADMIN','VERIFICADOR']);

$autoload=__DIR__.'/../vendor/autoload.php';
if(!is_file($autoload)){http_response_code(503);exit('Generador PDF no instalado. Ejecuta composer install/update en el servidor.');}
require_once $autoload;

use Dompdf\Dompdf;
use Dompdf\Options;

$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES=>false,
]);

function money(float|int|string|null $n):string{return '$'.number_format((float)$n,2,'.',',');}
function esc(string $v):string{return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
function monthName(string $periodo):string{[$y,$m]=array_map('intval',explode('-',$periodo));$meses=[1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];return ($meses[$m]??$periodo).' '.$y;}
function dateLabel(string $fecha):string{$meses=[1=>'enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];$d=new DateTimeImmutable($fecha);return (int)$d->format('j').' de '.$meses[(int)$d->format('n')].' de '.$d->format('Y');}

$periodo=(string)($_GET['periodo']??date('Y-m'));if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$periodo)){http_response_code(422);exit('Periodo inválido');}
$clave=auth_resolve_sede_clave((string)($_GET['sede']??'MONTEVERDE'));
$st=$pdo->prepare("SELECT * FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");$st->execute([':c'=>$clave]);$sede=$st->fetch();if(!$sede){http_response_code(422);exit('Sede inválida');}
$sedeId=$sede['id'];[$anio,$mes]=array_map('intval',explode('-',$periodo));$desde=$periodo.'-01';$hasta=(new DateTimeImmutable($desde))->modify('last day of this month')->format('Y-m-d');

$sql="SELECT COUNT(*) pagos_count,COALESCE(SUM(p.importe),0) total,COALESCE(SUM(p.tipo='INSCRIPCION'),0) inscripciones_count,COALESCE(SUM(CASE WHEN p.tipo='INSCRIPCION' THEN p.importe ELSE 0 END),0) inscripciones_total,COALESCE(SUM(p.tipo='MENSUALIDAD'),0) mensualidades_count,COALESCE(SUM(CASE WHEN p.tipo='MENSUALIDAD' THEN p.importe ELSE 0 END),0) mensualidades_total,COALESCE(SUM(p.tipo='INTENSIVO'),0) intensivos_count,COALESCE(SUM(CASE WHEN p.tipo='INTENSIVO' THEN p.importe ELSE 0 END),0) intensivos_total FROM pagos p LEFT JOIN mensualidades m ON m.id=p.mensualidad_id LEFT JOIN inscripciones i ON i.id=p.inscripcion_id LEFT JOIN cursos_intensivos ci ON ci.id=p.intensivo_id WHERE p.estado='VALIDO' AND ((p.tipo='MENSUALIDAD' AND m.sede_id=:sm AND m.mes=:mes AND m.anio=:anio) OR (p.tipo='INSCRIPCION' AND i.sede_id=:si AND i.fecha BETWEEN :di AND :hi) OR (p.tipo='INTENSIVO' AND ci.sede_id=:sc AND ci.fecha_inicio BETWEEN :dc AND :hc))";
$params=[':sm'=>$sedeId,':mes'=>$mes,':anio'=>$anio,':si'=>$sedeId,':di'=>$desde,':hi'=>$hasta,':sc'=>$sedeId,':dc'=>$desde,':hc'=>$hasta];$st=$pdo->prepare($sql);$st->execute($params);$fin=$st->fetch();
$mens=(float)$fin['mensualidades_total'];$int=(float)$fin['intensivos_total'];$ins=(float)$fin['inscripciones_total'];
$pm=(float)$sede['porcentaje_mensualidad_socio']/100;$pi=(float)$sede['porcentaje_intensivo_socio']/100;$pins=(float)$sede['porcentaje_inscripcion_socio']/100;
$aporteMens=$mens*$pm;$aporteInt=$int*$pi;$aporteIns=$ins*$pins;$aporteBase=$aporteMens+$aporteInt;$socio=$aporteBase+$aporteIns;
$minimo=$sede['minimo_mensual_socio']!==null?(float)$sede['minimo_mensual_socio']:0.0;$faltante=$minimo>0?max(0,$minimo-$socio):0.0;$alcanzado=$minimo>0?$socio>=$minimo:null;$socioNombre=(string)($sede['socio']?:'Socio');

$st=$pdo->prepare("SELECT a.id,a.nombre,COALESCE(SUM(p.importe),0) inscripcion,COALESCE((SELECT SUM(pm2.importe) FROM pagos pm2 JOIN mensualidades m2 ON m2.id=pm2.mensualidad_id WHERE pm2.alumno_id=a.id AND pm2.tipo='MENSUALIDAD' AND pm2.estado='VALIDO' AND m2.sede_id=:s_mens AND m2.mes=:mes_mens AND m2.anio=:anio_mens),0) mensualidad FROM pagos p JOIN alumnos a ON a.id=p.alumno_id JOIN inscripciones i ON i.id=p.inscripcion_id WHERE p.tipo='INSCRIPCION' AND p.estado='VALIDO' AND i.sede_id=:s_ins AND i.fecha BETWEEN :desde_ins AND :hasta_ins GROUP BY a.id,a.nombre ORDER BY a.nombre");$st->execute([':s_mens'=>$sedeId,':mes_mens'=>$mes,':anio_mens'=>$anio,':s_ins'=>$sedeId,':desde_ins'=>$desde,':hasta_ins'=>$hasta]);$nuevos=$st->fetchAll();
$st=$pdo->prepare("SELECT a.id,a.nombre,COALESCE(SUM(p.importe),0) mensualidad FROM pagos p JOIN alumnos a ON a.id=p.alumno_id JOIN mensualidades m ON m.id=p.mensualidad_id WHERE p.tipo='MENSUALIDAD' AND p.estado='VALIDO' AND m.sede_id=:s AND m.mes=:mes AND m.anio=:anio AND NOT EXISTS (SELECT 1 FROM pagos pi JOIN inscripciones i ON i.id=pi.inscripcion_id WHERE pi.alumno_id=a.id AND pi.tipo='INSCRIPCION' AND pi.estado='VALIDO' AND i.sede_id=:si AND i.fecha BETWEEN :d AND :h) GROUP BY a.id,a.nombre ORDER BY a.nombre");$st->execute([':s'=>$sedeId,':mes'=>$mes,':anio'=>$anio,':si'=>$sedeId,':d'=>$desde,':h'=>$hasta]);$mensualidades=$st->fetchAll();
$st=$pdo->prepare("SELECT ci.fecha_inicio,a.id,a.nombre,COALESCE(SUM(p.importe),0) importe FROM pagos p JOIN alumnos a ON a.id=p.alumno_id JOIN cursos_intensivos ci ON ci.id=p.intensivo_id WHERE p.tipo='INTENSIVO' AND p.estado='VALIDO' AND ci.sede_id=:s AND ci.fecha_inicio BETWEEN :d AND :h GROUP BY ci.fecha_inicio,a.id,a.nombre ORDER BY ci.fecha_inicio,a.nombre");$st->execute([':s'=>$sedeId,':d'=>$desde,':h'=>$hasta]);$intensivos=$st->fetchAll();

$newRows='';foreach($nuevos as $r){$newRows.='<tr><td>'.esc((string)$r['nombre']).'</td><td class="num">'.money($r['inscripcion']).'</td><td class="num">'.money($r['mensualidad']).'</td></tr>';}if($newRows==='')$newRows='<tr><td colspan="3" class="empty">Sin nuevas inscripciones en este periodo.</td></tr>';
$monthlyRows='';foreach($mensualidades as $r){$monthlyRows.='<tr><td>'.esc((string)$r['nombre']).'</td><td class="num">'.money($r['mensualidad']).'</td></tr>';}if($monthlyRows==='')$monthlyRows='<tr><td colspan="2" class="empty">Sin mensualidades regulares en este periodo.</td></tr>';
$intensiveHtml='';$currentDate=null;$groupRows='';$groupTotal=0.0;$groupCount=0;$flushIntensive=function() use (&$intensiveHtml,&$currentDate,&$groupRows,&$groupTotal,&$groupCount):void{if($currentDate===null)return;$intensiveHtml.='<div class="course"><div class="course-head"><div><span>Inicio del curso</span><b>'.esc(dateLabel($currentDate)).'</b></div><div class="course-total"><span>'.$groupCount.' alumno'.($groupCount===1?'':'s').'</span><b>'.money($groupTotal).'</b></div></div><table class="detail two"><thead><tr><th>Alumno</th><th>Pago intensivo</th></tr></thead><tbody>'.$groupRows.'</tbody></table></div>';};
foreach($intensivos as $r){$fecha=(string)$r['fecha_inicio'];if($currentDate!==null&&$fecha!==$currentDate){$flushIntensive();$groupRows='';$groupTotal=0.0;$groupCount=0;}$currentDate=$fecha;$groupRows.='<tr><td>'.esc((string)$r['nombre']).'</td><td class="num">'.money($r['importe']).'</td></tr>';$groupTotal+=(float)$r['importe'];$groupCount++;}$flushIntensive();if($intensiveHtml==='')$intensiveHtml='<div class="empty-box">Sin cursos intensivos con inicio en este periodo.</div>';
$minimoHtml=$minimo>0?('<div class="minimum"><span>Mínimo mensual acordado</span><b>'.money($minimo).'</b><em class="'.($alcanzado?'good':'warn').'">'.($alcanzado?'Mínimo cubierto':'Faltan '.money($faltante)).'</em></div>'):'';
$generated=(new DateTimeImmutable('now',new DateTimeZone('America/Cancun')))->format('d/m/Y H:i');

$html='<!doctype html><html lang="es"><head><meta charset="utf-8"><style>
@page{margin:28px 34px 34px}*{box-sizing:border-box}body{font-family:DejaVu Sans,Arial,sans-serif;color:#172033;font-size:10.5px;margin:0}.brand{background:#123b5d;color:white;padding:22px 24px;border-radius:14px}.brand h1{font-size:24px;margin:0 0 4px}.brand p{margin:0;color:#dbe8f1;font-size:11px}.meta{margin-top:12px;width:100%;border-collapse:collapse}.meta td{padding:2px 0;color:#64748b}.meta td:last-child{text-align:right}.title{margin:22px 0 10px}.title h2{margin:0;font-size:20px}.title div{color:#64748b;margin-top:4px}.cards,.split{width:100%;border-collapse:separate;border-spacing:7px;margin-left:-7px;margin-right:-7px}.cards td{width:25%;border:1px solid #dfe7ee;border-radius:10px;padding:12px;background:#f8fafc}.cards td.primary{background:#172033;color:#fff;border-color:#172033}.label{font-size:8.5px;text-transform:uppercase;letter-spacing:.6px;color:#708198;margin-bottom:5px}.primary .label{color:#cbd5e1}.value{font-size:18px;font-weight:bold}.section{margin-top:17px;border:1px solid #dfe7ee;border-radius:12px;padding:14px;page-break-inside:auto}.section h3{margin:0 0 4px;font-size:15px}.section .intro{color:#64748b;font-size:9px;margin-bottom:10px}.split td{width:33.33%;background:#f8fafc;border:1px solid #e5eaf0;border-radius:9px;padding:11px}.split td.total{background:#eef3f7;border-color:#cfdbe5}.minimum{margin-top:9px;background:#f8fafc;border-left:4px solid #123b5d;padding:10px 12px}.minimum span{display:inline-block;width:48%;color:#64748b}.minimum b{font-size:13px}.minimum em{float:right;font-style:normal;font-weight:bold}.good{color:#166534}.warn{color:#92400e}.formula{margin-top:10px;color:#64748b;font-size:9px;line-height:1.45}.detail{width:100%;border-collapse:collapse;margin-top:5px}.detail th{background:#eef3f7;color:#526174;text-transform:uppercase;font-size:8px;letter-spacing:.4px;padding:7px;text-align:left}.detail td{border-bottom:1px solid #e8edf2;padding:7px}.detail th.num,.detail td.num{text-align:right;font-weight:bold}.detail.three th:first-child,.detail.three td:first-child{width:58%}.detail.two th:first-child,.detail.two td:first-child{width:76%}.detail th:not(:first-child){text-align:right}.course{margin-top:10px;border:1px solid #e1e7ec;border-radius:10px;padding:10px;page-break-inside:avoid}.course-head{width:100%;display:table;margin-bottom:6px}.course-head>div{display:table-cell;vertical-align:middle}.course-head span{display:block;color:#718096;font-size:8px;text-transform:uppercase;letter-spacing:.5px}.course-head b{font-size:12px}.course-total{text-align:right}.empty,.empty-box{text-align:center;color:#64748b;padding:14px!important}.empty-box{background:#f8fafc;border-radius:9px;margin-top:8px}.foot{margin-top:16px;padding-top:9px;border-top:1px solid #e1e7ec;color:#7a8796;font-size:8.5px;line-height:1.4}.block-title{display:table;width:100%}.block-title h3{display:table-cell}.block-title span{display:table-cell;text-align:right;color:#64748b;font-size:9px}
</style></head><body>
<div class="brand"><h1>Hache Natación</h1><p>Liquidación mensual · '.esc((string)$sede['nombre']).'</p></div>
<table class="meta"><tr><td><b>Periodo:</b> '.esc(monthName($periodo)).'</td><td><b>Sede:</b> '.esc((string)$sede['nombre']).'</td></tr><tr><td>Del '.date('d/m/Y',strtotime($desde)).' al '.date('d/m/Y',strtotime($hasta)).'</td><td>Generado: '.$generated.'</td></tr></table>
<div class="title"><h2>Resumen mensual</h2><div>Ingresos válidos reconocidos en el periodo económico seleccionado.</div></div>
<table class="cards"><tr><td class="primary"><div class="label">Total cobrado</div><div class="value">'.money($fin['total']).'</div></td><td><div class="label">Mensualidades</div><div class="value">'.money($mens).'</div></td><td><div class="label">Inscripciones</div><div class="value">'.money($ins).'</div></td><td><div class="label">Intensivos</div><div class="value">'.money($int).'</div></td></tr></table>
<div class="section"><h3>Pagos a '.esc($socioNombre).' según acuerdo</h3><div class="intro">Aportación correspondiente al convenio vigente de la sede.</div><table class="split"><tr><td><div class="label">'.number_format((float)$sede['porcentaje_mensualidad_socio'],0).'% Mensualidades + '.number_format((float)$sede['porcentaje_intensivo_socio'],0).'% Intensivos</div><div class="value">'.money($aporteBase).'</div></td><td><div class="label">'.number_format((float)$sede['porcentaje_inscripcion_socio'],0).'% Inscripciones</div><div class="value">'.money($aporteIns).'</div></td><td class="total"><div class="label">Total aportado a '.esc($socioNombre).'</div><div class="value">'.money($socio).'</div></td></tr></table>'.$minimoHtml.'<div class="formula">El mínimo mensual se compara contra el total aportado a '.esc($socioNombre).': participación de mensualidades + participación de cursos intensivos + participación de inscripciones, conforme a los porcentajes configurados para esta sede.</div></div>
<div class="section"><div class="block-title"><h3>Nuevas inscripciones</h3><span>'.count($nuevos).' alumno'.(count($nuevos)===1?'':'s').'</span></div><div class="intro">Alumnos que pagaron inscripción en este periodo. Su mensualidad del mismo mes se muestra en la misma fila.</div><table class="detail three"><thead><tr><th>Alumno</th><th>Inscripción</th><th>Mensualidad</th></tr></thead><tbody>'.$newRows.'</tbody></table></div>
<div class="section"><div class="block-title"><h3>Mensualidades</h3><span>'.count($mensualidades).' alumno'.(count($mensualidades)===1?'':'s').'</span></div><div class="intro">Alumnos regulares que pagaron únicamente su mensualidad del periodo.</div><table class="detail two"><thead><tr><th>Alumno</th><th>Mensualidad</th></tr></thead><tbody>'.$monthlyRows.'</tbody></table></div>
<div class="section"><div class="block-title"><h3>Cursos intensivos</h3><span>'.count($intensivos).' alumno'.(count($intensivos)===1?'':'s').'</span></div><div class="intro">Alumnos separados según la fecha de inicio de su curso intensivo.</div>'.$intensiveHtml.'</div>
<div class="foot"><b>Hache Natación</b> · Liquidación generada por el sistema administrativo. El PDF prioriza lectura y presentación; el CSV conserva el detalle completo para auditoría.</div>
</body></html>';
$options=new Options();$options->set('isRemoteEnabled',false);$options->set('isHtml5ParserEnabled',true);$options->set('defaultFont','DejaVu Sans');$pdf=new Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4','portrait');$pdf->render();
$filename='Hache_Natacion_Reporte_'.preg_replace('/[^A-Za-z0-9_-]/','_',strtoupper($clave)).'_'.$periodo.'.pdf';
$output=$pdf->output();
while(ob_get_level()>0){ob_end_clean();}
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="'.$filename.'"; filename*=UTF-8\'\''.rawurlencode($filename));
header('Content-Length: '.strlen($output));
header('Content-Transfer-Encoding: binary');
header('Accept-Ranges: bytes');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
echo $output;
exit;
