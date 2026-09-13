<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-lab-worker.php';

function pr162_review_ok(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"SHARKY PR162 REVIEW FAIL: $message\n");exit(1);}
}

$now=1788883200;
$pdo=new PDO('sqlite::memory:');

// Legacy web/direct onboarding keeps the profile-first behavior introduced by PR162.
$fresh=hache_sharky_orchestrator_state(null,$now);
$fresh['identity']=array_replace($fresh['identity'],[
    'kind'=>'prospect','verified'=>false,'source'=>'whatsapp_unmatched',
]);
$firstText="Quiero clases regulares\nMonteverde";
$guided=hache_sharky_entry_guided_first_prospect($fresh,$firstText,$now);
pr162_review_ok(($guided['flow']['name']??null)==='prospect_onboarding'&&($guided['flow']['step']??null)==='name','Fresh unmatched direct prospect must start profile-first onboarding at name.');
pr162_review_ok(($guided['flow']['data']['entry_bootstrap']??false)===true,'First-turn legacy onboarding must be explicitly marked as entry bootstrap.');
pr162_review_ok(($guided['commercial_context']['entry_interest']??null)==='regular','Direct entry interest must remain durable discovery context.');
pr162_review_ok(empty($guided['commercial_context']['program']),'Direct entry interest must not become a confirmed program before qualification.');
pr162_review_ok(empty($guided['commercial_context']['sede_clave']),'First-turn venue wording must not skip the neutral identity onboarding.');

[$firstHandled,$firstDecision]=hache_sharky_prospect_onboarding_handle($pdo,$guided,[
    'from'=>'529981112233','text'=>$firstText,'interactive_id'=>'',
],$now+1,12);
pr162_review_ok(($firstHandled['flow']['step']??null)==='name','The original direct entry message must not be parsed as a name or venue selection.');
pr162_review_ok(str_contains((string)($firstDecision['message']??''),'¿me puedes decir tu nombre?'),'Profile-first direct bootstrap must ask for the prospect name before commercial choices.');

// Click-to-WhatsApp referral must be attached before entry context is derived.
$webhook=(string)file_get_contents(__DIR__.'/../public/api/whatsapp-orchestrator-lab.php');
$referralPos=strpos($webhook,'$referral=hache_sharky_orchestrator_referral($event,$now);');
$capturePos=strpos($webhook,'$state=hache_sharky_orchestrator_capture_referral($state,$referral);',$referralPos===false?0:$referralPos);
$bootstrapPos=strpos($webhook,'$state=hache_sharky_entry_guided_first_prospect($state,(string)($event[\'text\']??\'\'),$now);',$capturePos===false?0:$capturePos);
pr162_review_ok($referralPos!==false&&$capturePos!==false&&$bootstrapPos!==false&&$referralPos<$capturePos&&$capturePos<$bootstrapPos,'Webhook must capture referral into state before onboarding derives source/interest.');

// Sharky 3.0 intentionally supersedes PR162 only for Meta Ads. Campaign interest
// is retained for attribution but never becomes the selected product.
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
pr162_review_ok(($metaGuided['commercial_context']['entry_interest']??null)==='intensive','Current Meta ad must retain intensive attribution.');
pr162_review_ok(empty($metaGuided['commercial_context']['program']),'Meta referral interest must not become canonical program by itself.');
pr162_review_ok(($metaGuided['flow']['name']??null)==='meta_ad_onboarding'&&($metaGuided['flow']['step']??null)==='program','Meta Ads must enter the dedicated Sharky 3.0 program selector.');
pr162_review_ok(hache_sharky_entry_intro($metaGuided,'Hola')==='','Meta 3.0 renders its own deterministic IA disclosure instead of the legacy presentation.');

$handled=hache_sharky_meta_handle($pdo,$metaGuided,['from'=>'529981112233','text'=>'Hola','interactive_id'=>''],$now+1,['contact'=>'529981112233']);
pr162_review_ok(is_array($handled),'Meta 3.0 bootstrap must produce a deterministic response.');
[$metaWelcome,$metaDecision]=$handled;
pr162_review_ok(($metaDecision['kind']??null)==='meta_program_prompt','Meta first turn must show the approved product selector.');
pr162_review_ok(str_contains((string)($metaDecision['message']??''),'Soy Sharky 🦈, el asistente IA de Hache Natación.'),'Meta first turn must clearly disclose Sharky as an IA assistant.');
pr162_review_ok(array_column($metaDecision['ui']['buttons']??[],'id')===['meta:program:learn','meta:program:regular'],'Meta first turn must expose only the two approved product buttons.');
pr162_review_ok(empty($metaWelcome['commercial_context']['program']),'An intensive campaign still must not preselect intensive before the user taps a product.');

$handled=hache_sharky_meta_handle($pdo,$metaWelcome,[
    'from'=>'529981112233','text'=>'Clases regulares','interactive_id'=>'meta:program:regular',
],$now+2,['contact'=>'529981112233']);
pr162_review_ok(is_array($handled),'Explicit regular selection must be handled by Meta 3.0.');
[$metaRegular,$regularDecision]=$handled;
pr162_review_ok(($metaRegular['flow']['step']??null)==='regular_background','Regular selection must ask the previous-classes question before becoming canonical.');
pr162_review_ok(empty($metaRegular['commercial_context']['program']),'Campaign attribution must not bypass the regular eligibility check.');
pr162_review_ok(array_column($regularDecision['ui']['buttons']??[],'id')===['meta:regular:yes','meta:regular:no'],'Regular eligibility must retain both deterministic choices.');

// A course button that went stale must invalidate the old selected course and
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
