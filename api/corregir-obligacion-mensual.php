<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/finanzas-correcciones.php';
require_once __DIR__.'/../config/admin-historical-corrections.php';
require_once __DIR__.'/../config/reglas-acceso.php';
$config=require __DIR__.'/../config/database.php';

function correccion_mensual_out(array $data,int $status=200): never
{
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    exit;
}

try{
    $admin=auth_require(['ADMIN']);
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')correccion_mensual_out(['ok'=>false,'error'=>'Método no permitido'],405);
    $in=json_decode(file_get_contents('php://input'),true);
    if(!is_array($in))correccion_mensual_out(['ok'=>false,'error'=>'JSON inválido'],400);

    $mensualidadId=trim((string)($in['mensualidad_id']??''));
    $motivo=trim((string)($in['motivo']??''));
    if($mensualidadId==='')correccion_mensual_out(['ok'=>false,'error'=>'mensualidad_id es obligatorio'],422);
    if(mb_strlen($motivo)<5)correccion_mensual_out(['ok'=>false,'error'=>'Escribe un motivo claro para la corrección'],422);
    if(mb_strlen($motivo)>700)correccion_mensual_out(['ok'=>false,'error'=>'El motivo no puede exceder 700 caracteres'],422);

    $clave=auth_resolve_sede_clave((string)($in['sede']??''));
    $pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $st=$pdo->prepare("SELECT id FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");
    $st->execute([':c'=>$clave]);$sedeId=(string)$st->fetchColumn();
    if($sedeId==='')correccion_mensual_out(['ok'=>false,'error'=>'Sede inválida'],422);

    $pdo->beginTransaction();
    $sql="SELECT m.id,m.alumno_id,m.mes,m.anio,m.periodo_inicio,m.periodo_fin,m.importe_a_cobrar,m.importe_cobrado,m.estado obligacion_estado,m.observacion,a.nombre alumno,
                 EXISTS(SELECT 1 FROM curso_intensivo_alumnos cia INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.alumno_id=m.alumno_id AND ci.sede_id=m.sede_id AND ci.fecha_inicio<=m.periodo_fin AND ci.fecha_fin>=m.periodo_inicio) intensivo_solapado
          FROM mensualidades m INNER JOIN alumnos a ON a.id=m.alumno_id
          WHERE m.id=:id AND m.sede_id=:s LIMIT 1 FOR UPDATE";
    $st=$pdo->prepare($sql);$st->execute([':id'=>$mensualidadId,':s'=>$sedeId]);$m=$st->fetch();
    if(!$m){$pdo->rollBack();correccion_mensual_out(['ok'=>false,'error'=>'La mensualidad no existe en la sede activa'],404);}

    $st=$pdo->prepare("SELECT id FROM pagos WHERE mensualidad_id=:id FOR UPDATE");$st->execute([':id'=>$mensualidadId]);$pagos=$st->fetchAll(PDO::FETCH_COLUMN);
    $m['pagos_totales']=count($pagos);
    if(!finanzas_mensualidad_corregible($m)){
        $pdo->rollBack();
        correccion_mensual_out(['ok'=>false,'error'=>'Esta obligación ya tiene historial financiero o no tiene evidencia suficiente para retirarla desde esta corrección'],409);
    }

    $periodo=sprintf('%02d/%04d',(int)$m['mes'],(int)$m['anio']);
    $importe=number_format((float)$m['importe_a_cobrar'],2,'.',',');
    $origen=str_starts_with(trim((string)$m['observacion']),'Continuidad desde intensivo:')?'continuidad desde intensivo':'intensivo solapado en el periodo';
    $descripcion='Corrección histórica de obligación mensual: se retiró la mensualidad '.$periodo.' por $'.$importe.' MXN, pendiente y sin pagos/cobros asociados. Evidencia: '.$origen.'. Motivo: '.$motivo.'. ID retirado: '.$mensualidadId.'.';
    hache_admin_history($pdo,(string)$m['alumno_id'],'MENSUALIDAD',$descripcion,(string)$admin['id'],'MENSUALIDAD_CORREGIDA',$mensualidadId);

    $st=$pdo->prepare("DELETE FROM mensualidades WHERE id=:id AND alumno_id=:a AND sede_id=:s AND estado='PENDIENTE' AND importe_cobrado IS NULL AND NOT EXISTS (SELECT 1 FROM pagos p WHERE p.mensualidad_id=:pid)");
    $st->execute([':id'=>$mensualidadId,':a'=>$m['alumno_id'],':s'=>$sedeId,':pid'=>$mensualidadId]);
    if($st->rowCount()!==1)throw new RuntimeException('La obligación cambió durante la corrección');

    regla_recalcular_alumno($pdo,(string)$m['alumno_id']);
    $pdo->commit();
    correccion_mensual_out(['ok'=>true,'mensaje'=>'Obligación retirada con trazabilidad','mensualidad_id'=>$mensualidadId,'periodo'=>$periodo]);
}catch(Throwable $e){
    if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
    error_log('[corregir-obligacion-mensual] '.$e->getMessage());
    correccion_mensual_out(['ok'=>false,'error'=>'No se pudo aplicar la corrección histórica'],500);
}
