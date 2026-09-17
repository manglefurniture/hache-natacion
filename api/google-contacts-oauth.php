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

function google_contacts_oauth_store_refresh_token(string $token): bool
{
    if(strlen($token)<20||strlen($token)>2048||preg_match('/[\x00-\x20\x7F]/',$token))return false;
    $path=HACHE_SHARKY_GOOGLE_CONTACTS_REFRESH_TOKEN_FILE;
    $dir=dirname($path);
    if(!is_dir($dir)||!is_writable($dir)||is_link($dir))return false;
    $tmp=$dir.'/.google-contacts-refresh-token.'.bin2hex(random_bytes(6));
    if(file_put_contents($tmp,$token."\n",LOCK_EX)===false)return false;
    chmod($tmp,0600);
    if(!rename($tmp,$path)){@unlink($tmp);return false;}
    chmod($path,0600);
    return true;
}

$pdo=hache_sharky_pdo();
if(!$pdo instanceof PDO){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Base de datos no disponible']);exit;}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='GET'){
    $configured=hache_sharky_google_contacts_configured();
    echo json_encode([
        'ok'=>true,
        'csrf'=>auth_csrf_token(),
        'configured'=>$configured,
        'token_refresh_ok'=>$configured&&hache_sharky_google_contacts_access_token()!=='',
        'counts'=>google_contacts_oauth_summary($pdo),
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if($method!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Método no permitido']);exit;}
$body=auth_request_json();
if(!auth_csrf_validate(isset($body['csrf'])?(string)$body['csrf']:null)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'CSRF inválido']);exit;}
$code=trim((string)($body['authorization_code']??''));
if(strlen($code)<20||strlen($code)>4096||preg_match('/[\x00-\x20\x7F]/',$code)){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Código de autorización inválido']);exit;}
$clientId=hache_sharky_orchestrator_secret('GOOGLE_CONTACTS_CLIENT_ID');
$clientSecret=hache_sharky_orchestrator_secret('GOOGLE_CONTACTS_CLIENT_SECRET');
if($clientId===''||$clientSecret===''){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Credenciales OAuth del servidor no disponibles']);exit;}

$ch=curl_init('https://oauth2.googleapis.com/token');
if($ch===false){http_response_code(500);echo json_encode(['ok'=>false,'error'=>'No se pudo iniciar OAuth']);exit;}
$fields=http_build_query([
    'code'=>$code,
    'client_id'=>$clientId,
    'client_secret'=>$clientSecret,
    'redirect_uri'=>'https://developers.google.com/oauthplayground',
    'grant_type'=>'authorization_code',
]);
curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_POSTFIELDS=>$fields]);
$response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
$decoded=is_string($response)?json_decode($response,true):null;
$refreshToken=is_array($decoded)?trim((string)($decoded['refresh_token']??'')):'';
if($status<200||$status>=300||$refreshToken===''){
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Google rechazó el código. Genera uno nuevo en OAuth Playground e inténtalo otra vez.'],JSON_UNESCAPED_UNICODE);
    exit;
}
if(!google_contacts_oauth_store_refresh_token($refreshToken)){http_response_code(500);echo json_encode(['ok'=>false,'error'=>'No se pudo guardar la renovación OAuth']);exit;}
unset($refreshToken,$decoded,$response,$fields,$code);
if(hache_sharky_google_contacts_access_token()===''){http_response_code(502);echo json_encode(['ok'=>false,'error'=>'Google no aceptó el refresh token nuevo']);exit;}
$sync=hache_sharky_contact_book_sync_pending($pdo,50);
echo json_encode(['ok'=>true,'token_refresh_ok'=>true,'sync'=>$sync,'counts'=>google_contacts_oauth_summary($pdo)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
