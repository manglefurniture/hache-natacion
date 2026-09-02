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

function historias_confirm_token(string $comentarioId,string $email,string $secret): string
{
    return historias_token_hmac('confirmar-avisos-v1',$comentarioId,$email,$secret);
}

function historias_cancel_token(string $comentarioId,string $email,string $secret): string
{
    return historias_token_hmac('cancelar-avisos-v1',$comentarioId,$email,$secret);
}

function historias_resumen_texto(string $texto,int $max=220): string
{
    $texto=trim(preg_replace('/\s+/u',' ',$texto)??'');
    if(mb_strlen($texto)<=$max)return $texto;
    return rtrim(mb_substr($texto,0,max(1,$max-1))).'…';
}

function historias_url_comentario(string $slug,string $comentarioId): string
{
    return 'https://hnatacion.com/historias/'.rawurlencode($slug).'.php#comentario-'.rawurlencode($comentarioId);
}

function historias_enviar_confirmacion_comentario(PDO $pdo,string $comentarioId,array $config): bool
{
    $st=$pdo->prepare("SELECT c.historia_slug,c.autor_nombre,c.comentario,s.email,s.estado,s.confirm_expires_at,s.confirmacion_enviada_at FROM historia_comentarios c JOIN historia_comentario_suscripciones s ON s.comentario_id=c.id WHERE c.id=:id LIMIT 1");
    $st->execute([':id'=>$comentarioId]);
    $row=$st->fetch();
    if(!$row||$row['estado']!=='PENDIENTE')return false;
    if(strtotime((string)$row['confirm_expires_at'])<time())return false;
    if(!empty($row['confirmacion_enviada_at']))return true;

    try{$secret=historias_notificacion_secreto($config);}catch(Throwable $e){
        error_log('[historias-notificaciones] No se pudo construir el token de confirmación: '.$e->getMessage());
        return false;
    }
    $token=historias_confirm_token($comentarioId,(string)$row['email'],$secret);
    $confirmUrl='https://hnatacion.com/historias/notificaciones.php?accion=confirmar&token='.rawurlencode($token);
    $subject='Confirma los avisos de respuestas · Historias Hache';
    $body="Hola ".trim((string)$row['autor_nombre']).",\n\n".
        "Pediste recibir un aviso cuando alguien responda a tu comentario en Historias Hache.\n\n".
        "Tu comentario:\n“".historias_resumen_texto((string)$row['comentario'])."”\n\n".
        "Confirma los avisos aquí:\n".$confirmUrl."\n\n".
        "El enlace vence en 7 días. Estos avisos son solo para respuestas a tu comentario; no te suscriben a promociones ni newsletters.\n\n".
        "Hache Natación";

    if(!hache_enviar_correo_transaccional((string)$row['email'],$subject,$body))return false;
    $up=$pdo->prepare("UPDATE historia_comentario_suscripciones SET confirmacion_enviada_at=NOW(),updated_at=NOW() WHERE comentario_id=:id AND estado='PENDIENTE' AND confirmacion_enviada_at IS NULL");
    $up->execute([':id'=>$comentarioId]);
    return true;
}

function historias_notificar_respuesta_aprobada(PDO $pdo,string $respuestaId,array $config): bool
{
    $claim=$pdo->prepare("UPDATE historia_respuestas SET notificacion_estado='ENVIANDO',notificacion_intentos=notificacion_intentos+1,updated_at=NOW() WHERE comentario_id=:id AND notificacion_intentos<3 AND (notificacion_estado IN ('NO_APLICA','PENDIENTE','FALLO') OR (notificacion_estado='ENVIANDO' AND updated_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE))) AND EXISTS(SELECT 1 FROM historia_comentario_suscripciones s WHERE s.comentario_id=historia_respuestas.reply_to_id AND s.estado='ACTIVA')");
    $claim->execute([':id'=>$respuestaId]);
    if($claim->rowCount()!==1)return false;

    $st=$pdo->prepare("SELECT r.parent_id,r.reply_to_id,c.historia_slug,c.autor_nombre,c.comentario,target.autor_nombre target_autor,target.comentario target_comentario,s.email FROM historia_respuestas r JOIN historia_comentarios c ON c.id=r.comentario_id JOIN historia_comentarios target ON target.id=r.reply_to_id JOIN historia_comentario_suscripciones s ON s.comentario_id=r.reply_to_id AND s.estado='ACTIVA' WHERE r.comentario_id=:id AND c.estado='APROBADO' LIMIT 1");
    $st->execute([':id'=>$respuestaId]);
    $row=$st->fetch();
    if(!$row){
        $pdo->prepare("UPDATE historia_respuestas SET notificacion_estado='NO_APLICA',updated_at=NOW() WHERE comentario_id=:id AND notificacion_estado='ENVIANDO'")->execute([':id'=>$respuestaId]);
        return false;
    }

    try{$secret=historias_notificacion_secreto($config);}catch(Throwable $e){
        error_log('[historias-notificaciones] No se pudo construir el token de cancelación: '.$e->getMessage());
        $pdo->prepare("UPDATE historia_respuestas SET notificacion_estado='FALLO',updated_at=NOW() WHERE comentario_id=:id AND notificacion_estado='ENVIANDO'")->execute([':id'=>$respuestaId]);
        return false;
    }

    $targetId=(string)$row['reply_to_id'];
    $cancelToken=historias_cancel_token($targetId,(string)$row['email'],$secret);
    $viewUrl=historias_url_comentario((string)$row['historia_slug'],$targetId);
    $cancelUrl='https://hnatacion.com/historias/notificaciones.php?accion=cancelar&comentario='.rawurlencode($targetId).'&token='.rawurlencode($cancelToken);
    $subject=trim((string)$row['autor_nombre']).' respondió a tu comentario · Historias Hache';
    $body="Hola ".trim((string)$row['target_autor']).",\n\n".
        trim((string)$row['autor_nombre'])." respondió a tu comentario en Historias Hache.\n\n".
        "Tu comentario:\n“".historias_resumen_texto((string)$row['target_comentario'])."”\n\n".
        "Respuesta:\n“".historias_resumen_texto((string)$row['comentario'],320)."”\n\n".
        "Ver la conversación:\n".$viewUrl."\n\n".
        "Dejar de recibir avisos de este comentario:\n".$cancelUrl."\n\n".
        "Hache Natación";

    $sent=hache_enviar_correo_transaccional((string)$row['email'],$subject,$body);
    if($sent){
        $pdo->prepare("UPDATE historia_respuestas SET notificacion_estado='ENVIADA',notificacion_enviada_at=NOW(),updated_at=NOW() WHERE comentario_id=:id AND notificacion_estado='ENVIANDO'")->execute([':id'=>$respuestaId]);
        return true;
    }
    $pdo->prepare("UPDATE historia_respuestas SET notificacion_estado='FALLO',updated_at=NOW() WHERE comentario_id=:id AND notificacion_estado='ENVIANDO'")->execute([':id'=>$respuestaId]);
    return false;
}
