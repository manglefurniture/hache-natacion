<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/periodos-financieros.php';
auth_require(['ADMIN','VERIFICADOR']);
$config=require __DIR__.'/../config/database.php';

function out(array $d,int $code=200):never{http_response_code($code);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function getSede(PDO $pdo,string $clave):array{$st=$pdo->prepare('SELECT * FROM sedes WHERE clave=:c AND activo=1 LIMIT 1');$st->execute([':c'=>$clave]);$s=$st->fetch();if(!$s)out(['ok'=>false,'error'=>'Sede inválida'],422);return $s;}

try{
    $pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $periodo=financiero_validar_periodo((string)($_GET['periodo']??date('Y-m')));
    $sedeClave=auth_resolve_sede_clave((string)($_GET['sede']??'MONTEVERDE'));
    $sede=getSede($pdo,$sedeClave);$sedeId=(string)$sede['id'];
    $fin=financiero_totales($pdo,$sede,$periodo);$rango=$fin['rango'];$desde=$rango['inicio'];$hasta=$rango['cierre'];

    $mens=(float)$fin['mensualidades_total'];$int=(float)$fin['intensivos_total'];$ins=(float)$fin['inscripciones_total'];
    $pm=(float)$sede['porcentaje_mensualidad_socio']/100;$pi=(float)$sede['porcentaje_intensivo_socio']/100;$pins=(float)$sede['porcentaje_inscripcion_socio']/100;
    $socio=($mens*$pm)+($int*$pi)+($ins*$pins);$hache=($mens*(1-$pm))+($int*(1-$pi))+($ins*(1-$pins));
    $minimo=$sede['minimo_mensual_socio']!==null?(float)$sede['minimo_mensual_socio']:0.0;$faltante=$minimo>0?max(0,$minimo-$socio):0;$sobrecumplido=$minimo>0?max(0,$socio-$minimo):0;$alcanzado=$minimo>0?$socio>=$minimo:null;$progreso=$minimo>0?min(100,round(($socio/$minimo)*100,1)):null;

    $meses=[];$base=new DateTimeImmutable($periodo.'-01');
    for($i=11;$i>=0;$i--){
        $ym=$base->modify('-'.$i.' months')->format('Y-m');$t=financiero_totales($pdo,$sede,$ym);
        $m=(float)$t['mensualidades_total'];$ii=(float)$t['intensivos_total'];$is=(float)$t['inscripciones_total'];$soc=$m*$pm+$ii*$pi+$is*$pins;$hac=$m*(1-$pm)+$ii*(1-$pi)+$is*(1-$pins);
        $meses[]=['periodo'=>$ym,'movimientos'=>(int)$t['pagos_count'],'total'=>(float)$t['total'],'inscripciones'=>$is,'mensualidades'=>$m,'intensivos'=>$ii,'hache'=>$hac,'proa'=>$soc,'socio'=>$soc,'minimo_alcanzado'=>$minimo>0?$soc>=$minimo:null,'sobrecumplido'=>$minimo>0?max(0,$soc-$minimo):0,'desde'=>$t['rango']['inicio'],'hasta'=>$t['rango']['cierre']];
    }

    $st=$pdo->prepare("SELECT COUNT(DISTINCT a.alumno_id) alumnos_con_asistencia,COALESCE(SUM(a.estado='PRESENTE'),0) presentes,COALESCE(SUM(a.estado='AUSENTE_JUSTIFICADA'),0) justificadas,COALESCE(SUM(a.estado='AUSENTE_NO_JUSTIFICADA'),0) no_justificadas FROM asistencias a JOIN sesiones ss ON ss.id=a.sesion_id JOIN alumnos al ON al.id=a.alumno_id WHERE ss.fecha BETWEEN :d AND :h AND al.sede_id=:s");
    $st->execute([':d'=>$desde,':h'=>$hasta,':s'=>$sedeId]);$asis=$st->fetch();

    out(['ok'=>true,'periodo'=>$periodo,'sede'=>['clave'=>$sede['clave'],'nombre'=>$sede['nombre'],'socio'=>$sede['socio']],'desde'=>$desde,'hasta'=>$hasta,'rango_financiero'=>$rango,'finanzas'=>$fin,'convenio_proa'=>['hache'=>$hache,'proa'=>$socio,'socio'=>$socio,'socio_nombre'=>$sede['socio'],'minimo'=>$minimo,'faltante'=>$faltante,'sobrecumplido'=>$sobrecumplido,'alcanzado'=>$alcanzado,'progreso'=>$progreso,'porcentajes'=>['mensualidades'=>(float)$sede['porcentaje_mensualidad_socio'],'intensivos'=>(float)$sede['porcentaje_intensivo_socio'],'inscripciones'=>(float)$sede['porcentaje_inscripcion_socio']]],'meses'=>array_reverse($meses),'asistencia'=>$asis]);
}catch(InvalidArgumentException $e){out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){error_log('[reportes] '.$e->getMessage());out(['ok'=>false,'error'=>'No se pudo generar el reporte'],500);}
