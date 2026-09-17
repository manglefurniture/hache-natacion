<?php

declare(strict_types=1);

require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/sharky-runtime.php';
require_once __DIR__.'/../config/sharky-contact-book.php';

auth_require(['ADMIN']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function google_contacts_oauth_summary(PDO $pdo): array
{
    $counts=['PENDING'=>0,'SYNCED'=>0,'UNMANAGED'=>0,'FAILED'=>0];
    try{
        foreach($pdo->query("SELECT sync_status,COUNT(*) total FROM sharky_contacts GROUP BY sync_status")->fetchAll(PDO::FETCH_ASSOC) as $row){
            $status=(string)($row['sync_status']??'');
            if(array_key_exists($status,$counts))$counts[$status]=(int)($row['total']??0);
        }
    }catch(Throwable $e){}
    return $counts;
}

function google_contacts_oauth_secret_valid(string $value,int $max=2048): bool
{
    return strlen($value)>=16&&strlen($value)<=$max&&!preg_match('/[\x00-\x20\x7F]/',$value);
}

function google_contacts_oauth_authorize_url(string $clientId): string
{
    $query=http_build_query([
        'client_id'=>$clientId,
        'redirect_uri'=>'https://developers.google.com/oauthplayground',
        'response_type'=>'code',
        'scope'=>'https://www.googleapis.com/auth/contacts',
        'access_type'=>'offline',
        'prompt'=>'consent',
        'include_granted_scopes'=>'true',
    ],'', '&', PHP_QUERY_RFC3986);
    return 'https://accounts.google.com/o/oauth2/v2/auth?'.$query;
}

function google_contacts_oauth_stage(string $clientSecret,string $refreshToken): bool
{
    if(!google_contacts_oauth_secret_valid($clientSecret,1024)||!google_contacts_oauth_secret_valid($refreshToken))return false;
    $dir=hache_sharky_orchestrator_runtime_dir('secrets');
    if($dir===''||!is_dir($dir)||is_link($dir)||!is_writable($dir))return false;
    $path=$dir.'/google-contacts-oauth-stage.json';
    if(is_link($path))return false;
    $json=json_encode([
        'version'=>1,
        'issued_at'=>time(),
        'client_secret'=>$clientSecret,
        'refresh_token'=>$refreshToken,
    ],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $tmp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
    if(file_put_contents($tmp,$json."\n",LOCK_EX)===false)return false;
    chmod($tmp,0600);
    if(!rename($tmp,$path)){@unlink($tmp);return false;}
    chmod($path,0600);
    return true;
}

$pdo=hache_sharky_pdo();
if(!$pdo instanceof PDO){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Base de datos no disponible']);exit;}
$clientId=hache_sharky_orchestrator_secret('GOOGLE_CONTACTS_CLIENT_ID');
if($clientId===''){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Cliente OAuth de Google no configurado']);exit;}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='GET'){
    $configured=hache_sharky_google_contacts_configured();
    echo json_encode([
        'ok'=>true,
        'csrf'=>auth_csrf_token(),
        'configured'=>$configured,
        'token_refresh_ok'=>$configured&&hache_sharky_google_contacts_access_token()!=='',
        'counts'=>google_contacts_oauth_summary($pdo),
        'authorize_url'=>google_contacts_oauth_authorize_url($clientId),
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if($method!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Método no permitido']);exit;}
$body=auth_request_json();
if(!auth_csrf_validate(isset($body['csrf'])?(string)$body['csrf']:null)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'CSRF inválido']);exit;}
$clientSecret=trim((string)($body['client_secret']??''));
$code=trim((string)($body['authorization_code']??''));
if(!google_contacts_oauth_secret_valid($clientSecret,1024)){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Client Secret inválido']);exit;}
if(strlen($code)<20||strlen($code)>4096||preg_match('/[\x00-\x20\x7F]/',$code)){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Código de autorización inválido']);exit;}

$ch=curl_init('https://oauth2.googleapis.com/token');
if($ch===false){http_response_code(500);echo json_encode(['ok'=>false,'error'=>'No se pudo iniciar OAuth']);exit;}
$fields=http_build_query([
    'code'=>$code,
    'client_id'=>$clientId,
    'client_secret'=>$clientSecret,
    'redirect_uri'=>'https://developers.google.com/oauthplayground',
    'grant_type'=>'authorization_code',
]);
curl_setopt_array($ch,[
    CURLOPT_POST=>true,
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_CONNECTTIMEOUT=>5,
    CURLOPT_TIMEOUT=>20,
    CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_POSTFIELDS=>$fields,
]);
$response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
$decoded=is_string($response)?json_decode($response,true):null;
$refreshToken=is_array($decoded)?trim((string)($decoded['refresh_token']??'')):'';
$accessToken=is_array($decoded)?trim((string)($decoded['access_token']??'')):'';
if($status<200||$status>=300||$accessToken===''||!google_contacts_oauth_secret_valid($refreshToken)){
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Google rechazó el código o no entregó un refresh token nuevo. Genera otro código e inténtalo nuevamente.'],JSON_UNESCAPED_UNICODE);
    exit;
}
if(!google_contacts_oauth_stage($clientSecret,$refreshToken)){
    http_response_code(500);echo json_encode(['ok'=>false,'error'=>'No se pudo preparar la renovación en el servidor']);exit;
}
unset($clientSecret,$refreshToken,$accessToken,$decoded,$response,$fields,$code);
echo json_encode([
    'ok'=>true,
    'staged'=>true,
    'expires_in'=>900,
    'counts'=>google_contacts_oauth_summary($pdo),
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
