<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-whatsapp-batching.php';

function batch_context_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY BATCH CONTEXT FAIL: $message\n");exit(1);}
}

batch_context_ok(hache_sharky_whatsapp_batch_question_like('Que precio tienen las clases de natación'),'Natural price question without punctuation must be recognized as a pending question.');
batch_context_ok(hache_sharky_whatsapp_batch_question_like('¿Cuánto cuesta?'),'Question punctuation must be recognized.');
batch_context_ok(!hache_sharky_whatsapp_batch_question_like('Desde cero'),'A qualification answer alone is not a side question.');

batch_context_ok(hache_sharky_whatsapp_batch_joinable_interactive('qualify:beginner'),'Beginner discovery button must be eligible to join a pending question burst.');
batch_context_ok(hache_sharky_whatsapp_batch_joinable_interactive('qualify:swims'),'Swimmer discovery button must be eligible to join a pending question burst.');
batch_context_ok(hache_sharky_whatsapp_batch_joinable_interactive('sede:palapas'),'Venue discovery button must be eligible to join a pending question burst.');
batch_context_ok(!hache_sharky_whatsapp_batch_joinable_interactive('flow:yes'),'Confirmation buttons must never be delayed/coalesced.');
batch_context_ok(!hache_sharky_whatsapp_batch_joinable_interactive('action:register_intensive'),'Business-action buttons must never be delayed/coalesced.');
batch_context_ok(!hache_sharky_whatsapp_batch_joinable_interactive('action:human'),'Human takeover must never be delayed/coalesced.');

$marker=hache_sharky_whatsapp_batch_encode_interactive([
    'interactive_id'=>'qualify:beginner',
    'text'=>'Desde cero',
]);
batch_context_ok($marker!=='','Safe interactive choice must serialize for the debounce queue.');
$decoded=hache_sharky_whatsapp_batch_decode_interactive($marker);
batch_context_ok(is_array($decoded)&&($decoded['id']??'')==='qualify:beginner'&&($decoded['title']??'')==='Desde cero','Serialized qualification choice must round-trip without losing semantic ID or title.');

$burst=hache_sharky_whatsapp_batch_unpack("Que precio tienen las clases de natación\n".$marker);
batch_context_ok(($burst['text']??'')==='Que precio tienen las clases de natación',"Batch must keep the customer's free-text question separate from button metadata.");
batch_context_ok(count($burst['interactives']??[])===1&&($burst['interactives'][0]['id']??'')==='qualify:beginner','Batch must retain beginner button semantics instead of flattening it to plain text.');

$contact='529980000001';
$dir=hache_sharky_orchestrator_runtime_dir('batch');
batch_context_ok($dir!=='','Batch runtime directory must be available in CLI regression.');
$queue=$dir.'/'.hache_sharky_orchestrator_contact_hash($contact).'.json';
$nowMs=(int)floor(microtime(true)*1000);
$pending=['first_at_ms'=>$nowMs,'flush_at_ms'=>$nowMs+60000,'events'=>[['text'=>'Que precio tienen las clases','interactive_id'=>'']]];
file_put_contents($queue,json_encode($pending,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
batch_context_ok(hache_sharky_whatsapp_batch_pending_question($contact),'A live queued question must accept a safe discovery tap.');
$pending['flush_at_ms']=$nowMs-1;
file_put_contents($queue,json_encode($pending,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
batch_context_ok(!hache_sharky_whatsapp_batch_pending_question($contact),'An expired queued question must not absorb a later discovery tap.');
@unlink($queue);

$semanticDecision=hache_sharky_orchestrator_decision(
    'qualification_background',
    '¿Cómo aprendiste a nadar?',
    ['type'=>'buttons','buttons'=>[
        hache_sharky_orchestrator_button('qualify:formal','Con clases'),
        hache_sharky_orchestrator_button('qualify:self','Por mi cuenta'),
    ]]
);
$semanticResult=['decision'=>$semanticDecision,'payload'=>hache_sharky_whatsapp_render($contact,$semanticDecision)];
$textResult=[
    'decision'=>['kind'=>'side_question','message'=>'El precio depende del programa.','ui'=>[],'action'=>null],
    'payload'=>hache_sharky_whatsapp_text_payload($contact,'El precio depende del programa.'),
];
$merged=hache_sharky_whatsapp_batch_merge_semantic_controls($contact,$semanticResult,$textResult);
batch_context_ok(($merged['decision']['kind']??'')==='side_question','Merged side question must remain identifiable as a side question.');
batch_context_ok(($merged['decision']['ui']['type']??'')==='buttons','Semantic next-step buttons must survive the text pass.');
batch_context_ok(str_contains((string)($merged['decision']['message']??''),'El precio depende del programa.')&&str_contains((string)($merged['decision']['message']??''),'¿Cómo aprendiste a nadar?'),'Merged reply must include both the answer and the semantic next-step prompt.');

$longAnswer=str_repeat('Monteverde ',140);
$longTextResult=[
    'decision'=>['kind'=>'side_question','message'=>$longAnswer,'ui'=>[],'action'=>null],
    'payload'=>hache_sharky_whatsapp_text_payload($contact,$longAnswer),
];
$longMerged=hache_sharky_whatsapp_batch_merge_semantic_controls($contact,$semanticResult,$longTextResult);
$longDecisionMessage=(string)($longMerged['decision']['message']??'');
$longInteractiveBody=(string)($longMerged['payload']['interactive']['body']['text']??'');
batch_context_ok(mb_strlen($longDecisionMessage)<=1024,'Merged interactive decision must stay within the WhatsApp body limit after display-label expansion.');
batch_context_ok(mb_strlen($longInteractiveBody)<=1024,'Rendered interactive body must stay within the WhatsApp body limit.');
batch_context_ok(str_ends_with($longDecisionMessage,'¿Cómo aprendiste a nadar?'),'Long merged answer must reserve the tail for the semantic next-step prompt.');
batch_context_ok(str_contains($longInteractiveBody,'¿Cómo aprendiste a nadar?'),'Rendered long interactive body must retain the prompt that explains its buttons.');
batch_context_ok(!preg_match('/(?<!Colegio )\bMonteverde\b/u',$longDecisionMessage),'Body budgeting must happen after venue display labels are normalized.');

$handoffDecision=hache_sharky_orchestrator_decision('student_human_takeover','Te dejo con el equipo.',[],['type'=>'human_takeover']);
$handoffResult=['decision'=>$handoffDecision,'payload'=>hache_sharky_whatsapp_render($contact,$handoffDecision)];
$handoffMerged=hache_sharky_whatsapp_batch_merge_semantic_controls($contact,$semanticResult,$handoffResult);
batch_context_ok(($handoffMerged['decision']['kind']??'')==='student_human_takeover','A policy decision must supersede semantic controls.');

$venueState=hache_sharky_orchestrator_state(null,1788640000);
$venueState['identity']=array_replace($venueState['identity'],['kind'=>'prospect','verified'=>true,'source'=>'self_declared']);
$venueState['commercial_context']['program']='intensive';
$venueState['commercial_context']['sede_clave']='MONTEVERDE';
$venueState['commercial_context']['swim_level']='beginner';
$venueState['commercial_context']['age']=59;
$venueChange=hache_sharky_whatsapp_historical_venue_reselection($venueState,[
    'interactive_id'=>'sede:palapas','text'=>'Palapas Protudec',
]);
batch_context_ok(is_array($venueChange),'A historical venue button must be accepted when commercial context is already ready.');
[$venueChangedState,$venueChangedDecision]=$venueChange;
batch_context_ok(($venueChangedState['commercial_context']['sede_clave']??'')==='PALAPAS','Historical venue button must update only the selected venue.');
batch_context_ok(($venueChangedState['commercial_context']['program']??'')==='intensive','Venue correction must preserve the selected program.');
batch_context_ok(($venueChangedState['commercial_context']['swim_level']??'')==='beginner','Venue correction must preserve swim qualification.');
batch_context_ok(($venueChangedState['commercial_context']['age']??null)===59,'Venue correction must preserve declared age.');
batch_context_ok(($venueChangedDecision['kind']??'')==='commercial_next_action','Venue correction must return to the commercial menu instead of stale-button rejection.');
batch_context_ok(str_contains((string)($venueChangedDecision['message']??''),'Palapas Protudec')&&str_contains((string)($venueChangedDecision['message']??''),'precio total'),'Venue correction must acknowledge the new venue and show the intensive information block.');
$venueButtons=array_map(static fn(array $button):string=>(string)($button['id']??''),(array)($venueChangedDecision['ui']['buttons']??[]));
batch_context_ok($venueButtons===['action:register_intensive','flow:pause'],'Intensive venue correction must offer only registration or deferral after the information block.');

$sameVenue=hache_sharky_whatsapp_historical_venue_reselection($venueState,[
    'interactive_id'=>'sede:monteverde','text'=>'Colegio Monteverde',
]);
batch_context_ok(is_array($sameVenue)&&($sameVenue[1]['kind']??'')==='commercial_next_action','Re-selecting the current venue outside a controlled flow must not produce a stale-button error.');

$regularState=$venueState;
$regularState['commercial_context']['program']='regular';
$regularState['commercial_context']['swim_level']='swims';
$regularState['commercial_context']['background']='formal';
$regularState['commercial_context']['sede_clave']='PALAPAS';
$regularChange=hache_sharky_whatsapp_historical_venue_reselection($regularState,[
    'interactive_id'=>'sede:monteverde','text'=>'Colegio Monteverde',
]);
batch_context_ok(is_array($regularChange)&&($regularChange[0]['commercial_context']['program']??'')==='regular','Regular-program venue correction must preserve the regular program for a formally trained swimmer.');
$regularButtons=array_map(static fn(array $button):string=>(string)($button['id']??''),(array)($regularChange[1]['ui']['buttons']??[]));
batch_context_ok(in_array('action:commercial_schedules',$regularButtons,true)&&in_array('action:commercial_price',$regularButtons,true)&&!in_array('action:register_intensive',$regularButtons,true),'Regular venue correction must offer schedule and price without an intensive-registration action.');

$registrationState=hache_sharky_orchestrator_flow($venueState,'register_intensive','schedule',[
    'sede_clave'=>'MONTEVERDE','course_id'=>123,'schedule_id'=>456,'name'=>'Persona de prueba',
],1788640000);
$registrationChange=hache_sharky_whatsapp_historical_venue_reselection($registrationState,[
    'interactive_id'=>'sede:palapas','text'=>'Palapas Protudec',
]);
batch_context_ok(is_array($registrationChange)&&!is_array($registrationChange[0]['flow']??null),'Changing venue during registration must invalidate the venue-dependent controlled flow before returning to the commercial menu.');
batch_context_ok(($registrationChange[0]['commercial_context']['program']??'')==='intensive'&&($registrationChange[0]['commercial_context']['swim_level']??'')==='beginner','Registration venue correction must preserve venue-independent commercial memory.');

$source=file_get_contents(__DIR__.'/../config/sharky-whatsapp-batching.php')?:'';
$dbSource=file_get_contents(__DIR__.'/../config/sharky-orchestrator-db.php')?:'';
$runtimeSource=file_get_contents(__DIR__.'/../config/sharky-runtime.php')?:'';
$labSource=file_get_contents(__DIR__.'/../config/sharky-lab-worker.php')?:'';
batch_context_ok(str_contains($source,'hache_sharky_whatsapp_batch_pending_question($contact)'),'Interactive coalescing must require an already-pending question.');
batch_context_ok(str_contains($source,'$flushAtMs<=0||$flushAtMs<=$nowMs'),'Pending-question detection must reject expired batch queues.');
batch_context_ok(str_contains($source,'$choice=$choices[array_key_last($choices)]'),'If repeated taps arrive in one burst, only the latest safe discovery choice may be applied.');
batch_context_ok(str_contains($source,"if(\$latestReferral!==null)\$semantic['referral']=\$latestReferral"),'Coalesced semantic choices must retain the latest ad referral.');
batch_context_ok(str_contains($source,"'id'=>\$baseId.':text'")&&str_contains($source,'hache_sharky_whatsapp_process_with_delivery_lock($pdo,$textSynthetic,$conversationAnswer,$textContext)'),'Pending plain text must re-enter the normal guarded WhatsApp processing pipeline after the choice is applied.');
batch_context_ok(str_contains($source,"\$transferredLock=\$extraContext['_delivery_lock']??null")&&str_contains($source,"if(is_resource(\$heldDeliveryLock))\$textContext['_delivery_lock']=\$heldDeliveryLock"),'Coalesced semantic and text passes must transfer one delivery lock instead of reacquiring it.');
batch_context_ok(str_contains($source,"if(\$plainText!=='')\$semanticContext['defer_delivery_unlock']=true"),'Semantic pass must hold the delivery lock until the queued text pass completes.');
batch_context_ok(str_contains($source,'hache_sharky_whatsapp_batch_merge_semantic_controls($contact,$semanticResult,$textResult)'),'Text side questions must preserve semantic next-step controls.');
batch_context_ok(str_contains($source,"\$textMessage=hache_sharky_whatsapp_display_labels")&&str_contains($source,"\$nextMessage=hache_sharky_whatsapp_display_labels"),'Display labels must be normalized before interactive body budgeting.');
batch_context_ok(str_contains($source,'$bodyLimit=1024')&&str_contains($source,'$room=$bodyLimit-mb_strlen($nextMessage)'),'Semantic prompt space must be reserved before trimming a long side-question answer.');
batch_context_ok(!str_contains($source,'hache_sharky_whatsapp_batch_answer_after_choice'),'Coalesced text must not bypass policy guards through the legacy LLM-only side-question helper.');
batch_context_ok(str_contains($source,"if(\$groupId!=='')return hache_sharky_whatsapp_process_with_delivery_lock"),'Group messages must remain outside direct-chat batching.');
batch_context_ok(str_contains($source,'hache_sharky_whatsapp_guarded_historical_venue_reselection($deferredState,$event')&&str_contains($source,'hache_sharky_whatsapp_underage_gate($state,$event,$minAge)'),'Historical venue correction must pass through the minimum-age guard before stale-button handling.');

$pendingReadPos=strpos($dbSource,"\$pending=\$GLOBALS['hache_sharky_db_state_pending']??null");
$readyCheckPos=strpos($dbSource,'if(!hache_sharky_db_state_ready($pdo))');
batch_context_ok($pendingReadPos!==false&&$readyCheckPos!==false&&$pendingReadPos<$readyCheckPos,'Deferred state load must read its own pending write before durable DB reload.');
batch_context_ok(str_contains($dbSource,"(string)(\$pending['contact']??'')===\$contact")&&str_contains($dbSource,"is_array(\$pending['state']??null)"),'Deferred read-your-writes must be scoped to the same contact and a valid state array.');

$resumeStart=strpos($runtimeSource,'function hache_sharky_takeover_resume_hash');
$resumeEnd=$resumeStart===false?false:strpos($runtimeSource,"\nfunction ",$resumeStart+10);
$resumeBody=$resumeStart===false?'':substr($runtimeSource,$resumeStart,$resumeEnd===false?null:$resumeEnd-$resumeStart);
batch_context_ok($resumeStart!==false&&str_contains($resumeBody,'@unlink($path)'),'Resume must only remove the takeover marker.');
batch_context_ok(!str_contains($resumeBody,'sharky_conversation_state')&&!str_contains($resumeBody,'hache_sharky_db_state_'),'Resume must never delete or rewrite Sharky conversation memory.');
$echoStart=strpos($labSource,"if(\$kind==='echo'){");
$echoEnd=$echoStart===false?false:strpos($labSource,"\n\n    \$contact=preg_replace",$echoStart);
$echoBody=$echoStart===false?'':substr($labSource,$echoStart,$echoEnd===false?null:$echoEnd-$echoStart);
batch_context_ok($echoStart!==false&&str_contains($echoBody,'hache_sharky_takeover_mark($contact'),'A manual WhatsApp echo must activate takeover.');
batch_context_ok(!str_contains($echoBody,'hache_sharky_db_state_save')&&!str_contains($echoBody,'hache_sharky_orchestrator_clear_flow'),'A manual human message must not clear or overwrite the saved conversational state that Sharky will resume later.');

fwrite(STDOUT,"SHARKY_BATCH_CONTEXT_OK\n");