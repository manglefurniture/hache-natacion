<?php

declare(strict_types=1);

$deploy=(string)file_get_contents(__DIR__.'/../.github/workflows/deploy.yml');
$wrapper=(string)file_get_contents(__DIR__.'/../ops/production-readiness/deploy-hache-natacion-wrapper');
$docs=(string)file_get_contents(__DIR__.'/../docs/SHARKY-3-META-FLOW.md');

function brain_reactivation_ok(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"SHARKY BRAIN RETIREMENT FAIL: {$message}\n");exit(1);}
}

brain_reactivation_ok(!str_contains($deploy,'Reactivate conversational Brain once'),'Deploy must never auto-reactivate conversational Brain.');
brain_reactivation_ok(!str_contains($deploy,'deploy-hache-natacion brain-reactivate-once'),'Deploy must not invoke the old one-shot Brain reactivation command.');
brain_reactivation_ok(str_contains($deploy,'deploy-hache-natacion brain-off'),'Deploy must preserve the Brain-off state after every approved release.');
brain_reactivation_ok(str_contains($wrapper,'brain-off)'),'Protected wrapper must retain the bounded Brain-off command.');
brain_reactivation_ok(str_contains($docs,'Brain no interpreta las selecciones de este funnel'),'Sharky 3.0 documentation must make Brain non-authoritative in the Meta funnel.');

fwrite(STDOUT,"SHARKY_BRAIN_RETIREMENT_OK\n");
