<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

require_once __DIR__.'/../config/auth.php';
$me=auth_require(['ADMIN','VERIFICADOR']);
$config=require __DIR__.'/../config/database.php';
require_once __DIR__.'/../config/historias-notificaciones.php';
$pdo=new PDO(
    "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
    $config['user'],$config['password'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
);

function mod_out(array $data,int $status=200): never
{
    http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}
function mod_out_after(array $data,int $status,callable $task): never
{
    http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    if(function_exists('fastcgi_finish_request'))@fastcgi_finish_request();
    try{$task();}catch(Throwable $e){error_log('[historias/moderacion] Falló tarea posterior a respuesta: '.$e->getMessage());}
    exit;
}
function estado_valido(mixed $value): string
{
    $estado=strtoupper(trim((string)$value));$allowed=['PENDIENTE','APROBADO','RECHAZADO','OCULTO','ELIMINADO'];
    return in_array($estado,$allowed,true)?$estado:'PENDIENTE';
}
function mod_extension_disponible(PDO $pdo): bool
{
    $st=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('historia_respuestas','historia_comentario_suscripciones')");
    return (int)$st->fetchColumn()===2;
}

try{
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));$extension=mod_extension_disponible($pdo);
    if($method==='GET'){
        $estado=estado_valido($_GET['estado']??'PENDIENTE');$counts=['PENDIENTE'=>0,'APROBADO'=>0,'RECHAZADO'=>0,'OCULTO'=>0,'ELIMINADO'=>0];
        foreach($pdo->query('SELECT estado,COUNT(*) total FROM historia_comentarios GROUP BY estado')->fetchAll() as $row){if(isset($counts[$row['estado']]))$counts[$row['estado']]=(int)$row['total'];}
        $order=$estado==='PENDIENTE'?'ASC':'DESC';
        if($extension){
            $sql="SELECT c.id,c.historia_slug,c.autor_nombre,c.comentario,c.estado,c.flags,c.created_at,c.moderado_at,c.moderado_por,r.parent_id,r.reply_to_id,r.notificacion_estado,r.notificacion_intentos,target.autor_nombre reply_to_autor,target.comentario reply_to_comentario,s.estado aviso_estado,s.confirmacion_estado,s.confirmacion_intentos,EXISTS(SELECT 1 FROM historia_bloqueos b WHERE b.origen_hash=c.origen_hash AND b.activo=1) origen_bloqueado FROM historia_comentarios c LEFT JOIN historia_respuestas r ON r.comentario_id=c.id LEFT JOIN historia_comentarios target ON target.id=r.reply_to_id LEFT JOIN historia_comentario_suscripciones s ON s.comentario_id=c.id WHERE c.estado=:estado ORDER BY c.created_at {$order} LIMIT 100";
        }else{
            $sql="SELECT c.id,c.historia_slug,c.autor_nombre,c.comentario,c.estado,c.flags,c.created_at,c.moderado_at,c.moderado_por,NULL parent_id,NULL reply_to_id,NULL notificacion_estado,0 notificacion_intentos,NULL reply_to_autor,NULL reply_to_comentario,NULL aviso_estado,NULL confirmacion_estado,0 confirmacion_intentos,EXISTS(SELECT 1 FROM historia_bloqueos b WHERE b.origen_hash=c.origen_hash AND b.activo=1) origen_bloqueado FROM historia_comentarios c WHERE c.estado=:estado ORDER BY c.created_at {$order} LIMIT 100";
        }
        $st=$pdo->prepare($sql);$st->execute([':estado'=>$estado]);$items=$st->fetchAll();
        foreach($items as &$item){
            $replyRetry=$item['reply_to_id']!==null&&$item['estado']==='APROBADO'&&!in_array((string)$item['notificacion_estado'],['ENVIADA','NO_APLICA'],true)&&(int)$item['notificacion_intentos']<3;
            $confirmRetry=$item['aviso_estado']==='PENDIENTE'&&$item['estado']==='APROBADO'&&!in_array((string)$item['confirmacion_estado'],['ENVIADA'],true)&&(int)$item['confirmacion_intentos']<3;
            $item['correo_reintentable']=$extension&&($replyRetry||$confirmRetry);
        }unset($item);
        mod_out(['ok'=>true,'estado'=>$estado,'conteos'=>$counts,'comentarios'=>$items,'csrf'=>auth_csrf_token(),'extension_habilitada'=>$extension]);
    }

    if($method!=='POST')mod_out(['ok'=>false,'error'=>'Método no permitido'],405);
    $contentType=strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''),2)[0]));if($contentType!=='application/json')mod_out(['ok'=>false,'error'=>'Tipo de contenido no permitido'],415);
    $input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))mod_out(['ok'=>false,'error'=>'Solicitud inválida'],400);
    if(!auth_csrf_validate(isset($input['csrf'])?(string)$input['csrf']:null))mod_out(['ok'=>false,'error'=>'Sesión de seguridad vencida. Recarga la página.'],419);
    $accion=strtoupper(trim((string)($input['accion']??'')));$id=trim((string)($input['id']??''));
    if($id===''||!preg_match('/^[a-f0-9-]{36}$/i',$id))mod_out(['ok'=>false,'error'=>'Comentario inválido'],422);

    if($extension)$st=$pdo->prepare('SELECT c.id,c.origen_hash,c.estado,r.reply_to_id FROM historia_comentarios c LEFT JOIN historia_respuestas r ON r.comentario_id=c.id WHERE c.id=:id LIMIT 1');
    else $st=$pdo->prepare('SELECT c.id,c.origen_hash,c.estado,NULL reply_to_id FROM historia_comentarios c WHERE c.id=:id LIMIT 1');
    $st->execute([':id'=>$id]);$comment=$st->fetch();if(!$comment)mod_out(['ok'=>false,'error'=>'Comentario no encontrado'],404);

    $statusMap=['APROBAR'=>'APROBADO','RECHAZAR'=>'RECHAZADO','OCULTAR'=>'OCULTO','ELIMINAR'=>'ELIMINADO'];
    if(isset($statusMap[$accion])){
        $nuevoEstado=$statusMap[$accion];$st=$pdo->prepare('UPDATE historia_comentarios SET estado=:estado,moderado_por=:usuario,moderado_at=NOW(),updated_at=NOW() WHERE id=:id');
        $st->execute([':estado'=>$nuevoEstado,':usuario'=>$me['id'],':id'=>$id]);
        if($nuevoEstado==='APROBADO'&&$extension){
            mod_out_after(['ok'=>true,'estado'=>$nuevoEstado],200,static function() use($pdo,$id,$config,$comment): void {
                historias_enviar_confirmacion_comentario($pdo,$id,$config);if(!empty($comment['reply_to_id']))historias_notificar_respuesta_aprobada($pdo,$id,$config);
            });
        }
        mod_out(['ok'=>true,'estado'=>$nuevoEstado]);
    }

    if($accion==='REINTENTAR_CORREO'){
        if(!$extension)mod_out(['ok'=>false,'error'=>'La extensión de respuestas todavía no está migrada.'],503);
        if($comment['estado']!=='APROBADO')mod_out(['ok'=>false,'error'=>'Solo se reintentan correos de comentarios aprobados.'],409);
        mod_out_after(['ok'=>true,'reintento_iniciado'=>true],200,static function() use($pdo,$id,$config): void {historias_reintentar_correo_comentario($pdo,$id,$config);});
    }

    if($accion==='BLOQUEAR_ORIGEN'){
        $motivo=trim((string)($input['motivo']??'Abuso en comentarios de Historias'));$motivo=mb_substr($motivo!==''?$motivo:'Abuso en comentarios de Historias',0,160);
        $pdo->beginTransaction();
        $st=$pdo->prepare("INSERT INTO historia_bloqueos(origen_hash,motivo,activo,created_by,updated_by) VALUES(:origen,:motivo,1,:usuario,:usuario) ON DUPLICATE KEY UPDATE motivo=VALUES(motivo),activo=1,updated_by=VALUES(updated_by),updated_at=NOW()");
        $st->execute([':origen'=>$comment['origen_hash'],':motivo'=>$motivo,':usuario'=>$me['id']]);
        $st=$pdo->prepare("UPDATE historia_comentarios SET estado='RECHAZADO',moderado_por=:usuario,moderado_at=NOW(),updated_at=NOW() WHERE origen_hash=:origen AND estado='PENDIENTE'");$st->execute([':usuario'=>$me['id'],':origen'=>$comment['origen_hash']]);
        $pdo->commit();mod_out(['ok'=>true,'bloqueado'=>true]);
    }
    if($accion==='DESBLOQUEAR_ORIGEN'){$st=$pdo->prepare('UPDATE historia_bloqueos SET activo=0,updated_by=:usuario,updated_at=NOW() WHERE origen_hash=:origen');$st->execute([':usuario'=>$me['id'],':origen'=>$comment['origen_hash']]);mod_out(['ok'=>true,'bloqueado'=>false]);}
    mod_out(['ok'=>false,'error'=>'Acción inválida'],422);
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('[historias/moderacion] '.$e->getMessage());mod_out(['ok'=>false,'error'=>'No se pudo completar la moderación'],500);}
