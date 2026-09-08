<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-orchestrator.php';
require_once __DIR__.'/../config/sharky-payment-reminder.php';
require_once __DIR__.'/../config/sharky-commerce-runtime.php';
require_once __DIR__.'/sharky-enrollment-after-reserve-regression.php';

function commerce_expect(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

function commerce_json(string $name): array
{
    $path=__DIR__.'/../config/whatsapp-flows/'.$name;
    $data=json_decode((string)file_get_contents($path),true);
    commerce_expect(is_array($data),'Flow JSON must decode: '.$name);
    return $data;
}

$enrollment=commerce_json('enrollment-v1.json');
$method=commerce_json('payment-method-v1.json');
$transfer=commerce_json('payment-transfer-v1.json');
$card=commerce_json('payment-card-v1.json');

commerce_expect(($enrollment['version']??'')==='7.0','Enrollment Flow must use Flow JSON 7.0.');
commerce_expect(($enrollment['screens'][0]['id']??'')==='ENROLLMENT','Enrollment Flow must start on ENROLLMENT.');
$enrollmentText=json_encode($enrollment,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'';
commerce_expect(str_contains($enrollmentText,'Sede fija:'),'Enrollment must show the venue as fixed context.');
commerce_expect(str_contains($enrollmentText,'"name":"full_name"'),'Enrollment must collect full name.');
commerce_expect(str_contains($enrollmentText,'"type":"DatePicker"')&&str_contains($enrollmentText,'"name":"birthdate"'),'Enrollment must collect birthdate with DatePicker.');
commerce_expect(str_contains($enrollmentText,'"name":"course_id"')&&str_contains($enrollmentText,'"name":"schedule_id"'),'Enrollment must collect start date and schedule.');
commerce_expect(str_contains($enrollmentText,'"user_action":"cancel"')&&str_contains($enrollmentText,'"user_action":"submit"'),'Enrollment must expose explicit cancel and submit outcomes.');
commerce_expect(!str_contains($enrollmentText,'"name":"venue"')&&!str_contains($enrollmentText,'"name":"sede"'),'Enrollment must not offer a second venue selector.');

$methodOptions=$method['screens'][0]['data']['methods']['__example__']??[];
commerce_expect(array_column($methodOptions,'id')===['transfer','card','cash'],'Payment Flow must order SPEI, card, cash.');
commerce_expect(str_contains((string)($methodOptions[0]['description']??''),'sin recargo'),'SPEI must be presented as no-surcharge/recommended.');
commerce_expect(str_contains((string)($methodOptions[1]['description']??''),'+5%'),'Card example must disclose the configured 5% surcharge.');

foreach([['flow'=>$transfer,'method'=>'transfer'],['flow'=>$card,'method'=>'card']] as $case){
    $encoded=json_encode($case['flow'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'';
    commerce_expect(str_contains($encoded,'"type":"PhotoPicker"'),'Payment proof Flow must use PhotoPicker.');
    commerce_expect(str_contains($encoded,'"min-uploaded-photos":1'),'Payment proof must require at least one photo.');
    commerce_expect(str_contains($encoded,'"max-uploaded-photos":1'),'Payment proof must cap proof at one photo.');
    commerce_expect(str_contains($encoded,'"flow_kind":"payment_proof"'),'Proof completion must identify the commerce kind.');
    commerce_expect(str_contains($encoded,'"method":"'.$case['method'].'"'),'Proof completion must bind the chosen method.');
    commerce_expect(str_contains($encoded,'"media":"${form.proof}"'),'PhotoPicker media must be returned at the complete payload top level.');
}
$cardText=json_encode($card,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'';
commerce_expect(str_contains($cardText,'"name":"open_url"')&&str_contains($cardText,'${data.payment_url}'),'Card Flow must open the dynamic Mercado Pago URL.');

commerce_expect(hache_sharky_mp_card_total(1200.0,5.0)===1260.0,'Card total must apply +5% exactly.');
$captured=null;
$credentialResolver=static fn():array=>[
    'active'=>true,'environment'=>'PRODUCTION','access_token'=>'secret-token','source'=>'test',
];
$requester=static function(string $verb,string $path,string $token,?array $payload=null) use (&$captured): ?array {
    $captured=['verb'=>$verb,'path'=>$path,'token'=>$token,'payload'=>$payload];
    return ['id'=>'pref-123','init_point'=>'https://www.mercadopago.com.mx/checkout/v1/redirect?pref=pref-123'];
};
$preference=hache_sharky_mp_create_preference([
    'student_id'=>'student-1','course_id'=>'course-1','price'=>1200,
],['sharky_recargo_tarjeta_pct'=>'5'],$credentialResolver,$requester);
commerce_expect(($preference['ok']??false)===true,'Mercado Pago preference must succeed through injected gateway.');
commerce_expect(($preference['total']??0.0)===1260.0,'Mercado Pago preference must charge 1260 for a 1200 course at +5%.');
commerce_expect(($captured['verb']??'')==='POST'&&($captured['path']??'')==='/checkout/preferences','Card payment must use Mercado Pago Preferences API.');
commerce_expect((float)($captured['payload']['items'][0]['unit_price']??0)===1260.0,'Preference unit price must include surcharge.');
commerce_expect(str_starts_with((string)($captured['payload']['external_reference']??''),'sharky:'),'Preference must use a Sharky-scoped external reference.');
commerce_expect(!str_contains(json_encode($preference)?:'','secret-token'),'Access token must never escape in the preference result.');

$statusCredential=static fn():array=>['active'=>true,'environment'=>'PRODUCTION','access_token'=>'status-token'];
$approved=hache_sharky_mp_status_by_external_reference('sharky:test',$statusCredential,
    static fn(string $verb,string $path,string $token,?array $payload=null):array=>['results'=>[['status'=>'approved']]]);
$pending=hache_sharky_mp_status_by_external_reference('sharky:test',$statusCredential,
    static fn(string $verb,string $path,string $token,?array $payload=null):array=>['results'=>[['status'=>'pending']]]);
$missing=hache_sharky_mp_status_by_external_reference('sharky:test',$statusCredential,
    static fn(string $verb,string $path,string $token,?array $payload=null):array=>['results'=>[]]);
commerce_expect(($approved['state']??'')==='approved','Approved Mercado Pago status must suppress recovery.');
commerce_expect(($pending['state']??'')==='pending','Pending Mercado Pago status must defer recovery.');
commerce_expect(($missing['state']??'')==='not_found','No Mercado Pago payment must be distinguishable from pending.');

function commerce_meta_payload(array $response,string $id='wamid.flow'): array
{
    return ['entry'=>[[
        'id'=>'1234567890',
        'changes'=>[[
            'value'=>[
                'metadata'=>['phone_number_id'=>'phone-1'],
                'messages'=>[[
                    'id'=>$id,'from'=>'529981473867','timestamp'=>'1788780000','type'=>'interactive',
                    'interactive'=>['type'=>'nfm_reply','nfm_reply'=>['response_json'=>json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]],
                ]],
            ],
        ]],
    ]]];
}

$enrollmentPayload=commerce_meta_payload([
    'flow_kind'=>'enrollment','user_action'=>'submit','venue_key'=>'PALAPAS',
    'full_name'=>'Juan Pérez','birthdate'=>'1984-02-07',
    'course_id'=>'date:palapas:2026-09-14','schedule_id'=>'schedule-1',
]);
$enrollmentEvents=hache_sharky_commerce_flow_extract_events($enrollmentPayload);
commerce_expect(count($enrollmentEvents)===1&&($enrollmentEvents[0]['kind']??'')===HACHE_SHARKY_COMMERCE_FLOW_KIND,'Enrollment nfm_reply must normalize to one commerce event.');
commerce_expect(hache_sharky_whatsapp_birthdate_flow_extract_events($enrollmentPayload,'2026-09-07')===[],'Commerce nfm_reply must never duplicate as legacy birthdate Flow input.');

$methodEvents=hache_sharky_commerce_flow_extract_events(commerce_meta_payload([
    'flow_kind'=>'payment_method','method'=>'transfer',
],'wamid.method'));
commerce_expect(count($methodEvents)===1&&($methodEvents[0]['commerce']['method']??'')==='transfer','Payment-method Flow must normalize selected SPEI method.');

$proofEvents=hache_sharky_commerce_flow_extract_events(commerce_meta_payload([
    'flow_kind'=>'payment_proof','method'=>'card','student_id'=>'student-1','course_id'=>'course-1',
    'media'=>[['id'=>'meta-media-reference']],
],'wamid.proof'));
commerce_expect(count($proofEvents)===1&&($proofEvents[0]['kind']??'')===HACHE_SHARKY_PAYMENT_PROOF_KIND,'PhotoPicker reply must become durable payment-proof evidence.');
commerce_expect(is_array($proofEvents[0]['commerce']['media']??null),'Flow proof must retain only the structured media reference inside encrypted inbox payload.');
$badProof=hache_sharky_commerce_flow_extract_events(commerce_meta_payload([
    'flow_kind'=>'payment_proof','method'=>'card','student_id'=>'student-1','course_id'=>'course-1','media'=>[],
],'wamid.bad-proof'));
commerce_expect($badProof===[],'Proof Flow without media must fail closed.');

$prepared=hache_sharky_commerce_prepare_payload([
    'messaging_product'=>'whatsapp','to'=>'529981473867','type'=>'text','text'=>['body'=>'card'],
    '_sharky_payment_session'=>['stage'=>'method'],
    '_sharky_mp_followup_arm'=>[
        'token'=>'token-card','student_id'=>'student-1','course_id'=>'course-1',
        'external_reference'=>'sharky:ref','preference_id'=>'pref-1','watch_from'=>1788780000,
    ],
]);
commerce_expect(!isset($prepared['_sharky_mp_followup_arm'])&&!isset($prepared['_sharky_payment_session']),'Commerce-only internal markers must not reach Meta.');
commerce_expect(($prepared['_sharky_payment_reminder_arm']['mode']??'')==='mp_card','Card follow-up must reuse the established payment-reminder scheduler.');
commerce_expect(($prepared['_sharky_payment_reminder_arm']['external_reference']??'')==='sharky:ref','Card reminder must retain only the MP correlation reference, not credentials.');

$now=1788780000;
$state=hache_sharky_orchestrator_state(null,$now);
$state['identity']['kind']='prospect';$state['identity']['verified']=true;
$state['commercial_context']['program']='intensive';$state['commercial_context']['sede_clave']='PALAPAS';$state['commercial_context']['age']=42;
$state=hache_sharky_orchestrator_flow($state,'register_intensive','course',['sede_clave'=>'PALAPAS'],$now);
$options=[[
    'id'=>'date:palapas:2026-09-14','sede_clave'=>'PALAPAS','fecha_inicio'=>'2026-09-14','precio'=>1200,
    'schedules'=>[['id'=>'schedule-1','label'=>'08:00–09:00']],
]];
[$afterEnrollment,$enrollmentDecision]=hache_sharky_commerce_enrollment_submit($state,[
    'from'=>'529981473867',
    'commerce'=>[
        'flow_kind'=>'enrollment','user_action'=>'submit','venue_key'=>'PALAPAS','full_name'=>'Juan Pérez',
        'birthdate'=>'1984-02-07','course_id'=>'date:palapas:2026-09-14','schedule_id'=>'schedule-1',
    ],
],['now'=>$now+1,'today'=>'2026-09-07','min_age'=>12,'intensive_options'=>$options]);
commerce_expect(($enrollmentDecision['kind']??'')==='registration_confirm','Enrollment Flow must land on the existing deterministic confirmation step.');
commerce_expect(($afterEnrollment['flow']['name']??'')==='register_intensive'&&($afterEnrollment['flow']['step']??'')==='confirm','Enrollment Flow must not create a parallel registration state machine.');
commerce_expect(($afterEnrollment['flow']['data']['birthdate']??'')==='1984-02-07','Validated Flow birthdate must remain normalized ISO.');

$confirmed=hache_sharky_orchestrate($afterEnrollment,[
    'id'=>'commerce.confirm','from'=>'529981473867','text'=>'Sí','interactive_id'=>'flow:confirm',
],['now'=>$now+2,'today'=>'2026-09-07','min_age'=>12,'intensive_options'=>$options]);
commerce_expect(($confirmed['decision']['kind']??'')==='registration_execute','Final Flow confirmation must reuse registration_execute.');
commerce_expect(($confirmed['decision']['action']['type']??'')==='register_intensive','Only the existing register_intensive action may create the registration.');
commerce_expect(($confirmed['decision']['action']['requires_revalidation']??false)===true,'Flow registration must still require transactional revalidation.');

$stale=$state;
[$staleState,$staleDecision]=hache_sharky_commerce_enrollment_submit($stale,[
    'from'=>'529981473867','commerce'=>[
        'flow_kind'=>'enrollment','user_action'=>'submit','venue_key'=>'PALAPAS','full_name'=>'Juan Pérez',
        'birthdate'=>'1984-02-07','course_id'=>'date:palapas:2026-09-14','schedule_id'=>'stale-schedule',
    ],
],['now'=>$now+3,'today'=>'2026-09-07','min_age'=>12,'intensive_options'=>$options]);
commerce_expect(($staleDecision['kind']??'')==='registration_course_invalid','Stale Flow schedule must fail closed before any business action.');
commerce_expect(!is_array($staleDecision['action']??null)||($staleDecision['action']['type']??'')==='refresh_intensive_options','Stale Flow must never execute registration.');

$paymentSource=file_get_contents(__DIR__.'/../config/sharky-payment-reminder.php')?:'';
commerce_expect(str_contains($paymentSource,"\$mode === 'mp_card'"),'Payment reminder must have an explicit Mercado Pago mode.');
commerce_expect(str_contains($paymentSource,"HACHE_SHARKY_MP_CARD_FOLLOWUP_SECONDS")&&str_contains($paymentSource,'900'),'Card follow-up contract must remain 15 minutes.');
commerce_expect(str_contains($paymentSource,'hache_sharky_mp_status_by_external_reference'),'Card follow-up must query Mercado Pago before messaging.');
commerce_expect(str_contains($paymentSource,"'MP_APPROVED'"),'Approved Mercado Pago payment must cancel the follow-up.');
commerce_expect(str_contains($paymentSource,"'MP_PENDING'")&&str_contains($paymentSource,'reschedule_at'),'Pending Mercado Pago payment must reschedule instead of nagging the user.');
commerce_expect(!str_contains($paymentSource,'curl_'),'Reminder policy itself must not implement a second payment/media network stack.');

$runtimeSource=file_get_contents(__DIR__.'/../config/sharky-commerce-runtime.php')?:'';
commerce_expect(str_contains($runtimeSource,"str_starts_with(\$id, 'commerce:pay:')"),'Fallback payment buttons must use the protected commerce lane.');
commerce_expect(str_contains($runtimeSource,'hache_sharky_lab_queue_and_complete'),'Commerce replies must reuse the durable state+outbox+receipt boundary.');
commerce_expect(str_contains($runtimeSource,'hache_sharky_takeover_mark'),'Cash/handoff must persist takeover before delivery.');

$webhook=file_get_contents(__DIR__.'/../public/api/whatsapp-orchestrator-lab.php')?:'';
$extractPos=strpos($webhook,'hache_sharky_commerce_flow_extract_events');
$persistPos=strpos($webhook,'hache_sharky_inbox_store');
$ackPos=strpos($webhook,'http_response_code(200)');
commerce_expect($extractPos!==false&&$persistPos!==false&&$ackPos!==false&&$extractPos<$persistPos&&$persistPos<$ackPos,'Commerce Flow replies must be durably persisted before webhook ACK.');
$primePos=strpos($webhook,'hache_sharky_commerce_flows_prime');
commerce_expect($primePos!==false&&$primePos>$ackPos,'Commerce Flow provisioning must remain after webhook ACK.');
$routePos=strpos($webhook,'if(hache_sharky_commerce_event_candidate($event))');
$normalPos=strpos($webhook,'hache_sharky_lab_process_event($pdo,$event',$routePos===false?0:$routePos);
commerce_expect($routePos!==false&&$normalPos!==false&&$routePos<$normalPos,'Commerce Flow replies must be routed before the normal prospect/member worker.');

$groups=file_get_contents(__DIR__.'/../config/sharky-groups.php')?:'';
$preparePos=strpos($groups,'function hache_sharky_groups_prepare_outbound');
$finalPos=strpos($groups,'function hache_sharky_groups_finalize_outbound');
$upgradePos=strpos($groups,'hache_sharky_commerce_upgrade_direct_payload',$finalPos===false?0:$finalPos);
commerce_expect($preparePos!==false&&$finalPos!==false&&$upgradePos!==false&&$upgradePos>$finalPos,'Enrollment/payment Flow upgrade must run only after durable state commit, at final send time.');
commerce_expect(str_contains($groups,'WhatsApp Flows are never emitted into group chats'),'Group traffic must stay outside commerce Flows.');

fwrite(STDOUT,"SHARKY_COMMERCE_FLOWS_OK\n");
