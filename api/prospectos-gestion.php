<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/sharky-runtime.php';
require_once __DIR__.'/../config/sharky-crm-management.php';

$me=auth_require(['ADMIN']);

function prospectos_gestion_out(array $data,int $status=200): never
{
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')prospectos_gestion_out(['ok'=>false,'error'=>'Método no permitido'],405);
$input=auth_request_json();
if(!auth_csrf_validate($input['csrf']??null))prospectos_gestion_out(['ok'=>false,'error'=>'Solicitud no válida'],403);
if(strtoupper(trim((string)($input['accion']??'')))!=='REGISTRAR_GESTION')prospectos_gestion_out(['ok'=>false,'error'=>'Acción inválida'],422);

$contactHash=strtolower(trim((string)($input['contact_hash']??'')));
$pdo=hache_sharky_pdo();
if(!$pdo)prospectos_gestion_out(['ok'=>false,'error'=>'No se pudo conectar con la configuración'],503);

try {
    $gestion=hache_sharky_crm_record_management($pdo,$contactHash,(string)($me['id']??''));
    prospectos_gestion_out([
        'ok'=>true,
        'gestion'=>[
            'id'=>$gestion['id'],
            'managed_at'=>$gestion['managed_at'],
            'observed_last_contact_at'=>$gestion['observed_last_contact_at'],
        ],
    ],201);
} catch (InvalidArgumentException $e) {
    prospectos_gestion_out(['ok'=>false,'error'=>$e->getMessage()],422);
} catch (OutOfBoundsException $e) {
    prospectos_gestion_out(['ok'=>false,'error'=>$e->getMessage()],404);
} catch (Throwable $e) {
    error_log('[prospectos-gestion] '.$e->getMessage());
    prospectos_gestion_out(['ok'=>false,'error'=>'No se pudo registrar la gestión interna'],500);
}
