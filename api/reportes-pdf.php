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
function monthName(string $periodo):string{
 [$y,$m]=array_map('intval',explode('-',$periodo));
 $meses=[1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
 return ($meses[$m]??$periodo).' '.$y;
}

$periodo=(string)($_GET['periodo']??date('Y-m'));
if(!preg_match('/^\d{4}-\d{2}$/',$periodo))$periodo=date('Y-m');
$clave=auth_resolve_sede_clave((string)($_GET['sede']??'MONTEVERDE'));
$st=$pdo->prepare("SELECT * FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");
$st->execute([':c'=>$clave]);$sede=$st->fetch();
if(!$sede){http_response_code(422);exit('Sede inválida');}
$sedeId=$sede['id'];[$anio,$mes]=array_map('intval',explode('-',$periodo));
$desde=$periodo.'-01';$hasta=(new DateTimeImmutable($desde))->modify('last day of this month')->format('Y-m-d');

$sql="SELECT COUNT(*) pagos_count,COALESCE(SUM(p.importe),0) total,COALESCE(SUM(p.tipo='INSCRIPCION'),0) inscripciones_count,COALESCE(SUM(CASE WHEN p.tipo='INSCRIPCION' THEN p.importe ELSE 0 END),0) inscripciones_total,COALESCE(SUM(p.tipo='MENSUALIDAD'),0) mensualidades_count,COALESCE(SUM(CASE WHEN p.tipo='MENSUALIDAD' THEN p.importe ELSE 0 END),0) mensualidades_total,COALESCE(SUM(p.tipo='INTENSIVO'),0) intensivos_count,COALESCE(SUM(CASE WHEN p.tipo='INTENSIVO' THEN p.importe ELSE 0 END),0) intensivos_total FROM pagos p LEFT JOIN mensualidades m ON m.id=p.mensualidad_id LEFT JOIN inscripciones i ON i.id=p.inscripcion_id LEFT JOIN cursos_intensivos ci ON ci.id=p.intensivo_id WHERE p.estado='VALIDO' AND ((p.tipo='MENSUALIDAD' AND m.sede_id=:sm AND m.mes=:mes AND m.anio=:anio) OR (p.tipo='INSCRIPCION' AND i.sede_id=:si AND i.fecha BETWEEN :di AND :hi) OR (p.tipo='INTENSIVO' AND ci.sede_id=:sc AND ci.fecha_inicio BETWEEN :dc AND :hc))";
$params=[':sm'=>$sedeId,':mes'=>$mes,':anio'=>$anio,':si'=>$sedeId,':di'=>$desde,':hi'=>$hasta,':sc'=>$sedeId,':dc'=>$desde,':hc'=>$hasta];
$st=$pdo->prepare($sql);$st->execute($params);$fin=$st->fetch();

$mens=(float)$fin['mensualidades_total'];$int=(float)$fin['intensivos_total'];$ins=(float)$fin['inscripciones_total'];
$pm=(float)$sede['porcentaje_mensualidad_socio']/100;$pi=(float)$sede['porcentaje_intensivo_socio']/100;$pins=(float)$sede['porcentaje_inscripcion_socio']/100;
$socio=$mens*$pm+$int*$pi+$ins*$pins;$hache=$mens*(1-$pm)+$int*(1-$pi)+$ins*(1-$pins);
$minimo=$sede['minimo_mensual_socio']!==null?(float)$sede['minimo_mensual_socio']:0.0;$faltante=$minimo>0?max(0,$minimo-$socio):0.0;$alcanzado=$minimo>0?$socio>=$minimo:null;
$socioNombre=(string)($sede['socio']?:'Socio');

$st=$pdo->prepare("SELECT COUNT(DISTINCT a.alumno_id) alumnos_con_asistencia,COALESCE(SUM(a.estado='PRESENTE'),0) presentes,COALESCE(SUM(a.estado='AUSENTE_JUSTIFICADA'),0) justificadas,COALESCE(SUM(a.estado='AUSENTE_NO_JUSTIFICADA'),0) no_justificadas FROM asistencias a JOIN sesiones ss ON ss.id=a.sesion_id JOIN alumnos al ON al.id=a.alumno_id WHERE ss.fecha BETWEEN :d AND :h AND al.sede_id=:s");
$st->execute([':d'=>$desde,':h'=>$hasta,':s'=>$sedeId]);$asis=$st->fetch();

$detalleSql="SELECT p.folio,a.nombre,p.tipo,p.importe,p.metodo,p.fecha FROM pagos p JOIN alumnos a ON a.id=p.alumno_id LEFT JOIN mensualidades m ON m.id=p.mensualidad_id LEFT JOIN inscripciones i ON i.id=p.inscripcion_id LEFT JOIN cursos_intensivos ci ON ci.id=p.intensivo_id WHERE p.estado='VALIDO' AND ((p.tipo='MENSUALIDAD' AND m.sede_id=:sm AND m.mes=:mes AND m.anio=:anio) OR (p.tipo='INSCRIPCION' AND i.sede_id=:si AND i.fecha BETWEEN :di AND :hi) OR (p.tipo='INTENSIVO' AND ci.sede_id=:sc AND ci.fecha_inicio BETWEEN :dc AND :hc)) ORDER BY p.tipo,a.nombre,p.fecha";
$st=$pdo->prepare($detalleSql);$st->execute($params);$movs=$st->fetchAll();

$rows='';foreach($movs as $r){$rows.='<tr><td>'.esc((string)$r['folio']).'</td><td>'.esc((string)$r['nombre']).'</td><td>'.esc(ucfirst(strtolower((string)$r['tipo']))).'</td><td>'.esc((string)$r['metodo']).'</td><td class="num">'.money($r['importe']).'</td></tr>';}
if($rows==='')$rows='<tr><td colspan="5" class="empty">Sin movimientos válidos en este periodo.</td></tr>';
$minimoHtml=$minimo>0?('<div class="minimum"><span>Mínimo contractual '.$socioNombre.'</span><b>'.money($minimo).'</b><em class="'.($alcanzado?'good':'warn').'">'.($alcanzado?'Objetivo alcanzado':'Faltan '.money($faltante)).'</em></div>'):'';
$ausencias=(int)$asis['justificadas']+(int)$asis['no_justificadas'];
$generated=(new DateTimeImmutable('now',new DateTimeZone('America/Cancun')))->format('d/m/Y H:i');

$html='<!doctype html><html lang="es"><head><meta charset="utf-8"><style>
@page{margin:28px 34px 34px}*{box-sizing:border-box}body{font-family:DejaVu Sans,Arial,sans-serif;color:#172033;font-size:10.5px;margin:0}.brand{background:#123b5d;color:white;padding:22px 24px;border-radius:14px}.brand h1{font-size:24px;margin:0 0 4px;letter-spacing:-.5px}.brand p{margin:0;color:#dbe8f1;font-size:11px}.meta{margin-top:12px;width:100%;border-collapse:collapse}.meta td{padding:2px 0;color:#64748b}.meta td:last-child{text-align:right}.title{margin:22px 0 10px}.title h2{margin:0;font-size:20px}.title div{color:#64748b;margin-top:4px}.cards{width:100%;border-collapse:separate;border-spacing:7px;margin-left:-7px;margin-right:-7px}.cards td{width:25%;border:1px solid #dfe7ee;border-radius:10px;padding:12px;background:#f8fafc}.cards td.primary{background:#172033;color:#fff;border-color:#172033}.label{font-size:8.5px;text-transform:uppercase;letter-spacing:.6px;color:#708198;margin-bottom:5px}.primary .label{color:#cbd5e1}.value{font-size:18px;font-weight:bold}.section{margin-top:17px;border:1px solid #dfe7ee;border-radius:12px;padding:14px}.section h3{margin:0 0 10px;font-size:14px}.split{width:100%;border-collapse:separate;border-spacing:7px}.split td{width:33.33%;background:#f8fafc;border:1px solid #e5eaf0;border-radius:9px;padding:11px}.minimum{margin-top:9px;background:#f8fafc;border-left:4px solid #123b5d;padding:10px 12px}.minimum span{display:inline-block;width:48%;color:#64748b}.minimum b{font-size:13px}.minimum em{float:right;font-style:normal;font-weight:bold}.good{color:#166534}.warn{color:#92400e}.formula{margin-top:10px;color:#64748b;font-size:9px;line-height:1.45}.attendance{width:100%;border-collapse:collapse}.attendance td{width:33.33%;padding:10px;background:#f8fafc;border-right:5px solid white}.attendance b{display:block;font-size:17px}.attendance span{color:#64748b;font-size:9px}.mov{width:100%;border-collapse:collapse;margin-top:4px}.mov th{background:#eef3f7;color:#526174;text-transform:uppercase;font-size:8px;letter-spacing:.4px;padding:7px;text-align:left}.mov td{border-bottom:1px solid #e8edf2;padding:6px 7px}.mov .num{text-align:right;font-weight:bold}.empty{text-align:center;color:#64748b;padding:14px!important}.foot{margin-top:16px;padding-top:9px;border-top:1px solid #e1e7ec;color:#7a8796;font-size:8.5px;line-height:1.4}.page-break{page-break-before:always}.small{font-size:9px;color:#64748b}
</style></head><body>
<div class="brand"><h1>Hache Natación</h1><p>Reporte financiero y operativo · '.esc((string)$sede['nombre']).'</p></div>
<table class="meta"><tr><td><b>Periodo:</b> '.esc(monthName($periodo)).'</td><td><b>Sede:</b> '.esc((string)$sede['nombre']).'</td></tr><tr><td>Del '.date('d/m/Y',strtotime($desde)).' al '.date('d/m/Y',strtotime($hasta)).'</td><td>Generado: '.$generated.'</td></tr></table>
<div class="title"><h2>Resumen mensual</h2><div>Ingresos válidos reconocidos en el periodo económico seleccionado.</div></div>
<table class="cards"><tr><td class="primary"><div class="label">Total cobrado</div><div class="value">'.money($fin['total']).'</div></td><td><div class="label">Mensualidades</div><div class="value">'.money($mens).'</div></td><td><div class="label">Inscripciones</div><div class="value">'.money($ins).'</div></td><td><div class="label">Intensivos</div><div class="value">'.money($int).'</div></td></tr></table>
<div class="section"><h3>Distribución Hache · '.esc($socioNombre).'</h3><table class="split"><tr><td><div class="label">Participación Hache</div><div class="value">'.money($hache).'</div></td><td><div class="label">Participación '.esc($socioNombre).'</div><div class="value">'.money($socio).'</div></td><td><div class="label">Movimientos válidos</div><div class="value">'.(int)$fin['pagos_count'].'</div></td></tr></table>'.$minimoHtml.'<div class="formula">Reglas vigentes de esta sede: '.$socioNombre.' recibe '.number_format((float)$sede['porcentaje_mensualidad_socio'],0).'% de mensualidades, '.number_format((float)$sede['porcentaje_intensivo_socio'],0).'% de intensivos y '.number_format((float)$sede['porcentaje_inscripcion_socio'],0).'% de inscripciones. El resto corresponde a Hache Natación.</div></div>
<div class="section"><h3>Asistencia del mes</h3><table class="attendance"><tr><td><b>'.(int)$asis['alumnos_con_asistencia'].'</b><span>Alumnos con asistencia</span></td><td><b>'.(int)$asis['presentes'].'</b><span>Registros presentes</span></td><td><b>'.$ausencias.'</b><span>Ausencias registradas</span></td></tr></table></div>
<div class="section"><h3>Detalle de movimientos</h3><table class="mov"><thead><tr><th>Folio</th><th>Alumno</th><th>Concepto</th><th>Método</th><th style="text-align:right">Importe</th></tr></thead><tbody>'.$rows.'</tbody></table></div>
<div class="foot"><b>Hache Natación</b> · Documento generado por el sistema administrativo. Este reporte conserva la identidad visual Hache y usa las reglas financieras configuradas para la sede seleccionada. Para auditoría, el CSV conserva el detalle exportable de movimientos.</div>
</body></html>';

$options=new Options();$options->set('isRemoteEnabled',false);$options->set('isHtml5ParserEnabled',true);$options->set('defaultFont','DejaVu Sans');
$pdf=new Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4','portrait');$pdf->render();
$filename='Hache_Natacion_Reporte_'.preg_replace('/[^A-Za-z0-9_-]/','_',strtoupper($clave)).'_'.$periodo.'.pdf';
$pdf->stream($filename,['Attachment'=>true]);
