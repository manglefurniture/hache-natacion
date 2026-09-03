<?php

declare(strict_types=1);

require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/sharky-identity-verification.php';

$token=trim((string)($_GET['token']??$_SESSION['sharky_verification_token']??''));
if($token!=='' && preg_match('/^[a-f0-9]{64}$/',$token))$_SESSION['sharky_verification_token']=$token;
$token=(string)($_SESSION['sharky_verification_token']??'');
$me=auth_user();
if(!$me){
    ?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Verificar identidad — Hache Natación</title><style>body{margin:0;background:#f3f7fb;color:#172033;font-family:system-ui,sans-serif}.w{max-width:520px;margin:8vh auto;padding:18px}.c{background:#fff;border:1px solid #dbe5ef;border-radius:20px;padding:24px;box-shadow:0 16px 44px #0f172a12}.b{display:block;text-align:center;text-decoration:none;background:#123b5d;color:#fff;padding:13px;border-radius:12px;font-weight:800;margin-top:18px}p{line-height:1.55;color:#526174}</style></head><body><main class="w"><section class="c"><h1>Verifica que eres alumno</h1><p>Para proteger tus datos, inicia sesión con tu cuenta de Hache Natación. Al entrar volverás a esta verificación y podrás autorizar a Sharky para atender esta conversación.</p><a class="b" href="/">Iniciar sesión</a></section></main></body></html><?php exit;
}
if(($me['rol']??'')!=='ALUMNO' || empty($me['alumno_id'])){http_response_code(403);exit('Esta verificación solo puede completarla una cuenta de alumno.');}
if($token===''){http_response_code(410);exit('El enlace de verificación no está disponible. Solicita uno nuevo en WhatsApp.');}
$msg='';$ok=false;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    if(!auth_csrf_validate((string)($_POST['csrf']??''))){$msg='La sesión de verificación venció. Recarga la página.';}
    else{
        $cfg=require __DIR__.'/../config/database.php';
        $pdo=new PDO("mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset={$cfg['charset']}",$cfg['user'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        $result=hache_sharky_verification_confirm($pdo,$token,(string)$me['alumno_id']);
        if($result['ok']??false){$ok=true;$msg='Listo. Ya verificamos tu identidad para esta conversación.';unset($_SESSION['sharky_verification_token']);}
        else{$msg=in_array((string)($result['code']??''),['EXPIRED','NOT_FOUND'],true)?'Este enlace ya venció. Solicita uno nuevo a Sharky.':'No se pudo completar la verificación.';}
    }
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Verificar identidad — Hache Natación</title><style>body{margin:0;background:#f3f7fb;color:#172033;font-family:system-ui,sans-serif}.w{max-width:520px;margin:8vh auto;padding:18px}.c{background:#fff;border:1px solid #dbe5ef;border-radius:20px;padding:24px;box-shadow:0 16px 44px #0f172a12}.b{width:100%;border:0;background:#123b5d;color:#fff;padding:13px;border-radius:12px;font-weight:800;font-size:16px}p{line-height:1.55;color:#526174}.ok{background:#ecfdf5;color:#166534;padding:12px;border-radius:12px}.msg{background:#fff7ed;color:#9a3412;padding:12px;border-radius:12px}</style></head><body><main class="w"><section class="c"><h1>Verificación de Sharky</h1><?php if($msg!==''):?><div class="<?= $ok?'ok':'msg' ?>"><?=htmlspecialchars($msg,ENT_QUOTES,'UTF-8')?></div><?php endif;?><?php if(!$ok):?><p>Has iniciado sesión como <strong><?=htmlspecialchars((string)($me['usuario']??'alumno'),ENT_QUOTES,'UTF-8')?></strong>. Confirma para vincular únicamente esta conversación de WhatsApp con tu ficha de alumno.</p><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars(auth_csrf_token(),ENT_QUOTES,'UTF-8')?>"><button class="b" type="submit">Confirmar identidad</button></form><?php else:?><p>Ya puedes volver a WhatsApp. Sharky reconocerá tu sesión verificada en el siguiente mensaje.</p><?php endif;?></section></main></body></html>
