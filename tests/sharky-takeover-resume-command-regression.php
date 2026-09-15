<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-whatsapp-echoes.php';

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

$humanWorker=file_get_contents(__DIR__.'/../config/sharky-human-worker.php')?:'';
$graceRuntime=file_get_contents(__DIR__.'/../config/sharky-human-grace-runtime.php')?:'';
$intervention=file_get_contents(__DIR__.'/../config/sharky-human-intervention.php')?:'';
$echoSource=file_get_contents(__DIR__.'/../config/sharky-whatsapp-echoes.php')?:'';
$webhook=file_get_contents(__DIR__.'/../public/api/whatsapp-orchestrator-lab.php')?:'';
$inboxWorker=file_get_contents(__DIR__.'/../bin/sharky-inbox-dispatch.php')?:'';

resume_command_ok(str_contains($humanWorker,"'manual_sleep'"),'Sleep must persist an explicit manual takeover reason.');
resume_command_ok(str_contains($humanWorker,'hache_sharky_takeover_resume_hash'),'Wake must release only that conversation takeover.');
resume_command_ok(str_contains($humanWorker,'hache_sharky_human_grace_mark'),'An ordinary human reply must create manual_grace instead of automatic full takeover.');
resume_command_ok(str_contains($humanWorker,'hache_sharky_human_cancel_automatic_outbox'),'Human intervention must fence stale automatic outbound replies.');
resume_command_ok(str_contains($humanWorker,"status='CANCELLED'")&&str_contains($humanWorker,"['_sharky_allow_takeover']"),'Automatic PENDING rows must be cancellable without cancelling protected takeover delivery.');
resume_command_ok(str_contains($humanWorker,'hache_sharky_human_grace_wait'),'Customer replies after a human intervention must enter the grace timer.');
resume_command_ok(str_contains($humanWorker,"\$event['interactive_id']='meta:free_text'"),'A safe human question followed by a short confirmation must reuse the closed-funnel side-question lane.');
resume_command_ok(str_contains($graceRuntime,'hache_sharky_human_inbox_defer_until'),'Grace must leave a durable inbox recovery boundary before waiting.');
resume_command_ok(str_contains($graceRuntime,'hache_sharky_human_inbox_processed'),'A human intervention during the wait must stop the waiting Sharky turn.');
resume_command_ok(str_contains($graceRuntime,'hache_sharky_human_absorb_before_echo'),'Same-webhook races must not swallow a customer reply newer than the human echo.');
resume_command_ok(str_contains($intervention,"'latest_customer_event_id'")&&str_contains($intervention,'HACHE_SHARKY_HUMAN_GRACE_SECONDS'),'A genuinely newer customer message must move the 30-second deadline.');
resume_command_ok(str_contains($echoSource,"'operator_command'=>\$command??''")&&str_contains($echoSource,"'text'=>\$command===null"),'Operator commands must stay out of transcript text while remaining recoverable.');
resume_command_ok(str_contains($webhook,"require_once __DIR__.'/../../config/sharky-human-worker.php'")&&str_contains($webhook,'hache_sharky_human_process_event'),'The live webhook must use the supervised worker.');
resume_command_ok(str_contains($inboxWorker,"require_once __DIR__.'/../config/sharky-human-worker.php'")&&str_contains($inboxWorker,'hache_sharky_human_process_event'),'Durable inbox recovery must use the same supervised worker.');
resume_command_ok(!str_contains($humanWorker,'Hola, ya estoy de vuelta. ¿Continuamos?'),'Wake must be an internal control action, not an automatic customer-facing acknowledgement.');

$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE configuracion (clave TEXT PRIMARY KEY, valor TEXT NOT NULL)');
$pdo->exec("INSERT INTO configuracion(clave,valor) VALUES ('sharky_maps_monteverde','https://maps.example/mv'),('sharky_maps_palapas','https://maps.example/pal')");
$locations=hache_sharky_safe_side_answer($pdo,['commercial_context'=>[]],'¿Me pasas las ubicaciones?');
resume_command_ok(is_string($locations)&&str_contains($locations,'https://maps.example/mv')&&str_contains($locations,'https://maps.example/pal'),'Plural location intent must return both verified location authorities.');

$spec=file_get_contents(__DIR__.'/../docs/SHARKY-HUMAN-INTERVENTION-SPEC.md')?:'';
resume_command_ok(str_contains($spec,'`manual_grace`')&&str_contains($spec,'Sharky duerme')&&str_contains($spec,'Sharky despierta'),'The implementation must remain aligned with the approved product specification.');

echo "SHARKY_HUMAN_INTERVENTION_OK\n";
