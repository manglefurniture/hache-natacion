<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-orchestrator.php';
require_once __DIR__.'/../config/sharky-followup.php';

function followup_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY FOLLOWUP FAIL: $message\n");exit(1);}
}

$tz=new DateTimeZone('America/Cancun');
$ts=static fn(string $local):int=>(new DateTimeImmutable($local,$tz))->getTimestamp();

// First reminder is 15 minutes after silence, unless it would land at/after 22:00.
$normal=$ts('2026-09-03 20:00:00')+HACHE_SHARKY_FOLLOWUP_FIRST_DELAY_SECONDS;
followup_ok(hache_sharky_followup_next_allowed_at($normal)===$ts('2026-09-03 20:15:00'),'A normal first reminder must remain at +15 minutes.');
$late=$ts('2026-09-03 21:50:00')+HACHE_SHARKY_FOLLOWUP_FIRST_DELAY_SECONDS;
followup_ok(hache_sharky_followup_next_allowed_at($late)===$ts('2026-09-04 08:00:00'),'A first reminder that crosses 22:00 must wait until 08:00 next day.');
followup_ok(hache_sharky_followup_next_allowed_at($ts('2026-09-03 03:25:00'))===$ts('2026-09-03 08:00:00'),'No reminder may be sent during madrugada.');
followup_ok(hache_sharky_followup_send_allowed_now($ts('2026-09-03 21:59:59')),'21:59 must still be inside the allowed window.');
followup_ok(!hache_sharky_followup_send_allowed_now($ts('2026-09-03 22:00:00')),'22:00 must be quiet hours.');
followup_ok(!hache_sharky_followup_send_allowed_now($ts('2026-09-03 07:59:59')),'Before 08:00 must be quiet hours.');
followup_ok(hache_sharky_followup_send_allowed_now($ts('2026-09-03 08:00:00')),'08:00 must reopen the reminder window.');

// Second reminder is 90 minutes after the first actual send, again respecting quiet hours.
$secondLate=$ts('2026-09-03 21:30:00')+HACHE_SHARKY_FOLLOWUP_SECOND_DELAY_SECONDS;
followup_ok(hache_sharky_followup_next_allowed_at($secondLate)===$ts('2026-09-04 08:00:00'),'A second reminder due at night must defer to 08:00.');
$secondMorning=$ts('2026-09-04 08:00:00')+HACHE_SHARKY_FOLLOWUP_SECOND_DELAY_SECONDS;
followup_ok(hache_sharky_followup_next_allowed_at($secondMorning)===$ts('2026-09-04 09:30:00'),'A deferred first reminder at 08:00 must place the second at 09:30.');

$state=hache_sharky_orchestrator_state(null,$ts('2026-09-03 19:00:00'));
$state['identity']=array_replace($state['identity'],['kind'=>'prospect','verified'=>true,'source'=>'self_declared']);
$state['commercial_context']['program']='intensive';
$state['commercial_context']['sede_clave']='PALAPAS';
$state['last_user_text']='Quiero información';
followup_ok(hache_sharky_followup_commercial_ready($state),'A commercially ready prospect must be eligible.');

$arm=hache_sharky_followup_arm_meta($state,'529980000000','message-1');
followup_ok(is_array($arm)&&strlen((string)($arm['token']??''))===40,'A delivered-reply arm must carry a deterministic opaque token.');
followup_ok(($arm['user_turn_at']??0)===$state['updated_at'],'The arm must be bound to the exact user-turn version.');
followup_ok(($arm['program']??'')==='intensive'&&($arm['sede_clave']??'')==='PALAPAS','The arm must snapshot program and venue for stale-context rejection.');

$p1=hache_sharky_followup_payload('529980000000',$state,1,'token-1',(int)$state['updated_at']);
followup_ok(($p1['type']??'')==='interactive','First follow-up must be an interactive WhatsApp message.');
followup_ok(array_column(array_map(static fn(array $b):array=>$b['reply'],$p1['interactive']['action']['buttons']??[]),'id')===['action:register_intensive','action:commercial_schedules','action:commercial_price'],'Intensive first follow-up must offer registration, schedules and price.');
followup_ok(str_contains((string)($p1['interactive']['body']['text']??''),'Palapas Protudec'),'Follow-up must reuse the confirmed venue.');

$p2=hache_sharky_followup_payload('529980000000',$state,2,'token-1',(int)$state['updated_at']);
followup_ok(count($p2['interactive']['action']['buttons']??[])===2,'Second follow-up must be softer and avoid repeating the registration push.');
followup_ok(!str_contains(hache_sharky_orchestrator_normalize((string)($p2['interactive']['body']['text']??'')),'te escribi antes'),'Second follow-up must not guilt the prospect about the previous message.');

$regular=$state;$regular['commercial_context']['program']='regular';
$r1=hache_sharky_followup_payload('529980000000',$regular,1,'token-r',(int)$regular['updated_at']);
followup_ok(array_column(array_map(static fn(array $b):array=>$b['reply'],$r1['interactive']['action']['buttons']??[]),'id')===['action:commercial_schedules','action:commercial_price'],'Regular follow-up must never promise automatic intensive registration.');

$flow=$state;$flow['flow']=['name'=>'register_intensive','step'=>'offer','data'=>[],'updated_at'=>$flow['updated_at']];
followup_ok(!hache_sharky_followup_commercial_ready($flow),'An active controlled flow must suppress idle sales reminders.');
$student=$state;$student['identity']['kind']='student';
followup_ok(!hache_sharky_followup_commercial_ready($student),'Existing students must not receive prospect sales reminders.');
$optout=$state;$optout['last_user_text']='No gracias, yo te aviso';
followup_ok(!hache_sharky_followup_commercial_ready($optout),'Explicit opt-out must suppress reminders.');
$closed=$state;$closed['last_user_text']='Muchas gracias';
followup_ok(!hache_sharky_followup_commercial_ready($closed),'A courteous conversation close must not trigger a sales reminder.');

// The reminder lifecycle lives inside the already-encrypted commercial context. It must
// survive normal state hydration and later commercial-context capture without resetting.
$durableFollowup=[
    'status'=>'first_sent','token'=>'durable-token','user_turn_at'=>(int)$state['updated_at'],
    'sent_count'=>1,'next_stage'=>2,'first_due_at'=>$ts('2026-09-03 19:15:00'),
    'first_sent_at'=>$ts('2026-09-03 19:15:00'),'second_due_at'=>$ts('2026-09-03 20:45:00'),'completed_at'=>null,
];
$durable=hache_sharky_followup_set_state($state,$durableFollowup);
$hydrated=hache_sharky_orchestrator_state($durable,$ts('2026-09-03 19:20:00'));
followup_ok((hache_sharky_followup_state($hydrated)['token']??null)==='durable-token','Follow-up token must survive orchestrator state hydration.');
followup_ok((hache_sharky_followup_state($hydrated)['sent_count']??0)===1,'Follow-up sent count must survive state hydration.');
$captured=hache_sharky_orchestrator_capture_commercial_context($hydrated,'¿Cuánto cuesta?');
followup_ok((hache_sharky_followup_state($captured)['next_stage']??null)===2,'Commercial context capture must preserve pending second-stage metadata.');

$recent=['status'=>'completed_two_sent','completed_at'=>$ts('2026-09-03 19:30:00')];
followup_ok(hache_sharky_followup_completed_recently($recent,$ts('2026-09-04 19:29:59')),'A completed sequence must not re-arm inside the same 24-hour session.');
followup_ok(!hache_sharky_followup_completed_recently($recent,$ts('2026-09-04 19:30:01')),'A completed sequence may become eligible only after the 24-hour session has elapsed.');

$outboxSource=file_get_contents(__DIR__.'/../config/sharky-outbox.php')?:'';
$followSource=file_get_contents(__DIR__.'/../config/sharky-followup.php')?:'';
followup_ok(str_contains($outboxSource,'hache_sharky_followup_prepare_normal_outbound'),'A normal outbound must prepare follow-up metadata before durable enqueue.');
followup_ok(str_contains($outboxSource,"unset(\$payload['_sharky_followup_arm'])"),'Internal follow-up arm metadata must never be sent to Meta.');
followup_ok(str_contains($outboxSource,"elseif(is_array(\$followupArm))hache_sharky_followup_after_normal_sent"),'The 15-minute timer must start only after the normal reply is externally sent and marked SENT.');
followup_ok(str_contains($outboxSource,'hache_sharky_followup_validate_before_send'),'A due reminder must revalidate state immediately before external send.');
followup_ok(str_contains($outboxSource,'hache_sharky_outbox_reschedule_owner'),'Quiet-hour enforcement must be able to defer an already-due outbox row.');
followup_ok(str_contains($outboxSource,'hache_sharky_followup_after_sent'),'Only a successfully sent first reminder may schedule the second reminder.');
followup_ok(str_contains($followSource,'processed_at IS NULL')&&str_contains($followSource,'received_at>FROM_UNIXTIME(:u)'),'A persisted newer inbound message must cancel a due reminder even before that inbound turn is processed.');
followup_ok(substr_count($followSource,"'idle-followup|'.\$token.'|1'")===1,'The first reminder must have exactly one scheduling site, after normal delivery succeeds.');
followup_ok(substr_count($followSource,"'idle-followup|'.\$token.'|2'")===1,'The second reminder must have exactly one scheduling site, after the first send succeeds.');

echo "SHARKY_FOLLOWUP_OK\n";
