<?php
declare(strict_types=1);
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/periodos-financieros.php';
auth_require(['ADMIN','VERIFICADOR']);
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function exactDate(string $value):string{$date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||$date->format('Y-m-d')!==$value){http_response_code(422);exit('Fecha inválida');}return $value;}
function csvCell(mixed $value):mixed{if(!is_string($value))return $value;return preg_match('/^\s*[=+\-@]/u',$value)?"'".$value:$value;}
$desde=exactDate((string)($_GET['desde']??date('Y-m-01')));$hasta=exactDate((string)($_GET['hasta']??date('Y-m-t')));if($hasta<$desde){[$desde,$hasta]=[$hasta,$desde];}
$clave=auth_resolve_sede_clave((string)($_GET['sede']??'MONTEVERDE'));$st=$pdo->prepare('SELECT id,nombre FROM sedes WHERE clave=:c AND activo=1 LIMIT 1');$st->execute([':c'=>$clave]);$s=$st->fetch();if(!$s){http_response_code(422);exit('Sede inválida');}
$periodoDesde=substr($desde,0,7);$periodoHasta=substr($hasta,0,7);financiero_validar_periodo($periodoDesde);financiero_validar_periodo($periodoHasta);
$rd=financiero_rango($pdo,(string)$s['id'],$periodoDesde);$rh=financiero_rango($pdo,(string)$s['id'],$periodoHasta);$limiteDesde=$rd['inicio'];$limiteHasta=$rh['cierre'];
header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="hache-reporte-'.strtolower($clave).'-'.$desde.'-'.$hasta.'.csv"');echo "\xEF\xBB\xBF";$out=fopen('php://output','w');fputcsv($out,['Sede','Folio','Alumno','Tipo','Importe','Método','Fecha real de pago','Periodo económico','Estado']);
$sql="SELECT :sede_nombre sede,p.folio,a.nombre,p.tipo,p.importe,p.metodo,p.fecha,p.estado,m.mes mensualidad_mes,m.anio mensualidad_anio,ci.fecha_inicio intensivo_inicio,i.fecha inscripcion_fecha FROM pagos p JOIN alumnos a ON a.id=p.alumno_id LEFT JOIN mensualidades m ON m.id=p.mensualidad_id LEFT JOIN cursos_intensivos ci ON ci.id=p.intensivo_id LEFT JOIN inscripciones i ON i.id=p.inscripcion_id WHERE ((p.tipo='MENSUALIDAD' AND m.sede_id=:sm AND CONCAT(m.anio,'-',LPAD(m.mes,2,'0')) BETWEEN :pdm AND :phm) OR (p.tipo='INTENSIVO' AND ci.sede_id=:sc AND ci.fecha_inicio BETWEEN :dc AND :hc) OR (p.tipo='INSCRIPCION' AND i.sede_id=:si AND i.fecha BETWEEN :di AND :hi)) ORDER BY p.fecha,p.folio";
$st=$pdo->prepare($sql);$st->execute([':sede_nombre'=>$s['nombre'],':sm'=>$s['id'],':pdm'=>$periodoDesde,':phm'=>$periodoHasta,':sc'=>$s['id'],':dc'=>$limiteDesde,':hc'=>$limiteHasta,':si'=>$s['id'],':di'=>$limiteDesde,':hi'=>$limiteHasta]);
foreach($st as $r){
    if($r['tipo']==='MENSUALIDAD')$periodo=sprintf('%04d-%02d',(int)$r['mensualidad_anio'],(int)$r['mensualidad_mes']);
    elseif($r['tipo']==='INTENSIVO')$periodo=financiero_periodo_para_fecha($pdo,(string)$s['id'],(string)$r['intensivo_inicio']);
    elseif($r['tipo']==='INSCRIPCION')$periodo=financiero_periodo_para_fecha($pdo,(string)$s['id'],(string)$r['inscripcion_fecha']);
    else $periodo=substr((string)$r['fecha'],0,7);
    if($periodo<$periodoDesde||$periodo>$periodoHasta)continue;
    fputcsv($out,array_map('csvCell',[$r['sede'],$r['folio'],$r['nombre'],$r['tipo'],$r['importe'],$r['metodo'],$r['fecha'],$periodo,$r['estado']]));
}
fclose($out);
