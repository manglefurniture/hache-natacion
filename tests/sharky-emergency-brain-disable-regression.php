<?php

declare(strict_types=1);

$script=(string)file_get_contents(__DIR__.'/../bin/emergency-disable-brain-conversational.php');
$deploy=(string)file_get_contents(__DIR__.'/../.github/workflows/deploy.yml');
$wrapper=(string)file_get_contents(__DIR__.'/../ops/production-readiness/deploy-hache-natacion-wrapper');

function emergency_brain_ok(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"SHARKY EMERGENCY BRAIN DISABLE FAIL: {$message}\n");exit(1);}
}

emergency_brain_ok(str_contains($script,"sharky_brain_conversacional_habilitado"),'Retirement script must disable conversational Brain.');
emergency_brain_ok(str_contains($script,"sharky_brain_2ba_habilitado"),'Retirement script must disable Brain 2B-A.');
emergency_brain_ok(str_contains($script,"sharky_brain_2ba_canary_pct"),'Retirement script must zero the Brain canary.');
emergency_brain_ok(substr_count($script,"'valor'=>'0'")>=3,'All live Brain switches/canary must be forced OFF.');
emergency_brain_ok(str_contains($script,'beginTransaction')&&str_contains($script,'commit'),'Brain retirement must update its switches atomically.');
emergency_brain_ok(str_contains($script,'updated_by=NULL'),'Operational retirement must not impersonate an admin user.');
emergency_brain_ok(str_contains($wrapper,'brain-off)')&&str_contains($wrapper,'disable_brain_conversational'),'Protected helper must preserve the explicit brain-off operation.');
emergency_brain_ok(str_contains($wrapper,'bin/emergency-disable-brain-conversational.php'),'Protected helper must execute the tracked retirement script from the deployed repository.');
emergency_brain_ok(str_contains($deploy,'Keep Brain retired'),'Normal deploys must explicitly preserve Brain retirement.');
emergency_brain_ok(str_contains($deploy,'deploy-hache-natacion brain-off'),'Brain retirement must cross the root boundary only through the protected helper.');

fwrite(STDOUT,"SHARKY_EMERGENCY_BRAIN_DISABLE_OK\n");
