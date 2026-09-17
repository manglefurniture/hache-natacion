<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/sharky-runtime.php';
require_once __DIR__.'/../config/sharky-crm.php';

auth_require(['ADMIN']);

function prospectos_out(array $data,int $status=200): never
{
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET')prospectos_out(['ok'=>false,'error'=>'Método no permitido'],405);
$pdo=hache_sharky_pdo();
if(!$pdo)prospectos_out(['ok'=>false,'error'=>'No se pudo conectar con la configuración'],503);

try{
    $rows=hache_sharky_crm_list($pdo,300);
    $summary=['total'=>count($rows),'prospectos'=>0,'inscripcion_iniciada'=>0,'inscritos'=>0,'sin_fuente_persistente'=>0];
    foreach($rows as $row){
        $stage=(string)($row['estado_crm']??'PROSPECTO');
        if($stage==='INSCRITO')$summary['inscritos']++;
        elseif($stage==='INSCRIPCION_INICIADA')$summary['inscripcion_iniciada']++;
        else $summary['prospectos']++;
        if(($row['fuente']??null)===null)$summary['sin_fuente_persistente']++;
    }
    prospectos_out(['ok'=>true,'resumen'=>$summary,'prospectos'=>$rows]);
}catch(Throwable $e){
    error_log('[prospectos] '.$e->getMessage());
    prospectos_out(['ok'=>false,'error'=>'No se pudo cargar el CRM de prospectos'],500);
}
