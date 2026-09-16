<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-whatsapp-echoes.php';
require_once __DIR__.'/../config/sharky-human-grace-runtime.php';

function resume_command_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY HUMAN INTERVENTION FAIL: $message\n");exit(1);}
}

$payload=[
    'entry'=>[[
        'changes'=>[[
            'field'=>'smb_message_echoes',
            'value'=>[
                'metadata'=>['phone_number_id'=>'phone-id'],
                'message_echoes'=>[[
                    'id'=>'wamid.manual.wake',
                    'to'=>'+52 998 000 0001',
                    'timestamp'=>'1789500000',
                    'type'=>'text',
                    'text'=>['body'=>'Sharky despierta'],
                ]],
            ],
        ]],
    ]],
];

$echoes=hache_sharky_whatsapp_extract_echoes($payload);
resume_command_ok(count($echoes)===1,'The manual echo must be extracted once.');
$echo=$echoes[0]??[];
resume_command_ok(($echo['to']??'')==='529980000001','The destination must remain normalized for conversation lookup.');
resume_command_ok(($echo['text']??'')==='','Operator commands must not be persisted as conversation text.');
resume_command_ok(($echo['operator_command']??'')==='wake','The extractor must preserve the wake action as control-plane metadata.');
resume_command_ok(($echo['timestamp_ms']??0)===1789500000000,'The echo timestamp must survive for race-safe ordering.');
resume_command_ok(hache_sharky_whatsapp_echo_resume_requested($echo),'Sharky despierta must release exclusive takeover.');
resume_command_ok(hache_sharky_whatsapp_echo_resume_requested(['type'=>'text','text'=>'  SHARKY despierta!  ']),'Case, spaces and terminal punctuation may vary.');
resume_command_ok(!hache_sharky_whatsapp_echo_resume_requested(['type'=>'text','text'=>'Sharky vuelve ahora']),'The retired command must no longer wake Sharky.');
resume_command_ok(!hache_sharky_whatsapp_echo_resume_requested(['type'=>'text','text'=>'Por favor Sharky despierta']),'Wake must remain an exact operator command.');
resume_command_ok(!hache_sharky_whatsapp_echo_resume_requested(['type'=>'image','text'=>'Sharky despierta']),'Only text echoes may issue commands.');

resume_command_ok(hache_sharky_whatsapp_echo_sleep_requested(['type'=>'text','text'=>'Sharky duerme']),'Sharky duerme must request exclusive takeover.');
resume_command_ok(hache_sharky_whatsapp_echo_sleep_requested(['type'=>'text','text'=>'  SHARKY DUERME. ']),'Sleep may vary in case and terminal punctuation.');
resume_command_ok(!hache_sharky_whatsapp_echo_sleep_requested(['type'=>'text','text'=>'Sharky duerme un rato']),'Sleep must remain exact rather than substring-based.');
resume_command_ok(hache_sharky_human_command_text('Sharky duerme')&&hache_sharky_human_command_text('Sharky despierta'),'Operational commands must be identifiable as non-learning content.');
resume_command_ok(HACHE_SHARKY_HUMAN_GRACE_SECONDS===30,'Manual grace must be exactly 30 seconds.');
resume_command_ok(hache_sharky_human_affirmative_turn("Sí.\nGracias"),'An affirmative reply followed by a courtesy message must remain one affirmative human-context turn.');
resume_command_ok(hache_sharky_human_affirmative_turn("Gracias\nSí."),'Courtesy ordering within the same-second burst must not lose an otherwise unambiguous affirmative turn.');
resume_command_ok(!hache_sharky_human_affirmative_turn("Sí.\nPero quiero solo Palapas"),'A substantive follow-up must not be collapsed into a bare affirmative turn.');

$humanWorker=file_get_contents(__DIR__.'/../config/sharky-human-worker.php')?:'';
$graceRuntime=file_get_contents(__DIR__.'/../config/sharky-human-grace-runtime.php')?:'';
$intervention=file_get_contents(__DIR__.'/../config/sharky-human-intervention.php')?:'';
$echoSource=file_get_contents(__DIR__.'/../config/sharky-whatsapp-echoes.php')?:'';
$inboxSource=file_get_contents(__DIR__.'/../config/sharky-inbox.php')?:'';
$webhook=file_get_contents(__DIR__.'/../public/api/whatsapp-orchestrator-lab.php')?:'';
$inboxWorker=file_get_contents(__DIR__.'/../bin/sharky-inbox-dispatch.php')?:'';

resume_command_ok(str_contains($humanWorker,"'manual_sleep'"),'Sleep must persist an explicit manual takeover reason.');
resume_command_ok(str_contains($humanWorker,'hache_sharky_takeover_resume_hash'),'Wake must release only that conversation takeover.');
resume_command_ok(str_contains($humanWorker,'hache_sharky_human_grace_mark'),'An ordinary human reply must create manual_grace instead of automatic full takeover.');
resume_command_ok(str_contains($humanWorker,'hache_sharky_human_cancel_automatic_outbox'),'Human intervention must fence stale automatic outbound replies.');
resume_command_ok(str_contains($humanWorker,"status='CANCELLED'")&&str_contains($humanWorker,"['_sharky_allow_takeover']"),'Automatic PENDING rows must be cancellable without cancelling protected takeover delivery.');
resume_command_ok(str_contains($humanWorker,'hache_sharky_human_grace_wait'),'Customer replies after a human intervention must enter the grace timer.');
resume_command_ok(str_contains($humanWorker,'hache_sharky_human_latest_pending_customer_event'),'The live worker must fence an older waiter when a newer durable customer message exists.');
resume_command_ok(str_contains($humanWorker,'hache_sharky_human_affirmative_turn'),'Human-context recovery must understand an affirmative burst with a trailing courtesy message.');
resume_command_ok(str_contains($humanWorker,"(int)(\$event['timestamp_ms']??0)"),'Grace recovery must preserve the original customer timestamp.');
resume_command_ok(str_contains($humanWorker,"\$event['interactive_id']='meta:free_text'"),'A safe human question followed by a short confirmation must reuse the closed-funnel side-question lane.');
resume_command_ok(str_contains($graceRuntime,'hache_sharky_human_inbox_defer_until'),'Grace must leave a durable inbox recovery boundary before waiting.');
resume_command_ok(str_contains($graceRuntime,'hache_sharky_human_inbox_release_lease'),'Expired grace must release its receipt before normal processing claims it.');
resume_command_ok(str_contains($graceRuntime,'hache_sharky_human_latest_customer_row'),'Grace must consult the durable inbox before an older customer turn is allowed to answer.');
resume_command_ok(str_contains($graceRuntime,'hache_sharky_human_event_uses_generic_lane')&&str_contains($graceRuntime,'hache_sharky_member_supported_event')&&str_contains($graceRuntime,'hache_sharky_commerce_event_candidate'),'Grace election must ignore receipts owned by protected alternate routers.');
resume_command_ok(str_contains($graceRuntime,"HACHE_SHARKY_REGULAR_FLOW_KIND"),'Regular enrollment receipts must stay outside generic grace election.');
resume_command_ok(str_contains($inboxSource,"'_inbox_arrival_us'")&&str_contains($inboxSource,'hache_sharky_inbox_arrival_us'),'New durable receipts must carry a monotonic microsecond arrival marker for equal WhatsApp timestamps.');
resume_command_ok(str_contains($graceRuntime,'hache_sharky_human_inbox_processed'),'A human intervention during the wait must stop the waiting Sharky turn.');
resume_command_ok(str_contains($graceRuntime,'hache_sharky_human_absorb_before_echo'),'Same-webhook races must not swallow a customer reply newer than the human echo.');
resume_command_ok(str_contains($intervention,"'latest_customer_event_id'")&&str_contains($intervention,"'seen_customer_event_ids'")&&str_contains($intervention,'HACHE_SHARKY_HUMAN_GRACE_SECONDS'),'A genuinely newer customer message must move the 30-second deadline without replay rewind.');
resume_command_ok(str_contains($intervention,'SELECT 1 FROM sharky_message_receipts WHERE message_id=:m AND processed_at IS NULL LIMIT 1'),'Deferring the same durable receipt twice must remain idempotently valid.');
resume_command_ok(str_contains($echoSource,"'operator_command'=>\$command??''")&&str_contains($echoSource,"'text'=>\$command===null"),'Operator commands must stay out of transcript text while remaining recoverable.');
resume_command_ok(str_contains($webhook,"require_once __DIR__.'/../../config/sharky-human-worker.php'")&&str_contains($webhook,'hache_sharky_human_process_event'),'The live webhook must use the supervised worker.');
resume_command_ok(str_contains($inboxWorker,"require_once __DIR__.'/../config/sharky-human-worker.php'")&&str_contains($inboxWorker,'hache_sharky_human_process_event'),'Durable inbox recovery must use the same supervised worker.');
resume_command_ok(!str_contains($humanWorker,'Hola, ya estoy de vuelta. ¿Continuamos?'),'Wake must be an internal control action, not an automatic customer-facing acknowledgement.');

$graceContact='529980009999';
$tz=new DateTimeZone('America/Cancun');
$base=(new DateTimeImmutable('today',$tz))->setTime(12,0)->getTimestamp();
hache_sharky_human_grace_clear($graceContact);
resume_command_ok(hache_sharky_human_grace_mark($graceContact,'human-1',$base),'A manual reply must persist grace state.');
$first=hache_sharky_human_grace_customer_turn($graceContact,'customer-1',$base+1,($base+1)*1000);
$second=hache_sharky_human_grace_customer_turn($graceContact,'customer-2',$base+10,($base+10)*1000);
$third=hache_sharky_human_grace_customer_turn($graceContact,'customer-3',$base+20,($base+20)*1000);
$retryFirst=hache_sharky_human_grace_customer_turn($graceContact,'customer-1',$base+80,($base+1)*1000);
$graceState=hache_sharky_human_grace_read($graceContact,$base+80);
resume_command_ok(($first['due_at']??0)===$base+31&&($second['due_at']??0)===$base+40&&($third['due_at']??0)===$base+50,'Each genuinely newer customer message must reset the 30-second deadline from its original timestamp.');
resume_command_ok(($retryFirst['latest']??true)===false&&($retryFirst['due_at']??0)===$base+50,'Recovery of an older receipt must not move the latest pointer or restart the timer.');
resume_command_ok(is_array($graceState)&&($graceState['latest_customer_event_id']??'')==='customer-3'&&count((array)($graceState['seen_customer_event_ids']??[]))===3,'Grace state must remember seen customer receipts and keep the true latest message.');
$latestRow=hache_sharky_human_latest_customer_row([
    ['message_id'=>'customer-1','event'=>['timestamp_ms'=>($base+1)*1000,'_inbox_arrival_us'=>100,'text'=>'Sí.']],
    ['message_id'=>'customer-2','event'=>['timestamp_ms'=>($base+10)*1000,'_inbox_arrival_us'=>200,'text'=>'Gracias']],
],$base*1000);
resume_command_ok(($latestRow['message_id']??'')==='customer-2','A durable two-message burst must elect only the last customer receipt as the responder.');
$equalRows=[
    ['message_id'=>'opaque-late','event'=>['timestamp_ms'=>($base+25)*1000,'_inbox_arrival_us'=>200,'text'=>'Gracias']],
    ['message_id'=>'opaque-early','event'=>['timestamp_ms'=>($base+25)*1000,'_inbox_arrival_us'=>100,'text'=>'Sí.']],
];
$ordered=hache_sharky_human_customer_rows_ordered($equalRows);
$latestEqual=hache_sharky_human_latest_customer_row($equalRows,$base*1000);
resume_command_ok(($ordered[0]['message_id']??'')==='opaque-early'&&($ordered[1]['message_id']??'')==='opaque-late','Equal WhatsApp timestamps must use durable arrival order rather than opaque message IDs or row position.');
resume_command_ok(($latestEqual['message_id']??'')==='opaque-late','The last same-second receipt must win grace election by durable arrival marker.');
hache_sharky_human_grace_clear($graceContact);

$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('FROM_UNIXTIME',static fn($value):string=>gmdate('Y-m-d H:i:s',(int)$value),1);
$pdo->exec('CREATE TABLE sharky_message_receipts (message_id TEXT PRIMARY KEY, processed_at TEXT NULL, lease_until TEXT NULL)');
$pdo->exec("INSERT INTO sharky_message_receipts(message_id,processed_at,lease_until) VALUES ('receipt-1',NULL,NULL)");
resume_command_ok(hache_sharky_human_inbox_defer_until($pdo,'receipt-1',$base+30),'A grace receipt must accept its deadline lease.');
resume_command_ok(hache_sharky_human_inbox_defer_until($pdo,'receipt-1',$base+30),'Reapplying the same deadline must remain successful.');
resume_command_ok(hache_sharky_human_inbox_release_lease($pdo,'receipt-1'),'An expired grace receipt must be claimable immediately.');
resume_command_ok($pdo->query("SELECT lease_until IS NULL FROM sharky_message_receipts WHERE message_id='receipt-1'")->fetchColumn()==1,'Lease release must leave the receipt available for the base worker claim.');

$pdo->exec('CREATE TABLE configuracion (clave TEXT PRIMARY KEY, valor TEXT NOT NULL)');
$pdo->exec("INSERT INTO configuracion(clave,valor) VALUES ('sharky_maps_monteverde','https://maps.app.goo.gl/Ld75bhLforGm2Tk68'),('sharky_maps_palapas','https://maps.app.goo.gl/L7aEf9phtXtciUj78')");
$locations=hache_sharky_safe_side_answer($pdo,['commercial_context'=>[]],'¿Me pasas las ubicaciones?');
resume_command_ok(is_string($locations)&&str_contains($locations,'https://maps.app.goo.gl/Ld75bhLforGm2Tk68')&&str_contains($locations,'https://maps.app.goo.gl/L7aEf9phtXtciUj78'),'Plural location intent must return both verified location authorities.');

$spec=file_get_contents(__DIR__.'/../docs/SHARKY-HUMAN-INTERVENTION-SPEC.md')?:'';
resume_command_ok(str_contains($spec,'`manual_grace`')&&str_contains($spec,'Sharky duerme')&&str_contains($spec,'Sharky despierta'),'The implementation must remain aligned with the approved product specification.');

echo "SHARKY_HUMAN_INTERVENTION_OK\n";
