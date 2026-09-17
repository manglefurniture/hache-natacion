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
    $page=max(1,(int)($_GET['page']??1));
    $perPage=max(10,min(100,(int)($_GET['per_page']??50)));
    $query=mb_substr(trim((string)($_GET['q']??'')),0,120);
    $result=hache_sharky_crm_page($pdo,$page,$perPage,$query);
    $rows=$result['rows'];
    $summary=['total_crm'=>$result['total_all'],'resultados'=>$result['total'],'prospectos_pagina'=>0,'inscripcion_iniciada_pagina'=>0,'inscritos_pagina'=>0,'sin_fuente_persistente_pagina'=>0];
    foreach($rows as $row){
        $stage=(string)($row['estado_crm']??'PROSPECTO');
        if($stage==='INSCRITO')$summary['inscritos_pagina']++;
        elseif($stage==='INSCRIPCION_INICIADA')$summary['inscripcion_iniciada_pagina']++;
        else $summary['prospectos_pagina']++;
        if(($row['fuente']??null)===null)$summary['sin_fuente_persistente_pagina']++;
    }
    prospectos_out(['ok'=>true,'resumen'=>$summary,'paginacion'=>['page'=>$result['page'],'per_page'=>$result['per_page'],'pages'=>$result['pages'],'total'=>$result['total'],'total_all'=>$result['total_all']],'prospectos'=>$rows]);
}catch(Throwable $e){
    error_log('[prospectos] '.$e->getMessage());
    prospectos_out(['ok'=>false,'error'=>'No se pudo cargar el CRM de prospectos'],500);
}
