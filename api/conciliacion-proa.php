<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
$me=auth_require(['ADMIN']);
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
function out(array $data,int $code=200):never{http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function monthRange(string $ym):array{
    if(!preg_match('/^\d{4}-\d{2}$/',$ym))throw new InvalidArgumentException('Periodo inválido');
    $start=$ym.'-01 00:00:00';
    $end=(new DateTimeImmutable($ym.'-01'))->modify('first day of next month')->format('Y-m-d').' 00:00:00';
    return [$start,$end];
}
function obligation(PDO $pdo,string $ym):array{
    [$start,$end]=monthRange($ym);
    $sql="SELECT
      COALESCE(SUM(CASE WHEN p.tipo='MENSUALIDAD' THEN p.importe ELSE 0 END),0) mensualidades,
      COALESCE(SUM(CASE WHEN p.tipo='INTENSIVO' THEN p.importe ELSE 0 END),0) intensivos,
      COALESCE(SUM(CASE WHEN p.tipo='INSCRIPCION' THEN p.importe ELSE 0 END),0) inscripciones
      FROM pagos p
      INNER JOIN alumnos a ON a.id=p.alumno_id
      INNER JOIN sedes s ON s.id=a.sede_id
      WHERE p.estado='VALIDO' AND s.clave='MONTEVERDE' AND p.fecha>=:d AND p.fecha<:h";
    $st=$pdo->prepare($sql);$st->execute([':d'=>$start,':h'=>$end]);$r=$st->fetch()?:[];
    $mens=(float)($r['mensualidades']??0);$int=(float)($r['intensivos']??0);$ins=(float)($r['inscripciones']??0);
    return [
      'mensualidades'=>$mens,
      'intensivos'=>$int,
      'inscripciones'=>$ins,
      'proa_mensualidades'=>$mens*.5,
      'proa_intensivos'=>$int*.5,
      'proa_inscripciones'=>$ins,
      'total_proa'=>($mens*.5)+($int*.5)+$ins,
    ];
}
function movementEffect(string $tipo,float $importe):float{
    return $tipo==='COMISION_RECIBIDA_PROA' ? $importe : -$importe;
}
try{
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if($method==='GET'){
        $ym=(string)($_GET['periodo']??date('Y-m'));[$start,$end]=monthRange($ym);
        $base=obligation($pdo,$ym);
        $st=$pdo->prepare("SELECT m.id,m.fecha,m.tipo,m.importe,m.alumno_nombre,m.referencia,m.observacion,m.estado,m.created_at,m.anulado_at,m.motivo_anulacion,u.usuario created_by_usuario,ua.usuario anulado_by_usuario FROM conciliacion_proa_movimientos m LEFT JOIN usuarios u ON u.id=m.created_by LEFT JOIN usuarios ua ON ua.id=m.anulado_by WHERE m.fecha>=:d AND m.fecha<:h ORDER BY m.fecha DESC,m.created_at DESC");
        $st->execute([':d'=>$start,':h'=>$end]);$rows=$st->fetchAll();
        $entregado=0.0;$directos=0.0;$comisiones=0.0;$efectivo=0.0;$transferencias=0.0;
        foreach($rows as &$row){
            $row['importe']=(float)$row['importe'];
            $row['impacto']=$row['estado']==='ACTIVO'?movementEffect((string)$row['tipo'],(float)$row['importe']):0.0;
            if($row['estado']!=='ACTIVO')continue;
            if($row['tipo']==='EFECTIVO_A_PROA'){$efectivo+=(float)$row['importe'];$entregado+=(float)$row['importe'];}
            elseif($row['tipo']==='TRANSFERENCIA_A_PROA'){$transferencias+=(float)$row['importe'];$entregado+=(float)$row['importe'];}
            elseif($row['tipo']==='PAGO_DIRECTO_PROA')$directos+=(float)$row['importe'];
            elseif($row['tipo']==='COMISION_RECIBIDA_PROA')$comisiones+=(float)$row['importe'];
        }unset($row);
        $saldo=$base['total_proa']+$comisiones-$entregado-$directos;
        $situacion=abs($saldo)<0.005?'CUADRADO':($saldo>0?'HACHE_DEBE_PROA':'PROA_DEBE_HACHE');
        out(['ok'=>true,'periodo'=>$ym,'sede'=>'MONTEVERDE','base'=>$base,'resumen'=>[
            'efectivo_a_proa'=>$efectivo,'transferencias_a_proa'=>$transferencias,'entregado_a_proa'=>$entregado,
            'pagos_directos_proa'=>$directos,'comisiones_recibidas'=>$comisiones,'saldo'=>$saldo,'situacion'=>$situacion
        ],'movimientos'=>$rows]);
    }
    if($method!=='POST')out(['ok'=>false,'error'=>'Método no permitido'],405);
    $in=json_decode(file_get_contents('php://input'),true)?:[];
    $accion=strtoupper(trim((string)($in['accion']??'CREAR')));
    if($accion==='ANULAR'){
        $id=trim((string)($in['id']??''));$motivo=trim((string)($in['motivo']??''));
        if($id===''||$motivo==='')out(['ok'=>false,'error'=>'ID y motivo de anulación son obligatorios'],422);
        $st=$pdo->prepare("UPDATE conciliacion_proa_movimientos SET estado='ANULADO',anulado_by=:u,anulado_at=NOW(),motivo_anulacion=:m WHERE id=:id AND estado='ACTIVO'");
        $st->execute([':u'=>$me['id'],':m'=>$motivo,':id'=>$id]);
        if($st->rowCount()!==1)out(['ok'=>false,'error'=>'Movimiento no encontrado o ya anulado'],404);
        out(['ok'=>true]);
    }
    $tipos=['EFECTIVO_A_PROA','TRANSFERENCIA_A_PROA','PAGO_DIRECTO_PROA','COMISION_RECIBIDA_PROA'];
    $tipo=strtoupper(trim((string)($in['tipo']??'')));$importe=(float)($in['importe']??0);
    $fecha=trim((string)($in['fecha']??''));$alumno=trim((string)($in['alumno_nombre']??''));
    $ref=trim((string)($in['referencia']??''));$obs=trim((string)($in['observacion']??''));
    if(!in_array($tipo,$tipos,true))out(['ok'=>false,'error'=>'Tipo de movimiento inválido'],422);
    if($importe<=0)out(['ok'=>false,'error'=>'El importe debe ser mayor que cero'],422);
    if($fecha==='')$fecha=date('Y-m-d H:i:s');
    else{
        $dt=DateTimeImmutable::createFromFormat('Y-m-d\TH:i',$fecha)?:DateTimeImmutable::createFromFormat('Y-m-d H:i:s',$fecha);
        if(!$dt)out(['ok'=>false,'error'=>'Fecha inválida'],422);$fecha=$dt->format('Y-m-d H:i:s');
    }
    if($tipo==='PAGO_DIRECTO_PROA'&&$alumno==='')out(['ok'=>false,'error'=>'Indica el alumno que pagó directamente en PROA'],422);
    $id=(string)$pdo->query('SELECT UUID()')->fetchColumn();
    $st=$pdo->prepare("INSERT INTO conciliacion_proa_movimientos(id,fecha,tipo,importe,alumno_nombre,referencia,observacion,created_by) VALUES(:id,:f,:t,:i,:a,:r,:o,:u)");
    $st->execute([':id'=>$id,':f'=>$fecha,':t'=>$tipo,':i'=>$importe,':a'=>$alumno!==''?$alumno:null,':r'=>$ref!==''?$ref:null,':o'=>$obs!==''?$obs:null,':u'=>$me['id']]);
    out(['ok'=>true,'id'=>$id],201);
}catch(InvalidArgumentException $e){out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(PDOException $e){
    if(str_contains($e->getMessage(),'conciliacion_proa_movimientos'))out(['ok'=>false,'error'=>'Falta instalar la tabla de conciliación PROA. Ejecuta el migrador incluido en el despliegue.'],503);
    out(['ok'=>false,'error'=>'Error de base de datos'],500);
}catch(Throwable $e){out(['ok'=>false,'error'=>$e->getMessage()],500);}
