<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
auth_require(['ADMIN']);
require_once __DIR__.'/../config/sharky-template-notifications.php';
$config=require __DIR__.'/../config/database.php';

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok'=>false,'error'=>'Método no permitido'],JSON_UNESCAPED_UNICODE);
    exit;
}

try{
    $input=json_decode(file_get_contents('php://input'),true);
    if(!is_array($input)){
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'JSON inválido'],JSON_UNESCAPED_UNICODE);
        exit;
    }
    $folio=(int)($input['folio']??0);
    if($folio<=0){
        http_response_code(422);
        echo json_encode(['ok'=>false,'error'=>'Folio inválido'],JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pdo=new PDO(
        "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
        $config['user'],
        $config['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
    );
    $result=hache_sharky_notify_payment_confirmed($pdo,$folio);
    $status=($result['queued']??false)===true?200:202;
    http_response_code($status);
    echo json_encode([
        'ok'=>true,
        'queued'=>(bool)($result['queued']??false),
        'reason'=>(string)($result['reason']??''),
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    error_log('[pago-notificacion] failed');
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'No se pudo preparar la notificación'],JSON_UNESCAPED_UNICODE);
}
