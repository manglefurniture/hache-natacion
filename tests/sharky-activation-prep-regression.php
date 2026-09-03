<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-activation.php';

function sharky_activation_expect(bool $ok,string $message):void
{
    if(!$ok){fwrite(STDERR,"SHARKY ACTIVATION FAIL: $message\n");exit(1);}
}

$root=dirname(__DIR__);
$preflight=file_get_contents($root.'/bin/sharky-orchestrator-preflight.php')?:'';
$migrator=file_get_contents($root.'/bin/migrate-sharky-orchestrator.php')?:'';
$status=file_get_contents($root.'/bin/sharky-orchestrator-status.php')?:'';
$inbox=file_get_contents($root.'/bin/sharky-inbox-dispatch.php')?:'';
$outbox=file_get_contents($root.'/bin/sharky-outbox-dispatch.php')?:'';
$runbook=file_get_contents($root.'/docs/SHARKY-2-ACTIVATION.md')?:'';

sharky_activation_expect(str_contains($migrator,"SHARKY_ORCHESTRATOR_LAB_ENABLED")&&str_contains($migrator,"Refusing migration"),'Migration runner must refuse to run while the lab is enabled.');
sharky_activation_expect(str_contains($migrator,"GET_LOCK('hache_sharky_orchestrator_migration',10)"),'Migration runner must serialize execution with a DB advisory lock.');
sharky_activation_expect(str_contains($migrator,'20260902_sharky_orchestrator.sql')&&str_contains($migrator,'20260903_sharky_orchestrator_hardening.sql'),'Migration runner must apply base then hardening migrations.');
sharky_activation_expect(str_contains($preflight,'SHARKY_PREFLIGHT_OK')&&str_contains($preflight,'--allow-enabled'),'Preflight must expose safe before/after activation modes.');
sharky_activation_expect(str_contains($status,'feature_flag')&&str_contains($status,'queues'),'Status command must remain technical and queue-oriented.');
sharky_activation_expect(str_contains($inbox,"SHARKY_ORCHESTRATOR_LAB_ENABLED')!=='1'")&&str_contains($inbox,'disabled'),'Inbox worker must stop processing automatically when the lab flag is off.');
sharky_activation_expect(str_contains($outbox,"SHARKY_ORCHESTRATOR_LAB_ENABLED')!=='1'")&&str_contains($outbox,'disabled'),'Outbox worker must stop sending automatically when the lab flag is off.');
sharky_activation_expect(str_contains($runbook,'Rollback inmediato')&&str_contains($runbook,'SHARKY_ORCHESTRATOR_LAB_ENABLED=0'),'Runbook must define an explicit rollback path.');

$sql="-- semicolon ; in comment\nCREATE TABLE x (v VARCHAR(10) DEFAULT ';');\n/* block ; */\nINSERT INTO x(v) VALUES('a;b');\n";
$parts=hache_sharky_activation_split_sql($sql);
sharky_activation_expect(count($parts)===2,'SQL splitter must ignore semicolons in comments and quoted strings.');
sharky_activation_expect(str_contains($parts[0],"DEFAULT ';'"),'SQL splitter must preserve quoted semicolons.');
sharky_activation_expect(str_contains($parts[1],"'a;b'"),'SQL splitter must preserve string payloads.');

foreach([
    'ops/systemd/hache-sharky-inbox.service',
    'ops/systemd/hache-sharky-inbox.timer',
    'ops/systemd/hache-sharky-outbox.service',
    'ops/systemd/hache-sharky-outbox.timer',
] as $file)sharky_activation_expect(is_file($root.'/'.$file),'Missing worker unit '.$file);

fwrite(STDOUT,"SHARKY_ACTIVATION_PREP_OK\n");
