<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-schedule-scope-guard.php';

function schedule_scope_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY SCHEDULE SCOPE FAIL: {$message}\n");exit(1);}
}

$state=[
    'identity'=>['kind'=>'prospect'],
    'commercial_context'=>[
        'program'=>'intensive','sede_clave'=>'MONTEVERDE','swim_level'=>'beginner',
    ],
];
// Current Monteverde intensive catalog: mornings only. Evening rows belong to
// regular classes and must never leak into the active intensive funnel.
$intensiveHours=['06:00–07:00','07:00–08:00','08:00–09:00'];
$regularHours=['06:00–07:00','07:00–08:00','08:00–09:00','18:00–19:00','19:00–20:00','20:00–21:00'];
$loader=static function(string $program,string $sede) use($intensiveHours,$regularHours): array {
    schedule_scope_ok($sede==='MONTEVERDE','Test loader must preserve the selected venue.');
    return $program==='regular'?$regularHours:$intensiveHours;
};

schedule_scope_ok(hache_sharky_schedule_guard_venue_selection('Colegio monte verde')==='MONTEVERDE','The real-world spaced “monte verde” venue selection must resolve to MONTEVERDE.');
schedule_scope_ok(hache_sharky_schedule_guard_venue_selection('Monteverde')==='MONTEVERDE','A bare Monteverde venue selection must remain deterministic.');
schedule_scope_ok(hache_sharky_schedule_guard_venue_selection('Prefiero Palapas Protudec')==='PALAPAS','An explicit Palapas preference must resolve deterministically.');
schedule_scope_ok(hache_sharky_schedule_guard_venue_selection('¿Dónde queda Monteverde?')===null,'A location question must not be mistaken for a venue selection.');
schedule_scope_ok(hache_sharky_schedule_guard_venue_selection('Monteverde me queda lejos')===null,'Mentioning a venue without selecting it must not change routing.');

$invalid=hache_sharky_schedule_guard_invalid_selection_from_hours('De 19 a 20',$state,$intensiveHours)??'';
schedule_scope_ok($invalid!=='','A regular-only evening time must be rejected while intensive is active.');
schedule_scope_ok(str_contains(hache_sharky_schedule_guard_normalize($invalid),'no esta activo para curso intensivo'),'The rejection must explain that the chosen time is not active for intensive.');
schedule_scope_ok(str_contains($invalid,'06:00–07:00')&&str_contains($invalid,'07:00–08:00')&&str_contains($invalid,'08:00–09:00'),'The rejection must offer only the current intensive schedules.');
schedule_scope_ok(!str_contains($invalid,'19:00–20:00')&&!str_contains($invalid,'20:00–21:00'),'The corrective response must not leak Monteverde regular evening schedules back into intensive.');
schedule_scope_ok(hache_sharky_schedule_guard_invalid_selection_from_hours('De 6 a 7',$state,$intensiveHours)===null,'A valid intensive morning time must pass through without a false rejection.');

schedule_scope_ok(hache_sharky_schedule_guard_answer_ranges('El horario es de 6 a 7.')===['06:00–07:00'],'Natural model wording “de 6 a 7” must be parsed as a schedule range.');
schedule_scope_ok(hache_sharky_schedule_guard_answer_ranges('Puedes venir de 6:00 a 7:00.')===['06:00–07:00'],'Colon-formatted ranges joined by “a” must be parsed.');
schedule_scope_ok(hache_sharky_schedule_guard_answer_ranges('Horario de 6:00 p. m. a 7:00 p. m.')===['18:00–19:00'],'Spanish p. m. notation must normalize to a 24-hour range before validation.');
schedule_scope_ok(hache_sharky_schedule_guard_answer_ranges('Horario de 8 a 9 p.m.')===['20:00–21:00'],'A trailing meridiem must scope both ends of a natural range.');
schedule_scope_ok(hache_sharky_schedule_guard_answer_ranges('Tenemos planes de 3 a 5 clases por semana.')===[],'Weekly-plan prose must not be misread as clock time.');

// Exact production failure observed from the Meta-ad prospect conversation:
// Brain correctly kept the intensive product but answered with the old
// Monteverde catalog 08:00, 19:00 and 20:00, mixing regular evening slots.
$screenshotAnswer="¡Claro! Para curso intensivo (Colegio Monteverde) los horarios activos son:\n\n"
    ."• 08:00–09:00\n\n"
    ."• 19:00–20:00\n\n"
    ."• 20:00–21:00\n\n"
    ."¿Cuál de estos horarios te gustaría para tu inscripción?";
schedule_scope_ok(hache_sharky_schedule_guard_scope_violation_from_hours($screenshotAnswer,'intensive',$intensiveHours),'The exact screenshot-like Monteverde regular/intensive mix must be blocked.');
$repairedScreenshot=hache_sharky_schedule_guard_model_answer($screenshotAnswer,$state,'¿Cuáles son los horarios de ambos?',$loader);
schedule_scope_ok(str_contains($repairedScreenshot,'06:00–07:00')&&str_contains($repairedScreenshot,'07:00–08:00')&&str_contains($repairedScreenshot,'08:00–09:00'),'The screenshot failure must be replaced with the verified Monteverde intensive morning catalog.');
schedule_scope_ok(!str_contains($repairedScreenshot,'19:00–20:00')&&!str_contains($repairedScreenshot,'20:00–21:00'),'The repaired screenshot response must exclude regular-only evening slots.');

$mixedAnswer="¡Perfecto! Para Colegio Monteverde, los horarios de clases que tenemos activos son:\n\n"
    ."• 06:00–07:00 (regular / intensivo)\n"
    ."• 07:00–08:00 (regular / intensivo)\n"
    ."• 08:00–09:00 (regular / intensivo)\n"
    ."• 19:00–20:00 (regular)\n"
    ."• 20:00–21:00 (regular)\n\n"
    ."¿Cuál horario te gustaría para tu curso intensivo?";
schedule_scope_ok(hache_sharky_schedule_guard_scope_violation_from_hours($mixedAnswer,'intensive',$intensiveHours),'A mixed regular/intensive answer must be blocked.');

$invented="Para el curso intensivo puedes elegir 19:00–20:00 o 08:00–09:00.";
schedule_scope_ok(hache_sharky_schedule_guard_scope_violation_from_hours($invented,'intensive',$intensiveHours),'A model answer containing a regular-only evening time must be blocked even if it labels it intensive.');
schedule_scope_ok(hache_sharky_schedule_guard_scope_violation_from_hours('El horario es de 19 a 20.','intensive',$intensiveHours),'A natural regular-only evening range must not escape the final firewall.');
schedule_scope_ok(hache_sharky_schedule_guard_scope_violation_from_hours('Horario de 8:00 p. m. a 9:00 p. m.','intensive',$intensiveHours),'A 12-hour regular-only evening range must not escape the final firewall.');
schedule_scope_ok(!hache_sharky_schedule_guard_scope_violation_from_hours('Horario de 8 a 9 a.m.','intensive',$intensiveHours),'A 12-hour range that maps to an active intensive morning time must remain valid.');

$allowed="Horarios del curso intensivo:\n• 06:00–07:00\n• 07:00–08:00\n• 08:00–09:00";
schedule_scope_ok(!hache_sharky_schedule_guard_scope_violation_from_hours($allowed,'intensive',$intensiveHours),'A schedule answer containing only active intensive times must remain valid.');

$nonSchedule='Las clases regulares funcionan con mensualidad; seguimos con tu intensivo cuando quieras.';
schedule_scope_ok(!hache_sharky_schedule_guard_scope_violation_from_hours($nonSchedule,'intensive',$intensiveHours),'A non-schedule explanation may mention regular classes without triggering the schedule firewall.');
schedule_scope_ok(hache_sharky_schedule_guard_requested_programs('¿Qué horarios tienen las regulares?','intensive')===['regular'],'An explicit lateral regular-schedule question must resolve to the regular product.');
schedule_scope_ok(hache_sharky_schedule_guard_cross_program_request('¿Qué horarios tienen las regulares?','intensive'),'An explicit lateral question about regular schedules must remain answerable without changing the active program.');
schedule_scope_ok(!hache_sharky_schedule_guard_cross_program_request('¿Qué horarios tienen?','intensive'),'A generic schedule question must stay scoped to the active intensive program.');
schedule_scope_ok(hache_sharky_schedule_guard_requested_programs('de las dos','intensive')===['intensive'],'Bare “de las dos” must not be reinterpreted as a request for both products.');

$naturalBlocked=hache_sharky_schedule_guard_model_answer('El horario es de 19 a 20.',$state,'¿Qué horario hay?',$loader);
schedule_scope_ok(str_contains($naturalBlocked,'06:00–07:00')&&!str_contains($naturalBlocked,'19:00–20:00'),'The final firewall must replace a natural invalid evening time with verified intensive hours.');
$ampmBlocked=hache_sharky_schedule_guard_model_answer('Horario de 8:00 p. m. a 9:00 p. m.',$state,'¿Qué horario hay?',$loader);
schedule_scope_ok(str_contains($ampmBlocked,'08:00–09:00')&&!str_contains($ampmBlocked,'20:00–21:00'),'The final firewall must replace an invalid 12-hour evening range with verified intensive hours.');

$lateral=hache_sharky_schedule_guard_model_answer('Las regulares son a las 10:00–11:00.',$state,'¿Qué horarios tienen las regulares?',$loader);
schedule_scope_ok(str_contains($lateral,'19:00–20:00')&&str_contains($lateral,'20:00–21:00')&&!str_contains($lateral,'10:00–11:00'),'Lateral regular schedules must be rendered from backend authority instead of trusting the model.');

$both=hache_sharky_schedule_guard_model_answer('Hay varios horarios.',$state,'¿Qué horarios tienen el intensivo y las regulares?',$loader);
schedule_scope_ok(str_contains($both,'curso intensivo')&&str_contains($both,'clases regulares')&&str_contains($both,'06:00–07:00')&&str_contains($both,'19:00–20:00'),'An explicit both-products schedule comparison must validate and render both catalogs.');

$failed=hache_sharky_schedule_guard_model_answer('El horario es de 19 a 20.',$state,'¿Qué horario hay?',static function(string $program,string $sede): array {throw new RuntimeException('db down');});
schedule_scope_ok(str_contains(hache_sharky_schedule_guard_normalize($failed),'no pude verificar los horarios'),'Schedule-like output must fail closed when backend availability cannot be loaded.');

$dispatcher=file_get_contents(__DIR__.'/../public/api/sharky-whatsapp-dispatch.php')?:'';
schedule_scope_ok(str_contains($dispatcher,'sharky-schedule-scope-guard.php'),'The WhatsApp dispatcher must load the schedule scope guard.');
schedule_scope_ok(str_contains($dispatcher,'hache_sharky_schedule_guard_reply($deterministicInput,$state)'),'An invalid time selection must be intercepted before the model is called.');
schedule_scope_ok(str_contains($dispatcher,'hache_sharky_schedule_guard_model_answer($answer,$state,$message)'),'Model output must pass through the final schedule-scope firewall.');

$catalogFix=file_get_contents(__DIR__.'/../bin/fix-monteverde-intensive-schedule-catalog.php')?:'';
schedule_scope_ok(str_contains($catalogFix,"['06:00:00','07:00:00']")&&str_contains($catalogFix,"['07:00:00','08:00:00']")&&str_contains($catalogFix,"['08:00:00','09:00:00']"),'The production catalog repair must explicitly enable all three Monteverde intensive morning slots.');
schedule_scope_ok(str_contains($catalogFix,"['18:00:00','19:00:00']")&&str_contains($catalogFix,"['19:00:00','20:00:00']")&&str_contains($catalogFix,"['20:00:00','21:00:00']"),'The production catalog repair must explicitly identify the Monteverde regular evening slots.');
schedule_scope_ok(str_contains($catalogFix,'UPDATE horarios SET intensivo=1')&&str_contains($catalogFix,'UPDATE horarios SET intensivo=0'),'The production catalog repair must correct the authoritative intensivo flags, not just prompt wording.');
schedule_scope_ok(str_contains($catalogFix,"ci.estado IN ('PROGRAMADO','EN_CURSO')"),'The catalog repair must fail closed if an active intensive enrollment still uses an evening slot.');
schedule_scope_ok(str_contains($catalogFix,"['06:00–07:00','07:00–08:00','08:00–09:00']"),'The catalog repair must verify the exact resulting Monteverde intensive catalog before commit.');

fwrite(STDOUT,"SHARKY_SCHEDULE_SCOPE_GUARD_OK\n");
