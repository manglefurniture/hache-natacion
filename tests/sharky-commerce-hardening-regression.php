<?php

declare(strict_types=1);

putenv('SHARKY_CONTACT_HASH_KEY='.str_repeat('k',64));

require_once __DIR__.'/../config/sharky-whatsapp-adapter.php';
require_once __DIR__.'/../config/sharky-commerce-runtime.php';
require_once __DIR__.'/../config/sharky-groups.php';

function commerce_hard_expect(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

function commerce_hard_assert_dynamic_text_is_pure(mixed $node,string $path='root'): void
{
    if(!is_array($node))return;
    foreach($node as $key=>$value){
        $next=$path.'.'.(string)$key;
        if($key==='text'&&is_string($value)&&str_contains($value,'${')){
            commerce_hard_expect(
                preg_match('/^\$\{(?:data|form)\.[A-Za-z0-9_]+\}$/',$value)===1,
                'WhatsApp Flow display text must not mix literals with dynamic expressions at '.$next.': '.$value
            );
        }
        if(is_array($value))commerce_hard_assert_dynamic_text_is_pure($value,$next);
    }
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

// Every Flow display expression must stand alone. WhatsApp renders mixed literal
// text such as "Sede: ${data.venue_label}" literally instead of interpolating it.
$flowFiles=['enrollment-v1.json','payment-method-v1.json','payment-transfer-v1.json','payment-card-v1.json'];
$decodedFlows=[];
foreach($flowFiles as $file){
    $decoded=json_decode((string)file_get_contents(__DIR__.'/../config/whatsapp-flows/'.$file),true);
    commerce_hard_expect(is_array($decoded),'Flow JSON must decode: '.$file);
    commerce_hard_assert_dynamic_text_is_pure($decoded,$file);
    $decodedFlows[$file]=$decoded;
}
$enrollmentText=json_encode($decodedFlows['enrollment-v1.json'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'';
commerce_hard_expect(str_contains($enrollmentText,'"text":"Sede fija"')&&str_contains($enrollmentText,'"text":"${data.venue_label}"'),'Enrollment venue label and dynamic value must be separate components.');
commerce_hard_expect(!str_contains($enrollmentText,'Sede fija: ${data.venue_label}')&&!str_contains($enrollmentText,'Sede: ${data.venue_label}')&&!str_contains($enrollmentText,'Nombre: ${data.full_name}')&&!str_contains($enrollmentText,'Nacimiento: ${data.birthdate}'),'Enrollment Flow must not contain mixed literal/dynamic display strings.');
$transferText=json_encode($decodedFlows['payment-transfer-v1.json'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'';
commerce_hard_expect(str_contains($transferText,'"text":"Importe"')&&str_contains($transferText,'"text":"${data.amount_label}"'),'SPEI amount label and dynamic value must be separate components.');
commerce_hard_expect(!str_contains($transferText,'Importe: ${data.amount_label}')&&!str_contains($transferText,'Institución: ${data.institution}')&&!str_contains($transferText,'Beneficiario: ${data.beneficiary}'),'SPEI Flow must not contain mixed literal/dynamic display strings.');
$cardText=json_encode($decodedFlows['payment-card-v1.json'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'';
commerce_hard_expect(str_contains($cardText,'"text":"Total con tarjeta"')&&str_contains($cardText,'"text":"${data.amount_label}"'),'Card total label and dynamic value must be separate components.');
commerce_hard_expect(!str_contains($cardText,'Total con tarjeta: ${data.amount_label}'),'Card Flow must not contain the literal placeholder pattern seen in production.');

// Payment-method Flow must round-trip the exact registration binding.
$method=$decodedFlows['payment-method-v1.json'];
$data=$method['screens'][0]['data']??[];
commerce_hard_expect(isset($data['student_id'],$data['course_id']),'Payment-method Flow must declare student/course binding data.');
$methodText=json_encode($method,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'';
commerce_hard_expect(str_contains($methodText,'"student_id":"${data.student_id}"')&&str_contains($methodText,'"course_id":"${data.course_id}"'),'Payment-method completion must return the registration binding.');

// Corrected published resources are versioned independently from the already-live v1.
$v2Specs=hache_sharky_commerce_flow_v2_specs();
commerce_hard_expect(count($v2Specs)===4,'Commerce Flow v2 must cover all four commerce resources.');
foreach($v2Specs as $key=>$spec){
    commerce_hard_expect(str_ends_with((string)($spec['name']??''),'_v2'),'Corrected Flow resource must use a v2 Meta name for '.$key.'.');
    commerce_hard_expect(str_contains(hache_sharky_commerce_flow_v2_cache_path((string)$key),'commerce-v2-'),'Corrected Flow cache must be versioned for '.$key.'.');
}
$v2Runtime=file_get_contents(__DIR__.'/../config/sharky-commerce-flow-v2.php')?:'';
commerce_hard_expect(str_contains($v2Runtime,'hache_sharky_commerce_flow_v2_drop_legacy_cache'),'v2 bootstrap must remove legacy runtime IDs before fallback/new provisioning.');
commerce_hard_expect(str_contains($v2Runtime,'$networkAttempted=false'),'v2 provisioning must remain bounded to one unresolved Graph attempt per webhook.');
commerce_hard_expect(str_contains($v2Runtime,'$mtime<=$now-900'),'v2 provisioning failures must back off for 15 minutes.');

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

// Only the native intensive course/date list may be upgraded to the enrollment Flow.
$courseList=[
    'messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>'529981473867','type'=>'interactive',
    'interactive'=>['type'=>'list','body'=>['text'=>'Elige fecha'],'action'=>['sections'=>[['rows'=>[
        ['id'=>'course:course-pal-1','title'=>'14/09/2026'],
        ['id'=>'course:course-pal-2','title'=>'21/09/2026'],
    ]]]]],
];
commerce_hard_expect(hache_sharky_groups_direct_commerce_upgrade_allowed($courseList),'Native course:* list must remain eligible for enrollment Flow upgrade.');
$sideList=$courseList;
$sideList['interactive']['action']['sections'][0]['rows'][0]['id']='sede:PALAPAS';
commerce_hard_expect(!hache_sharky_groups_direct_commerce_upgrade_allowed($sideList),'A side-question/menu list must never be converted into the enrollment Flow.');
$mixedList=$courseList;
$mixedList['interactive']['action']['sections'][0]['rows'][]=['id'=>'menu:prices','title'=>'Precios'];
commerce_hard_expect(!hache_sharky_groups_direct_commerce_upgrade_allowed($mixedList),'Mixed lists must fail closed instead of partially masquerading as a course list.');
$emptyList=$courseList;
$emptyList['interactive']['action']['sections'][0]['rows']=[];
commerce_hard_expect(!hache_sharky_groups_direct_commerce_upgrade_allowed($emptyList),'Empty lists must not trigger enrollment Flow upgrade.');

// Tienda Natación root detection is structural, not a blind absolute-path assumption.
$tmpRoot=sys_get_temp_dir().'/hache-sharky-store-root-'.bin2hex(random_bytes(4));
@mkdir($tmpRoot.'/src',0700,true);
file_put_contents($tmpRoot.'/.env',"DB_HOST=127.0.0.1\nDB_DATABASE=hache_tienda\n");
file_put_contents($tmpRoot.'/src/PaymentCredentialCipher.php',"<?php\n");
file_put_contents($tmpRoot.'/src/PaymentGatewayConfig.php',"<?php\n");
commerce_hard_expect(hache_sharky_mp_store_root_valid($tmpRoot),'A store root with .env plus both gateway classes must be accepted.');
@unlink($tmpRoot.'/src/PaymentGatewayConfig.php');
commerce_hard_expect(!hache_sharky_mp_store_root_valid($tmpRoot),'A partial Tienda checkout tree must be rejected.');
@unlink($tmpRoot.'/src/PaymentCredentialCipher.php');
@unlink($tmpRoot.'/.env');
@rmdir($tmpRoot.'/src');
@rmdir($tmpRoot);

$runtime=file_get_contents(__DIR__.'/../config/sharky-commerce-runtime.php')?:'';
commerce_hard_expect(str_contains($runtime,'$networkAttempted = false')&&str_contains($runtime,'$networkAttempted = true'),'Legacy commerce provisioner must remain bounded if invoked outside realtime webhook.');
commerce_hard_expect(str_contains($runtime,'$mtime <= $now - 900'),'Legacy commerce provisioning backoff must remain 15 minutes.');
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
commerce_hard_expect(str_contains($groups,"str_starts_with(\$id,'course:')"),'Enrollment upgrade gate must remain scoped to native course:* list rows.');
commerce_hard_expect(str_contains($groups,"require_once __DIR__.'/sharky-commerce-flow-v2.php'")&&str_contains($groups,'hache_sharky_commerce_flow_v2_bootstrap();'),'All outbound senders must bootstrap corrected v2 Flow IDs through the shared groups layer.');

$mpRuntime=file_get_contents(__DIR__.'/../config/sharky-mercadopago.php')?:'';
commerce_hard_expect(str_contains($mpRuntime,'function hache_sharky_mp_store_root_valid'),'Mercado Pago bridge must validate a Tienda root structurally.');
commerce_hard_expect(str_contains($mpRuntime,"'/var/www/tienda.hnatacion.com'")&&str_contains($mpRuntime,"'/var/www/tienda-natacion'"),'Tienda autodetection must support domain-root and repo-style production layouts.');

$paymentReminder=file_get_contents(__DIR__.'/../config/sharky-payment-reminder.php')?:'';
$mpPayloadPos=strpos($paymentReminder,'hache_sharky_mp_followup_payload');
$mpPreparePos=strpos($paymentReminder,'hache_sharky_commerce_prepare_payload($payload)',$mpPayloadPos===false?0:$mpPayloadPos);
$mpMetaPos=strpos($paymentReminder,"\$payload['_sharky_payment_reminder'] = \$reminderMeta",$mpPreparePos===false?0:$mpPreparePos);
commerce_hard_expect($mpPayloadPos!==false&&$mpPreparePos!==false&&$mpMetaPos!==false&&$mpPayloadPos<$mpPreparePos&&$mpPreparePos<$mpMetaPos,'The delayed 15-minute Mercado Pago recovery selector must be bound before its encrypted outbox metadata is attached.');

$webhook=file_get_contents(__DIR__.'/../public/api/whatsapp-orchestrator-lab.php')?:'';
commerce_hard_expect(str_contains($webhook,'hache_sharky_commerce_flow_v2_prime_throttled'),'Realtime webhook must provision corrected Commerce Flow v2 resources.');
commerce_hard_expect(!str_contains($webhook,'hache_sharky_commerce_flows_prime_throttled($payload'),'Realtime webhook must not recreate/reuse legacy Commerce Flow v1 resources.');
commerce_hard_expect(!str_contains($webhook,'hache_sharky_commerce_flows_prime($payload'),'Realtime webhook must not call the unthrottled legacy commerce provisioner.');
$ackPos=strpos($webhook,'http_response_code(200)');
$dispatchPos=strpos($webhook,"hache_sharky_outbox_dispatch(\$pdo,'hache_sharky_lab_send',20)");
$primePos=strpos($webhook,'hache_sharky_commerce_flow_v2_prime_throttled');
commerce_hard_expect($ackPos!==false&&$dispatchPos!==false&&$primePos!==false&&$ackPos<$dispatchPos&&$dispatchPos<$primePos,'Corrected Commerce v2 provisioning must run only after ACK, current-turn processing and current outbox dispatch.');

echo "OK sharky commerce hardening regression\n";
