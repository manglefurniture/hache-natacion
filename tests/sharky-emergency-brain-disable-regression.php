<?php

declare(strict_types=1);

$script=(string)file_get_contents(__DIR__.'/../bin/emergency-disable-brain-conversational.php');
$deploy=(string)file_get_contents(__DIR__.'/../.github/workflows/deploy.yml');

function emergency_brain_ok(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"SHARKY EMERGENCY BRAIN DISABLE FAIL: {$message}\n");exit(1);}
}

emergency_brain_ok(str_contains($script,"sharky_brain_conversacional_habilitado"),'Emergency script must target only the conversational Brain switch.');
emergency_brain_ok(str_contains($script,"':valor'=>'0'"),'Emergency script must force the conversational Brain switch OFF.');
emergency_brain_ok(str_contains($script,'updated_by=NULL'),'Emergency operational change must not impersonate an admin user.');
emergency_brain_ok(str_contains($deploy,'Emergency disable conversational Brain'),'Deploy must execute the emergency kill switch after the approved SHA is deployed.');
emergency_brain_ok(str_contains($deploy,'/var/www/hache-natacion/bin/emergency-disable-brain-conversational.php'),'Deploy must execute the kill switch from the deployed repository.');

fwrite(STDOUT,"SHARKY_EMERGENCY_BRAIN_DISABLE_OK\n");
