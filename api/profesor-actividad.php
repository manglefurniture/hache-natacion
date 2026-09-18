<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/profesor-actividad.php';

auth_require(['ADMIN']);
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO(
    "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
    $config['user'],$config['password'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
);

function profesor_actividad_out(array $data,int $status=200): never
{
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function profesor_actividad_fecha(string $value,string $label): string
{
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('America/Cancun'));
    if(!$d||$d->format('Y-m-d')!==$value)profesor_actividad_out(['ok'=>false,'error'=>$label.' inválida.'],422);
    return $value;
}

try{
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET'){
        profesor_actividad_out(['ok'=>false,'error'=>'Método no permitido'],405);
    }
    if(!hache_profesor_actividad_schema_ready($pdo)){
        profesor_actividad_out(['ok'=>false,'error'=>'Falta aplicar la base vigente de profesores F7.'],503);
    }

    $now=new DateTimeImmutable('now',new DateTimeZone('America/Cancun'));
    $defaultDesde=$now->modify('first day of this month')->format('Y-m-d');
    $defaultHasta=$now->format('Y-m-d');
    $desde=profesor_actividad_fecha((string)($_GET['desde']??$defaultDesde),'Fecha inicial');
    $hasta=profesor_actividad_fecha((string)($_GET['hasta']??$defaultHasta),'Fecha final');
    $d1=new DateTimeImmutable($desde,new DateTimeZone('America/Cancun'));
    $d2=new DateTimeImmutable($hasta,new DateTimeZone('America/Cancun'));
    $days=(int)$d1->diff($d2)->format('%r%a');
    if($days<0||$days>62)profesor_actividad_out(['ok'=>false,'error'=>'El rango debe estar entre 0 y 62 días.'],422);

    $profesorId=trim((string)($_GET['profesor_id']??''));
    $ctx=hache_profesor_actividad_contexto($pdo,$desde,$hasta,$profesorId!==''?$profesorId:null);
    if($profesorId!==''&&count($ctx['profesores']??[])===0){
        profesor_actividad_out(['ok'=>false,'error'=>'Profesor no encontrado.'],404);
    }

    profesor_actividad_out(['ok'=>true]+$ctx);
}catch(Throwable $e){
    error_log('[profesor-actividad] '.$e->getMessage());
    profesor_actividad_out(['ok'=>false,'error'=>'No se pudo consultar la actividad de profesores.'],500);
}
