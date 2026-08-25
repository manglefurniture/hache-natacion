<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/periodos-financieros.php';
$me=auth_require(['ADMIN']);
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function monthStart(string $ym):string{financiero_validar_periodo($ym);return $ym.'-01';}
function exactDate(string $date):bool{$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);return $d!==false&&$d->format('Y-m-d')===$date;}
function validateCommission(string $name,float $amount,string $observation):void{if($name===''||mb_strlen($name)>150||$amount<=0||$amount>1000000||mb_strlen($observation)>500)throw new InvalidArgumentException('Nombre, importe u observación inválidos');}
function proaForMonth(PDO $pdo,string $ym,array $site):array{
  $t=financiero_totales($pdo,$site,$ym);
  $mens=(float)$t['mensualidades_total'];$intensivos=(float)$t['intensivos_total'];$inscripciones=(float)$t['inscripciones_total'];
  $aporte=($mens*((float)$site['porcentaje_mensualidad_socio']/100))+($intensivos*((float)$site['porcentaje_intensivo_socio']/100))+($inscripciones*((float)$site['porcentaje_inscripcion_socio']/100));
  return ['mensualidades'=>$mens,'intensivos'=>$intensivos,'inscripciones'=>$inscripciones,'aporte_proa'=>$aporte,'rango'=>$t['rango']];
}
try{
  $st=$pdo->query("SELECT id,porcentaje_mensualidad_socio,porcentaje_intensivo_socio,porcentaje_inscripcion_socio,minimo_mensual_socio FROM sedes WHERE clave='MONTEVERDE' AND activo=1 LIMIT 1");$site=$st->fetch();if(!$site)out(['ok'=>false,'error'=>'La sede Monteverde no está disponible'],422);$sedeId=(string)$site['id'];
  $minimo=$site['minimo_mensual_socio']!==null?(float)$site['minimo_mensual_socio']:(float)($pdo->query("SELECT valor FROM configuracion WHERE clave='minimo_proa_mensual' LIMIT 1")->fetchColumn()?:28000);
  if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
    $ym=financiero_validar_periodo((string)($_GET['periodo']??date('Y-m')));
    $periodo=monthStart($ym);$prev=financiero_periodo_anterior($ym);
    $prevData=proaForMonth($pdo,$prev,$site);$habilitado=$prevData['aporte_proa']>=$minimo;
    $st=$pdo->prepare("SELECT id,periodo,alumno_proa_nombre,importe,observacion,created_at FROM comisiones_proa WHERE periodo=:p ORDER BY alumno_proa_nombre,created_at");$st->execute([':p'=>$periodo]);$rows=$st->fetchAll();
    $total=array_sum(array_map(fn($x)=>(float)$x['importe'],$rows));
    out(['ok'=>true,'periodo'=>$ym,'periodo_generador'=>$prev,'rango_periodo_generador'=>$prevData['rango'],'minimo'=>$minimo,'aporte_periodo_generador'=>$prevData['aporte_proa'],'habilitado'=>$habilitado,'comisiones'=>$rows,'total'=>$total]);
  }
  if(($_SERVER['REQUEST_METHOD']??'')!=='POST')out(['ok'=>false,'error'=>'Método no permitido'],405);
  $in=json_decode(file_get_contents('php://input'),true);if(!is_array($in))out(['ok'=>false,'error'=>'JSON inválido'],400);$accion=strtoupper((string)($in['accion']??'CREAR'));
  if($accion==='ELIMINAR'){$id=trim((string)($in['id']??''));if($id==='')out(['ok'=>false,'error'=>'ID obligatorio'],422);$st=$pdo->prepare("DELETE FROM comisiones_proa WHERE id=:id");$st->execute([':id'=>$id]);if($st->rowCount()===0)out(['ok'=>false,'error'=>'Comisión no encontrada'],404);out(['ok'=>true]);}
  if($accion==='EDITAR'){$id=trim((string)($in['id']??''));$nombre=trim((string)($in['alumno_proa_nombre']??''));$importe=(float)($in['importe']??0);$obs=trim((string)($in['observacion']??''));$fecha=trim((string)($in['fecha']??''));if($id==='')out(['ok'=>false,'error'=>'ID obligatorio'],422);validateCommission($nombre,$importe,$obs);if($fecha!==''&&!exactDate($fecha))out(['ok'=>false,'error'=>'Fecha inválida'],422);$st=$pdo->prepare("SELECT periodo FROM comisiones_proa WHERE id=:id LIMIT 1");$st->execute([':id'=>$id]);$row=$st->fetch();if(!$row)out(['ok'=>false,'error'=>'Comisión no encontrada'],404);if($fecha!==''&&substr($fecha,0,7)!==substr((string)$row['periodo'],0,7))out(['ok'=>false,'error'=>'La fecha debe permanecer dentro del mismo periodo'],422);if($fecha===''){$st=$pdo->prepare("UPDATE comisiones_proa SET alumno_proa_nombre=:n,importe=:i,observacion=:o WHERE id=:id");$st->execute([':n'=>$nombre,':i'=>$importe,':o'=>$obs!==''?$obs:null,':id'=>$id]);}else{$st=$pdo->prepare("UPDATE comisiones_proa SET alumno_proa_nombre=:n,importe=:i,observacion=:o,created_at=CONCAT(:f,' ',TIME(created_at)) WHERE id=:id");$st->execute([':n'=>$nombre,':i'=>$importe,':o'=>$obs!==''?$obs:null,':f'=>$fecha,':id'=>$id]);}out(['ok'=>true]);}
  $ym=financiero_validar_periodo((string)($in['periodo']??''));$periodo=monthStart($ym);$prev=financiero_periodo_anterior($ym);$prevData=proaForMonth($pdo,$prev,$site);if($prevData['aporte_proa']<$minimo)out(['ok'=>false,'error'=>'Las comisiones de este mes no están habilitadas: el mes anterior no alcanzó el mínimo PROA.'],422);$nombre=trim((string)($in['alumno_proa_nombre']??''));$importe=(float)($in['importe']??0);$obs=trim((string)($in['observacion']??''));validateCommission($nombre,$importe,$obs);$id=(string)$pdo->query('SELECT UUID()')->fetchColumn();$st=$pdo->prepare("INSERT INTO comisiones_proa(id,periodo,alumno_proa_nombre,importe,observacion,created_by) VALUES(:id,:p,:n,:i,:o,:u)");$st->execute([':id'=>$id,':p'=>$periodo,':n'=>$nombre,':i'=>$importe,':o'=>$obs!==''?$obs:null,':u'=>$me['id']]);out(['ok'=>true,'id'=>$id],201);
}catch(InvalidArgumentException $e){out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){error_log('[comisiones-proa] '.$e->getMessage());out(['ok'=>false,'error'=>'No se pudo procesar la solicitud'],500);}
