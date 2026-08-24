<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
$me=auth_require(['ADMIN']);
require_once __DIR__.'/../config/reglas-acceso.php';
$config=require __DIR__.'/../config/database.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);header('Allow: POST');echo json_encode(['ok'=>false,'error'=>'Método no permitido'],JSON_UNESCAPED_UNICODE);exit;}

function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;}
function fechaPagoExacta(string $value):DateTimeImmutable{foreach(['Y-m-d H:i:s','Y-m-d\TH:i'] as $format){$date=DateTimeImmutable::createFromFormat('!'.$format,$value);if($date&&$date->format($format)===$value)return $date;}throw new InvalidArgumentException('Fecha de pago inválida');}

try{
    $pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $input=json_decode(file_get_contents('php://input'),true);if(!is_array($input))out(['ok'=>false,'error'=>'JSON inválido'],400);
    $pagoId=trim((string)($input['pago_id']??''));$usuarioId=(string)$me['id'];$motivo=trim((string)($input['motivo']??''));
    $importe=$input['importe']??null;$metodo=strtoupper(trim((string)($input['metodo']??'')));$fecha=trim((string)($input['fecha']??''));$observacion=trim((string)($input['observacion']??''));
    if($pagoId===''||$motivo===''||!is_numeric($importe)||(float)$importe<=0||(float)$importe>9999999.99||!in_array($metodo,['EFECTIVO','TRANSFERENCIA','MERCADO_PAGO'],true)||$fecha==='')out(['ok'=>false,'error'=>'Pago, motivo, importe, método y fecha son obligatorios'],422);if(mb_strlen($motivo)>500||mb_strlen($observacion)>1000)out(['ok'=>false,'error'=>'El motivo o la observación exceden la longitud permitida'],422);
    try{$fechaPago=fechaPagoExacta($fecha);}catch(InvalidArgumentException $e){out(['ok'=>false,'error'=>$e->getMessage()],422);} $fechaSql=$fechaPago->format('Y-m-d H:i:s');$fechaDia=$fechaPago->format('Y-m-d');$importeDec=number_format((float)$importe,2,'.','');
    $sedeClave=auth_active_sede_clave();$st=$pdo->prepare("SELECT id FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");$st->execute([':c'=>$sedeClave]);$sedeId=(string)$st->fetchColumn();if($sedeId==='')out(['ok'=>false,'error'=>'Sede activa inválida'],422);
    $pdo->beginTransaction();
    $st=$pdo->prepare("SELECT p.*,a.nombre alumno_nombre,m.importe_a_cobrar,m.importe_cobrado,m.fecha_pago,m.observacion mensualidad_observacion,i.importe inscripcion_importe,i.fecha inscripcion_fecha,i.observacion inscripcion_observacion FROM pagos p INNER JOIN alumnos a ON a.id=p.alumno_id LEFT JOIN mensualidades m ON m.id=p.mensualidad_id AND m.sede_id=a.sede_id LEFT JOIN inscripciones i ON i.id=p.inscripcion_id AND i.sede_id=a.sede_id WHERE p.id=:id AND a.sede_id=:s LIMIT 1 FOR UPDATE");$st->execute([':id'=>$pagoId,':s'=>$sedeId]);$old=$st->fetch();if(!$old){$pdo->rollBack();out(['ok'=>false,'error'=>'Pago no encontrado en la sede activa'],404);}if($old['estado']!=='VALIDO'){$pdo->rollBack();out(['ok'=>false,'error'=>'Solo se pueden editar pagos válidos'],422);}if($old['tipo']==='MENSUALIDAD'&&!empty($old['mensualidad_id'])&&$old['importe_a_cobrar']===null){$pdo->rollBack();out(['ok'=>false,'error'=>'La mensualidad relacionada no pertenece a la sede activa'],409);}if($old['tipo']==='INSCRIPCION'&&!empty($old['inscripcion_id'])&&$old['inscripcion_importe']===null){$pdo->rollBack();out(['ok'=>false,'error'=>'La inscripción relacionada no pertenece a la sede activa'],409);}
    $oldImporte=number_format((float)$old['importe'],2,'.','');$oldMetodo=(string)$old['metodo'];$oldFecha=(string)$old['fecha'];$oldObs=trim((string)($old['observacion']??''));
    if($oldImporte===$importeDec&&$oldMetodo===$metodo&&substr($oldFecha,0,19)===$fechaSql&&$oldObs===$observacion){$pdo->rollBack();out(['ok'=>false,'error'=>'No hay cambios que guardar'],422);}
    $st=$pdo->prepare("UPDATE pagos SET importe=:importe,metodo=:metodo,fecha=:fecha,observacion=:obs WHERE id=:id AND estado='VALIDO'");$st->execute([':importe'=>$importeDec,':metodo'=>$metodo,':fecha'=>$fechaSql,':obs'=>$observacion!==''?$observacion:null,':id'=>$pagoId]);if($st->rowCount()!==1)throw new RuntimeException('El pago cambió mientras se procesaba la edición');
    if($old['tipo']==='MENSUALIDAD'&&!empty($old['mensualidad_id'])){
        $st=$pdo->prepare("UPDATE mensualidades SET importe_a_cobrar=:importe_cobrar,importe_cobrado=:importe_cobrado,fecha_pago=:fecha,observacion=:obs,updated_at=NOW() WHERE id=:id AND sede_id=:sede");
        $st->execute([':importe_cobrar'=>$importeDec,':importe_cobrado'=>$importeDec,':fecha'=>$fechaSql,':obs'=>$observacion!==''?$observacion:null,':id'=>$old['mensualidad_id'],':sede'=>$sedeId]);
    }elseif($old['tipo']==='INSCRIPCION'&&!empty($old['inscripcion_id'])){
        $st=$pdo->prepare("UPDATE inscripciones SET importe=:importe,fecha=:fecha,observacion=:obs WHERE id=:id AND sede_id=:sede");$st->execute([':importe'=>$importeDec,':fecha'=>$fechaDia,':obs'=>$observacion!==''?$observacion:null,':id'=>$old['inscripcion_id'],':sede'=>$sedeId]);
    }
    $descripcion='Edición de pago #'.$old['folio'].'. Motivo: '.$motivo.'. Antes: $'.$oldImporte.', '.$oldMetodo.', '.$oldFecha.'. Después: $'.$importeDec.', '.$metodo.', '.$fechaSql.'.';
    $hid=(string)$pdo->query('SELECT UUID()')->fetchColumn();$st=$pdo->prepare("INSERT INTO historial(id,alumno_id,tipo,descripcion,usuario_id,referencia_tipo,referencia_id) VALUES(:id,:alumno,'PAGO',:descripcion,:usuario,'PAGO',:pago)");$st->execute([':id'=>$hid,':alumno'=>$old['alumno_id'],':descripcion'=>$descripcion,':usuario'=>$usuarioId,':pago'=>$pagoId]);
    regla_recalcular_alumno($pdo,(string)$old['alumno_id']);
    $pdo->commit();
    $st=$pdo->prepare("SELECT p.*,a.nombre alumno_nombre FROM pagos p INNER JOIN alumnos a ON a.id=p.alumno_id WHERE p.id=:id LIMIT 1");$st->execute([':id'=>$pagoId]);
    out(['ok'=>true,'mensaje'=>'Pago actualizado correctamente','pago'=>$st->fetch(),'auditoria'=>['motivo'=>$motivo,'usuario'=>$me['usuario'],'descripcion'=>$descripcion]]);
}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();error_log('[editar-pago] '.$e->getMessage());out(['ok'=>false,'error'=>'No se pudo editar el pago'],500);}
