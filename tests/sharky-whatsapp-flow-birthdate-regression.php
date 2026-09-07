<?php

declare(strict_types=1);

if(!function_exists('mb_substr')){function mb_substr(string $s,int $start,?int $length=null,?string $enc=null):string{return $length===null?substr($s,$start):substr($s,$start,$length);}}
if(!function_exists('mb_strlen')){function mb_strlen(string $s,?string $enc=null):int{return strlen($s);}}
if(!function_exists('mb_strtolower')){function mb_strtolower(string $s,?string $enc=null):string{return strtolower($s);}}

require_once __DIR__.'/../config/sharky-orchestrator.php';
require_once __DIR__.'/../config/sharky-groups.php';

function flow_ok(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function flow_eq(mixed $actual,mixed $expected,string $message):void{if($actual!==$expected){fwrite(STDERR,"FAIL: $message\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true)."\n");exit(1);}}

$today='2026-09-07';
$flowPath=__DIR__.'/../config/whatsapp-flows/birthdate-v1.json';
$flow=json_decode((string)file_get_contents($flowPath),true);
flow_ok(is_array($flow),'Flow JSON must be valid JSON');
flow_eq($flow['version']??null,'7.0','Flow JSON version stays explicit');
$screen=$flow['screens'][0]??null;
flow_eq($screen['id']??null,'BIRTHDATE','birthdate screen id is stable');
flow_eq($screen['terminal']??null,true,'birthdate screen is terminal');
$children=$screen['layout']['children'][0]['children']??[];
$datePicker=null;$footer=null;
foreach($children as $child){
    if(!is_array($child))continue;
    if(($child['type']??'')==='DatePicker')$datePicker=$child;
    if(($child['type']??'')==='Footer')$footer=$child;
}
flow_ok(is_array($datePicker),'Flow contains a DatePicker');
flow_eq($datePicker['name']??null,'birthdate','DatePicker field name matches webhook parser');
flow_eq($datePicker['required']??null,true,'DatePicker is required');
flow_ok(is_array($footer),'Flow contains terminal footer');
flow_eq($footer['on-click-action']['name']??null,'complete','footer completes the Flow');
flow_eq($footer['on-click-action']['payload']['birthdate']??null,'${form.birthdate}','Flow returns the selected birthdate');

flow_eq(hache_sharky_whatsapp_birthdate_flow_response_date('{"birthdate":"1983-03-08"}',$today),'1983-03-08','valid Flow date is normalized');
flow_eq(hache_sharky_whatsapp_birthdate_flow_response_date('{"birthdate":"1983-02-31"}',$today),null,'impossible Flow date is rejected');
flow_eq(hache_sharky_whatsapp_birthdate_flow_response_date('{bad',$today),null,'malformed Flow response is rejected');

$payload=[
    'entry'=>[ [
        'id'=>'9988776655',
        'changes'=>[ [
            'value'=>[
                'metadata'=>['phone_number_id'=>'12345'],
                'messages'=>[ [
                    'id'=>'wamid.flow.1','from'=>'529981234567','timestamp'=>'1788756300','type'=>'interactive',
                    'interactive'=>[
                        'type'=>'nfm_reply',
                        'nfm_reply'=>['response_json'=>'{"birthdate":"1983-03-08"}'],
                    ],
                ] ],
            ],
        ] ],
    ] ],
];
$events=hache_sharky_whatsapp_birthdate_flow_extract_events($payload,$today);
flow_eq(count($events),1,'one terminal Flow reply becomes one event');
flow_eq($events[0]['text']??null,'1983-03-08','Flow reply re-enters controlled registration as ISO date');
flow_eq($events[0]['interactive_id']??null,'','terminal Flow date is consumed through the same typed-date path, not stale-interactive routing');
flow_eq($events[0]['waba_id']??null,'9988776655','signed webhook WABA id is retained for Flow lifecycle');
flow_eq($events[0]['phone_number_id']??null,'12345','phone number id is retained');

$badPayload=$payload;
$badPayload['entry'][0]['changes'][0]['value']['messages'][0]['interactive']['nfm_reply']['response_json']='{"birthdate":"no-es-fecha"}';
flow_eq(hache_sharky_whatsapp_birthdate_flow_extract_events($badPayload,$today),[],'malformed nfm_reply never enters free-text pipeline');

putenv('WHATSAPP_BIRTHDATE_FLOW_ID=123456789012345');
$birthPrompt=[
    'messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>'529981234567','type'=>'text',
    'text'=>['preview_url'=>false,'body'=>'Por seguridad y para validar la edad mínima, dime la fecha de nacimiento. Puedes escribirla como te resulte más cómodo.'],
];
$upgraded=hache_sharky_groups_prepare_outbound($birthPrompt,'');
flow_eq($upgraded['type']??null,'interactive','cached/pinned Flow upgrades birthdate prompt');
flow_eq($upgraded['interactive']['type']??null,'flow','outbound interactive type is flow');
flow_eq($upgraded['interactive']['action']['name']??null,'flow','outbound action is flow');
flow_eq($upgraded['interactive']['action']['parameters']['flow_message_version']??null,'3','Flow message version is explicit');
flow_eq($upgraded['interactive']['action']['parameters']['flow_id']??null,'123456789012345','pinned published Flow id is used');
flow_eq($upgraded['interactive']['action']['parameters']['flow_cta']??null,'Elegir fecha','CTA opens the date selector');
flow_eq($upgraded['interactive']['action']['parameters']['flow_action']??null,'navigate','Flow opens directly on birthdate screen');
flow_eq($upgraded['interactive']['action']['parameters']['flow_action_payload']['screen']??null,'BIRTHDATE','navigate target matches Flow JSON');
flow_ok(str_contains((string)($upgraded['interactive']['body']['text']??''),'También puedes escribir la fecha directamente'),'typed-date fallback remains visible');

$invalidPrompt=[
    'messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>'529981234567','type'=>'text',
    'text'=>['preview_url'=>false,'body'=>'No pude reconocer esa fecha. Escríbela con día, mes y año, por ejemplo 07/02/1984.'],
];
flow_ok(hache_sharky_whatsapp_birthdate_prompt_payload($invalidPrompt),'invalid typed-date prompt is recognized even without repeating “fecha de nacimiento”');
$invalidUpgraded=hache_sharky_groups_prepare_outbound($invalidPrompt,'');
flow_eq($invalidUpgraded['type']??null,'interactive','invalid typed date reopens the DatePicker when Flow is available');
flow_eq($invalidUpgraded['interactive']['type']??null,'flow','invalid typed date retry uses the same Flow selector');

$normal=[
    'messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>'529981234567','type'=>'text',
    'text'=>['preview_url'=>false,'body'=>'Elige el horario que prefieres.'],
];
flow_eq(hache_sharky_groups_prepare_outbound($normal,''),$normal,'non-birthdate direct messages stay byte-for-byte unchanged');
$groupVersion=hache_sharky_groups_prepare_outbound($birthPrompt,'group-123');
flow_eq($groupVersion['type']??null,'text','group chats never receive a WhatsApp Flow');
flow_eq($groupVersion['_sharky_group']??null,true,'group routing metadata remains intact');
putenv('WHATSAPP_BIRTHDATE_FLOW_ID');

$webhook=(string)file_get_contents(__DIR__.'/../public/api/whatsapp-orchestrator-lab.php');
$persistPos=strpos($webhook,'hache_sharky_inbox_store($pdo,$event)');
$ackPos=strpos($webhook,'fastcgi_finish_request');
$primePos=strpos($webhook,'hache_sharky_whatsapp_birthdate_flow_prime');
flow_ok($persistPos!==false&&$ackPos!==false&&$primePos!==false&&$persistPos<$ackPos&&$ackPos<$primePos,'Flow provisioning stays after durable persistence and webhook ACK');
flow_ok(str_contains($webhook,'hache_sharky_whatsapp_birthdate_flow_extract_events'),'webhook ingests nfm_reply events');

fwrite(STDOUT,"SHARKY_WHATSAPP_FLOW_BIRTHDATE_OK\n");
