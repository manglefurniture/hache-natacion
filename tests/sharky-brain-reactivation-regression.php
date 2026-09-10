<?php

declare(strict_types=1);

$script=(string)file_get_contents(__DIR__.'/../bin/reactivate-brain-conversational-once.php');
$deploy=(string)file_get_contents(__DIR__.'/../.github/workflows/deploy.yml');
$wrapper=(string)file_get_contents(__DIR__.'/../ops/production-readiness/deploy-hache-natacion-wrapper');

function brain_reactivation_ok(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"SHARKY BRAIN REACTIVATION FAIL: {$message}\n");exit(1);}
}

brain_reactivation_ok(str_contains($script,"sharky_brain_conversacional_habilitado"),'Reactivation must target the conversational Brain switch.');
brain_reactivation_ok(str_contains($script,"sharky_brain_conversacional_reactivated_20260910"),'Reactivation must be protected by a one-shot marker.');
brain_reactivation_ok(str_contains($script,"':valor'=>'1'"),'Reactivation must set the conversational Brain switch ON.');
brain_reactivation_ok(str_contains($script,'FOR UPDATE')&&str_contains($script,'beginTransaction'),'One-shot reactivation must be transactionally serialized.');
brain_reactivation_ok(str_contains($wrapper,'brain-reactivate-once)')&&str_contains($wrapper,'reactivate_brain_conversational_once'),'Protected helper must expose the bounded one-shot reactivation operation.');
brain_reactivation_ok(str_contains($deploy,'Reactivate conversational Brain once'),'This recovery deploy must invoke the bounded one-shot reactivation.');
brain_reactivation_ok(str_contains($deploy,'deploy-hache-natacion brain-reactivate-once'),'Reactivation must cross the root boundary only through the protected helper.');

fwrite(STDOUT,"SHARKY_BRAIN_REACTIVATION_OK\n");
