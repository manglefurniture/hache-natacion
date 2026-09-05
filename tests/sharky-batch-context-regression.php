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

// A safe tap may join only a queue whose debounce deadline is still in the future.
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

// A side question must keep the semantic pass's next-step controls instead of
// replacing buttons/list with a plain-text-only payload.
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

$handoffDecision=hache_sharky_orchestrator_decision('student_human_takeover','Te dejo con el equipo.',[],['type'=>'human_takeover']);
$handoffResult=['decision'=>$handoffDecision,'payload'=>hache_sharky_whatsapp_render($contact,$handoffDecision)];
$handoffMerged=hache_sharky_whatsapp_batch_merge_semantic_controls($contact,$semanticResult,$handoffResult);
batch_context_ok(($handoffMerged['decision']['kind']??'')==='student_human_takeover','A policy decision must supersede semantic controls.');

$source=file_get_contents(__DIR__.'/../config/sharky-whatsapp-batching.php')?:'';
$dbSource=file_get_contents(__DIR__.'/../config/sharky-orchestrator-db.php')?:'';
batch_context_ok(str_contains($source,'hache_sharky_whatsapp_batch_pending_question($contact)'),'Interactive coalescing must require an already-pending question.');
batch_context_ok(str_contains($source,'$flushAtMs<=0||$flushAtMs<=$nowMs'),'Pending-question detection must reject expired batch queues.');
batch_context_ok(str_contains($source,'$choice=$choices[array_key_last($choices)]'),'If repeated taps arrive in one burst, only the latest safe discovery choice may be applied.');
batch_context_ok(str_contains($source,"if(\$latestReferral!==null)\$semantic['referral']=\$latestReferral"),'Coalesced semantic choices must retain the latest ad referral.');
batch_context_ok(str_contains($source,"'id'=>\$baseId.':text'")&&str_contains($source,'hache_sharky_whatsapp_process_with_delivery_lock($pdo,$textSynthetic,$conversationAnswer,$textContext)'),'Pending plain text must re-enter the normal guarded WhatsApp processing pipeline after the choice is applied.');
batch_context_ok(str_contains($source,"\$transferredLock=\$extraContext['_delivery_lock']??null")&&str_contains($source,"if(is_resource(\$heldDeliveryLock))\$textContext['_delivery_lock']=\$heldDeliveryLock"),'Coalesced semantic and text passes must transfer one delivery lock instead of reacquiring it.');
batch_context_ok(str_contains($source,"if(\$plainText!=='')\$semanticContext['defer_delivery_unlock']=true"),'Semantic pass must hold the delivery lock until the queued text pass completes.');
batch_context_ok(str_contains($source,'hache_sharky_whatsapp_batch_merge_semantic_controls($contact,$semanticResult,$textResult)'),'Text side questions must preserve semantic next-step controls.');
batch_context_ok(!str_contains($source,'hache_sharky_whatsapp_batch_answer_after_choice'),'Coalesced text must not bypass policy guards through the legacy LLM-only side-question helper.');
batch_context_ok(str_contains($source,"if(\$groupId!=='')return hache_sharky_whatsapp_process_with_delivery_lock"),'Group messages must remain outside direct-chat batching.');

$pendingReadPos=strpos($dbSource,"\$pending=\$GLOBALS['hache_sharky_db_state_pending']??null");
$readyCheckPos=strpos($dbSource,'if(!hache_sharky_db_state_ready($pdo))');
batch_context_ok($pendingReadPos!==false&&$readyCheckPos!==false&&$pendingReadPos<$readyCheckPos,'Deferred state load must read its own pending write before durable DB reload.');
batch_context_ok(str_contains($dbSource,"(string)(\$pending['contact']??'')===\$contact")&&str_contains($dbSource,"is_array(\$pending['state']??null)"),'Deferred read-your-writes must be scoped to the same contact and a valid state array.');

fwrite(STDOUT,"SHARKY_BATCH_CONTEXT_OK\n");
