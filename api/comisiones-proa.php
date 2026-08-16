<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
$me=auth_require(['ADMIN']);
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function monthStart(string $ym):string{if(!preg_match('/^\d{4}-\d{2}$/',$ym))throw new InvalidArgumentException('Periodo inválido');return $ym.'-01';}
function proaForMonth(PDO $pdo,string $ym):array{
  $start=monthStart($ym);$end=(new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
  $st=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN tipo='MENSUALIDAD' THEN importe ELSE 0 END),0) mensualidades,COALESCE(SUM(CASE WHEN tipo='INTENSIVO' THEN importe ELSE 0 END),0) intensivos,COALESCE(SUM(CASE WHEN tipo='INSCRIPCION' THEN importe ELSE 0 END),0) inscripciones FROM pagos WHERE estado='VALIDO' AND DATE(fecha) BETWEEN :d AND :h");
  $st->execute([':d'=>$start,':h'=>$end]);$r=$st->fetch();
  $aporte=((float)$r['mensualidades']*.5)+((float)$r['intensivos']*.5)+(float)$r['inscripciones'];
  return ['mensualidades'=>(float)$r['mensualidades'],'intensivos'=>(float)$r['intensivos'],'inscripciones'=>(float)$r['inscripciones'],'aporte_proa'=>$aporte];
}
try{
  $minimo=(float)($pdo->query("SELECT valor FROM configuracion WHERE clave='minimo_proa_mensual' LIMIT 1")->fetchColumn()?:28000);
  if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
    $ym=(string)($_GET['periodo']??date('Y-m'));
    $periodo=monthStart($ym);$prev=(new DateTimeImmutable($periodo))->modify('-1 month')->format('Y-m');
    $prevData=proaForMonth($pdo,$prev);$habilitado=$prevData['aporte_proa']>=$minimo;
    $st=$pdo->prepare("SELECT id,periodo,alumno_proa_nombre,importe,observacion,created_at FROM comisiones_proa WHERE periodo=:p ORDER BY alumno_proa_nombre,created_at");$st->execute([':p'=>$periodo]);$rows=$st->fetchAll();
    $total=array_sum(array_map(fn($x)=>(float)$x['importe'],$rows));
    out(['ok'=>true,'periodo'=>$ym,'periodo_generador'=>$prev,'minimo'=>$minimo,'aporte_periodo_generador'=>$prevData['aporte_proa'],'habilitado'=>$habilitado,'comisiones'=>$rows,'total'=>$total]);
  }
  if(($_SERVER['REQUEST_METHOD']??'')!=='POST')out(['ok'=>false,'error'=>'Método no permitido'],405);
  $in=json_decode(file_get_contents('php://input'),true)?:[];$accion=strtoupper((string)($in['accion']??'CREAR'));
  if($accion==='ELIMINAR'){
    $id=trim((string)($in['id']??''));if($id==='')out(['ok'=>false,'error'=>'ID obligatorio'],422);
    $st=$pdo->prepare("DELETE FROM comisiones_proa WHERE id=:id");$st->execute([':id'=>$id]);out(['ok'=>true]);
  }
  $ym=(string)($in['periodo']??'');$periodo=monthStart($ym);$prev=(new DateTimeImmutable($periodo))->modify('-1 month')->format('Y-m');
  $prevData=proaForMonth($pdo,$prev);if($prevData['aporte_proa']<$minimo)out(['ok'=>false,'error'=>'Las comisiones de este mes no están habilitadas: el mes anterior no alcanzó el mínimo PROA.'],422);
  $nombre=trim((string)($in['alumno_proa_nombre']??''));$importe=(float)($in['importe']??0);$obs=trim((string)($in['observacion']??''));
  if($nombre===''||$importe<=0)out(['ok'=>false,'error'=>'Nombre e importe válido son obligatorios'],422);
  $id=(string)$pdo->query('SELECT UUID()')->fetchColumn();
  $st=$pdo->prepare("INSERT INTO comisiones_proa(id,periodo,alumno_proa_nombre,importe,observacion,created_by) VALUES(:id,:p,:n,:i,:o,:u)");$st->execute([':id'=>$id,':p'=>$periodo,':n'=>$nombre,':i'=>$importe,':o'=>$obs!==''?$obs:null,':u'=>$me['id']]);
  out(['ok'=>true,'id'=>$id],201);
}catch(InvalidArgumentException $e){out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){out(['ok'=>false,'error'=>$e->getMessage()],500);}
