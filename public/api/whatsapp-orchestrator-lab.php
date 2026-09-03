<?php

declare(strict_types=1);

header('Cache-Control: no-store');
require_once __DIR__.'/../../config/sharky-runtime.php';
require_once __DIR__.'/../../config/sharky-whatsapp-batching.php';
require_once __DIR__.'/../../config/sharky-whatsapp-echoes.php';
require_once __DIR__.'/../../config/sharky-draft-parity.php';
require_once __DIR__.'/../../config/sharky-outbox.php';

function sharky_lab_secret(string $name): string
{
    $value=trim((string)getenv($name));if($value!=='')return $value;
    foreach([dirname(__DIR__,2).'/.env',dirname(__DIR__,3).'/.env'] as $file){
        if(!is_readable($file))continue;
        foreach(file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){
            $line=trim((string)$line);if($line===''||str_starts_with($line,'#'))continue;
            if(str_starts_with($line,'export '))$line=trim(substr($line,7));
            if(str_starts_with($line,$name.'='))return trim(trim(substr($line,strlen($name)+1)),"\"'");
        }
    }
    return '';
}

function sharky_lab_json(int $status,array $body): never
{
    header('Content-Type: application/json; charset=utf-8');http_response_code($status);echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}

function sharky_lab_graph_version(): string
{
    $version=sharky_lab_secret('WHATSAPP_GRAPH_VERSION');return preg_match('/^v\d+\.\d+$/',$version)===1?$version:'v26.0';
}

function sharky_lab_send(array $payload): bool
{
    $token=sharky_lab_secret('WHATSAPP_ACCESS_TOKEN');$phoneId=sharky_lab_secret('WHATSAPP_PHONE_NUMBER_ID');
    if($token===''||$phoneId==='')return false;
    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)return false;
    $url='https://graph.facebook.com/'.rawurlencode(sharky_lab_graph_version()).'/'.rawurlencode($phoneId).'/messages';
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token],CURLOPT_POSTFIELDS=>$json]);
    $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    return $response!==false&&$error===''&&$status>=200&&$status<300;
}

function sharky_lab_queue(PDO $pdo,string $contact,array $payload,string $dedupeSeed): bool
{
    if(!hache_sharky_outbox_enqueue($pdo,$contact,$payload,$dedupeSeed))return false;
    hache_sharky_outbox_dispatch($pdo,'sharky_lab_send',10);
    return true;
}

function sharky_lab_answer(string $text,string $instruction,array $state,array $context): string
{
    $history=[];$ref=$state['referral']['latest']??null;
    if(is_array($ref)&&!empty($ref['headline']))$history[]=['role'=>'system','content'=>'Origen de campaña: '.mb_substr((string)$ref['headline'],0,180)];
    $history[]=['role'=>'system','content'=>$instruction];
    $payload=json_encode(['message'=>$text,'history'=>$history,'channel'=>'whatsapp'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($payload===false)return '';
    $ch=curl_init('https://hnatacion.com/api/sharky.php');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>$payload,CURLOPT_RESOLVE=>['hnatacion.com:443:127.0.0.1']]);
    $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($response===false||$status<200||$status>=300)return '';
    $data=json_decode((string)$response,true);return is_array($data)&&($data['ok']??false)===true?trim((string)($data['answer']??'')):'';
}

if(sharky_lab_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED')!=='1')sharky_lab_json(404,['ok'=>false,'error'=>'Lab disabled']);
if(strlen(sharky_lab_secret('SHARKY_CONTACT_HASH_KEY'))<32)sharky_lab_json(503,['ok'=>false,'error'=>'Sharky security key not configured']);

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='GET'){
    $mode=(string)($_GET['hub_mode']??$_GET['hub.mode']??'');$token=(string)($_GET['hub_verify_token']??$_GET['hub.verify_token']??'');$challenge=(string)($_GET['hub_challenge']??$_GET['hub.challenge']??'');$expected=sharky_lab_secret('WHATSAPP_VERIFY_TOKEN');
    if($mode==='subscribe'&&$expected!==''&&hash_equals($expected,$token)){header('Content-Type: text/plain; charset=utf-8');echo $challenge;exit;}
    sharky_lab_json(403,['ok'=>false,'error'=>'Webhook verification failed']);
}
if($method!=='POST')sharky_lab_json(405,['ok'=>false,'error'=>'Method not allowed']);

$raw=(string)file_get_contents('php://input');$secret=sharky_lab_secret('META_APP_SECRET');$signature=trim((string)($_SERVER['HTTP_X_HUB_SIGNATURE_256']??''));$expectedSignature='sha256='.hash_hmac('sha256',$raw,$secret);
if($secret===''||$signature===''||!hash_equals($expectedSignature,$signature))sharky_lab_json(401,['ok'=>false,'error'=>'Invalid signature']);
$payload=json_decode($raw,true);if(!is_array($payload))sharky_lab_json(400,['ok'=>false,'error'=>'Invalid JSON']);
$events=array_merge(hache_sharky_whatsapp_extract($payload),hache_sharky_draft_extract_audio_events($payload));usort($events,static fn(array $a,array $b):int=>(int)($a['timestamp_ms']??0)<=>(int)($b['timestamp_ms']??0));$echoes=hache_sharky_whatsapp_extract_echoes($payload);

http_response_code(200);header('Content-Type: application/json; charset=utf-8');echo '{"ok":true}';if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();ignore_user_abort(true);@set_time_limit(90);

$pdo=hache_sharky_pdo();if(!$pdo instanceof PDO){error_log('[sharky-orchestrator] lab database unavailable; event ignored safely');exit;}
if(!hache_sharky_orchestrator_store_ready($pdo)){error_log('[sharky-orchestrator] lab migration incomplete; event ignored safely');exit;}
hache_sharky_outbox_dispatch($pdo,'sharky_lab_send',20);
$business=hache_sharky_business_values($pdo);$minAge=hache_sharky_config_int($business,'sharky_edad_minima',12,1,99);$escalationThreshold=hache_sharky_config_int($business,'sharky_escalado_intentos',2,1,5);$configured=sharky_lab_secret('WHATSAPP_PHONE_NUMBER_ID');$secretResolver=static fn(string $name):string=>sharky_lab_secret($name);

foreach($echoes as $echo){if($configured!==''&&($echo['phone_number_id']??'')!==''&&!hash_equals($configured,(string)$echo['phone_number_id']))continue;hache_sharky_takeover_mark((string)$echo['to'],'manual','Respuesta manual detectada por WhatsApp coexistence.');}

foreach($events as $event){
    if($configured!==''&&($event['phone_number_id']??'')!==''&&!hash_equals($configured,(string)$event['phone_number_id']))continue;
    $contact=(string)($event['from']??'');if($contact===''||hache_sharky_takeover_active($contact))continue;$eventId=(string)($event['id']??'event');
    if(($event['type']??'')==='audio'){
        $text=hache_sharky_draft_transcribe_audio($event,$business,$secretResolver);
        if($text===''){sharky_lab_queue($pdo,$contact,hache_sharky_whatsapp_text_payload($contact,'No pude procesar esa nota de voz. Escríbeme el mensaje y seguimos por aquí.'),$eventId.'|audio-fallback');continue;}
        $event['type']='text';$event['text']=$text;
    }
    $text=trim((string)($event['text']??''));
    if($text!==''&&hache_sharky_draft_requires_handoff($text)){
        hache_sharky_takeover_mark($contact,'shared_v2_policy','Handoff decidido por la misma regla vigente del webhook v2.');
        sharky_lab_queue($pdo,$contact,hache_sharky_whatsapp_text_payload($contact,'Te dejo con el equipo de Hache Natación. Una persona continuará contigo por este mismo chat.'),$eventId.'|handoff');continue;
    }
    $result=hache_sharky_whatsapp_enqueue($pdo,$event,'sharky_lab_answer',['verification_base_url'=>'https://hnatacion.com/sharky-verificar.php','min_age'=>$minAge]);if($result['skip']??false)continue;
    $decision=is_array($result['decision']??null)?$result['decision']:[];$action=is_array($decision['action']??null)?$decision['action']:null;
    if(is_array($action)&&($action['type']??'')==='human_takeover')hache_sharky_takeover_mark($contact,'requested_human','Sharky 2.0 controlled handoff');
    $out=is_array($result['payload']??null)?$result['payload']:null;$actionResult=is_array($result['action_result']??null)?$result['action_result']:null;
    if(is_array($actionResult)){
        $studentId=trim((string)($actionResult['result']['student_id']??''));if($studentId!=='')hache_sharky_draft_link_attribution($pdo,$contact,$studentId,is_array($result['state']??null)?$result['state']:[]);
        $registrationMessage=hache_sharky_draft_registration_message($actionResult,$business);if($registrationMessage!==null)$out=hache_sharky_whatsapp_text_payload($contact,$registrationMessage);
    }
    $decisionKind=(string)($decision['kind']??'');
    if(in_array($decisionKind,['conversation','conversation_identity_prompt','side_question'],true)){
        $answer=hache_sharky_draft_payload_text($out);
        if($answer!==''&&hache_sharky_draft_escalation_update($contact,$answer,$escalationThreshold)){
            hache_sharky_takeover_mark($contact,'unresolved','Sharky 2.0 agotó el umbral configurable de respuestas no resueltas.');
            $out=hache_sharky_whatsapp_text_payload($contact,'Para no hacerte dar vueltas, te dejo con el equipo de Hache Natación. Una persona continuará contigo por este mismo chat.');
        }
    }
    if(is_array($out)){
        $payloadHash=hash('sha256',json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'');
        sharky_lab_queue($pdo,$contact,$out,$eventId.'|'.$decisionKind.'|'.$payloadHash);
    }
}
exit;
