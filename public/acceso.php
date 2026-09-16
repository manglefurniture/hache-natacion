<?php

declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');

require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/portal-access.php';
$config=require __DIR__.'/../config/database.php';

function portal_access_fail(): never
{
    http_response_code(410);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Acceso no disponible — Hache Natación</title></head><body style="font-family:system-ui,sans-serif;padding:32px;max-width:620px;margin:auto"><h1>Este acceso ya no está disponible</h1><p>El enlace pudo haber sido utilizado o haber vencido. Contacta a Hache Natación para solicitar un nuevo acceso.</p></body></html>';
    exit;
}

function portal_access_token(string $value): string
{
    $value=strtolower(trim($value));
    return preg_match('/^[a-f0-9]{64}$/',$value)===1?$value:'';
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='GET'){
    $token=portal_access_token((string)($_GET['t']??''));
    if($token==='')portal_access_fail();
    $safe=htmlspecialchars($token,ENT_QUOTES,'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Acceso seguro — Hache Natación</title></head><body style="font-family:system-ui,sans-serif;background:#f3f7fb;color:#172033;padding:24px"><main style="max-width:460px;margin:8vh auto;background:#fff;border:1px solid #dde5ee;border-radius:18px;padding:24px;box-shadow:0 12px 36px #0f172a10"><h1 style="margin-top:0">Acceso seguro</h1><p>Continúa para entrar al portal de Hache Natación. Este enlace es personal y de un solo uso.</p><form method="post" action="/acceso.php"><input type="hidden" name="t" value="'.$safe.'"><button type="submit" style="width:100%;border:0;border-radius:11px;padding:14px;background:#123b5d;color:#fff;font:inherit;font-weight:800;cursor:pointer">Entrar al portal</button></form></main></body></html>';
    exit;
}
if($method!=='POST'){http_response_code(405);header('Allow: GET, POST');exit;}

$token=portal_access_token((string)($_POST['t']??''));
if($token==='')portal_access_fail();

try{
    $pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $user=hache_portal_access_consume($pdo,$token);
    if(!is_array($user))portal_access_fail();
    if(empty($user['alumno_id'])||strtoupper((string)($user['rol']??''))!=='ALUMNO')portal_access_fail();

    auth_login($user);
    $pdo->prepare('UPDATE usuarios SET last_login=NOW() WHERE id=:id')->execute([':id'=>(string)$user['id']]);
    if(!empty($user['debe_cambiar_password'])){
        auth_portal_bootstrap_grant((string)$user['id'],HACHE_PORTAL_ACCESS_RESET_GRANT_SECONDS);
        header('Location: /cambiar-password.php',true,303);
        exit;
    }
    header('Location: /mi-cuenta.php',true,303);
    exit;
}catch(Throwable $e){
    error_log('[portal-access] Falló el acceso desde WhatsApp: '.$e->getMessage());
    portal_access_fail();
}
