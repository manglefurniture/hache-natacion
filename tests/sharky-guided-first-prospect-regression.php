<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-lab-worker.php';

function guided_first_ok(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"SHARKY GUIDED FIRST PROSPECT FAIL: $message\n");exit(1);}
}

$now=1788883200;
$pdo=new PDO('sqlite::memory:');
$webhook=(string)file_get_contents(__DIR__.'/../public/api/whatsapp-orchestrator-lab.php');
guided_first_ok(str_contains($webhook,'hache_sharky_entry_guided_first_prospect($state,(string)($event[\'text\']??\'\'),$now)'),'The real unmatched-number webhook must bootstrap guided qualification before the adapter runs.');

$fresh=hache_sharky_orchestrator_state(null,$now);
$fresh['identity']=array_replace($fresh['identity'],['kind'=>'prospect','verified'=>false,'source'=>'whatsapp_unmatched']);
$guided=hache_sharky_entry_guided_first_prospect($fresh,'Hola',$now);
guided_first_ok(($guided['flow']['name']??null)==='qualify_prospect'&&($guided['flow']['step']??null)==='swim','A clean unmatched prospect must enter the swim guided step immediately.');

// The exact first real turn must bypass text debounce. Contact capture/rename is
// durable before reply processing, so allowing this bootstrap turn to return
// BATCH_DEFERRED can leave a visible prospect contact without Sharky's welcome.
$firstEvent=['from'=>'529981112233','type'=>'text','text'=>'¡Hola! Quiero más información','interactive_id'=>'','group_id'=>''];
guided_first_ok(hache_sharky_whatsapp_first_prospect_welcome_turn($guided,$firstEvent),'The untouched first prospect turn must be recognized as the welcome fast path.');
$afterWelcome=$guided;$afterWelcome['last_user_text']='¡Hola! Quiero más información';
guided_first_ok(!hache_sharky_whatsapp_first_prospect_welcome_turn($afterWelcome,$firstEvent),'Only the untouched first turn may bypass debounce.');
$presented=$guided;$presented['assistant_presentation_queued']=true;
guided_first_ok(!hache_sharky_whatsapp_first_prospect_welcome_turn($presented,$firstEvent),'A prospect that already received presentation must not reuse the welcome fast path.');
$interactiveFirst=$firstEvent;$interactiveFirst['type']='interactive';$interactiveFirst['interactive_id']='qualify:swims';
guided_first_ok(!hache_sharky_whatsapp_first_prospect_welcome_turn($guided,$interactiveFirst),'Interactive qualification replies must keep their normal structured routing.');
$groupFirst=$firstEvent;$groupFirst['group_id']='group-1';
guided_first_ok(!hache_sharky_whatsapp_first_prospect_welcome_turn($guided,$groupFirst),'Group traffic must never use the direct prospect welcome fast path.');
$batchingSource=(string)file_get_contents(__DIR__.'/../config/sharky-whatsapp-batching.php');
$enqueuePos=strpos($batchingSource,'function hache_sharky_whatsapp_enqueue');
$welcomeFastPos=$enqueuePos===false?false:strpos($batchingSource,'hache_sharky_whatsapp_first_prospect_welcome_turn($entryState,$event)',$enqueuePos);
$debouncePos=$enqueuePos===false?false:strpos($batchingSource,'hache_sharky_orchestrator_batch_enqueue_and_wait',$enqueuePos);
guided_first_ok($enqueuePos!==false&&$welcomeFastPos!==false&&$debouncePos!==false&&$welcomeFastPos<$debouncePos,'The first-prospect welcome fast path must execute before text debounce.');

[$helloState,$helloDecision]=hache_sharky_whatsapp_qualification_input($pdo,$guided,['text'=>'Hola','interactive_id'=>''],$now+1,12);
guided_first_ok(($helloDecision['ui']['type']??null)==='buttons','The first orientation step must use native buttons.');
guided_first_ok(array_column($helloDecision['ui']['buttons']??[],'id')===['qualify:swims','qualify:beginner'],'First-turn buttons must expose deterministic swim-level IDs.');

// Interactive and free text are two interfaces over the same structured state.
[$tapBeginner,$tapBeginnerDecision]=hache_sharky_whatsapp_qualification_input($pdo,$guided,['text'=>'Desde cero','interactive_id'=>'qualify:beginner'],$now+2,12);
[$textBeginner,$textBeginnerDecision]=hache_sharky_whatsapp_qualification_input($pdo,$guided,['text'=>'no sé nadar','interactive_id'=>''],$now+2,12);
guided_first_ok(hache_sharky_commercial_snapshot($tapBeginner)===hache_sharky_commercial_snapshot($textBeginner),'“no sé nadar” must be state-equivalent to qualify:beginner.');
guided_first_ok(($tapBeginner['commercial_context']['swim_level']??null)==='beginner'&&($tapBeginner['commercial_context']['program']??null)==='intensive','Beginner selection must persist beginner + intensive deterministically.');
guided_first_ok(($tapBeginner['flow']['step']??null)==='sede'&&($textBeginner['flow']['step']??null)==='sede','Both beginner interfaces must advance to the same venue step.');
guided_first_ok(array_column($tapBeginnerDecision['ui']['buttons']??[],'id')===['sede:monteverde','sede:palapas'],'Beginner path must offer both real venue choices as buttons.');

[$swimsState,$swimsDecision]=hache_sharky_whatsapp_qualification_input($pdo,$guided,['text'=>'ya sé nadar','interactive_id'=>''],$now+2,12);
guided_first_ok(($swimsState['commercial_context']['swim_level']??null)==='swims','“ya sé nadar” must persist the swims state.');
guided_first_ok(($swimsState['flow']['step']??null)==='background','A swimmer must advance, not repeat the swim question.');
guided_first_ok(($swimsDecision['ui']['type']??null)==='buttons','Swimmer background choice must stay guided.');

// “hola” and lateral commercial questions cannot move an active funnel backwards.
[$helloMidState,$helloMidDecision]=hache_sharky_whatsapp_qualification_input($pdo,$tapBeginner,['text'=>'hola','interactive_id'=>''],$now+3,12);
guided_first_ok(($helloMidState['flow']['step']??null)==='sede','Saying hello mid-funnel must keep the current venue step.');
guided_first_ok(array_column($helloMidDecision['ui']['buttons']??[],'id')===['sede:monteverde','sede:palapas'],'Hello mid-funnel must re-offer the current controls, not restart discovery.');
guided_first_ok(hache_sharky_whatsapp_is_side_question($tapBeginner,['text'=>'¿Cuánto cuesta?','interactive_id'=>''])===true,'Price during qualification must be treated as a side question.');
guided_first_ok(hache_sharky_whatsapp_is_side_question($tapBeginner,['text'=>'¿Dónde están?','interactive_id'=>''])===true,'Location during qualification must be treated as a side question.');

[$tapVenue,$tapVenueDecision]=hache_sharky_whatsapp_qualification_input($pdo,$tapBeginner,['text'=>'Colegio Monteverde','interactive_id'=>'sede:monteverde'],$now+4,12);
[$textVenue,$textVenueDecision]=hache_sharky_whatsapp_qualification_input($pdo,$textBeginner,['text'=>'monteverde','interactive_id'=>''],$now+4,12);
guided_first_ok(hache_sharky_commercial_snapshot($tapVenue)===hache_sharky_commercial_snapshot($textVenue),'Typing “monteverde” must be state-equivalent to tapping sede:monteverde.');
guided_first_ok(($tapVenue['commercial_context']['sede_clave']??null)==='MONTEVERDE'&&($tapVenue['flow']??null)===null,'Venue selection must persist Monteverde and complete qualification.');
guided_first_ok(($tapVenueDecision['kind']??null)==='commercial_next_action'&&($textVenueDecision['kind']??null)==='commercial_next_action','Both venue interfaces must converge on the same commercial continuation.');

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

// A side question after venue confirmation must not erase the venue.
$beforeLocation=hache_sharky_commercial_snapshot($tapVenue);
$afterLocation=hache_sharky_commercial_capture($tapVenue,'¿Dónde están?',$catalog,'2026-09-08');
guided_first_ok(hache_sharky_commercial_snapshot($afterLocation)===$beforeLocation,'A location question after choosing Monteverde must preserve every confirmed selection.');

// Asking for the next course must use the already-confirmed venue and only backend dates.
$dateQuestion=hache_sharky_commercial_capture($afterLocation,'¿Cuándo inicia el próximo curso?',$catalog,'2026-09-08');
guided_first_ok(($dateQuestion['commercial_context']['sede_clave']??null)==='MONTEVERDE','Course-start question must reuse confirmed Monteverde instead of asking venue again.');
guided_first_ok(($dateQuestion['commercial_context']['_requested_slot']??null)==='course','Course-start question must request current backend course options without changing canonical selections.');
$dateReply=hache_sharky_commercial_reply($dateQuestion,'El modelo no debe decidir una fecha.',$catalog);
guided_first_ok(($dateReply['ui']['type']??null)==='buttons','Two available starts must render as native buttons.');
guided_first_ok(array_column($dateReply['ui']['buttons']??[],'id')===['action:commercial:course:c14','action:commercial:course:c21'],'Only the two backend course IDs for Monteverde may be offered.');
guided_first_ok(!str_contains((string)($dateReply['message']??''),'El modelo no debe decidir una fecha.'),'Course availability answer must be deterministic, not model-authored.');

// A displayed date button remains valid for exactly that guided choice and revalidates the live catalog.
$context=['today'=>'2026-09-08','intensive_options'=>$catalog['courses'],'min_age'=>12];
$courseTap=hache_sharky_commercial_interactive_input($pdo,$dateQuestion,['text'=>'Lun 14 sep','interactive_id'=>'action:commercial:course:c14'],$context);
guided_first_ok(is_array($courseTap),'A freshly displayed course button must be accepted.');
[$courseTapState]=$courseTap;
guided_first_ok(($courseTapState['commercial_context']['course_id']??null)==='c14'&&($courseTapState['commercial_context']['fecha_inicio']??null)==='2026-09-14','Course tap must persist the exact backend course and date.');
$courseText=hache_sharky_commercial_capture($dateQuestion,'14 de septiembre de 2026',$catalog,'2026-09-08');
guided_first_ok(($courseText['commercial_context']['course_id']??null)==='c14'&&($courseText['commercial_context']['fecha_inicio']??null)==='2026-09-14','Typing the displayed date must persist the same course/date as the button.');

$afterUnrelated=hache_sharky_commercial_capture($dateQuestion,'hola',$catalog,'2026-09-08');
$stale=hache_sharky_commercial_interactive_input($pdo,$afterUnrelated,['text'=>'Lun 14 sep','interactive_id'=>'action:commercial:course:c14'],$context);
guided_first_ok(is_array($stale)&&empty($stale[0]['commercial_context']['course_id']),'An old course button must become stale after the guided choice is no longer current.');

// Backend cardinality drives buttons/list; never truncate a real option set to three.
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
        guided_first_ok(($reply['ui']['type']??null)==='list'&&count($reply['ui']['options']??[])===4,'More than three backend options must use a list preserving all four options.');
    }
}

// An explicit venue change keeps independent discovery but invalidates venue-dependent selections only.
$withDependent=$courseTapState;
$withDependent['commercial_context']['schedule_id']='h8';$withDependent['commercial_context']['schedule_label']='08:00–09:00';
$beforeChange=$withDependent['commercial_context'];
$withDependent['commercial_context']['sede_clave']='PALAPAS';
$changed=hache_sharky_commercial_invalidate($withDependent,$beforeChange);
guided_first_ok(($changed['commercial_context']['program']??null)==='intensive'&&($changed['commercial_context']['swim_level']??null)==='beginner','Changing venue must preserve program and swim level.');
guided_first_ok(empty($changed['commercial_context']['schedule_id'])&&empty($changed['commercial_context']['course_id']),'Changing venue must invalidate only venue-dependent schedule/course selections.');

guided_first_ok(hache_sharky_orchestrator_sede_choice('Monteverde')==='MONTEVERDE','A short but unequivocal venue name must be accepted without requiring the internal full label.');

fwrite(STDOUT,"SHARKY_GUIDED_FIRST_PROSPECT_OK\n");
