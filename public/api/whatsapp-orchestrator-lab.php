<?php

declare(strict_types=1);

header('Cache-Control: no-store');
require_once __DIR__.'/../../config/sharky-runtime.php';
require_once __DIR__.'/../../config/sharky-whatsapp-batching.php';
require_once __DIR__.'/../../config/sharky-whatsapp-echoes.php';

function sharky_lab_secret(string $name): string
{
    $v=trim((string)getenv($name));if($v!=='')return $v;
    foreach([dirname(__DIR__,2).'/.env',dirname(__DIR__,3).'/.env'] as $f){if(!is_readable($f))continue;foreach(file($f,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){$line=trim((string)$line);if($line===''||str_starts_with($line,'#'))continue;if(str_starts_with($line,'export '))$line=trim(substr($line,7));if(str_starts_with($line,$name.'='))return trim(trim(substr($line,strlen($name)+1)),"\"'");}}
    return '';
}
function sharky_lab_json(int $status,array $body): never{header('Content-Type: application/json; charset=utf-8');http_response_code($status);echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function sharky_lab_graph_version(): string{$v=sharky_lab_secret('WHATSAPP_GRAPH_VERSION');return preg_match('/^v\d+\.\d+$/',$v)?$v:'v26.0';}
function sharky_lab_send(array $payload): bool
{
    $token=sharky_lab_secret('WHATSAPP_ACCESS_TOKEN');$phoneId=sharky_lab_secret('WHATSAPP_PHONE_NUMBER_ID');if($token===''||$phoneId==='')return false;
    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)return false;
    $url='https://graph.facebook.com/'.rawurlencode(sharky_lab_graph_version()).'/'.rawurlencode($phoneId).'/messages';$ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token],CURLOPT_POSTFIELDS=>$json]);
    $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);return $response!==false&&$error===''&&$status>=200&&$status<300;
}
function sharky_lab_answer(string $text,string $instruction,array $state,array $context): string
{
    $history=[];$ref=$state['referral']['latest']??null;
    if(is_array($ref)&&!empty($ref['headline']))$history[]=['role'=>'system','content'=>'Origen de campaña: '.mb_substr((string)$ref['headline'],0,180)];
    $history[]=['role'=>'system','content'=>$instruction];
    $payload=json_encode(['message'=>$text,'history'=>$history,'channel'=>'whatsapp'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($payload===false)return '';
    $ch=curl_init('https://hnatacion.com/api/sharky.php');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>$payload,CURLOPT_RESOLVE=>['hnatacion.com:443:127.0.0.1']]);
    $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($response===false||$status<200||$status>=300)return '';$data=json_decode($response,true);return is_array($data)&&($data['ok']??false)===true?trim((string)($data['answer']??'')):'';
}

if(sharky_lab_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED')!=='1')sharky_lab_json(404,['ok'=>false,'error'=>'Lab disabled']);
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='GET'){$mode=(string)($_GET['hub_mode']??$_GET['hub.mode']??'');$token=(string)($_GET['hub_verify_token']??$_GET['hub.verify_token']??'');$challenge=(string)($_GET['hub_challenge']??$_GET['hub.challenge']??'');$expected=sharky_lab_secret('WHATSAPP_VERIFY_TOKEN');if($mode==='subscribe'&&$expected!==''&&hash_equals($expected,$token)){header('Content-Type: text/plain; charset=utf-8');echo $challenge;exit;}sharky_lab_json(403,['ok'=>false,'error'=>'Webhook verification failed']);}
if($method!=='POST')sharky_lab_json(405,['ok'=>false,'error'=>'Method not allowed']);
$raw=(string)file_get_contents('php://input');$secret=sharky_lab_secret('META_APP_SECRET');$sig=trim((string)($_SERVER['HTTP_X_HUB_SIGNATURE_256']??''));$expected='sha256='.hash_hmac('sha256',$raw,$secret);if($secret===''||$sig===''||!hash_equals($expected,$sig))sharky_lab_json(401,['ok'=>false,'error'=>'Invalid signature']);
$payload=json_decode($raw,true);if(!is_array($payload))sharky_lab_json(400,['ok'=>false,'error'=>'Invalid JSON']);
$events=hache_sharky_whatsapp_extract($payload);$echoes=hache_sharky_whatsapp_extract_echoes($payload);http_response_code(200);header('Content-Type: application/json; charset=utf-8');echo '{"ok":true}';if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();ignore_user_abort(true);@set_time_limit(90);
$pdo=hache_sharky_pdo();$configured=sharky_lab_secret('WHATSAPP_PHONE_NUMBER_ID');
foreach($echoes as $echo){if($configured!==''&&($echo['phone_number_id']??'')!==''&&!hash_equals($configured,(string)$echo['phone_number_id']))continue;hache_sharky_takeover_mark((string)$echo['to'],'manual','Respuesta manual detectada por WhatsApp coexistence.');}
foreach($events as $event){if($configured!==''&&($event['phone_number_id']??'')!==''&&!hash_equals($configured,(string)$event['phone_number_id']))continue;if(hache_sharky_takeover_active((string)$event['from']))continue;$result=hache_sharky_whatsapp_enqueue($pdo,$event,'sharky_lab_answer',['verification_base_url'=>'https://hnatacion.com/sharky-verificar.php']);if($result['skip']??false)continue;$decision=$result['decision']??[];$action=$decision['action']??null;if(is_array($action)&&($action['type']??'')==='human_takeover')hache_sharky_takeover_mark((string)$event['from'],'requested_human','Sharky 2.0 controlled handoff');$out=$result['payload']??null;if(is_array($out))sharky_lab_send($out);}
exit;
