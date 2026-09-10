<?php

declare(strict_types=1);

$script=(string)file_get_contents(__DIR__.'/../bin/emergency-disable-brain-conversational.php');
$deploy=(string)file_get_contents(__DIR__.'/../.github/workflows/deploy.yml');
$wrapper=(string)file_get_contents(__DIR__.'/../ops/production-readiness/deploy-hache-natacion-wrapper');

function emergency_brain_ok(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"SHARKY EMERGENCY BRAIN DISABLE FAIL: {$message}\n");exit(1);}
}

emergency_brain_ok(str_contains($script,"sharky_brain_conversacional_habilitado"),'Emergency script must target only the conversational Brain switch.');
emergency_brain_ok(str_contains($script,"':valor'=>'0'"),'Emergency script must force the conversational Brain switch OFF.');
emergency_brain_ok(str_contains($script,'updated_by=NULL'),'Emergency operational change must not impersonate an admin user.');
emergency_brain_ok(str_contains($wrapper,'brain-off)')&&str_contains($wrapper,'disable_brain_conversational'),'Protected helper must preserve the explicit manual brain-off operation.');
emergency_brain_ok(str_contains($wrapper,'bin/emergency-disable-brain-conversational.php'),'Protected helper must execute the tracked kill-switch script from the deployed repository.');
emergency_brain_ok(!str_contains($deploy,'deploy-hache-natacion brain-off'),'Normal deploys must not automatically disable conversational Brain.');

fwrite(STDOUT,"SHARKY_EMERGENCY_BRAIN_DISABLE_OK\n");
