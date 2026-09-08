<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-whatsapp-echoes.php';

function resume_command_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY RESUME COMMAND FAIL: $message\n");exit(1);}
}

$payload=[
    'entry'=>[[
        'changes'=>[[
            'field'=>'smb_message_echoes',
            'value'=>[
                'metadata'=>['phone_number_id'=>'phone-id'],
                'message_echoes'=>[[
                    'id'=>'wamid.manual.resume',
                    'to'=>'+52 998 000 0001',
                    'type'=>'text',
                    'text'=>['body'=>'Sharky vuelve ahora'],
                ]],
            ],
        ]],
    ]],
];

$echoes=hache_sharky_whatsapp_extract_echoes($payload);
resume_command_ok(count($echoes)===1,'The manual echo must be extracted once.');
$echo=$echoes[0]??[];
resume_command_ok(($echo['to']??'')==='529980000001','The destination must remain normalized for takeover lookup.');
resume_command_ok(($echo['text']??'')==='Sharky vuelve ahora','The extractor must retain the operator text.');
resume_command_ok(hache_sharky_whatsapp_echo_resume_requested($echo),'The exact operator command must release takeover.');
resume_command_ok(hache_sharky_whatsapp_echo_resume_requested(['type'=>'text','text'=>'  SHARKY vuelve ahora!  ']),'Case, surrounding spaces and terminal punctuation may vary.');
resume_command_ok(!hache_sharky_whatsapp_echo_resume_requested(['type'=>'text','text'=>'Sharky vuelve mañana']),'A similar phrase must not release takeover.');
resume_command_ok(!hache_sharky_whatsapp_echo_resume_requested(['type'=>'text','text'=>'Por favor Sharky vuelve ahora']),'The command must remain exclusive rather than substring-based.');
resume_command_ok(!hache_sharky_whatsapp_echo_resume_requested(['type'=>'image','text'=>'Sharky vuelve ahora']),'Only a text echo may issue the operator command.');

$worker=file_get_contents(__DIR__.'/../config/sharky-lab-worker.php')?:'';
$echoStart=strpos($worker,"if(\$kind==='echo'){");
$echoEnd=$echoStart===false?false:strpos($worker,"\n\n    \$contact=preg_replace",$echoStart);
$echoBody=$echoStart===false?'':substr($worker,$echoStart,$echoEnd===false?null:$echoEnd-$echoStart);
resume_command_ok($echoStart!==false,'The worker must retain the manual echo control plane.');
resume_command_ok(str_contains($echoBody,'hache_sharky_whatsapp_echo_resume_requested($event)'),'The resume command must be checked before generic manual takeover.');
resume_command_ok(str_contains($echoBody,'hache_sharky_takeover_resume_hash(hache_sharky_contact_hash($contact))'),'The command must release only the takeover marker for that contact.');
resume_command_ok(str_contains($echoBody,"Hola, ya estoy de vuelta. ¿Continuamos?"),'Sharky must acknowledge the hand-back immediately.');
resume_command_ok(str_contains($echoBody,'hache_sharky_outbox_enqueue_raw'),'The acknowledgement must use the durable outbox without arming normal follow-ups.');
resume_command_ok(strpos($echoBody,'hache_sharky_whatsapp_echo_resume_requested($event)')<strpos($echoBody,'hache_sharky_takeover_mark($contact'),'The resume command must never be reclassified as a new manual takeover.');
resume_command_ok(!str_contains($echoBody,'hache_sharky_db_state_save')&&!str_contains($echoBody,'hache_sharky_orchestrator_clear_flow'),'Reactivation must not erase or rewrite conversation memory.');

echo "SHARKY_TAKEOVER_RESUME_COMMAND_OK\n";
