<?php

declare(strict_types=1);

header('Cache-Control: no-store');
require_once __DIR__.'/../../config/sharky-lab-worker.php';
require_once __DIR__.'/../../config/sharky-inbox.php';

function sharky_lab_json(int $status,array $body): never
{
    header('Content-Type: application/json; charset=utf-8');http_response_code($status);echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}

if(hache_sharky_lab_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED')!=='1')sharky_lab_json(404,['ok'=>false,'error'=>'Lab disabled']);
if(strlen(hache_sharky_lab_secret('SHARKY_CONTACT_HASH_KEY'))<32)sharky_lab_json(503,['ok'=>false,'error'=>'Sharky contact security key not configured']);
if(strlen(hache_sharky_lab_secret('SHARKY_STATE_ENCRYPTION_KEY'))<32)sharky_lab_json(503,['ok'=>false,'error'=>'Sharky state security key not configured']);

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='GET'){
    $mode=(string)($_GET['hub_mode']??$_GET['hub.mode']??'');$token=(string)($_GET['hub_verify_token']??$_GET['hub.verify_token']??'');$challenge=(string)($_GET['hub_challenge']??$_GET['hub.challenge']??'');$expected=hache_sharky_lab_secret('WHATSAPP_VERIFY_TOKEN');
    if($mode==='subscribe'&&$expected!==''&&hash_equals($expected,$token)){header('Content-Type: text/plain; charset=utf-8');echo $challenge;exit;}
    sharky_lab_json(403,['ok'=>false,'error'=>'Webhook verification failed']);
}
if($method!=='POST')sharky_lab_json(405,['ok'=>false,'error'=>'Method not allowed']);

$raw=(string)file_get_contents('php://input');$secret=hache_sharky_lab_secret('META_APP_SECRET');$signature=trim((string)($_SERVER['HTTP_X_HUB_SIGNATURE_256']??''));$expectedSignature='sha256='.hash_hmac('sha256',$raw,$secret);
if($secret===''||$signature===''||!hash_equals($expectedSignature,$signature))sharky_lab_json(401,['ok'=>false,'error'=>'Invalid signature']);
$payload=json_decode($raw,true);if(!is_array($payload))sharky_lab_json(400,['ok'=>false,'error'=>'Invalid JSON']);

$events=array_merge(hache_sharky_whatsapp_extract($payload),hache_sharky_draft_extract_audio_events($payload));
foreach($events as &$event)$event['kind']='message';unset($event);
$echoes=hache_sharky_whatsapp_extract_echoes($payload);foreach($echoes as &$echo)$echo['kind']='echo';unset($echo);
$durable=array_merge($events,$echoes);usort($durable,static fn(array $a,array $b):int=>(int)($a['timestamp_ms']??0)<=>(int)($b['timestamp_ms']??0));

// P0 durability: persist every normalized inbound message/echo before returning 200.
// If DB/migration is unavailable we intentionally do NOT ACK, so Meta can retry.
$pdo=hache_sharky_pdo();if(!$pdo instanceof PDO)sharky_lab_json(503,['ok'=>false,'error'=>'Database unavailable']);
if(!hache_sharky_orchestrator_store_ready($pdo))sharky_lab_json(503,['ok'=>false,'error'=>'Sharky migration incomplete']);
foreach($durable as $event)if(!hache_sharky_inbox_store($pdo,$event))sharky_lab_json(503,['ok'=>false,'error'=>'Unable to persist inbound event']);

http_response_code(200);header('Content-Type: application/json; charset=utf-8');echo '{"ok":true}';if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();ignore_user_abort(true);@set_time_limit(90);

$business=hache_sharky_business_values($pdo);$minAge=hache_sharky_config_int($business,'sharky_edad_minima',12,1,99);$escalationThreshold=hache_sharky_config_int($business,'sharky_escalado_intentos',2,1,5);

// A manual echo wins over every automatic send in the same webhook. Persist/process
// echoes first, then normal messages. Only after that may leftovers in the outbox run.
$processing=array_merge($echoes,$events);
usort($processing,static function(array $a,array $b):int{
    $ak=($a['kind']??'')==='echo'?0:1;$bk=($b['kind']??'')==='echo'?0:1;
    if($ak!==$bk)return $ak<=>$bk;
    return (int)($a['timestamp_ms']??0)<=>(int)($b['timestamp_ms']??0);
});
foreach($processing as $event){
    if(hache_sharky_lab_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED')!=='1')break;
    hache_sharky_lab_process_event($pdo,$event,$business,$minAge,$escalationThreshold);
}
if(hache_sharky_lab_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED')==='1')hache_sharky_outbox_dispatch($pdo,'hache_sharky_lab_send',20);
exit;
