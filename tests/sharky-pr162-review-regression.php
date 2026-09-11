<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-lab-worker.php';

function pr162_review_ok(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"SHARKY PR162 REVIEW FAIL: $message\n");exit(1);}
}

$now=1788883200;
$pdo=new PDO('sqlite::memory:');

// P1: first-turn program language is an entry preference, not a confirmed program.
$fresh=hache_sharky_orchestrator_state(null,$now);
$fresh['identity']=array_replace($fresh['identity'],[
    'kind'=>'prospect','verified'=>false,'source'=>'whatsapp_unmatched',
]);
$firstText="Quiero clases regulares\nMonteverde";
$guided=hache_sharky_entry_guided_first_prospect($fresh,$firstText,$now);
pr162_review_ok(($guided['flow']['name']??null)==='qualify_prospect'&&($guided['flow']['step']??null)==='swim','Fresh unmatched prospect must stay on swim qualification.');
pr162_review_ok(($guided['flow']['data']['entry_bootstrap']??false)===true,'First-turn guided flow must be explicitly marked as entry bootstrap.');
pr162_review_ok(($guided['flow']['data']['preferred_program']??null)==='regular','First-turn regular wording must be retained only as preferred program.');
pr162_review_ok(($guided['commercial_context']['entry_interest']??null)==='regular','Entry interest must remain durable discovery context.');
pr162_review_ok(empty($guided['commercial_context']['program']),'Entry interest must not be a confirmed program before qualification.');

// Reproduce the real adapter capture sequence for that same inbound event.
$before=$guided['commercial_context'];
$captured=hache_sharky_orchestrator_capture_commercial_context($guided,$firstText);
$captured=hache_sharky_whatsapp_apply_natural_venue_preference($captured,$firstText);
$captured=hache_sharky_whatsapp_apply_natural_swim_level($captured,$firstText);
$captured=hache_sharky_commercial_invalidate($captured,$before);
$captured=hache_sharky_commercial_capture($captured,$firstText,[],'2026-09-08');
$captured=hache_sharky_whatsapp_reconcile_qualification_context($captured);
pr162_review_ok(empty($captured['commercial_context']['program']),'Adapter capture must not promote the first-turn regular preference to canonical program.');
pr162_review_ok(($captured['commercial_context']['sede_clave']??null)==='MONTEVERDE','An explicit first-turn venue must remain available for the later guided step.');
pr162_review_ok(($captured['flow']['step']??null)==='swim','Program/venue wording on entry must not skip the swim question.');

[$beginner,$beginnerDecision]=hache_sharky_whatsapp_qualification_input($pdo,$captured,[
    'text'=>'Desde cero','interactive_id'=>'qualify:beginner',
],$now+1,12);
pr162_review_ok(($beginner['commercial_context']['program']??null)==='intensive','Beginner answer must be authoritative over an earlier regular preference.');
pr162_review_ok(($beginner['commercial_context']['sede_clave']??null)==='MONTEVERDE','Known Monteverde must survive beginner qualification without being re-asked.');
pr162_review_ok(($beginner['flow']??null)===null,'Known venue must let beginner qualification advance instead of moving backwards.');
pr162_review_ok(($beginnerDecision['kind']??null)==='commercial_next_action','Beginner + known venue must converge on the commercial continuation.');

// P2: click-to-WhatsApp referral must be attached before entry context is derived.
$webhook=(string)file_get_contents(__DIR__.'/../public/api/whatsapp-orchestrator-lab.php');
$referralPos=strpos($webhook,'$referral=hache_sharky_orchestrator_referral($event,$now);');
$capturePos=strpos($webhook,'$state=hache_sharky_orchestrator_capture_referral($state,$referral);',$referralPos===false?0:$referralPos);
$bootstrapPos=strpos($webhook,'$state=hache_sharky_entry_guided_first_prospect($state,(string)($event[\'text\']??\'\'),$now);',$capturePos===false?0:$capturePos);
pr162_review_ok($referralPos!==false&&$capturePos!==false&&$bootstrapPos!==false&&$referralPos<$capturePos&&$capturePos<$bootstrapPos,'Webhook must capture referral into state before guided entry derives source/interest.');

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
pr162_review_ok(empty($metaGuided['flow']['data']['preferred_program']),'Meta intensive context must remain a recommendation until the user chooses a program.');
pr162_review_ok(empty($metaGuided['commercial_context']['program']),'Referral interest must not become canonical program by itself.');

// Regression: arriving from the intensive ad is not the same as choosing intensive.
// A swimmer with formal lessons is routed to regular classes by the current product rule.
[$metaSwims,$metaSwimsDecision]=hache_sharky_whatsapp_qualification_input($pdo,$metaGuided,[
    'text'=>'Ya sé nadar','interactive_id'=>'qualify:swims',
],$now+1,12);
pr162_review_ok(($metaSwims['flow']['step']??null)==='background','A swimmer from an intensive ad must still reach the background question.');
pr162_review_ok(array_column($metaSwimsDecision['ui']['buttons']??[],'id')===['qualify:formal','qualify:self'],'Background question must retain both guided choices.');
[$metaFormal,$metaFormalDecision]=hache_sharky_whatsapp_qualification_input($pdo,$metaSwims,[
    'text'=>'He tomado clases','interactive_id'=>'qualify:formal',
],$now+2,12);
pr162_review_ok(($metaFormal['commercial_context']['program']??null)==='regular','Formal experience must route to regular classes, never inherit the intensive ad program.');
pr162_review_ok(($metaFormal['commercial_context']['background']??null)==='formal','Formal training history must persist as durable commercial context.');
pr162_review_ok(($metaFormal['flow']['step']??null)==='sede','A formal swimmer must go directly to venue selection after product resolution.');
pr162_review_ok(array_column($metaFormalDecision['ui']['buttons']??[],'id')===['sede:monteverde','sede:palapas'],'Formal swimmer must receive venue buttons, not a product-choice screen.');

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
