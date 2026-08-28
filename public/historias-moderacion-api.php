<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, max-age=0');

require_once __DIR__.'/../config/auth.php';
$me=auth_require(['ADMIN','VERIFICADOR']);
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO(
    "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
    $config['user'],
    $config['password'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
);

function mod_out(array $data,int $status=200): never
{
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function estado_valido(mixed $value): string
{
    $estado=strtoupper(trim((string)$value));
    $allowed=['PENDIENTE','APROBADO','RECHAZADO','OCULTO','ELIMINADO'];
    return in_array($estado,$allowed,true)?$estado:'PENDIENTE';
}

try{
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    if($method==='GET'){
        $estado=estado_valido($_GET['estado']??'PENDIENTE');
        $counts=['PENDIENTE'=>0,'APROBADO'=>0,'RECHAZADO'=>0,'OCULTO'=>0,'ELIMINADO'=>0];
        foreach($pdo->query('SELECT estado,COUNT(*) total FROM historia_comentarios GROUP BY estado')->fetchAll() as $row){
            if(isset($counts[$row['estado']]))$counts[$row['estado']]=(int)$row['total'];
        }
        $order=$estado==='PENDIENTE'?'ASC':'DESC';
        $sql="SELECT c.id,c.historia_slug,c.autor_nombre,c.comentario,c.estado,c.flags,c.created_at,c.moderado_at,c.moderado_por,EXISTS(SELECT 1 FROM historia_bloqueos b WHERE b.origen_hash=c.origen_hash AND b.activo=1) origen_bloqueado FROM historia_comentarios c WHERE c.estado=:estado ORDER BY c.created_at {$order} LIMIT 100";
        $st=$pdo->prepare($sql);
        $st->execute([':estado'=>$estado]);
        mod_out(['ok'=>true,'estado'=>$estado,'conteos'=>$counts,'comentarios'=>$st->fetchAll(),'csrf'=>auth_csrf_token()]);
    }

    if($method!=='POST')mod_out(['ok'=>false,'error'=>'Método no permitido'],405);
    $input=json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($input))mod_out(['ok'=>false,'error'=>'Solicitud inválida'],400);
    if(!auth_csrf_validate(isset($input['csrf'])?(string)$input['csrf']:null))mod_out(['ok'=>false,'error'=>'Sesión de seguridad vencida. Recarga la página.'],419);
    $accion=strtoupper(trim((string)($input['accion']??'')));
    $id=trim((string)($input['id']??''));
    if($id==='')mod_out(['ok'=>false,'error'=>'Comentario inválido'],422);

    $st=$pdo->prepare('SELECT id,origen_hash,estado FROM historia_comentarios WHERE id=:id LIMIT 1');
    $st->execute([':id'=>$id]);
    $comment=$st->fetch();
    if(!$comment)mod_out(['ok'=>false,'error'=>'Comentario no encontrado'],404);

    $statusMap=['APROBAR'=>'APROBADO','RECHAZAR'=>'RECHAZADO','OCULTAR'=>'OCULTO','ELIMINAR'=>'ELIMINADO'];
    if(isset($statusMap[$accion])){
        $st=$pdo->prepare('UPDATE historia_comentarios SET estado=:estado,moderado_por=:usuario,moderado_at=NOW(),updated_at=NOW() WHERE id=:id');
        $st->execute([':estado'=>$statusMap[$accion],':usuario'=>$me['id'],':id'=>$id]);
        mod_out(['ok'=>true,'estado'=>$statusMap[$accion]]);
    }

    if($accion==='BLOQUEAR_ORIGEN'){
        $motivo=trim((string)($input['motivo']??'Abuso en comentarios de Historias'));
        $motivo=mb_substr($motivo!==''?$motivo:'Abuso en comentarios de Historias',0,160);
        $pdo->beginTransaction();
        $st=$pdo->prepare("INSERT INTO historia_bloqueos(origen_hash,motivo,activo,created_by,updated_by) VALUES(:origen,:motivo,1,:usuario,:usuario) ON DUPLICATE KEY UPDATE motivo=VALUES(motivo),activo=1,updated_by=VALUES(updated_by),updated_at=NOW()");
        $st->execute([':origen'=>$comment['origen_hash'],':motivo'=>$motivo,':usuario'=>$me['id']]);
        $st=$pdo->prepare("UPDATE historia_comentarios SET estado='RECHAZADO',moderado_por=:usuario,moderado_at=NOW(),updated_at=NOW() WHERE origen_hash=:origen AND estado='PENDIENTE'");
        $st->execute([':usuario'=>$me['id'],':origen'=>$comment['origen_hash']]);
        $pdo->commit();
        mod_out(['ok'=>true,'bloqueado'=>true]);
    }

    if($accion==='DESBLOQUEAR_ORIGEN'){
        $st=$pdo->prepare('UPDATE historia_bloqueos SET activo=0,updated_by=:usuario,updated_at=NOW() WHERE origen_hash=:origen');
        $st->execute([':usuario'=>$me['id'],':origen'=>$comment['origen_hash']]);
        mod_out(['ok'=>true,'bloqueado'=>false]);
    }

    mod_out(['ok'=>false,'error'=>'Acción inválida'],422);
}catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    error_log('[historias/moderacion] '.$e->getMessage());
    mod_out(['ok'=>false,'error'=>'No se pudo completar la moderación'],500);
}
