<?php
declare(strict_types=1);

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

$config=require __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../config/historias-notificaciones.php';
$pdo=new PDO(
    "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
    $config['user'],$config['password'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
);

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
$source=$method==='POST'?$_POST:$_GET;
$accion=strtolower(trim((string)($source['accion']??'')));
$token=trim((string)($source['token']??''));
$comentario=trim((string)($source['comentario']??''));
$title='Avisos de Historias Hache';
$message='No pudimos procesar este enlace.';
$storyUrl='/historias/';
$ok=false;
$canSubmit=false;
$buttonLabel='Confirmar';

try{
    if(!in_array($method,['GET','POST'],true)){
        http_response_code(405);$message='Método no permitido.';
    }elseif(!preg_match('/^[A-Za-z0-9_-]{43}$/',$token)){
        http_response_code(400);$message='El enlace no es válido o está incompleto.';
    }elseif($accion==='confirmar'){
        $st=$pdo->prepare("SELECT s.comentario_id,s.estado,s.confirm_expires_at,c.historia_slug FROM historia_comentario_suscripciones s JOIN historia_comentarios c ON c.id=s.comentario_id WHERE s.confirm_token_hash=:hash LIMIT 1");
        $st->execute([':hash'=>hash('sha256',$token)]);$row=$st->fetch();
        if(!$row){http_response_code(404);$message='Este enlace de confirmación ya no es válido.';}
        else{
            $comentario=(string)$row['comentario_id'];
            $storyUrl='/historias/'.rawurlencode((string)$row['historia_slug']).'.php#comentario-'.rawurlencode($comentario);
            if($row['estado']==='ACTIVA'){$ok=true;$message='Los avisos de respuestas ya están activados para este comentario.';}
            elseif($row['estado']==='CANCELADA'){$message='Los avisos de este comentario fueron cancelados y este enlace ya no puede reactivarlos.';}
            elseif(strtotime((string)$row['confirm_expires_at'])<time()){http_response_code(410);$message='El enlace de confirmación venció. No se activaron avisos.';}
            elseif($method==='GET'){$message='Confirma que quieres recibir avisos cuando alguien responda a tu comentario.';$canSubmit=true;$buttonLabel='Activar avisos';}
            else{
                $up=$pdo->prepare("UPDATE historia_comentario_suscripciones SET estado='ACTIVA',confirmado_at=NOW(),updated_at=NOW() WHERE comentario_id=:id AND estado='PENDIENTE' AND confirm_expires_at>=NOW()");
                $up->execute([':id'=>$comentario]);$ok=$up->rowCount()===1;
                $message=$ok?'Listo. Te avisaremos por correo cuando alguien responda directamente a tu comentario y la respuesta sea aprobada.':'No fue posible activar los avisos.';
            }
        }
    }elseif($accion==='cancelar'){
        if(!preg_match('/^[a-f0-9-]{36}$/i',$comentario)){http_response_code(400);$message='El enlace para cancelar avisos no es válido.';}
        else{
            $st=$pdo->prepare("SELECT s.email,s.estado,c.historia_slug FROM historia_comentario_suscripciones s JOIN historia_comentarios c ON c.id=s.comentario_id WHERE s.comentario_id=:id LIMIT 1");
            $st->execute([':id'=>$comentario]);$row=$st->fetch();
            if(!$row){http_response_code(404);$message='No encontramos avisos para este comentario.';}
            else{
                $storyUrl='/historias/'.rawurlencode((string)$row['historia_slug']).'.php#comentario-'.rawurlencode($comentario);
                $secret=historias_notificacion_secreto($config);$expected=historias_cancel_token($comentario,(string)$row['email'],$secret);
                if(!hash_equals($expected,$token)){http_response_code(403);$message='El enlace para cancelar avisos no es válido.';}
                elseif($row['estado']==='CANCELADA'){$ok=true;$message='Los avisos de este comentario ya estaban cancelados.';}
                elseif($method==='GET'){$message='Confirma que quieres dejar de recibir avisos de respuestas a este comentario.';$canSubmit=true;$buttonLabel='Dejar de recibir avisos';}
                else{
                    $up=$pdo->prepare("UPDATE historia_comentario_suscripciones SET estado='CANCELADA',cancelado_at=NOW(),updated_at=NOW() WHERE comentario_id=:id AND estado<>'CANCELADA'");
                    $up->execute([':id'=>$comentario]);$ok=true;$message='Listo. Ya no recibirás avisos de respuestas a este comentario.';
                }
            }
        }
    }else{http_response_code(400);$message='La acción solicitada no es válida.';}
}catch(Throwable $e){error_log('[historias/notificaciones] '.$e->getMessage());http_response_code(500);$message='No pudimos procesar el enlace en este momento. Intenta nuevamente más tarde.';$canSubmit=false;}
?>
<!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <meta name="theme-color" content="#062a45">
  <title><?=htmlspecialchars($title,ENT_QUOTES,'UTF-8')?></title>
  <style>
    *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#eef4f8;color:#0b2237;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.card{width:min(100%,620px);padding:34px;border:1px solid #d8e3ea;border-radius:24px;background:#fff;box-shadow:0 18px 52px rgba(6,42,69,.09)}.mark{width:48px;height:48px;display:grid;place-items:center;border-radius:14px;background:#062a45;color:#fff;font-size:25px;font-weight:900}.eyebrow{margin:24px 0 8px;color:#0b6fe8;font-size:11px;font-weight:900;letter-spacing:.16em}.card h1{margin:0 0 14px;font-size:clamp(30px,8vw,46px);line-height:1;letter-spacing:-.045em}.card p{margin:0;color:#536b7d;font-size:17px;line-height:1.65}.status{margin-top:22px;padding:14px 16px;border-radius:14px;background:<?= $ok?'#ecfdf3':'#f6f9fb' ?>;color:<?= $ok?'#166534':'#42596b' ?>}.actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:24px}.back,.confirm{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:13px 16px;border:0;border-radius:12px;font:inherit;font-weight:850;text-decoration:none;cursor:pointer}.back{background:#eef4f8;color:#24465f}.confirm{background:#0b6fe8;color:#fff}.back:focus-visible,.confirm:focus-visible{outline:3px solid #ffc83d;outline-offset:3px}
  </style>
</head>
<body>
  <main class="card">
    <div class="mark" aria-hidden="true">H</div>
    <p class="eyebrow">HISTORIAS HACHE · AVISOS</p>
    <h1><?= $ok?'Todo listo.':($canSubmit?'Confirma tu decisión.':'Revisa este enlace.') ?></h1>
    <p class="status"><?=htmlspecialchars($message,ENT_QUOTES,'UTF-8')?></p>
    <div class="actions">
      <?php if($canSubmit): ?>
      <form method="post" action="/historias/notificaciones.php">
        <input type="hidden" name="accion" value="<?=htmlspecialchars($accion,ENT_QUOTES,'UTF-8')?>">
        <input type="hidden" name="token" value="<?=htmlspecialchars($token,ENT_QUOTES,'UTF-8')?>">
        <?php if($comentario!==''): ?><input type="hidden" name="comentario" value="<?=htmlspecialchars($comentario,ENT_QUOTES,'UTF-8')?>"><?php endif; ?>
        <button class="confirm" type="submit"><?=htmlspecialchars($buttonLabel,ENT_QUOTES,'UTF-8')?></button>
      </form>
      <?php endif; ?>
      <a class="back" href="<?=htmlspecialchars($storyUrl,ENT_QUOTES,'UTF-8')?>">Volver a la historia</a>
    </div>
  </main>
</body>
</html>
