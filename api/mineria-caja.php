<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
require __DIR__.'/../config/database.php';
require_once __DIR__.'/../config/auth.php';
auth_require(['ADMIN']);
require_once __DIR__.'/../config/mineria-caja.php';
require_once __DIR__.'/../config/salad-monitor.php';
function mineria_caja_out(array $result,int $status=200): never {
    http_response_code($status);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    exit;
}
try {
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    if($method==='POST'){
        $body=auth_request_json();
        if(!auth_csrf_validate($_SERVER['HTTP_X_CSRF_TOKEN']??null))
            mineria_caja_out(['ok'=>false,'error'=>'Token de seguridad no válido. Recarga la página.'],403);
        $entry=mineria_caja_add($body);
        mineria_caja_out(['ok'=>true,'entry'=>$entry],201);
    }
    if($method!=='GET')mineria_caja_out(['ok'=>false,'error'=>'Método no permitido.'],405);
    $data=mineria_caja_read();
    $snapshot=hache_salad_monitor_read_snapshot();
    mineria_caja_out(['ok'=>true,'summary'=>mineria_caja_summary($data['entries']),
        'types'=>mineria_caja_types(),'entries'=>array_reverse($data['entries']),
        'csrf_token'=>auth_csrf_token(),
        'operational'=>mineria_caja_operational($snapshot,time()),
        'note'=>'Los costos estimados no se contabilizan como consumo real.']);
}catch(InvalidArgumentException $e){
    mineria_caja_out(['ok'=>false,'error'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('[mineria-caja] '.$e->getMessage());
    mineria_caja_out(['ok'=>false,'error'=>'No se pudo acceder al registro de caja.'],503);
}
