<?php

declare(strict_types=1);

require __DIR__.'/../config/sharky-whatsapp-batching.php';
require __DIR__.'/../config/sharky-conversation-brain.php';

function lab_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY CONVERSATION LAB FAIL: $message\n");exit(1);}
}
function lab_eq(mixed $actual,mixed $expected,string $message): void
{
    if($actual!==$expected){
        fwrite(STDERR,"SHARKY CONVERSATION LAB FAIL: $message\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true)."\n");
        exit(1);
    }
}

$scenarioCount=0;
$now=1788746400;
$today='2026-09-07';
$contact='529900001234';

// -------------------------------------------------------------------------
// Matrix lab: 128 multi-turn policy traces.
// 2 programs × 2 venues × 4 age states × paused/not paused ×
// side-question/not × low-information/not = 128 traces.
// Each trace starts from unknown identity, crosses into prospect mode, completes
// commercial context, then verifies the Brain recommendation on reengagement.
// -------------------------------------------------------------------------
$programs=['intensive'=>'Intensivo','regular'=>'Regulares'];
$venues=['PALAPAS'=>'Palapas Protudec','MONTEVERDE'=>'Colegio Monteverde'];
$ages=[null,15,43,59];
$bools=[false,true];

foreach($programs as $program=>$programText){
    foreach($venues as $venue=>$venueText){
        foreach($ages as $age){
            foreach($bools as $paused){
                foreach($bools as $sideQuestion){
                    foreach($bools as $lowInformation){
                        $scenarioCount++;
                        $idPrefix='lab.'.str_pad((string)$scenarioCount,3,'0',STR_PAD_LEFT);

                        $beforeIdentity=hache_sharky_orchestrator_state(null,$now+$scenarioCount*10);
                        $identityResult=hache_sharky_orchestrate(
                            $beforeIdentity,
                            ['id'=>$idPrefix.'.identity','from'=>$contact,'text'=>'Soy nuevo','interactive_id'=>'identity:new'],
                            ['now'=>$now+$scenarioCount*10,'today'=>$today,'identity'=>['found'=>false]]
                        );
                        $state=$identityResult['state'];
                        lab_eq($state['identity']['kind']??null,'prospect','Trace '.$scenarioCount.': identity:new must enter prospect mode.');
                        $brain=hache_sharky_brain_next_best_action($beforeIdentity,$state,['text'=>'Soy nuevo'],[
                            'decision_kind'=>(string)($identityResult['decision']['kind']??'conversation'),
                        ]);
                        lab_eq($brain['action'],'start_guided_qualification','Trace '.$scenarioCount.': new prospect must start guided qualification.');

                        [$state,$qualificationDecision]=hache_sharky_whatsapp_qualification_start($state,$now+$scenarioCount*10+1);
                        lab_eq($state['flow']['name']??null,'qualify_prospect','Trace '.$scenarioCount.': qualification must open the controlled flow.');
                        $brain=hache_sharky_brain_next_best_action($state,$state,['text'=>''],[
                            'decision_kind'=>(string)($qualificationDecision['kind']??''),
                        ]);
                        lab_eq($brain['action'],'continue_controlled_flow','Trace '.$scenarioCount.': controlled qualification must remain authoritative.');

                        // Exercise the same deterministic parsers used by WhatsApp before
                        // storing the explicit program and venue choices in durable state.
                        $parsedProgram=hache_sharky_orchestrator_program_choice($programText);
                        lab_eq($parsedProgram,$program,'Trace '.$scenarioCount.': program parser must recognize '.$programText.'.');
                        $venueInteractive=$venue==='PALAPAS'?'sede:palapas':'sede:monteverde';
                        $parsedVenue=hache_sharky_whatsapp_detect_venue_preference($venueText,$venueInteractive);
                        lab_eq($parsedVenue,$venue,'Trace '.$scenarioCount.': venue parser must recognize '.$venueText.'.');

                        $state['commercial_context']['program']=$parsedProgram;
                        $beforeReady=$state;
                        $state['commercial_context']['sede_clave']=$parsedVenue;
                        if($age!==null){
                            $ageText='Tengo '.$age.' años';
                            $parsedAge=hache_sharky_whatsapp_declared_age($ageText);
                            lab_eq($parsedAge,$age,'Trace '.$scenarioCount.': age parser must preserve volunteered age.');
                            $state['commercial_context']['age']=$parsedAge;
                        }
                        $state=hache_sharky_orchestrator_clear_flow($state);

                        lab_ok(hache_sharky_whatsapp_commercial_ready($state),'Trace '.$scenarioCount.': program + venue must be commercially ready.');
                        lab_ok(hache_sharky_brain_snapshot($state)['commercial_ready']===true,'Trace '.$scenarioCount.': Brain readiness must match live readiness.');
                        $brain=hache_sharky_brain_next_best_action($beforeReady,$state,['text'=>$venueText,'interactive_id'=>$venueInteractive],[
                            'decision_kind'=>'conversation','turn_discovery_only'=>true,
                        ]);
                        lab_eq($brain['action'],'show_commercial_menu','Trace '.$scenarioCount.': completing discovery must expose the commercial menu.');

                        if($paused){
                            $state['commercial_context']['_idle_followup']=[
                                'status'=>'completed_optout','token'=>null,'next_stage'=>null,'pause_reason'=>'user_now_not',
                            ];
                        }

                        $eventText=$sideQuestion?'¿Qué equipo necesito?':($lowInformation?'Hola':'¿Qué horarios tienen?');
                        $evaluationState=$state;
                        if($sideQuestion){
                            // A side question may interrupt a controlled flow. The Brain
                            // must answer it without throwing away the flow.
                            $evaluationState=hache_sharky_orchestrator_flow(
                                $evaluationState,
                                $program==='intensive'?'register_intensive':'qualify_prospect',
                                $program==='intensive'?'offer':'program',
                                [],
                                $now+$scenarioCount*10+2
                            );
                        }

                        $brain=hache_sharky_brain_next_best_action($evaluationState,$evaluationState,['text'=>$eventText],[
                            'decision_kind'=>'conversation',
                            'side_question'=>$sideQuestion,
                            'low_information'=>$lowInformation,
                        ]);
                        $expected=$sideQuestion?'answer_side_question':($lowInformation?'show_commercial_menu':'answer_user');
                        lab_eq($brain['action'],$expected,'Trace '.$scenarioCount.': reengagement next-best-action mismatch.');
                        lab_eq($brain['after']['program'],$program,'Trace '.$scenarioCount.': Brain must retain program context.');
                        lab_eq($brain['after']['sede_clave'],$venue,'Trace '.$scenarioCount.': Brain must retain venue context.');
                        lab_eq($brain['after']['age'],$age,'Trace '.$scenarioCount.': Brain must retain optional age context.');
                        lab_eq($brain['after']['followup_paused'],$paused,'Trace '.$scenarioCount.': pause status must not leak or disappear.');
                    }
                }
            }
        }
    }
}

// -------------------------------------------------------------------------
// Cross-feature live-contract scenarios. These call the current production
// helpers so the shadow Brain cannot silently drift away from live semantics.
// -------------------------------------------------------------------------

// New pause ID is unambiguous.
$scenarioCount++;
lab_ok(hache_sharky_whatsapp_now_not_request(['text'=>'Ahora no','interactive_id'=>'flow:pause']),'flow:pause must be recognized as the new explicit pause action.');
$brain=hache_sharky_brain_next_best_action([],[],['text'=>'Ahora no','interactive_id'=>'flow:pause'],['pause_requested'=>true]);
lab_eq($brain['action'],'pause_commercial_intent','Brain must map flow:pause to commercial pause.');

// Historic already-sent button remains compatible only because its visible title says Ahora no.
$scenarioCount++;
lab_ok(hache_sharky_whatsapp_now_not_request(['text'=>'Ahora no','interactive_id'=>'flow:no']),'Legacy flow:no + Ahora no must remain backward compatible.');
lab_ok(!hache_sharky_whatsapp_now_not_request(['text'=>'No','interactive_id'=>'flow:no']),'Generic flow:no + No must NOT be interpreted as pause.');

// Plain typed pause is still supported.
$scenarioCount++;
lab_ok(hache_sharky_whatsapp_now_not_request(['text'=>'Por ahora no','interactive_id'=>'']),'Natural typed pause must remain supported.');

// The old No path must keep its controlled-flow semantics.
$scenarioCount++;
$absenceState=hache_sharky_orchestrator_state(null,$now+5000);
$absenceState['identity']=array_replace($absenceState['identity'],['kind'=>'student','verified'=>true,'student_id'=>'stu-lab']);
$absenceState=hache_sharky_orchestrator_flow($absenceState,'absence','offer',[],$now+5000);
$absenceResult=hache_sharky_orchestrate($absenceState,[
    'id'=>'lab.absence.no','from'=>$contact,'text'=>'No','interactive_id'=>'flow:no',
],['now'=>$now+5001,'today'=>$today]);
lab_eq($absenceResult['decision']['kind']??null,'flow_cancelled','flow:no inside absence must keep cancellation semantics.');
$brain=hache_sharky_brain_next_best_action($absenceState,$absenceResult['state'],['text'=>'No','interactive_id'=>'flow:no'],[
    'decision_kind'=>(string)($absenceResult['decision']['kind']??''),
    'pause_requested'=>hache_sharky_whatsapp_now_not_request(['text'=>'No','interactive_id'=>'flow:no']),
]);
lab_eq($brain['action'],'preserve_deterministic_decision','Brain must preserve the normal No/cancel result instead of pausing.');

// Baby/matronatación shortcut is warm closure, but eligible teenagers are not rejected by it.
$scenarioCount++;
lab_ok(hache_sharky_whatsapp_family_age_scope_request(['text'=>'¿Es para bebés?','interactive_id'=>'']),'Baby wording must hit the dedicated age-scope policy.');
lab_ok(!hache_sharky_whatsapp_family_age_scope_request(['text'=>'Es para una niña de 15 años','interactive_id'=>'']),'A 15-year-old must not hit the baby shortcut.');
$brain=hache_sharky_brain_next_best_action([],[],['text'=>'¿Es para bebés?'],['family_age_unavailable'=>true]);
lab_eq($brain['action'],'close_age_scope','Brain must rank baby/matronatación closure above commercial discovery.');

// Natural side questions are normalized before the strict active-flow detector.
$scenarioCount++;
$sideState=hache_sharky_orchestrator_state(null,$now+5100);
$sideState['identity']=array_replace($sideState['identity'],['kind'=>'prospect','verified'=>true]);
$sideState['commercial_context']['program']='intensive';
$sideState['commercial_context']['sede_clave']='PALAPAS';
$sideState=hache_sharky_orchestrator_flow($sideState,'register_intensive','sede',[],$now+5100);
$normalizedQuestion=hache_sharky_whatsapp_batch_question_text('Precio para las clases');
lab_ok(str_ends_with($normalizedQuestion,'?'),'Natural price wording must be normalized as a question.');
lab_ok(hache_sharky_whatsapp_is_side_question($sideState,['text'=>$normalizedQuestion,'interactive_id'=>'']),'Normalized price question must interrupt the active flow informationally.');
$brain=hache_sharky_brain_next_best_action($sideState,$sideState,['text'=>$normalizedQuestion],['side_question'=>true]);
lab_eq($brain['action'],'answer_side_question','Brain must preserve active flow while answering the informational interruption.');

// Operational choices must not be rewritten as questions.
$scenarioCount++;
lab_eq(hache_sharky_whatsapp_batch_question_text('Pago el 50%'),'Pago el 50%','Payment choice must remain an operational reply.');
lab_eq(hache_sharky_whatsapp_batch_question_text('Horario matutino'),'Horario matutino','Daypart choice must remain an operational reply.');

// Known student direct-chat policy still outranks prospect automation.
$scenarioCount++;
$knownStudent=hache_sharky_orchestrator_state(null,$now+5200);
$knownStudent['identity']=array_replace($knownStudent['identity'],['kind'=>'student','verified'=>true,'student_id'=>'stu-known']);
$brain=hache_sharky_brain_next_best_action($knownStudent,$knownStudent,['text'=>'¿Qué llevo mañana?'],[
    'known_student'=>true,'direct_chat'=>true,'decision_kind'=>'conversation',
]);
lab_eq($brain['action'],'handoff_known_student','Current student policy must remain a direct human handoff in shadow mode.');

// Human takeover is absolute and wins even over a known student signal.
$scenarioCount++;
$brain=hache_sharky_brain_next_best_action($knownStudent,$knownStudent,['text'=>'Hola'],[
    'human_takeover_active'=>true,'known_student'=>true,'direct_chat'=>true,
]);
lab_eq($brain['action'],'wait_for_human','Manual takeover must silence Sharky before every other route.');

// A pause keeps commercial facts and merely suppresses automatic pursuit.
$scenarioCount++;
$pausedState=hache_sharky_orchestrator_state(null,$now+5300);
$pausedState['identity']=array_replace($pausedState['identity'],['kind'=>'prospect','verified'=>true]);
$pausedState['commercial_context']=array_replace($pausedState['commercial_context'],[
    'program'=>'intensive','sede_clave'=>'PALAPAS',
    '_idle_followup'=>['status'=>'completed_optout','token'=>null,'next_stage'=>null,'pause_reason'=>'user_now_not'],
]);
$snapshot=hache_sharky_brain_snapshot($pausedState);
lab_ok($snapshot['followup_paused']===true,'Paused state must be visible to the Brain.');
lab_ok($snapshot['commercial_ready']===true,'Pause must preserve program + venue.');
$brain=hache_sharky_brain_next_best_action($pausedState,$pausedState,['text'=>'Hola'],[
    'low_information'=>hache_sharky_whatsapp_low_information_reengagement('Hola'),
]);
lab_eq($brain['action'],'show_commercial_menu','When the user reopens a paused chat, Sharky may resume from the remembered menu.');

// First identity question and repeated greeting policies remain compatible with the Brain.
$scenarioCount++;
$unknown=hache_sharky_orchestrator_state(null,$now+5400);
$brain=hache_sharky_brain_next_best_action($unknown,$unknown,['text'=>'Hola'],['decision_kind'=>'conversation']);
lab_eq($brain['action'],'ask_identity','Unknown contact must begin with identity, not commercial assumptions.');
lab_eq(hache_sharky_whatsapp_strip_repeated_greeting_body("¡Hola!\n\nAquí tienes la información."),'Aquí tienes la información.','Repeated greeting scrubber must remove a later generic greeting.');

// Age remains optional for commercial readiness, but volunteered values survive snapshots.
$scenarioCount++;
$adult=hache_sharky_orchestrator_state(null,$now+5500);
$adult['identity']=array_replace($adult['identity'],['kind'=>'prospect','verified'=>true]);
$adult['commercial_context']['program']='regular';
$adult['commercial_context']['sede_clave']='MONTEVERDE';
lab_ok(hache_sharky_brain_commercial_ready($adult),'Program + venue must be enough without routine age interrogation.');
$adult['commercial_context']['age']=59;
lab_eq(hache_sharky_brain_snapshot($adult)['age'],59,'Volunteered age must remain available when present.');

lab_ok($scenarioCount>=140,'Conversation Lab must protect at least 140 traces/cross-feature scenarios.');
fwrite(STDOUT,"SHARKY_CONVERSATION_LAB_OK scenarios=$scenarioCount\n");
