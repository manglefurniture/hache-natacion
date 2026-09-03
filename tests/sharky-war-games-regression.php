<?php

declare(strict_types=1);

require __DIR__.'/../config/sharky-whatsapp-adapter.php';
require_once __DIR__.'/../config/sharky-draft-parity.php';

function expect_war(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"WAR FAIL: $message\n");exit(1);}}

$now=1788382800;

// P0: a delayed button from an earlier step must never confirm a later transaction.
$registrationConfirm=hache_sharky_orchestrator_flow(
    hache_sharky_orchestrator_state(null,$now),
    'register_intensive','confirm',
    ['name'=>'Juan Pérez','fecha_inicio'=>'2026-09-07'],$now
);
expect_war(!hache_sharky_whatsapp_interactive_is_current($registrationConfirm,['interactive_id'=>'flow:yes']), 'A stale offer YES must not confirm registration.');
expect_war(hache_sharky_whatsapp_interactive_is_current($registrationConfirm,['interactive_id'=>'flow:confirm']), 'Only the current final confirm button is valid.');

$absenceConfirm=hache_sharky_orchestrator_flow(
    hache_sharky_orchestrator_state(null,$now),
    'absence','confirm',
    ['date_from'=>'2026-09-03'],$now
);
expect_war(!hache_sharky_whatsapp_interactive_is_current($absenceConfirm,['interactive_id'=>'flow:yes']), 'A stale absence-offer YES must not execute the absence.');
expect_war(hache_sharky_whatsapp_interactive_is_current($absenceConfirm,['interactive_id'=>'flow:confirm']), 'Current absence confirmation remains valid.');

// Stale course/schedule buttons cannot jump steps.
$registrationSede=hache_sharky_orchestrator_flow(hache_sharky_orchestrator_state(null,$now),'register_intensive','sede',[],$now);
expect_war(!hache_sharky_whatsapp_interactive_is_current($registrationSede,['interactive_id'=>'course:old-course']), 'An old course list reply cannot skip the sede step.');
expect_war(hache_sharky_whatsapp_interactive_is_current($registrationSede,['interactive_id'=>'sede:monteverde']), 'The current sede button remains valid.');

// P1: an empty backend option set must fail safely instead of trapping the chat in an empty list.
$courseState=hache_sharky_orchestrator_flow(hache_sharky_orchestrator_state(null,$now),'register_intensive','course',['sede_clave'=>'MONTEVERDE'],$now);
[$guardedState,$guardedDecision]=hache_sharky_whatsapp_empty_options_guard($courseState,hache_sharky_orchestrator_decision('registration_course','Elige fecha',['type'=>'list','options'=>[]]));
expect_war(($guardedDecision['kind']??'')==='options_unavailable_handoff','Empty course options must hand off safely.');
expect_war(($guardedDecision['action']['type']??'')==='human_takeover','Empty options must never ask the model to invent a choice.');
expect_war(($guardedState['flow']??null)===null,'Empty options must clear the unusable controlled flow.');

// P0: verification from an unknown number is a temporary authenticated session, not a permanent binding.
expect_war(hache_sharky_verification_expired('2026-09-02 18:00:00',strtotime('2026-09-02 19:00:00')),'Past verification sessions must expire.');
expect_war(!hache_sharky_verification_expired('2026-09-02 20:00:00',strtotime('2026-09-02 19:00:00')),'A fresh verified session remains valid.');
expect_war(HACHE_SHARKY_VERIFIED_SESSION_TTL>=900 && HACHE_SHARKY_VERIFIED_SESSION_TTL<=14400,'Verified-session TTL must stay bounded.');

// P1: a transaction cannot use the sender WhatsApp as a second student's identity.
expect_war(hache_sharky_draft_third_party_registration('No quiero inscribirme yo, quiero inscribir a mi hijo al intensivo.'),'Third-party enrollment must leave automated new-student creation.');
expect_war(hache_sharky_draft_third_party_registration('Quiero registrar a mi esposa al curso.'),'Partner enrollment must be treated as third-party.');
expect_war(!hache_sharky_draft_third_party_registration('Quiero registrarme al intensivo.'),'Self enrollment must not be mislabeled as third-party.');

// Parity: frustration from #72 is a human-handoff condition too.
if(!function_exists('hache_sharky_frustration')){function hache_sharky_frustration(string $text):bool{return str_contains($text,'NO_ME_ENTIENDES');}}
expect_war(hache_sharky_draft_requires_handoff('NO_ME_ENTIENDES'),'Frustration must not get lost when moving from v2 to the orchestrator.');

// P0 attribution: media/referral details captured by Meta must survive durable persistence.
$migration=file_get_contents(__DIR__.'/../database/migrations/20260902_sharky_orchestrator.sql')?:'';
$store=file_get_contents(__DIR__.'/../config/sharky-orchestrator-store.php')?:'';
foreach(['image_url','video_url','thumbnail_url','referral_json'] as $field){
    expect_war(str_contains($migration,$field),'Referral migration must include '.$field.'.');
    expect_war(str_contains($store,$field),'Referral store must persist '.$field.'.');
}

// Known students use a separate safe path instead of failing after attempting duplicate creation.
$adapter=file_get_contents(__DIR__.'/../config/sharky-whatsapp-adapter.php')?:'';
expect_war(str_contains($adapter,'existing_student_intensive_handoff'),'Known students must not fall through the new-student intensive creator.');
expect_war(str_contains($adapter,'stale_interactive'),'Stale interactive replies must have an explicit no-op response.');

fwrite(STDOUT,"SHARKY_WAR_GAMES_OK\n");
