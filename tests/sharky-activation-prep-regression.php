<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-activation.php';

function sharky_activation_expect(bool $ok,string $message):void
{
    if(!$ok){fwrite(STDERR,"SHARKY ACTIVATION FAIL: $message\n");exit(1);}
}

$root=dirname(__DIR__);
$activation=file_get_contents($root.'/config/sharky-activation.php')?:'';
$preflight=file_get_contents($root.'/bin/sharky-orchestrator-preflight.php')?:'';
$migrator=file_get_contents($root.'/bin/migrate-sharky-orchestrator.php')?:'';
$status=file_get_contents($root.'/bin/sharky-orchestrator-status.php')?:'';
$inbox=file_get_contents($root.'/bin/sharky-inbox-dispatch.php')?:'';
$outboxWorker=file_get_contents($root.'/bin/sharky-outbox-dispatch.php')?:'';
$outboxCore=file_get_contents($root.'/config/sharky-outbox.php')?:'';
$runbook=file_get_contents($root.'/docs/SHARKY-2-ACTIVATION.md')?:'';
$inboxService=file_get_contents($root.'/ops/systemd/hache-sharky-inbox.service')?:'';
$outboxService=file_get_contents($root.'/ops/systemd/hache-sharky-outbox.service')?:'';

sharky_activation_expect(str_contains($migrator,"SHARKY_ORCHESTRATOR_LAB_ENABLED")&&str_contains($migrator,"Refusing migration"),'Migration runner must refuse to run while the lab is enabled.');
sharky_activation_expect(str_contains($migrator,"GET_LOCK('hache_sharky_orchestrator_migration',10)"),'Migration runner must serialize execution with a DB advisory lock.');
sharky_activation_expect(str_contains($migrator,'20260902_sharky_orchestrator.sql')&&str_contains($migrator,'20260903_sharky_orchestrator_hardening.sql'),'Migration runner must apply base then hardening migrations.');
sharky_activation_expect(str_contains($migrator,"throw new RuntimeException('Schema verification failed:"),'Migration runner must fail through the outer cleanup path when final schema verification fails.');

sharky_activation_expect(str_contains($preflight,'SHARKY_PREFLIGHT_OK')&&str_contains($preflight,'--allow-enabled'),'Preflight must expose safe before/after activation modes.');
sharky_activation_expect(str_contains($activation,'$flagOk=$allowEnabled?in_array($flag,[\'0\',\'1\'],true):$flag===\'0\';'),'Pre-activation preflight must require an explicit flag=0 instead of treating an unset flag as safe configuration.');
sharky_activation_expect(str_contains($activation,'pending_outbox_total')&&str_contains($activation,'pending_inbox_total')&&str_contains($activation,'pending_actions_total')&&str_contains($activation,'completed_actions_without_delivery'),'Preflight must account for stale actionable backlog before first activation.');
sharky_activation_expect(str_contains($activation,'$cleanCutoverOk=$allowEnabled||('),'Preflight must enforce an empty actionable backlog before cutover while allowing live diagnostics after activation.');
foreach(['fk_sharky_referral_alumno','fk_sharky_identity_student','fk_sharky_action_alumno'] as $constraint){
    sharky_activation_expect(str_contains($activation,$constraint),'Schema preflight must verify foreign key '.$constraint.'.');
}
sharky_activation_expect(str_contains($preflight,'missing_constraints')&&str_contains($preflight,'Colas limpias para cutover'),'CLI preflight must surface missing constraints and clean-cutover status.');

sharky_activation_expect(str_contains($status,'feature_flag')&&str_contains($status,'queues'),'Status command must remain technical and queue-oriented.');
sharky_activation_expect(str_contains($inbox,"SHARKY_ORCHESTRATOR_LAB_ENABLED')==='1'")&&str_contains($inbox,'disabled'),'Inbox worker must stop processing automatically when the lab flag is off.');
sharky_activation_expect(str_contains($outboxWorker,"SHARKY_ORCHESTRATOR_LAB_ENABLED')!=='1'")&&str_contains($outboxWorker,'disabled'),'Outbox worker must stop sending automatically when the lab flag is off.');
sharky_activation_expect(str_contains($outboxCore,"SHARKY_ORCHESTRATOR_LAB_ENABLED')!=='1'")&&str_contains($outboxCore,'return $stats'),'Outbox dispatch must fail closed unless the lab feature flag is exactly enabled.');
sharky_activation_expect(str_contains($runbook,'Rollback inmediato')&&str_contains($runbook,'SHARKY_ORCHESTRATOR_LAB_ENABLED=0'),'Runbook must define an explicit rollback path.');
sharky_activation_expect(str_contains($runbook,'sudo -u www-data test -r .env')&&str_contains($runbook,'sudo -u www-data php /var/www/hache-natacion/bin/sharky-orchestrator-preflight.php'),'Runbook must validate secrets/preflight using the same www-data identity as workers.');
sharky_activation_expect(str_contains($inboxService,'User=www-data')&&str_contains($outboxService,'User=www-data'),'Both systemd workers must run as www-data.');
sharky_activation_expect(str_contains($inboxService,'TimeoutStartSec=10min')&&str_contains($outboxService,'TimeoutStartSec=10min'),'Worker units need an explicit bounded runtime longer than the network retry budget.');

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
