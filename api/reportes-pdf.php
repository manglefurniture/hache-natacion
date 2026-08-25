<?php
declare(strict_types=1);
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/periodos-financieros.php';
auth_require(['ADMIN','VERIFICADOR']);

$autoload=__DIR__.'/../vendor/autoload.php';
if(!is_file($autoload)){http_response_code(503);exit('Generador PDF no instalado. Ejecuta composer install/update en el servidor.');}
require_once $autoload;
use Dompdf\Dompdf;
use Dompdf\Options;

$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function money(float|int|string|null $n):string{return '$'.number_format((float)$n,2,'.',',');}
function esc(string $v):string{return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
function monthName(string $periodo):string{[$y,$m]=array_map('intval',explode('-',$periodo));$meses=[1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];return ($meses[$m]??$periodo).' '.$y;}

try{$periodo=financiero_validar_periodo((string)($_GET['periodo']??date('Y-m')));}catch(InvalidArgumentException $e){http_response_code(422);exit($e->getMessage());}
$clave=auth_resolve_sede_clave((string)($_GET['sede']??'MONTEVERDE'));
$st=$pdo->prepare('SELECT * FROM sedes WHERE clave=:c AND activo=1 LIMIT 1');$st->execute([':c'=>$clave]);$sede=$st->fetch();if(!$sede){http_response_code(422);exit('Sede inválida');}
$sedeId=(string)$sede['id'];$fin=financiero_totales($pdo,$sede,$periodo);$rango=$fin['rango'];$desde=$rango['inicio'];$hasta=$rango['cierre'];[$anio,$mes]=array_map('intval',explode('-',$periodo));
$mens=(float)$fin['mensualidades_total'];$int=(float)$fin['intensivos_total'];$ins=(float)$fin['inscripciones_total'];$pm=(float)$sede['porcentaje_mensualidad_socio']/100;$pi=(float)$sede['porcentaje_intensivo_socio']/100;$pins=(float)$sede['porcentaje_inscripcion_socio']/100;
$aporteMens=$mens*$pm;$aporteInt=$int*$pi;$aporteIns=$ins*$pins;$socio=$aporteMens+$aporteInt+$aporteIns;$hache=$mens+$int+$ins-$socio;$minimo=$sede['minimo_mensual_socio']!==null?(float)$sede['minimo_mensual_socio']:0.0;

$st=$pdo->prepare("SELECT a.nombre,COALESCE(SUM(p.importe),0) importe FROM pagos p JOIN alumnos a ON a.id=p.alumno_id JOIN cursos_intensivos ci ON ci.id=p.intensivo_id WHERE p.tipo='INTENSIVO' AND p.estado='VALIDO' AND ci.sede_id=:s AND ci.fecha_inicio BETWEEN :d AND :h GROUP BY a.id,a.nombre ORDER BY a.nombre");$st->execute([':s'=>$sedeId,':d'=>$desde,':h'=>$hasta]);$intRows=$st->fetchAll();
$st=$pdo->prepare("SELECT a.nombre,COALESCE(SUM(p.importe),0) importe FROM pagos p JOIN alumnos a ON a.id=p.alumno_id JOIN inscripciones i ON i.id=p.inscripcion_id WHERE p.tipo='INSCRIPCION' AND p.estado='VALIDO' AND i.sede_id=:s AND i.fecha BETWEEN :d AND :h GROUP BY a.id,a.nombre ORDER BY a.nombre");$st->execute([':s'=>$sedeId,':d'=>$desde,':h'=>$hasta]);$insRows=$st->fetchAll();
$st=$pdo->prepare("SELECT a.nombre,COALESCE(SUM(p.importe),0) importe FROM pagos p JOIN alumnos a ON a.id=p.alumno_id JOIN mensualidades m ON m.id=p.mensualidad_id WHERE p.tipo='MENSUALIDAD' AND p.estado='VALIDO' AND m.sede_id=:s AND m.mes=:m AND m.anio=:a GROUP BY a.id,a.nombre ORDER BY a.nombre");$st->execute([':s'=>$sedeId,':m'=>$mes,':a'=>$anio]);$mensRows=$st->fetchAll();
$rows=function(array $items):string{$html='';foreach($items as $r)$html.='<tr><td>'.esc((string)$r['nombre']).'</td><td class="num">'.money($r['importe']).'</td></tr>';return $html?:'<tr><td colspan="2" class="empty">Sin movimientos.</td></tr>';};
$generated=(new DateTimeImmutable('now',new DateTimeZone('America/Cancun')))->format('d/m/Y H:i');
$html='<!doctype html><html lang="es"><head><meta charset="utf-8"><style>@page{margin:28px 34px}body{font-family:DejaVu Sans,Arial,sans-serif;color:#172033;font-size:10px}h1{font-size:24px;margin:0}h2{margin:20px 0 8px;font-size:15px}.hero{background:#123b5d;color:#fff;padding:20px;border-radius:12px}.meta{margin:10px 0 15px;color:#64748b}.cards{width:100%;border-collapse:separate;border-spacing:6px}.cards td{border:1px solid #dde6ee;background:#f8fafc;border-radius:9px;padding:10px;width:25%}.k{font-size:8px;color:#64748b;text-transform:uppercase}.v{font-size:16px;font-weight:bold;margin-top:4px}.detail{width:100%;border-collapse:collapse}.detail th{background:#eef3f7;text-align:left;padding:7px}.detail td{padding:7px;border-bottom:1px solid #e7edf2}.num{text-align:right;font-weight:bold}.empty{text-align:center;color:#64748b}.foot{margin-top:18px;border-top:1px solid #e1e7ec;padding-top:8px;color:#718096;font-size:8px}</style></head><body>';
$html.='<div class="hero"><h1>Hache Natación</h1><div>Liquidación mensual · '.esc((string)$sede['nombre']).'</div></div>';
$html.='<div class="meta"><b>Periodo económico:</b> '.esc(monthName($periodo)).' · '.date('d/m/Y',strtotime($desde)).' al '.date('d/m/Y',strtotime($hasta)).' · Generado '.$generated.'</div>';
$html.='<table class="cards"><tr><td><div class="k">Total</div><div class="v">'.money($fin['total']).'</div></td><td><div class="k">Mensualidades</div><div class="v">'.money($mens).'</div></td><td><div class="k">Inscripciones</div><div class="v">'.money($ins).'</div></td><td><div class="k">Intensivos</div><div class="v">'.money($int).'</div></td></tr></table>';
$html.='<table class="cards"><tr><td><div class="k">Hache</div><div class="v">'.money($hache).'</div></td><td><div class="k">'.esc((string)$sede['socio']).'</div><div class="v">'.money($socio).'</div></td><td><div class="k">Mínimo socio</div><div class="v">'.money($minimo).'</div></td><td><div class="k">Estado mínimo</div><div class="v">'.($minimo>0?($socio>=$minimo?'Cubierto':'Pendiente'):'N/A').'</div></td></tr></table>';
$html.='<h2>Mensualidades</h2><table class="detail"><thead><tr><th>Alumno</th><th class="num">Importe</th></tr></thead><tbody>'.$rows($mensRows).'</tbody></table>';
$html.='<h2>Inscripciones</h2><table class="detail"><thead><tr><th>Alumno</th><th class="num">Importe</th></tr></thead><tbody>'.$rows($insRows).'</tbody></table>';
$html.='<h2>Cursos intensivos</h2><table class="detail"><thead><tr><th>Alumno</th><th class="num">Importe</th></tr></thead><tbody>'.$rows($intRows).'</tbody></table>';
$html.='<div class="foot">Los intensivos se reconocen en el periodo financiero donde inicia el curso; las mensualidades conservan su mes contratado.</div></body></html>';
$options=new Options();$options->set('isRemoteEnabled',false);$dompdf=new Dompdf($options);$dompdf->loadHtml($html,'UTF-8');$dompdf->setPaper('A4','portrait');$dompdf->render();$dompdf->stream('hache-liquidacion-'.strtolower($clave).'-'.$periodo.'.pdf',['Attachment'=>true]);
