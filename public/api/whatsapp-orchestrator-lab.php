<?php

declare(strict_types=1);

header('Cache-Control: no-store');
require_once __DIR__.'/../../config/sharky-lab-worker.php';
require_once __DIR__.'/../../config/sharky-inbox.php';
require_once __DIR__.'/../../config/sharky-groups.php';
require_once __DIR__.'/../../config/sharky-delivery-status.php';

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

$pdo=hache_sharky_pdo();if(!$pdo instanceof PDO)sharky_lab_json(503,['ok'=>false,'error'=>'Database unavailable']);
if(!hache_sharky_orchestrator_store_ready($pdo))sharky_lab_json(503,['ok'=>false,'error'=>'Sharky migration incomplete']);

// Delivery/read evidence is accepted only after the Meta signature above. The
// optional schema keeps deploy-before-migration backward compatible; once it is
// present, an eligible status that cannot be persisted returns 503 so Meta may retry.
$delivery=hache_sharky_delivery_store_payload($pdo,$payload,hache_sharky_lab_secret('WHATSAPP_PHONE_NUMBER_ID'));
if(($delivery['schema_ready']??false)===true&&($delivery['eligible']??0)>($delivery['stored']??0))sharky_lab_json(503,['ok'=>false,'error'=>'Unable to persist delivery status']);

// Group traffic is fail-closed. With the backend toggle off, group messages are
// acknowledged but never normalized, persisted, sent to OpenAI or answered.
$groupsEnabled=hache_sharky_groups_enabled($pdo);
$groupCount=hache_sharky_groups_count_messages($payload);
$payload=hache_sharky_groups_filter_payload($payload,$groupsEnabled);
if(!$groupsEnabled&&$groupCount>0){
    for($i=0;$i<$groupCount;$i++)hache_sharky_metric_increment('messages_skipped_group');
}

$events=array_merge(
    hache_sharky_whatsapp_extract($payload),
    hache_sharky_commerce_flow_extract_events($payload),
    hache_sharky_whatsapp_birthdate_flow_extract_events($payload,hache_sharky_lab_today()),
    hache_sharky_draft_extract_audio_events($payload),
    hache_sharky_payment_reminder_extract_proof_events($payload,hache_sharky_lab_secret('WHATSAPP_PHONE_NUMBER_ID'))
);
$events=hache_sharky_groups_decorate_events($events,$payload);
foreach($events as &$event){
    if(!isset($event['kind']))$event['kind']='message';
    // Una imagen/comprobante de un grupo no puede cancelar el recordatorio de una conversación individual.
    if(($event['kind']??'')===HACHE_SHARKY_PAYMENT_PROOF_KIND&&trim((string)($event['group_id']??''))!=='')$event['kind']='group_media';
}
unset($event);
$echoes=hache_sharky_whatsapp_extract_echoes($payload);foreach($echoes as &$echo)$echo['kind']='echo';unset($echo);
$durable=array_merge($events,$echoes);usort($durable,static fn(array $a,array $b):int=>(int)($a['timestamp_ms']??0)<=>(int)($b['timestamp_ms']??0));

// P0 durability: persist every supported normalized inbound message/echo before returning 200.
foreach($durable as $event)if(!hache_sharky_inbox_store($pdo,$event))sharky_lab_json(503,['ok'=>false,'error'=>'Unable to persist inbound event']);

// Imágenes/documentos quedan como evidencia durable cifrada, pero no se envían a
// OpenAI ni generan una respuesta automática. El recordatorio los consulta por
// contact_hash + kind justo antes de enviarse. PhotoPicker Flow replies are
// interactive and are handled by the dedicated commerce path after the ACK.
foreach($events as $event){
    if(!in_array((string)($event['type']??''),['image','document'],true))continue;
    if(!hache_sharky_orchestrator_mark_processed($pdo,(string)($event['id']??'')))sharky_lab_json(503,['ok'=>false,'error'=>'Unable to finalize inbound media event']);
}

http_response_code(200);header('Content-Type: application/json; charset=utf-8');echo '{"ok":true}';if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();ignore_user_abort(true);@set_time_limit(90);

// Flow creation/list/upload is deliberately after Meta's ACK. The birthdate
// provisioner already has its own backoff. Commerce adds per-key 15-minute
// backoff and allows at most one unresolved Graph provisioning attempt per webhook.
hache_sharky_whatsapp_birthdate_flow_prime($payload,static fn(string $name):string=>hache_sharky_lab_secret($name));
hache_sharky_commerce_flows_prime_throttled($payload,static fn(string $name):string=>hache_sharky_lab_secret($name));

$business=hache_sharky_business_values($pdo);$minAge=hache_sharky_config_int($business,'sharky_edad_minima',12,1,99);$escalationThreshold=hache_sharky_config_int($business,'sharky_escalado_intentos',2,1,5);

// A manual echo wins over every automatic send in the same webhook. Persist/process
// echoes first, then normal messages. Payment-proof media was already finalized
// above and is deliberately excluded from conversational processing.
$events=array_values(array_filter($events,static fn(array $event):bool=>!in_array((string)($event['type']??''),['image','document'],true)));
$processing=array_merge($echoes,$events);
usort($processing,static function(array $a,array $b):int{
    $ak=($a['kind']??'')==='echo'?0:1;$bk=($b['kind']??'')==='echo'?0:1;
    if($ak!==$bk)return $ak<=>$bk;
    return (int)($a['timestamp_ms']??0)<=>(int)($b['timestamp_ms']??0);
});
foreach($processing as $event){
    if(hache_sharky_lab_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED')!=='1')break;
    if(hache_sharky_commerce_event_candidate($event)){
        hache_sharky_commerce_process_event($pdo,$event,$business,$minAge);
        continue;
    }
    hache_sharky_lab_process_event($pdo,$event,$business,$minAge,$escalationThreshold);
}
if(hache_sharky_lab_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED')==='1')hache_sharky_outbox_dispatch($pdo,'hache_sharky_lab_send',20);
exit;