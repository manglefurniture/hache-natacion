<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/profesor-sustituciones.php';

$me=auth_require(['ADMIN']);
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO(
    "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
    $config['user'],$config['password'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
);

function profesor_sustituciones_out(array $data,int $status=200): never
{
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function profesor_sustituciones_fecha(string $value,string $label): string
{
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if(!$d||$d->format('Y-m-d')!==$value)profesor_sustituciones_out(['ok'=>false,'error'=>$label.' inválida.'],422);
    return $value;
}

try{
    if(!hache_profesor_sustituciones_schema_ready($pdo)){
        profesor_sustituciones_out(['ok'=>false,'error'=>'Falta aplicar la migración F7.2 de profesores.'],503);
    }
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    if($method==='GET'){
        $desde=profesor_sustituciones_fecha((string)($_GET['desde']??date('Y-m-d')),'Fecha inicial');
        $hasta=profesor_sustituciones_fecha((string)($_GET['hasta']??date('Y-m-d',strtotime('+31 days'))),'Fecha final');
        $d1=new DateTimeImmutable($desde);$d2=new DateTimeImmutable($hasta);
        $days=(int)$d1->diff($d2)->format('%r%a');
        if($days<0||$days>62)profesor_sustituciones_out(['ok'=>false,'error'=>'El rango debe estar entre 0 y 62 días.'],422);
        profesor_sustituciones_out(['ok'=>true,'csrf'=>auth_csrf_token()]+hache_profesor_sustituciones_contexto($pdo,$desde,$hasta));
    }

    if($method!=='POST')profesor_sustituciones_out(['ok'=>false,'error'=>'Método no permitido'],405);
    $in=json_decode(file_get_contents('php://input'),true);
    if(!is_array($in))profesor_sustituciones_out(['ok'=>false,'error'=>'JSON inválido'],400);
    if(!auth_csrf_validate(isset($in['csrf'])?(string)$in['csrf']:null)){
        profesor_sustituciones_out(['ok'=>false,'error'=>'Sesión de seguridad vencida. Recarga la página.'],419);
    }

    $action=strtoupper(trim((string)($in['accion']??'')));
    if($action==='REGISTRAR'){
        $id=hache_profesor_sustitucion_registrar(
            $pdo,
            (string)($in['sesion_id']??''),
            (string)($in['profesor_original_id']??''),
            (string)($in['profesor_sustituto_id']??''),
            (string)($in['motivo']??''),
            (string)$me['id']
        );
        profesor_sustituciones_out(['ok'=>true,'sustitucion_id'=>$id],201);
    }
    if($action==='ANULAR'){
        hache_profesor_sustitucion_anular(
            $pdo,
            (string)($in['sustitucion_id']??''),
            (string)($in['motivo']??''),
            (string)$me['id']
        );
        profesor_sustituciones_out(['ok'=>true]);
    }
    profesor_sustituciones_out(['ok'=>false,'error'=>'Acción inválida'],422);
}catch(HacheProfesorSustitucionException $e){
    if($pdo->inTransaction())$pdo->rollBack();
    profesor_sustituciones_out(['ok'=>false,'error'=>$e->getMessage()],$e->httpStatus);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('[profesor-sustituciones] '.$e->getMessage());
    profesor_sustituciones_out(['ok'=>false,'error'=>'No se pudo procesar la sustitución.'],500);
}
