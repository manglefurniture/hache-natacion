<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-lab-worker.php';

function pr162_review_ok(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"SHARKY PR162 REVIEW FAIL: $message\n");exit(1);}
}

$now=1788883200;
$pdo=new PDO('sqlite::memory:');

// P1: first-turn product/location wording remains entry context, never a confirmed choice.
$fresh=hache_sharky_orchestrator_state(null,$now);
$fresh['identity']=array_replace($fresh['identity'],[
    'kind'=>'prospect','verified'=>false,'source'=>'whatsapp_unmatched',
]);
$firstText="Quiero clases regulares\nMonteverde";
$guided=hache_sharky_entry_guided_first_prospect($fresh,$firstText,$now);
pr162_review_ok(($guided['flow']['name']??null)==='prospect_onboarding'&&($guided['flow']['step']??null)==='name','Fresh unmatched prospect must start profile-first onboarding at name.');
pr162_review_ok(($guided['flow']['data']['entry_bootstrap']??false)===true,'First-turn onboarding must be explicitly marked as entry bootstrap.');
pr162_review_ok(($guided['commercial_context']['entry_interest']??null)==='regular','Entry interest must remain durable discovery context.');
pr162_review_ok(empty($guided['commercial_context']['program']),'Entry interest must not become a confirmed program before qualification.');
pr162_review_ok(empty($guided['commercial_context']['sede_clave']),'First-turn venue wording must not skip the neutral identity onboarding.');

[$firstHandled,$firstDecision]=hache_sharky_prospect_onboarding_handle($pdo,$guided,[
    'from'=>'529981112233','text'=>$firstText,'interactive_id'=>'',
],$now+1,12);
pr162_review_ok(($firstHandled['flow']['step']??null)==='name','The original entry message must not be parsed as a name or venue selection.');
pr162_review_ok(str_contains((string)($firstDecision['message']??''),'¿me puedes decir tu nombre?'),'Profile-first bootstrap must ask for the prospect name before commercial choices.');

// P2: click-to-WhatsApp referral must be attached before entry context is derived.
$webhook=(string)file_get_contents(__DIR__.'/../public/api/whatsapp-orchestrator-lab.php');
$referralPos=strpos($webhook,'$referral=hache_sharky_orchestrator_referral($event,$now);');
$capturePos=strpos($webhook,'$state=hache_sharky_orchestrator_capture_referral($state,$referral);',$referralPos===false?0:$referralPos);
$bootstrapPos=strpos($webhook,'$state=hache_sharky_entry_guided_first_prospect($state,(string)($event[\'text\']??\'\'),$now);',$capturePos===false?0:$capturePos);
pr162_review_ok($referralPos!==false&&$capturePos!==false&&$bootstrapPos!==false&&$referralPos<$capturePos&&$capturePos<$bootstrapPos,'Webhook must capture referral into state before onboarding derives source/interest.');

$metaEvent=[
    'referral'=>[
        'source_type'=>'ad','source_id'=>'1200000001','headline'=>'Intensivo septiembre',
        'body'=>'Aprende a nadar','ctwa_clid'=>'clid-pr162',
    ],
];
$metaState=$fresh;
$ref=hache_sharky_orchestrator_referral($metaEvent,$now);
pr162_review_ok(is_array($ref),'Meta referral fixture must normalize successfully.');
$metaState=hache_sharky_orchestrator_capture_referral($metaState,$ref);
$metaGuided=hache_sharky_entry_guided_first_prospect($metaState,'Hola',$now);
pr162_review_ok(($metaGuided['commercial_context']['entry_source']??null)==='meta_ad','First CTWA turn must retain Meta-ad source.');
pr162_review_ok(($metaGuided['commercial_context']['entry_interest']??null)==='intensive','Current Meta ad must retain intensive entry interest.');
pr162_review_ok(empty($metaGuided['commercial_context']['program']),'Referral interest must not become canonical program by itself.');
pr162_review_ok(hache_sharky_entry_intro($metaGuided,'Hola')==='Hola, soy Sharky, asistente IA de Hache Natación.','Even an intensive-ad prospect must receive the neutral first onboarding presentation.');

// Regression: arriving from an intensive ad is not the same as choosing intensive.
// An intermediate swimmer with previous classes still resolves to regular.
[$metaAskName]=hache_sharky_prospect_onboarding_handle($pdo,$metaGuided,['from'=>'529981112233','text'=>'Hola','interactive_id'=>''],$now+1,12);
[$metaNamed]=hache_sharky_prospect_onboarding_handle($pdo,$metaAskName,['from'=>'529981112233','text'=>'Roberto','interactive_id'=>''],$now+2,12);
[$metaSelf]=hache_sharky_prospect_onboarding_handle($pdo,$metaNamed,['from'=>'529981112233','text'=>'Sí','interactive_id'=>'onboarding:self:yes'],$now+3,12);
[$metaAge]=hache_sharky_prospect_onboarding_handle($pdo,$metaSelf,['from'=>'529981112233','text'=>'35','interactive_id'=>''],$now+4,12);
[$metaIntermediate,$metaBackgroundDecision]=hache_sharky_prospect_onboarding_handle($pdo,$metaAge,['from'=>'529981112233','text'=>'Intermedio','interactive_id'=>'onboarding:level:intermediate'],$now+5,12);
pr162_review_ok(($metaIntermediate['flow']['step']??null)==='intermediate_background','Intermediate swimmer from an intensive ad must still reach the previous-classes check.');
pr162_review_ok(array_column($metaBackgroundDecision['ui']['buttons']??[],'id')===['onboarding:background:yes','onboarding:background:no'],'Previous-classes question must retain both guided choices.');
[$metaFormal,$metaFormalDecision]=hache_sharky_prospect_onboarding_handle($pdo,$metaIntermediate,[
    'from'=>'529981112233','text'=>'Sí','interactive_id'=>'onboarding:background:yes',
],$now+6,12);
pr162_review_ok(($metaFormal['commercial_context']['program']??null)==='regular','Previous classes must route intermediate to regular, never inherit the intensive ad program.');
pr162_review_ok(($metaFormal['commercial_context']['background']??null)==='formal','Confirmed training history must persist as durable commercial context.');
pr162_review_ok(($metaFormal['flow']['step']??null)==='product_info','Resolved regular product must show its information gate before venue selection.');
pr162_review_ok(array_column($metaFormalDecision['ui']['buttons']??[],'id')===['onboarding:info:regular'],'Regular resolution must expose only its information button first.');

// P2: a course button that went stale must invalidate the old selected course and
// refresh the course controls; it must never expose enrollment for the old date.
$staleState=hache_sharky_orchestrator_state(null,$now);
$staleState['identity']=array_replace($staleState['identity'],['kind'=>'prospect','verified'=>false,'source'=>'whatsapp_unmatched']);
$staleState['commercial_context']=array_replace($staleState['commercial_context'],[
    'program'=>'intensive','sede_clave'=>'MONTEVERDE',
    'schedule_id'=>'h8','schedule_label'=>'08:00–09:00',
    'course_id'=>'old14','fecha_inicio'=>'2026-09-14','course_price'=>1200.0,
    '_requested_slot'=>'course',
]);
$currentCourses=[
    ['id'=>'c21','sede_clave'=>'MONTEVERDE','fecha_inicio'=>'2026-09-21','precio'=>1200,'schedules'=>[['id'=>'h8','label'=>'08:00–09:00']]],
    ['id'=>'c28','sede_clave'=>'MONTEVERDE','fecha_inicio'=>'2026-09-28','precio'=>1200,'schedules'=>[['id'=>'h8','label'=>'08:00–09:00']]],
];
$staleResult=hache_sharky_commercial_interactive_input($pdo,$staleState,[
    'text'=>'Lun 5 oct','interactive_id'=>'action:commercial:course:gone05',
],[
    'today'=>'2026-09-08','intensive_options'=>$currentCourses,'min_age'=>12,
]);
pr162_review_ok(is_array($staleResult),'Stale requested-course tap must receive a deterministic response.');
[$afterStale,$staleDecision]=$staleResult;
pr162_review_ok(empty($afterStale['commercial_context']['course_id'])&&empty($afterStale['commercial_context']['fecha_inicio']),'Stale requested-course tap must invalidate the previously selected course/date.');
pr162_review_ok(($afterStale['commercial_context']['_requested_slot']??null)==='course','Stale requested-course tap must remain on course selection until a current option succeeds.');
$staleIds=array_column($staleDecision['ui']['buttons']??[],'id');
pr162_review_ok($staleIds===['action:commercial:course:c21','action:commercial:course:c28'],'Stale course response must refresh only currently valid backend course buttons.');
pr162_review_ok(!in_array('action:register_intensive',$staleIds,true),'Stale course response must never offer enrollment for the old date.');

fwrite(STDOUT,"SHARKY_PR162_REVIEW_OK\n");
