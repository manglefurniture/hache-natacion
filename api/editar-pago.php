<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
auth_require(['ADMIN']);
$config=require __DIR__.'/../config/database.php';

function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;}

try{
    $pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $input=json_decode(file_get_contents('php://input'),true);if(!is_array($input))out(['ok'=>false,'error'=>'JSON inválido'],400);
    $pagoId=trim((string)($input['pago_id']??''));$usuarioId=trim((string)($input['usuario_id']??''));$motivo=trim((string)($input['motivo']??''));
    $importe=$input['importe']??null;$metodo=strtoupper(trim((string)($input['metodo']??'')));$fecha=trim((string)($input['fecha']??''));$observacion=trim((string)($input['observacion']??''));
    if($pagoId===''||$usuarioId===''||$motivo===''||!is_numeric($importe)||(float)$importe<0||!in_array($metodo,['EFECTIVO','TRANSFERENCIA','MERCADO_PAGO'],true)||$fecha==='')out(['ok'=>false,'error'=>'Pago, usuario, motivo, importe, método y fecha son obligatorios'],422);
    try{$fechaPago=new DateTimeImmutable($fecha);}catch(Throwable $e){out(['ok'=>false,'error'=>'Fecha inválida'],422);} $fechaSql=$fechaPago->format('Y-m-d H:i:s');$fechaDia=$fechaPago->format('Y-m-d');$importeDec=number_format((float)$importe,2,'.','');
    $st=$pdo->prepare("SELECT id,usuario FROM usuarios WHERE id=:id AND activo=1 LIMIT 1");$st->execute([':id'=>$usuarioId]);$u=$st->fetch();if(!$u)out(['ok'=>false,'error'=>'Usuario administrativo inválido'],422);
    $st=$pdo->prepare("SELECT p.*,a.nombre alumno_nombre,m.importe_a_cobrar,m.importe_cobrado,m.fecha_pago,m.observacion mensualidad_observacion,i.importe inscripcion_importe,i.fecha inscripcion_fecha,i.observacion inscripcion_observacion FROM pagos p INNER JOIN alumnos a ON a.id=p.alumno_id LEFT JOIN mensualidades m ON m.id=p.mensualidad_id LEFT JOIN inscripciones i ON i.id=p.inscripcion_id WHERE p.id=:id LIMIT 1");$st->execute([':id'=>$pagoId]);$old=$st->fetch();if(!$old)out(['ok'=>false,'error'=>'Pago no encontrado'],404);if($old['estado']!=='VALIDO')out(['ok'=>false,'error'=>'Solo se pueden editar pagos válidos'],422);
    $oldImporte=number_format((float)$old['importe'],2,'.','');$oldMetodo=(string)$old['metodo'];$oldFecha=(string)$old['fecha'];$oldObs=trim((string)($old['observacion']??''));
    if($oldImporte===$importeDec&&$oldMetodo===$metodo&&substr($oldFecha,0,19)===$fechaSql&&$oldObs===$observacion)out(['ok'=>false,'error'=>'No hay cambios que guardar'],422);
    $pdo->beginTransaction();
    $st=$pdo->prepare("UPDATE pagos SET importe=:importe,metodo=:metodo,fecha=:fecha,observacion=:obs WHERE id=:id");$st->execute([':importe'=>$importeDec,':metodo'=>$metodo,':fecha'=>$fechaSql,':obs'=>$observacion!==''?$observacion:null,':id'=>$pagoId]);
    if($old['tipo']==='MENSUALIDAD'&&!empty($old['mensualidad_id'])){
        $st=$pdo->prepare("UPDATE mensualidades SET importe_a_cobrar=:importe_cobrar,importe_cobrado=:importe_cobrado,fecha_pago=:fecha,observacion=:obs,updated_at=NOW() WHERE id=:id");
        $st->execute([':importe_cobrar'=>$importeDec,':importe_cobrado'=>$importeDec,':fecha'=>$fechaSql,':obs'=>$observacion!==''?$observacion:null,':id'=>$old['mensualidad_id']]);
    }elseif($old['tipo']==='INSCRIPCION'&&!empty($old['inscripcion_id'])){
        $st=$pdo->prepare("UPDATE inscripciones SET importe=:importe,fecha=:fecha,observacion=:obs WHERE id=:id");$st->execute([':importe'=>$importeDec,':fecha'=>$fechaDia,':obs'=>$observacion!==''?$observacion:null,':id'=>$old['inscripcion_id']]);
    }
    $descripcion='Edición de pago #'.$old['folio'].'. Motivo: '.$motivo.'. Antes: $'.$oldImporte.', '.$oldMetodo.', '.$oldFecha.'. Después: $'.$importeDec.', '.$metodo.', '.$fechaSql.'.';
    $hid=(string)$pdo->query('SELECT UUID()')->fetchColumn();$st=$pdo->prepare("INSERT INTO historial(id,alumno_id,tipo,descripcion,usuario_id,referencia_tipo,referencia_id) VALUES(:id,:alumno,'PAGO',:descripcion,:usuario,'PAGO',:pago)");$st->execute([':id'=>$hid,':alumno'=>$old['alumno_id'],':descripcion'=>$descripcion,':usuario'=>$usuarioId,':pago'=>$pagoId]);
    $pdo->commit();
    $st=$pdo->prepare("SELECT p.*,a.nombre alumno_nombre FROM pagos p INNER JOIN alumnos a ON a.id=p.alumno_id WHERE p.id=:id LIMIT 1");$st->execute([':id'=>$pagoId]);
    out(['ok'=>true,'mensaje'=>'Pago actualizado correctamente','pago'=>$st->fetch(),'auditoria'=>['motivo'=>$motivo,'usuario'=>$u['usuario'],'descripcion'=>$descripcion]]);
}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();out(['ok'=>false,'error'=>$e->getMessage()],500);}
