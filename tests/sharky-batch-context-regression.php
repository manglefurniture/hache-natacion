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

$source=file_get_contents(__DIR__.'/../config/sharky-whatsapp-batching.php')?:'';
batch_context_ok(str_contains($source,'hache_sharky_whatsapp_batch_pending_question($contact)'),'Interactive coalescing must require an already-pending question.');
batch_context_ok(str_contains($source,'$choice=$choices[array_key_last($choices)]'),'If repeated taps arrive in one burst, only the latest safe discovery choice may be applied.');
batch_context_ok(str_contains($source,'hache_sharky_whatsapp_batch_answer_after_choice'),'The pending question must be answered after applying the selected discovery context.');
batch_context_ok(str_contains($source,"if(\$groupId!=='')return hache_sharky_whatsapp_process_with_delivery_lock"),'Group messages must remain outside direct-chat batching.');

fwrite(STDOUT,"SHARKY_BATCH_CONTEXT_OK\n");
