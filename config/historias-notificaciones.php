<?php

declare(strict_types=1);

require_once __DIR__.'/notificaciones-email.php';

function historias_notificacion_secreto(array $config): string
{
    $secret=trim((string)(getenv('HACHE_PUBLIC_INTERACTION_SALT')?:''));
    if($secret!=='')return $secret;
    $dbPass=(string)($config['password']??'');
    if($dbPass!=='')return $dbPass;
    throw new RuntimeException('Falta un secreto estable para los avisos de Historias.');
}
function historias_token_hmac(string $purpose,string $comentarioId,string $email,string $secret): string
{
    $raw=hash_hmac('sha256',$purpose."\n".$comentarioId."\n".strtolower(trim($email)),$secret,true);
    return rtrim(strtr(base64_encode($raw),'+/','-_'),'=');
}
function historias_confirm_token(string $comentarioId,string $email,string $secret): string{return historias_token_hmac('confirmar-avisos-v1',$comentarioId,$email,$secret);}
function historias_cancel_token(string $comentarioId,string $email,string $secret): string{return historias_token_hmac('cancelar-avisos-v1',$comentarioId,$email,$secret);}
function historias_resumen_texto(string $texto,int $max=220): string
{
    $texto=trim(preg_replace('/\s+/u',' ',$texto)??'');if(mb_strlen($texto)<=$max)return $texto;return rtrim(mb_substr($texto,0,max(1,$max-1))).'…';
}
function historias_url_comentario(string $slug,string $comentarioId): string{return 'https://hnatacion.com/historias/'.rawurlencode($slug).'.php#comentario-'.rawurlencode($comentarioId);}

function historias_enviar_confirmacion_comentario(PDO $pdo,string $comentarioId,array $config): bool
{
    try{
        $pdo->beginTransaction();
        // El UPDATE reclama y bloquea la suscripción hasta terminar el intento. Así una baja
        // concurrente gana antes del claim (y no se envía) o espera a que finalice el envío.
        $claim=$pdo->prepare("UPDATE historia_comentario_suscripciones SET confirmacion_estado='ENVIANDO',confirmacion_intentos=confirmacion_intentos+1,updated_at=NOW() WHERE comentario_id=:id AND estado='PENDIENTE' AND confirm_expires_at>=NOW() AND confirmacion_intentos<3 AND (confirmacion_estado IN ('PENDIENTE','FALLO') OR (confirmacion_estado='ENVIANDO' AND updated_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE)))");
        $claim->execute([':id'=>$comentarioId]);
        if($claim->rowCount()!==1){$pdo->rollBack();return false;}

        $st=$pdo->prepare("SELECT c.historia_slug,c.autor_nombre,c.comentario,s.email FROM historia_comentarios c JOIN historia_comentario_suscripciones s ON s.comentario_id=c.id WHERE c.id=:id AND s.estado='PENDIENTE' LIMIT 1 FOR UPDATE");
        $st->execute([':id'=>$comentarioId]);$row=$st->fetch();
        if(!$row){$pdo->prepare("UPDATE historia_comentario_suscripciones SET confirmacion_estado='FALLO',updated_at=NOW() WHERE comentario_id=:id AND confirmacion_estado='ENVIANDO'")->execute([':id'=>$comentarioId]);$pdo->commit();return false;}

        try{$secret=historias_notificacion_secreto($config);}catch(Throwable $e){
            error_log('[historias-notificaciones] No se pudo construir el token de confirmación: '.$e->getMessage());
            $pdo->prepare("UPDATE historia_comentario_suscripciones SET confirmacion_estado='FALLO',updated_at=NOW() WHERE comentario_id=:id AND confirmacion_estado='ENVIANDO'")->execute([':id'=>$comentarioId]);$pdo->commit();return false;
        }
        $email=(string)$row['email'];$token=historias_confirm_token($comentarioId,$email,$secret);$cancelToken=historias_cancel_token($comentarioId,$email,$secret);
        $confirmUrl='https://hnatacion.com/historias/notificaciones.php?accion=confirmar&token='.rawurlencode($token);
        $cancelUrl='https://hnatacion.com/historias/notificaciones.php?accion=cancelar&comentario='.rawurlencode($comentarioId).'&token='.rawurlencode($cancelToken);
        $subject='Confirma los avisos de respuestas · Historias Hache';
        $body="Hola ".trim((string)$row['autor_nombre']).",\n\n".
            "Pediste recibir un aviso cuando alguien responda a tu comentario en Historias Hache.\n\n".
            "Tu comentario:\n“".historias_resumen_texto((string)$row['comentario'])."”\n\n".
            "Confirma los avisos aquí:\n".$confirmUrl."\n\n".
            "Si no solicitaste estos avisos, puedes cancelarlos aquí:\n".$cancelUrl."\n\n".
            "El enlace de confirmación vence en 7 días. Estos avisos son solo para respuestas a tu comentario; no te suscriben a promociones ni newsletters.\n\n".
            "Hache Natación";

        $sent=hache_enviar_correo_transaccional($email,$subject,$body,'historias-confirmacion/'.$comentarioId);
        $pdo->prepare("UPDATE historia_comentario_suscripciones SET confirmacion_estado=:estado,confirmacion_enviada_at=CASE WHEN :estado_fecha='ENVIADA' THEN NOW() ELSE confirmacion_enviada_at END,updated_at=NOW() WHERE comentario_id=:id AND confirmacion_estado='ENVIANDO'")->execute([':estado'=>$sent?'ENVIADA':'FALLO',':estado_fecha'=>$sent?'ENVIADA':'FALLO',':id'=>$comentarioId]);
        $pdo->commit();return $sent;
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        try{$pdo->prepare("UPDATE historia_comentario_suscripciones SET confirmacion_estado='FALLO',updated_at=NOW() WHERE comentario_id=:id AND confirmacion_estado='ENVIANDO'")->execute([':id'=>$comentarioId]);}catch(Throwable $markError){error_log('[historias-notificaciones] No se pudo liberar el claim de confirmación: '.$markError->getMessage());}
        error_log('[historias-notificaciones] Falló la coordinación del correo de confirmación: '.$e->getMessage());return false;
    }
}

function historias_notificar_respuesta_aprobada(PDO $pdo,string $respuestaId,array $config): bool
{
    $claim=$pdo->prepare("UPDATE historia_respuestas SET notificacion_estado='ENVIANDO',notificacion_intentos=notificacion_intentos+1,updated_at=NOW() WHERE comentario_id=:id AND notificacion_intentos<3 AND (notificacion_estado IN ('NO_APLICA','PENDIENTE','FALLO') OR (notificacion_estado='ENVIANDO' AND updated_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE))) AND EXISTS(SELECT 1 FROM historia_comentario_suscripciones s WHERE s.comentario_id=historia_respuestas.reply_to_id AND s.estado='ACTIVA') AND EXISTS(SELECT 1 FROM historia_comentarios root WHERE root.id=historia_respuestas.parent_id AND root.estado='APROBADO')");
    $claim->execute([':id'=>$respuestaId]);if($claim->rowCount()!==1)return false;

    $st=$pdo->prepare("SELECT r.parent_id,r.reply_to_id,c.historia_slug,c.autor_nombre,c.comentario,target.autor_nombre target_autor,target.comentario target_comentario FROM historia_respuestas r JOIN historia_comentarios c ON c.id=r.comentario_id JOIN historia_comentarios target ON target.id=r.reply_to_id WHERE r.comentario_id=:id LIMIT 1");
    $st->execute([':id'=>$respuestaId]);$row=$st->fetch();
    if(!$row){$pdo->prepare("UPDATE historia_respuestas SET notificacion_estado='NO_APLICA',updated_at=NOW() WHERE comentario_id=:id AND notificacion_estado='ENVIANDO'")->execute([':id'=>$respuestaId]);return false;}
    try{$secret=historias_notificacion_secreto($config);}catch(Throwable $e){
        error_log('[historias-notificaciones] No se pudo construir el token de cancelación: '.$e->getMessage());$pdo->prepare("UPDATE historia_respuestas SET notificacion_estado='FALLO',updated_at=NOW() WHERE comentario_id=:id AND notificacion_estado='ENVIANDO'")->execute([':id'=>$respuestaId]);return false;
    }

    try{
        $pdo->beginTransaction();
        $visibility=$pdo->prepare("SELECT id,estado FROM historia_comentarios WHERE id IN (:root,:target,:reply) ORDER BY id FOR UPDATE");
        $visibility->execute([':root'=>$row['parent_id'],':target'=>$row['reply_to_id'],':reply'=>$respuestaId]);$states=[];
        foreach($visibility->fetchAll() as $visibleRow)$states[(string)$visibleRow['id']]=(string)$visibleRow['estado'];
        $rootId=(string)$row['parent_id'];$targetId=(string)$row['reply_to_id'];
        if(($states[$rootId]??null)!=='APROBADO'||($states[$targetId]??null)!=='APROBADO'||($states[$respuestaId]??null)!=='APROBADO'){
            $pdo->prepare("UPDATE historia_respuestas SET notificacion_estado='NO_APLICA',updated_at=NOW() WHERE comentario_id=:id AND notificacion_estado='ENVIANDO'")->execute([':id'=>$respuestaId]);$pdo->commit();return false;
        }

        // Mantener este bloqueo hasta terminar el intento garantiza que una baja concurrente
        // no pueda responder "cancelada" y después recibir un correo que ya estaba en memoria.
        $subscription=$pdo->prepare("SELECT email FROM historia_comentario_suscripciones WHERE comentario_id=:id AND estado='ACTIVA' FOR UPDATE");
        $subscription->execute([':id'=>$targetId]);$subscriptionRow=$subscription->fetch();
        if(!$subscriptionRow){
            $pdo->prepare("UPDATE historia_respuestas SET notificacion_estado='NO_APLICA',updated_at=NOW() WHERE comentario_id=:id AND notificacion_estado='ENVIANDO'")->execute([':id'=>$respuestaId]);$pdo->commit();return false;
        }

        $email=(string)$subscriptionRow['email'];$cancelToken=historias_cancel_token($targetId,$email,$secret);$viewUrl=historias_url_comentario((string)$row['historia_slug'],$respuestaId);
        $cancelUrl='https://hnatacion.com/historias/notificaciones.php?accion=cancelar&comentario='.rawurlencode($targetId).'&token='.rawurlencode($cancelToken);
        $subject=trim((string)$row['autor_nombre']).' respondió a tu comentario · Historias Hache';
        $body="Hola ".trim((string)$row['target_autor']).",\n\n".
            trim((string)$row['autor_nombre'])." respondió a tu comentario en Historias Hache.\n\n".
            "Tu comentario:\n“".historias_resumen_texto((string)$row['target_comentario'])."”\n\n".
            "Respuesta:\n“".historias_resumen_texto((string)$row['comentario'],320)."”\n\n".
            "Ver la conversación:\n".$viewUrl."\n\n".
            "Dejar de recibir avisos de este comentario:\n".$cancelUrl."\n\n".
            "Hache Natación";
        $sent=hache_enviar_correo_transaccional($email,$subject,$body,'historias-respuesta/'.$respuestaId);
        $pdo->prepare("UPDATE historia_respuestas SET notificacion_estado=:estado,notificacion_enviada_at=CASE WHEN :estado_fecha='ENVIADA' THEN NOW() ELSE notificacion_enviada_at END,updated_at=NOW() WHERE comentario_id=:id AND notificacion_estado='ENVIANDO'")->execute([':estado'=>$sent?'ENVIADA':'FALLO',':estado_fecha'=>$sent?'ENVIADA':'FALLO',':id'=>$respuestaId]);
        $pdo->commit();return $sent;
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        try{$pdo->prepare("UPDATE historia_respuestas SET notificacion_estado='FALLO',updated_at=NOW() WHERE comentario_id=:id AND notificacion_estado='ENVIANDO'")->execute([':id'=>$respuestaId]);}catch(Throwable $markError){error_log('[historias-notificaciones] No se pudo liberar el claim de respuesta: '.$markError->getMessage());}
        error_log('[historias-notificaciones] Falló la coordinación del aviso de respuesta: '.$e->getMessage());return false;
    }
}

function historias_reintentar_correo_comentario(PDO $pdo,string $comentarioId,array $config): bool
{
    $st=$pdo->prepare("SELECT estado,confirmacion_estado,confirmacion_intentos,confirm_expires_at FROM historia_comentario_suscripciones WHERE comentario_id=:id LIMIT 1");$st->execute([':id'=>$comentarioId]);$subscription=$st->fetch();
    if($subscription&&$subscription['estado']==='PENDIENTE'&&(int)$subscription['confirmacion_intentos']<3&&strtotime((string)$subscription['confirm_expires_at'])>=time()){
        if(historias_enviar_confirmacion_comentario($pdo,$comentarioId,$config))return true;
    }
    $st=$pdo->prepare("SELECT notificacion_estado,notificacion_intentos FROM historia_respuestas WHERE comentario_id=:id LIMIT 1");$st->execute([':id'=>$comentarioId]);$reply=$st->fetch();
    if($reply&&$reply['notificacion_estado']!=='ENVIADA'&&(int)$reply['notificacion_intentos']<3)return historias_notificar_respuesta_aprobada($pdo,$comentarioId,$config);
    return false;
}
