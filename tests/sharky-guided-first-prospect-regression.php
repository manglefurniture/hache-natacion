<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-lab-worker.php';

function guided_first_ok(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"SHARKY GUIDED FIRST PROSPECT FAIL: $message\n");exit(1);}
}

function guided_first_button_ids(array $decision): array
{
    return array_column($decision['ui']['buttons']??[],'id');
}

$now=1788883200;
$pdo=new PDO('sqlite::memory:');
$webhook=(string)file_get_contents(__DIR__.'/../public/api/whatsapp-orchestrator-lab.php');
guided_first_ok(str_contains($webhook,'hache_sharky_entry_guided_first_prospect($state,(string)($event[\'text\']??\'\'),$now)'),'The real unmatched-number webhook must bootstrap prospect onboarding before the adapter runs.');

$fresh=hache_sharky_orchestrator_state(null,$now);
$fresh['identity']=array_replace($fresh['identity'],['kind'=>'prospect','verified'=>false,'source'=>'whatsapp_unmatched']);
$guided=hache_sharky_entry_guided_first_prospect($fresh,'¡Hola! Quiero información',$now);
guided_first_ok(($guided['flow']['name']??null)==='prospect_onboarding'&&($guided['flow']['step']??null)==='name','A clean unmatched prospect must start in the profile-first name step.');
guided_first_ok(($guided['flow']['data']['entry_bootstrap']??false)===true,'The first inbound message must be marked as bootstrap, not treated as the answer to the name question.');
guided_first_ok(($guided['commercial_context']['entry_interest']??null)===null,'A generic first message must not manufacture a product choice.');

guided_first_ok(HACHE_SHARKY_BATCH_WINDOW_MS===2800,'The WhatsApp text debounce must remain 2.8 seconds.');
$firstEvent=['from'=>'529981112233','type'=>'text','text'=>'¡Hola! Quiero más información','interactive_id'=>'','group_id'=>''];
guided_first_ok(!hache_sharky_whatsapp_first_prospect_welcome_turn($guided,$firstEvent),'Profile-first onboarding must use the normal debounce instead of the legacy immediate welcome path.');

[$askNameState,$askNameDecision]=hache_sharky_prospect_onboarding_handle($pdo,$guided,$firstEvent,$now+1,12);
guided_first_ok(($askNameState['flow']['step']??null)==='name'&&($askNameState['flow']['data']['entry_bootstrap']??true)===false,'The first inbound turn must only open the name question and remain in the name step.');
guided_first_ok(($askNameDecision['message']??'')==='Necesito un par de datos tuyos para conocernos mejor. Por favor, ¿me puedes decir tu nombre?','The first onboarding body must ask only for the prospect name.');
guided_first_ok(hache_sharky_entry_intro($askNameState,$firstEvent['text'])==='Hola, soy Sharky, asistente IA de Hache Natación.','The first presentation must be a neutral greeting and explicit AI disclosure.');

[$badNameState,$badNameDecision]=hache_sharky_prospect_onboarding_handle($pdo,$askNameState,['from'=>'529981112233','text'=>'pelusita.com 😺','interactive_id'=>''],$now+2,12);
guided_first_ok(($badNameState['flow']['step']??null)==='name'&&str_starts_with((string)($badNameDecision['message']??''),'Una disculpa'),'An invalid/dirty profile-style name must stay on the name step with a soft retry.');

[$named,$participantDecision]=hache_sharky_prospect_onboarding_handle($pdo,$askNameState,['from'=>'529981112233','text'=>'Roberto','interactive_id'=>''],$now+2,12);
guided_first_ok(($named['commercial_context']['prospect_name']??null)==='Roberto','A clean prospect name must become structured commercial identity.');
guided_first_ok(($named['identity']['name']??null)==='Roberto','The confirmed prospect name must also become the conversation identity name.');
guided_first_ok(($named['flow']['step']??null)==='participant','After name, onboarding must ask who will take the classes.');
guided_first_ok(($participantDecision['message']??'')==='Bueno, Roberto, ¿las clases son para ti?','The participant question must use the confirmed name naturally.');
guided_first_ok(guided_first_button_ids($participantDecision)===['onboarding:self:yes','onboarding:self:no'],'The participant question must use deterministic Yes/No buttons.');

[$selfState,$ageDecision]=hache_sharky_prospect_onboarding_handle($pdo,$named,['from'=>'529981112233','text'=>'Sí','interactive_id'=>'onboarding:self:yes'],$now+3,12);
guided_first_ok(($selfState['flow']['step']??null)==='age'&&($selfState['commercial_context']['participant_relation']??null)==='self','Self selection must persist the participant relation and advance to age.');
guided_first_ok(($ageDecision['message']??'')==='¿Qué edad tienes?','Self path must ask the prospect age directly.');

[$badAgeState,$badAgeDecision]=hache_sharky_prospect_onboarding_handle($pdo,$selfState,['from'=>'529981112233','text'=>'muchos','interactive_id'=>''],$now+4,12);
guided_first_ok(($badAgeState['flow']['step']??null)==='age'&&str_starts_with((string)($badAgeDecision['message']??''),'Una disculpa'),'Unclear age must stay on the same step with a soft retry.');

[$ageState,$levelDecision]=hache_sharky_prospect_onboarding_handle($pdo,$selfState,['from'=>'529981112233','text'=>'35','interactive_id'=>''],$now+4,12);
guided_first_ok(($ageState['commercial_context']['age']??null)===35&&($ageState['flow']['step']??null)==='level','A valid adult age must persist and advance to level.');
guided_first_ok(($levelDecision['message']??'')==='Escoge el nivel que mejor te representa:','Level question must use the agreed wording.');
guided_first_ok(guided_first_button_ids($levelDecision)===['onboarding:level:beginner','onboarding:level:intermediate','onboarding:level:advanced'],'Level must expose exactly Beginner, Intermediate and Advanced buttons.');

[$badLevelState,$badLevelDecision]=hache_sharky_prospect_onboarding_handle($pdo,$ageState,['from'=>'529981112233','text'=>'más o menos','interactive_id'=>''],$now+5,12);
guided_first_ok(($badLevelState['flow']['step']??null)==='level'&&str_starts_with((string)($badLevelDecision['message']??''),'Una disculpa'),'Unclear level must stay on the level step with a soft retry.');

// Beginner -> intensive, then explicit product information and three venue choices.
[$beginner,$beginnerOffer]=hache_sharky_prospect_onboarding_handle($pdo,$ageState,['from'=>'529981112233','text'=>'Principiante','interactive_id'=>'onboarding:level:beginner'],$now+5,12);
guided_first_ok(($beginner['commercial_context']['swim_level']??null)==='beginner'&&($beginner['commercial_context']['program']??null)==='intensive','Beginner must route directly to intensive.');
guided_first_ok(($beginnerOffer['message']??'')==='Roberto, para tu nivel tenemos un curso básico e intensivo:','Beginner offer must remain concise before details.');
guided_first_ok(guided_first_button_ids($beginnerOffer)===['onboarding:info:intensive'],'Beginner offer must expose one information button.');
[$beginnerInfo,$beginnerInfoDecision]=hache_sharky_prospect_onboarding_handle($pdo,$beginner,['from'=>'529981112233','text'=>'Ver información','interactive_id'=>'onboarding:info:intensive'],$now+6,12);
$beginnerInfoText=(string)($beginnerInfoDecision['message']??'');
guided_first_ok(str_contains($beginnerInfoText,'Curso básico para aprender a nadar')&&str_contains($beginnerInfoText,'Costo: $1,200')&&str_contains($beginnerInfoText,'Duración: 3 semanas (clases de lunes a viernes)'),'Intensive details must contain the agreed product, price and duration.');
guided_first_ok(guided_first_button_ids($beginnerInfoDecision)===['sede:monteverde','sede:palapas','sede:both'],'Intensive details must offer Monteverde, Palapas and both locations.');

// When both locations are requested, onboarding stays on venue and then offers only the two venue choices.
$bothState=$beginnerInfo;
$bothState['flow']['data']['both_shown']=true;
guided_first_ok(guided_first_button_ids(hache_sharky_orchestrator_decision('venues','',hache_sharky_prospect_onboarding_venue_ui(false)))===['sede:monteverde','sede:palapas'],'After showing both locations only the two venue choices must remain.');

// Intermedio -> confirm previous classes. No -> intensive; Yes -> regular.
[$intermediate,$intermediateDecision]=hache_sharky_prospect_onboarding_handle($pdo,$ageState,['from'=>'529981112233','text'=>'Intermedio','interactive_id'=>'onboarding:level:intermediate'],$now+5,12);
guided_first_ok(($intermediate['flow']['step']??null)==='intermediate_background','Intermediate must have exactly one extra training-history check.');
guided_first_ok(($intermediateDecision['message']??'')==='Roberto, ¿ya has tomado clases de natación antes?','Intermediate must ask the agreed previous-classes question.');
guided_first_ok(guided_first_button_ids($intermediateDecision)===['onboarding:background:yes','onboarding:background:no'],'Intermediate previous-classes check must use Yes/No buttons.');
[$intermediateNo,$intermediateNoOffer]=hache_sharky_prospect_onboarding_handle($pdo,$intermediate,['from'=>'529981112233','text'=>'No','interactive_id'=>'onboarding:background:no'],$now+6,12);
guided_first_ok(($intermediateNo['commercial_context']['background']??null)==='no_formal'&&($intermediateNo['commercial_context']['program']??null)==='intensive','Intermediate without prior classes must route to intensive.');
guided_first_ok(str_contains((string)($intermediateNoOffer['message']??''),'curso básico e intensivo'),'Intermediate/no branch must use the same intensive offer.');
[$intermediateYes,$intermediateYesOffer]=hache_sharky_prospect_onboarding_handle($pdo,$intermediate,['from'=>'529981112233','text'=>'Sí','interactive_id'=>'onboarding:background:yes'],$now+6,12);
guided_first_ok(($intermediateYes['commercial_context']['background']??null)==='formal'&&($intermediateYes['commercial_context']['program']??null)==='regular','Intermediate with prior classes must route to regular.');

// Advanced -> regular directly, but do not invent a claim of formal training.
[$advanced,$advancedOffer]=hache_sharky_prospect_onboarding_handle($pdo,$ageState,['from'=>'529981112233','text'=>'Avanzado','interactive_id'=>'onboarding:level:advanced'],$now+5,12);
guided_first_ok(($advanced['commercial_context']['program']??null)==='regular'&&($advanced['commercial_context']['background']??null)==='advanced','Advanced must route directly to regular using a distinct truthful eligibility marker.');
guided_first_ok(($advanced['flow']['step']??null)==='product_info'&&str_contains((string)($advancedOffer['message']??''),'clases regulares'),'Advanced must skip the intermediate previous-classes question.');
guided_first_ok(!hache_sharky_product_boundary_regular_pending_background($advanced),'The commercial guard must accept advanced as explicit eligibility without asking formal-background again.');
$advancedSanitized=hache_sharky_product_boundary_sanitize_state($advanced,'');
guided_first_ok(($advancedSanitized['commercial_context']['program']??null)==='regular','Commercial safety sanitization must preserve the advanced regular route.');

[$regularInfo,$regularInfoDecision]=hache_sharky_prospect_onboarding_handle($pdo,$advanced,['from'=>'529981112233','text'=>'Ver información','interactive_id'=>'onboarding:info:regular'],$now+6,12);
$regularText=(string)($regularInfoDecision['message']??'');
guided_first_ok(str_contains($regularText,'3 clases por semana: $1,000/mes')&&str_contains($regularText,'5 clases por semana: $1,200/mes'),'Regular details must show both current plan prices.');
guided_first_ok(str_contains($regularText,'Inscripción Monteverde: $500')&&str_contains($regularText,'Inscripción Palapas: $400'),'Regular details must show the current venue enrollment fees.');
guided_first_ok(guided_first_button_ids($regularInfoDecision)===['sede:monteverde','sede:palapas','sede:both'],'Regular details must offer the same three location choices.');

// Responsible-person branch asks the student's name and then that student's age.
[$otherState,$otherNameDecision]=hache_sharky_prospect_onboarding_handle($pdo,$named,['from'=>'529981112233','text'=>'No','interactive_id'=>'onboarding:self:no'],$now+3,12);
guided_first_ok(($otherState['flow']['step']??null)==='student_name','When classes are for someone else, Sharky must ask that person's name first.');
[$studentNamed,$studentAgeDecision]=hache_sharky_prospect_onboarding_handle($pdo,$otherState,['from'=>'529981112233','text'=>'Daniela','interactive_id'=>''],$now+4,12);
guided_first_ok(($studentNamed['commercial_context']['participant_name']??null)==='Daniela'&&($studentAgeDecision['message']??'')==='¿Qué edad tiene Daniela?','Responsible-person path must keep contact and student identity separate.');

// Existing students must still escape onboarding to a person instead of becoming a fake name.
[$studentClaimState,$studentClaimDecision]=hache_sharky_prospect_onboarding_handle($pdo,$askNameState,['from'=>'529981112233','text'=>'Soy alumno','interactive_id'=>''],$now+2,12);
guided_first_ok(($studentClaimState['flow']??null)===null&&($studentClaimDecision['action']['type']??null)==='human_takeover','“Soy alumno” must keep the existing human handoff safety path.');

// Preserve the canonical commercial catalogue tests after profile-first onboarding.
$tapVenue=$beginner;
$tapVenue=hache_sharky_orchestrator_clear_flow($tapVenue);
$tapVenue['commercial_context']['sede_clave']='MONTEVERDE';
$catalog=[
    'plans'=>[],
    'schedules'=>[
        ['id'=>'h8','label'=>'08:00–09:00'],
        ['id'=>'h20','label'=>'20:00–21:00'],
    ],
    'courses'=>[
        ['id'=>'c14','sede_clave'=>'MONTEVERDE','fecha_inicio'=>'2026-09-14','precio'=>1200,'schedules'=>[['id'=>'h8','label'=>'08:00–09:00'],['id'=>'h20','label'=>'20:00–21:00']]],
        ['id'=>'c21','sede_clave'=>'MONTEVERDE','fecha_inicio'=>'2026-09-21','precio'=>1200,'schedules'=>[['id'=>'h8','label'=>'08:00–09:00']]],
    ],
];

$beforeLocation=hache_sharky_commercial_snapshot($tapVenue);
$afterLocation=hache_sharky_commercial_capture($tapVenue,'¿Dónde están?',$catalog,'2026-09-08');
guided_first_ok(hache_sharky_commercial_snapshot($afterLocation)===$beforeLocation,'A location question after choosing Monteverde must preserve every confirmed selection.');

$dateQuestion=hache_sharky_commercial_capture($afterLocation,'¿Cuándo inicia el próximo curso?',$catalog,'2026-09-08');
guided_first_ok(($dateQuestion['commercial_context']['sede_clave']??null)==='MONTEVERDE','Course-start question must reuse confirmed Monteverde instead of asking venue again.');
guided_first_ok(($dateQuestion['commercial_context']['_requested_slot']??null)==='course','Course-start question must request current backend course options without changing canonical selections.');
$dateReply=hache_sharky_commercial_reply($dateQuestion,'El modelo no debe decidir una fecha.',$catalog);
guided_first_ok(($dateReply['ui']['type']??null)==='buttons','Two available starts must render as native buttons.');
guided_first_ok(array_column($dateReply['ui']['buttons']??[],'id')===['action:commercial:course:c14','action:commercial:course:c21'],'Only backend course IDs for the chosen venue may be offered.');

$context=['today'=>'2026-09-08','intensive_options'=>$catalog['courses'],'min_age'=>12];
$courseTap=hache_sharky_commercial_interactive_input($pdo,$dateQuestion,['text'=>'Lun 14 sep','interactive_id'=>'action:commercial:course:c14'],$context);
guided_first_ok(is_array($courseTap),'A freshly displayed course button must be accepted.');
[$courseTapState]=$courseTap;
guided_first_ok(($courseTapState['commercial_context']['course_id']??null)==='c14'&&($courseTapState['commercial_context']['fecha_inicio']??null)==='2026-09-14','Course tap must persist the exact backend course and date.');

$courseTemplate=static fn(string $id,string $date):array=>['id'=>$id,'sede_clave'=>'MONTEVERDE','fecha_inicio'=>$date,'precio'=>1200,'schedules'=>[['id'=>'h8','label'=>'08:00–09:00']]];
$dates=['2026-09-14','2026-09-21','2026-09-28','2026-10-05'];
for($count=0;$count<=4;$count++){
    $countCatalog=['plans'=>[],'schedules'=>[['id'=>'h8','label'=>'08:00–09:00']],'courses'=>[]];
    for($i=0;$i<$count;$i++)$countCatalog['courses'][]=$courseTemplate('c'.($i+1),$dates[$i]);
    $countState=$tapVenue;
    $countState['commercial_context']['_requested_slot']='course';
    $reply=hache_sharky_commercial_reply($countState,'',$countCatalog);
    if($count===0){
        guided_first_ok(($reply['ui']['type']??null)==='list'&&count($reply['ui']['options']??[])===0,'Zero backend options must remain explicitly empty for the safe empty-options guard.');
        [$guardState,$guardDecision]=hache_sharky_whatsapp_empty_options_guard($countState,$reply);
        guided_first_ok(($guardDecision['kind']??null)==='options_unavailable_handoff','Zero options must fail safe instead of inventing availability.');
    }elseif($count<=3){
        guided_first_ok(($reply['ui']['type']??null)==='buttons'&&count($reply['ui']['buttons']??[])===$count,'One to three backend options must use exactly that many buttons.');
    }else{
        guided_first_ok(($reply['ui']['type']??null)==='list'&&count($reply['ui']['options']??[])===4,'More than three backend options must use a list preserving all options.');
    }
}

$withDependent=$courseTapState;
$withDependent['commercial_context']['schedule_id']='h8';$withDependent['commercial_context']['schedule_label']='08:00–09:00';
$beforeChange=$withDependent['commercial_context'];
$withDependent['commercial_context']['sede_clave']='PALAPAS';
$changed=hache_sharky_commercial_invalidate($withDependent,$beforeChange);
guided_first_ok(($changed['commercial_context']['program']??null)==='intensive'&&($changed['commercial_context']['swim_level']??null)==='beginner','Changing venue must preserve program and swim level.');
guided_first_ok(empty($changed['commercial_context']['schedule_id'])&&empty($changed['commercial_context']['course_id']),'Changing venue must invalidate only venue-dependent schedule/course selections.');

guided_first_ok(hache_sharky_orchestrator_sede_choice('Monteverde')==='MONTEVERDE','A short unequivocal venue name must still be accepted.');

fwrite(STDOUT,"SHARKY_GUIDED_FIRST_PROSPECT_OK\n");
