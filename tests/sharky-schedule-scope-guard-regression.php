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
        'program'=>'intensive',
        'sede_clave'=>'MONTEVERDE',
        'swim_level'=>'beginner',
    ],
];
$intensiveHours=['08:00–09:00','19:00–20:00','20:00–21:00'];

schedule_scope_ok(
    hache_sharky_schedule_guard_venue_selection('Colegio monte verde')==='MONTEVERDE',
    'The real-world spaced “monte verde” venue selection must resolve to MONTEVERDE.'
);
schedule_scope_ok(
    hache_sharky_schedule_guard_venue_selection('Monteverde')==='MONTEVERDE',
    'A bare Monteverde venue selection must remain deterministic.'
);
schedule_scope_ok(
    hache_sharky_schedule_guard_venue_selection('Prefiero Palapas Protudec')==='PALAPAS',
    'An explicit Palapas preference must resolve deterministically.'
);
schedule_scope_ok(
    hache_sharky_schedule_guard_venue_selection('¿Dónde queda Monteverde?')===null,
    'A location question must not be mistaken for a venue selection.'
);
schedule_scope_ok(
    hache_sharky_schedule_guard_venue_selection('Monteverde me queda lejos')===null,
    'Mentioning a venue without selecting it must not change routing.'
);

$invalid=hache_sharky_schedule_guard_invalid_selection_from_hours('De 6 a 7',$state,$intensiveHours)??'';
schedule_scope_ok($invalid!=='','A regular-only time must be rejected while intensive is active.');
schedule_scope_ok(
    str_contains(hache_sharky_schedule_guard_normalize($invalid),'no esta activo para curso intensivo'),
    'The rejection must explain that the chosen time is not active for intensive.'
);
schedule_scope_ok(
    str_contains($invalid,'08:00–09:00')&&str_contains($invalid,'19:00–20:00')&&str_contains($invalid,'20:00–21:00'),
    'The rejection must offer only the current intensive schedules.'
);
schedule_scope_ok(
    !str_contains(hache_sharky_schedule_guard_normalize($invalid),'regular'),
    'The corrective response must not leak regular schedules back into the intensive funnel.'
);
schedule_scope_ok(
    hache_sharky_schedule_guard_invalid_selection_from_hours('De 8 a 9',$state,$intensiveHours)===null,
    'A valid intensive time must pass through without a false rejection.'
);

$mixedAnswer="¡Perfecto! Para Colegio Monteverde, los horarios de clases que tenemos activos son:\n\n"
    ."• 06:00–07:00 (regular)\n"
    ."• 07:00–08:00 (regular)\n"
    ."• 08:00–09:00 (regular / intensivo)\n"
    ."• 19:00–20:00 (regular / intensivo)\n"
    ."• 20:00–21:00 (regular / intensivo)\n\n"
    ."¿Cuál horario te gustaría para tu curso intensivo?";
schedule_scope_ok(
    hache_sharky_schedule_guard_scope_violation_from_hours($mixedAnswer,'intensive',$intensiveHours),
    'The screenshot-like mixed regular/intensive answer must be blocked.'
);

$invented="Para el curso intensivo puedes elegir 06:00–07:00 o 08:00–09:00.";
schedule_scope_ok(
    hache_sharky_schedule_guard_scope_violation_from_hours($invented,'intensive',$intensiveHours),
    'A model answer containing a regular-only time must be blocked even if it labels it intensive.'
);

$allowed="Horarios del curso intensivo:\n• 08:00–09:00\n• 19:00–20:00\n• 20:00–21:00";
schedule_scope_ok(
    !hache_sharky_schedule_guard_scope_violation_from_hours($allowed,'intensive',$intensiveHours),
    'A schedule answer containing only active intensive times must remain valid.'
);

$nonSchedule='Las clases regulares funcionan con mensualidad; seguimos con tu intensivo cuando quieras.';
schedule_scope_ok(
    !hache_sharky_schedule_guard_scope_violation_from_hours($nonSchedule,'intensive',$intensiveHours),
    'A non-schedule explanation may mention regular classes without triggering the schedule firewall.'
);
schedule_scope_ok(
    hache_sharky_schedule_guard_cross_program_request('¿Qué horarios tienen las regulares?','intensive'),
    'An explicit lateral question about regular schedules must remain answerable without changing the active program.'
);
schedule_scope_ok(
    !hache_sharky_schedule_guard_cross_program_request('¿Qué horarios tienen?','intensive'),
    'A generic schedule question must stay scoped to the active intensive program.'
);

$dispatcher=file_get_contents(__DIR__.'/../public/api/sharky-whatsapp-dispatch.php')?:'';
schedule_scope_ok(
    str_contains($dispatcher,'sharky-schedule-scope-guard.php'),
    'The WhatsApp dispatcher must load the schedule scope guard.'
);
schedule_scope_ok(
    str_contains($dispatcher,'hache_sharky_schedule_guard_reply($deterministicInput,$state)'),
    'An invalid time selection must be intercepted before the model is called.'
);
schedule_scope_ok(
    str_contains($dispatcher,'hache_sharky_schedule_guard_model_answer($answer,$state,$message)'),
    'Model output must pass through the final schedule-scope firewall.'
);

fwrite(STDOUT,"SHARKY_SCHEDULE_SCOPE_GUARD_OK\n");
