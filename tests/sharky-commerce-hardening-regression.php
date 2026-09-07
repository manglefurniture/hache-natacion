<?php

declare(strict_types=1);

putenv('SHARKY_CONTACT_HASH_KEY='.str_repeat('k',64));

require_once __DIR__.'/../config/sharky-whatsapp-adapter.php';
require_once __DIR__.'/../config/sharky-commerce-runtime.php';

function commerce_hard_expect(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

// The generic adapter must not duplicate nfm_reply with the same message id.
$nfmPayload=['entry'=>[['changes'=>[['value'=>[
    'metadata'=>['phone_number_id'=>'phone-1'],
    'messages'=>[[
        'id'=>'wamid.nfm','from'=>'529981473867','timestamp'=>'1788780000','type'=>'interactive',
        'interactive'=>['type'=>'nfm_reply','nfm_reply'=>['response_json'=>'{"flow_kind":"payment_method","method":"transfer","student_id":"S1","course_id":"C1"}']],
    ]],
]]]]]];
commerce_hard_expect(hache_sharky_whatsapp_extract($nfmPayload)===[],'Generic WhatsApp extraction must ignore nfm_reply so the encrypted inbox keeps the commerce event only.');
$commerceEvents=hache_sharky_commerce_flow_extract_events($nfmPayload);
commerce_hard_expect(count($commerceEvents)===1,'Commerce nfm_reply must normalize exactly once.');
commerce_hard_expect(($commerceEvents[0]['commerce']['student_id']??'')==='S1'&&($commerceEvents[0]['commerce']['course_id']??'')==='C1','Payment Flow binding identifiers must survive normalization.');

// Payment-method Flow must round-trip the exact registration binding.
$method=json_decode((string)file_get_contents(__DIR__.'/../config/whatsapp-flows/payment-method-v1.json'),true);
commerce_hard_expect(is_array($method),'Payment-method Flow JSON must decode.');
$data=$method['screens'][0]['data']??[];
commerce_hard_expect(isset($data['student_id'],$data['course_id']),'Payment-method Flow must declare student/course binding data.');
$methodText=json_encode($method,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'';
commerce_hard_expect(str_contains($methodText,'"student_id":"${data.student_id}"')&&str_contains($methodText,'"course_id":"${data.course_id}"'),'Payment-method completion must return the registration binding.');

// Outbound Flow binding is injected from the server-side session, never trusted from user text.
$flow=hache_sharky_commerce_flow_payload(
    '529981473867','Elige cómo pagar','123456','PAYMENT_METHOD','Elegir forma de pago',
    ['intro'=>'SPEI recomendado','methods'=>[['id'=>'transfer','title'=>'Transferencia SPEI','description'=>'Sin recargo']]],
    'flow-token-test'
);
$flow['_sharky_payment_session']=['student_id'=>'Student-A','course_id'=>'Course-B','stage'=>'method'];
$boundFlow=hache_sharky_commerce_prepare_payload($flow);
$boundData=$boundFlow['interactive']['action']['parameters']['flow_action_payload']['data']??[];
commerce_hard_expect(($boundData['student_id']??'')==='Student-A'&&($boundData['course_id']??'')==='Course-B','Outbound payment Flow must be bound to the exact pending registration.');
commerce_hard_expect(!isset($boundFlow['_sharky_payment_session']),'Binding metadata must be removed before network delivery.');

// Fallback buttons receive the same binding and preserve case through URL encoding.
$buttons=[
    'messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>'529981473867','type'=>'interactive',
    'interactive'=>['type'=>'button','body'=>['text'=>'Pago'],'action'=>['buttons'=>[
        ['type'=>'reply','reply'=>['id'=>'commerce:pay:transfer','title'=>'Transferencia SPEI']],
        ['type'=>'reply','reply'=>['id'=>'commerce:pay:card','title'=>'Tarjeta']],
        ['type'=>'reply','reply'=>['id'=>'commerce:pay:cash','title'=>'Efectivo']],
    ]]],
    '_sharky_payment_session'=>['student_id'=>'Student-A','course_id'=>'Course-B','stage'=>'method'],
];
$boundButtons=hache_sharky_commerce_prepare_payload($buttons);
$buttonId=(string)($boundButtons['interactive']['action']['buttons'][0]['reply']['id']??'');
commerce_hard_expect(str_starts_with($buttonId,'commerce:pay:transfer:'),'Fallback payment button must carry a registration binding.');
$decoded=hache_sharky_commerce_bind_fallback_event(['interactive_id'=>$buttonId]);
commerce_hard_expect(($decoded['commerce']['student_id']??'')==='Student-A'&&($decoded['commerce']['course_id']??'')==='Course-B','Bound fallback button must decode exact case-sensitive ids.');
$legacy=hache_sharky_commerce_bind_fallback_event(['interactive_id'=>'commerce:pay:transfer']);
commerce_hard_expect(($legacy['commerce']['method']??'')==='transfer'&&!isset($legacy['commerce']['student_id']),'Already-sent legacy payment buttons must remain compatible but explicitly unbound.');

// Card follow-up is idempotent per registration, not per Mercado Pago preference.
$cardA=[
    'messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>'529981473867','type'=>'text','text'=>['body'=>'A'],
    '_sharky_mp_followup_arm'=>['token'=>'old-a','student_id'=>'Student-A','course_id'=>'Course-B','external_reference'=>'sharky:same','watch_from'=>1788780000],
];
$cardB=$cardA;$cardB['_sharky_mp_followup_arm']['token']='old-b';$cardB['text']['body']='B';
$preparedA=hache_sharky_commerce_prepare_payload($cardA);
$preparedB=hache_sharky_commerce_prepare_payload($cardB);
$tokenA=(string)($preparedA['_sharky_payment_reminder_arm']['token']??'');
$tokenB=(string)($preparedB['_sharky_payment_reminder_arm']['token']??'');
commerce_hard_expect($tokenA!==''&&hash_equals($tokenA,$tokenB),'Repeated card preferences for one registration must converge on one follow-up token.');
commerce_hard_expect(($preparedA['_sharky_payment_reminder_arm']['mode']??'')==='mp_card','Card follow-up must still reuse payment-reminder mode.');

// Provisioning failure uses a real per-key 15 minute marker.
$now=1788780000;
hache_sharky_commerce_clear_retry('enrollment');
commerce_hard_expect(hache_sharky_commerce_retry_allowed('enrollment',$now),'Fresh commerce Flow key must be eligible for provisioning.');
hache_sharky_commerce_mark_retry('enrollment',$now);
commerce_hard_expect(!hache_sharky_commerce_retry_allowed('enrollment',$now+899),'Failed commerce Flow key must stay throttled for 15 minutes.');
commerce_hard_expect(hache_sharky_commerce_retry_allowed('enrollment',$now+901),'Commerce Flow key must become eligible after throttle window.');
hache_sharky_commerce_clear_retry('enrollment');

$runtime=file_get_contents(__DIR__.'/../config/sharky-commerce-runtime.php')?:'';
commerce_hard_expect(str_contains($runtime,'$networkAttempted = false')&&str_contains($runtime,'$networkAttempted = true'),'Post-ACK provisioning must bound Graph work to one unresolved key per webhook.');
commerce_hard_expect(str_contains($runtime,'$mtime <= $now - 900'),'Commerce provisioning backoff must remain 15 minutes.');
commerce_hard_expect(str_contains($runtime,'hache_sharky_commerce_payment_binding_matches'),'Commerce processor must validate bound payment selectors before acting.');
commerce_hard_expect(str_contains($runtime,"'payment_method_stale'"),'Stale payment selectors must fail closed with an explicit decision.');
commerce_hard_expect(str_contains($runtime,"return trim((string)(\$event['interactive_id'] ?? '')) !== '' ? null : false;"),'Only explicit legacy fallback buttons may bypass the new registration binding; unbound Flow replies must fail closed.');

$worker=file_get_contents(__DIR__.'/../bin/sharky-inbox-dispatch.php')?:'';
$candidatePos=strpos($worker,'hache_sharky_commerce_event_candidate($event)');
$commercePos=strpos($worker,'hache_sharky_commerce_process_event($pdo,$event',$candidatePos===false?0:$candidatePos);
$normalPos=strpos($worker,'hache_sharky_lab_process_event($pdo,$event',$candidatePos===false?0:$candidatePos);
commerce_hard_expect($candidatePos!==false&&$commercePos!==false&&$normalPos!==false&&$candidatePos<$commercePos&&$commercePos<$normalPos,'Recovered commerce events must re-enter the commerce lane before normal Sharky processing.');

$groups=file_get_contents(__DIR__.'/../config/sharky-groups.php')?:'';
$finalStart=strpos($groups,'function hache_sharky_groups_finalize_outbound');
$upgradePos=strpos($groups,'hache_sharky_commerce_upgrade_direct_payload($payload)',$finalStart===false?0:$finalStart);
$preparePos=strpos($groups,'hache_sharky_commerce_prepare_payload($payload)',$upgradePos===false?0:$upgradePos);
$finalizePos=strpos($groups,'hache_sharky_commerce_finalize_payload($payload)',$preparePos===false?0:$preparePos);
commerce_hard_expect($finalStart!==false&&$upgradePos!==false&&$preparePos!==false&&$finalizePos!==false&&$finalStart<$upgradePos&&$upgradePos<$preparePos&&$preparePos<$finalizePos,'Late registration-success upgrade must bind the first payment selector before its final network cleanup.');

$paymentReminder=file_get_contents(__DIR__.'/../config/sharky-payment-reminder.php')?:'';
$mpPayloadPos=strpos($paymentReminder,'hache_sharky_mp_followup_payload');
$mpPreparePos=strpos($paymentReminder,'hache_sharky_commerce_prepare_payload($payload)',$mpPayloadPos===false?0:$mpPayloadPos);
$mpMetaPos=strpos($paymentReminder,"\$payload['_sharky_payment_reminder'] = \$reminderMeta",$mpPreparePos===false?0:$mpPreparePos);
commerce_hard_expect($mpPayloadPos!==false&&$mpPreparePos!==false&&$mpMetaPos!==false&&$mpPayloadPos<$mpPreparePos&&$mpPreparePos<$mpMetaPos,'The delayed 15-minute Mercado Pago recovery selector must be bound before its encrypted outbox metadata is attached.');

$webhook=file_get_contents(__DIR__.'/../public/api/whatsapp-orchestrator-lab.php')?:'';
commerce_hard_expect(str_contains($webhook,'hache_sharky_commerce_flows_prime_throttled'),'Realtime webhook must use throttled commerce Flow provisioning.');
commerce_hard_expect(!str_contains($webhook,'hache_sharky_commerce_flows_prime($payload'),'Realtime webhook must not call the unthrottled commerce provisioner directly.');
$ackPos=strpos($webhook,'http_response_code(200)');
$dispatchPos=strpos($webhook,"hache_sharky_outbox_dispatch(\$pdo,'hache_sharky_lab_send',20)");
$primePos=strpos($webhook,'hache_sharky_commerce_flows_prime_throttled');
commerce_hard_expect($ackPos!==false&&$dispatchPos!==false&&$primePos!==false&&$ackPos<$dispatchPos&&$dispatchPos<$primePos,'Commerce provisioning must run only after ACK, current-turn processing and current outbox dispatch.');

echo "OK sharky commerce hardening regression\n";
