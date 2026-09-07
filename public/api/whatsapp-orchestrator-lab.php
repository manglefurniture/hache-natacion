<?php

declare(strict_types=1);

header('Cache-Control: no-store');
require_once __DIR__.'/../../config/sharky-lab-worker.php';
require_once __DIR__.'/../../config/sharky-member-ops.php';
require_once __DIR__.'/../../config/sharky-member-payments.php';
require_once __DIR__.'/../../config/sharky-member-routing.php';
require_once __DIR__.'/../../config/sharky-inbox.php';
require_once __DIR__.'/../../config/sharky-groups.php';
require_once __DIR__.'/../../config/sharky-delivery-status.php';

function sharky_lab_json(int $status,array $body): never
{
    header('Content-Type: application/json; charset=utf-8');http_response_code($status);echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}

function sharky_member_should_handle(PDO $pdo,array $event): bool
{
    if(!hache_sharky_member_schema_ready($pdo)||!hache_sharky_member_payments_schema_ready($pdo))return false;
    if(trim((string)($event['group_id']??''))!=='')return false;
    $contact=preg_replace('/\D+/','',(string)($event['from']??''))?:'';if($contact==='')return false;
    try{$state=hache_sharky_db_state_load($pdo,$contact);}catch(Throwable $e){return false;}
    $flow=hache_sharky_member_flow($state);$flowName=(string)($flow['name']??'');
    if((string)($event['kind']??'')===HACHE_SHARKY_MEMBER_EVIDENCE_KIND)return $flowName==='absence'&&($flow['step']??'')==='evidence';
    if(in_array($flowName,['absence','teacher_cancel'],true))return true;
    $intent=hache_sharky_member_intent((string)($event['text']??''),(string)($event['interactive_id']??''));
    $teacher=hache_sharky_member_teacher_by_whatsapp($pdo,$contact);
    if(($teacher['found']??false)===true&&in_array($intent,['greeting','teacher_agenda','teacher_cancel','teacher_cancel_select','member:tc_confirm','member:tc_abort'],true))return true;
    $student=hache_sharky_business_identity_by_whatsapp($pdo,$contact);
    return ($student['found']??false)===true&&in_array($intent,['greeting','class_today','payments','absence','repos','member:absence_no_evidence','member:absence_add_evidence','member:absence_confirm','member:absence_abort','absence_date'],true);
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
// The code may deploy before the additive member migrations are executed. Keep
// the new routes completely dormant until every required table exists so the
// current production behavior remains unchanged during that controlled window.
$memberOpsReady=hache_sharky_member_schema_ready($pdo)&&hache_sharky_member_payments_schema_ready($pdo);

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

// The generic payment-proof extractor intentionally sees every image/document.
// When an absence flow is waiting for evidence, that same Meta message must have
// exactly one durable receipt, owned by member-ops; otherwise the generic media
// copy would mark the shared message ID processed before the absence flow claims it.
$memberEvidenceEvents=$memberOpsReady?hache_sharky_member_extract_media_events($pdo,$payload):[];
$memberEvidenceIds=[];
foreach($memberEvidenceEvents as $memberEvidenceEvent){
    $memberEvidenceId=trim((string)($memberEvidenceEvent['id']??''));
    if($memberEvidenceId!=='')$memberEvidenceIds[$memberEvidenceId]=true;
}
$paymentProofEvents=hache_sharky_payment_reminder_extract_proof_events($payload,hache_sharky_lab_secret('WHATSAPP_PHONE_NUMBER_ID'));
if($memberEvidenceIds){
    $paymentProofEvents=array_values(array_filter($paymentProofEvents,static fn(array $event):bool=>!isset($memberEvidenceIds[(string)($event['id']??'')])));
}

$events=array_merge(
    hache_sharky_whatsapp_extract($payload),
    $memberEvidenceEvents,
    hache_sharky_commerce_flow_extract_events($payload),
    hache_sharky_whatsapp_birthdate_flow_extract_events($payload,hache_sharky_lab_today()),
    hache_sharky_draft_extract_audio_events($payload),
    $paymentProofEvents
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

// Generic media remains evidence-only and is finalized immediately. Absence
// evidence is the exception: it must pass through member-ops so it can be bound
// to the confirmed absence before the inbox receipt is completed.
foreach($events as $event){
    if(!in_array((string)($event['type']??''),['image','document'],true))continue;
    if((string)($event['kind']??'')===HACHE_SHARKY_MEMBER_EVIDENCE_KIND)continue;
    if(!hache_sharky_orchestrator_mark_processed($pdo,(string)($event['id']??'')))sharky_lab_json(503,['ok'=>false,'error'=>'Unable to finalize inbound media event']);
}

http_response_code(200);header('Content-Type: application/json; charset=utf-8');echo '{"ok":true}';if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();ignore_user_abort(true);@set_time_limit(90);

$business=hache_sharky_business_values($pdo);$minAge=hache_sharky_config_int($business,'sharky_edad_minima',12,1,99);$escalationThreshold=hache_sharky_config_int($business,'sharky_escalado_intentos',2,1,5);

// A manual echo wins over every automatic send in the same webhook. Persist/process
// echoes first, then normal messages. Payment-proof media stays evidence-only;
// absence evidence is retained for the member-ops controlled flow.
$events=array_values(array_filter($events,static fn(array $event):bool=>!in_array((string)($event['type']??''),['image','document'],true)||(string)($event['kind']??'')===HACHE_SHARKY_MEMBER_EVIDENCE_KIND));
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
    if($memberOpsReady){
        $member=hache_sharky_member_route_event($pdo,$event,$business);
        if($member!==null)continue;
    }
    hache_sharky_lab_process_event($pdo,$event,$business,$minAge,$escalationThreshold);
}
if(hache_sharky_lab_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED')==='1')hache_sharky_outbox_dispatch($pdo,'hache_sharky_lab_send',20);

// Resource management is deliberately last. The current turn has already been
// processed and its durable outbox dispatched, so a slow/unavailable Graph API
// can only affect future Flow UX, never starve the message that triggered it.
hache_sharky_whatsapp_birthdate_flow_prime($payload,static fn(string $name):string=>hache_sharky_lab_secret($name));
// Compatibility invariant: hache_sharky_commerce_flows_prime semantics now live
// in the versioned v2 provisioner below; legacy commerce v1 is intentionally not invoked.
// Commerce v2 carries display-only fixes. Provision it separately so already-
// published v1 resources are never silently reused after a JSON correction.
hache_sharky_commerce_flow_v2_prime_throttled($payload,static fn(string $name):string=>hache_sharky_lab_secret($name));
exit;
