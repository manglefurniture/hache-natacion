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

// Commercial truth agreed for Hache Natación:
// Monteverde intensive = 08:00, 19:00, 20:00.
// Palapas intensive = 07:00, 08:00, 09:00, 20:00, and those same slots are regular too.
$catalogs=[
    'intensive'=>[
        'MONTEVERDE'=>['08:00–09:00','19:00–20:00','20:00–21:00'],
        'PALAPAS'=>['07:00–08:00','08:00–09:00','09:00–10:00','20:00–21:00'],
    ],
    'regular'=>[
        'MONTEVERDE'=>['06:00–07:00','07:00–08:00','08:00–09:00','18:00–19:00','19:00–20:00','20:00–21:00'],
        'PALAPAS'=>['07:00–08:00','08:00–09:00','09:00–10:00','20:00–21:00'],
    ],
];
$loader=static function(string $program,string $sede) use($catalogs): array {
    return $catalogs[$program][$sede]??[];
};

schedule_scope_ok(hache_sharky_schedule_guard_venue_selection('Colegio monte verde')==='MONTEVERDE','The real-world spaced “monte verde” venue selection must resolve to MONTEVERDE.');
schedule_scope_ok(hache_sharky_schedule_guard_venue_selection('Monteverde')==='MONTEVERDE','A bare Monteverde venue selection must remain deterministic.');
schedule_scope_ok(hache_sharky_schedule_guard_venue_selection('Prefiero Palapas Protudec')==='PALAPAS','An explicit Palapas preference must resolve deterministically.');
schedule_scope_ok(hache_sharky_schedule_guard_venue_selection('¿Dónde queda Monteverde?')===null,'A location question must not be mistaken for a venue selection.');
schedule_scope_ok(hache_sharky_schedule_guard_venue_selection('Monteverde me queda lejos')===null,'Mentioning a venue without selecting it must not change routing.');

$mvIntensive=$catalogs['intensive']['MONTEVERDE'];
$invalid=hache_sharky_schedule_guard_invalid_selection_from_hours('De 6 a 7',$state,$mvIntensive)??'';
schedule_scope_ok($invalid!=='','A regular-only Monteverde morning time must be rejected while intensive is active.');
schedule_scope_ok(str_contains(hache_sharky_schedule_guard_normalize($invalid),'no esta activo para curso intensivo'),'The rejection must explain that the chosen time is not active for intensive.');
schedule_scope_ok(str_contains($invalid,'08:00–09:00')&&str_contains($invalid,'19:00–20:00')&&str_contains($invalid,'20:00–21:00'),'The rejection must offer only the three real Monteverde intensive schedules.');
schedule_scope_ok(!str_contains($invalid,'06:00–07:00')&&!str_contains($invalid,'07:00–08:00')&&!str_contains($invalid,'18:00–19:00'),'The corrective response must not leak Monteverde regular-only schedules.');
schedule_scope_ok(hache_sharky_schedule_guard_invalid_selection_from_hours('De 19 a 20',$state,$mvIntensive)===null,'A valid Monteverde intensive evening time must remain valid.');

schedule_scope_ok(hache_sharky_schedule_guard_answer_ranges('El horario es de 6 a 7.')===['06:00–07:00'],'Natural model wording “de 6 a 7” must be parsed as a schedule range.');
schedule_scope_ok(hache_sharky_schedule_guard_answer_ranges('Puedes venir de 6:00 a 7:00.')===['06:00–07:00'],'Colon-formatted ranges joined by “a” must be parsed.');
schedule_scope_ok(hache_sharky_schedule_guard_answer_ranges('Horario de 6:00 p. m. a 7:00 p. m.')===['18:00–19:00'],'Spanish p. m. notation must normalize to a 24-hour range before validation.');
schedule_scope_ok(hache_sharky_schedule_guard_answer_ranges('Horario de 7 a 8 p.m.')===['19:00–20:00'],'A trailing meridiem must scope both ends of a natural range.');
schedule_scope_ok(hache_sharky_schedule_guard_answer_ranges('Tenemos planes de 3 a 5 clases por semana.')===[],'Weekly-plan prose must not be misread as clock time.');

$correctMonteverde="¡Claro! Para curso intensivo (Colegio Monteverde) los horarios activos son:\n\n"
    ."• 08:00–09:00\n"
    ."• 19:00–20:00\n"
    ."• 20:00–21:00";
schedule_scope_ok(!hache_sharky_schedule_guard_scope_violation_from_hours($correctMonteverde,'intensive',$mvIntensive),'The first Patty answer with 08:00, 19:00 and 20:00 is a correct Monteverde intensive catalog and must not be blocked.');

$mixedMonteverde="Para tu curso intensivo en Colegio Monteverde:\n"
    ."• 06:00–07:00\n"
    ."• 07:00–08:00\n"
    ."• 08:00–09:00";
schedule_scope_ok(hache_sharky_schedule_guard_scope_violation_from_hours($mixedMonteverde,'intensive',$mvIntensive),'Regular-only 06:00 and 07:00 Monteverde slots must be blocked from the intensive funnel.');
schedule_scope_ok(hache_sharky_schedule_guard_scope_violation_from_hours('Horario de 6:00 p. m. a 7:00 p. m.','intensive',$mvIntensive),'18:00–19:00 is regular-only in Monteverde and must not escape the firewall.');
schedule_scope_ok(!hache_sharky_schedule_guard_scope_violation_from_hours('Horario de 7 a 8 p.m.','intensive',$mvIntensive),'19:00–20:00 is a real Monteverde intensive schedule and must remain valid.');

schedule_scope_ok(hache_sharky_schedule_guard_requested_venues('¿Cuáles son los horarios de ambos?',$state)===['MONTEVERDE','PALAPAS'],'In an active single-product funnel, “horarios de ambos” must mean both venues, not both products.');
$bothReply=hache_sharky_schedule_guard_multi_venue_reply('¿Cuáles son los horarios de ambos?',$state,$loader)??'';
$mvPos=strpos($bothReply,'Colegio Monteverde');$palPos=strpos($bothReply,'Palapas Protudec');
schedule_scope_ok($mvPos!==false&&$palPos!==false&&$mvPos<$palPos,'A both-venue schedule answer must contain separate Monteverde and Palapas sections.');
$mvSection=substr($bothReply,$mvPos,$palPos-$mvPos);
$palSection=substr($bothReply,$palPos);
schedule_scope_ok(str_contains($mvSection,'08:00–09:00')&&str_contains($mvSection,'19:00–20:00')&&str_contains($mvSection,'20:00–21:00'),'Both-venue answer must preserve the three real Monteverde intensive schedules.');
schedule_scope_ok(!str_contains($mvSection,'06:00–07:00')&&!str_contains($mvSection,'07:00–08:00')&&!str_contains($mvSection,'18:00–19:00'),'Both-venue answer must exclude Monteverde regular-only schedules.');
schedule_scope_ok(str_contains($palSection,'07:00–08:00')&&str_contains($palSection,'08:00–09:00')&&str_contains($palSection,'09:00–10:00')&&str_contains($palSection,'20:00–21:00'),'Both-venue answer must show the four real Palapas intensive schedules.');

$followupState=$state;
$followupState['previous_user_text']='¿Cuáles son los horarios de ambos?';
schedule_scope_ok(hache_sharky_schedule_guard_requested_venues('¿Solo hay un horario por la mañana?',$followupState)===['MONTEVERDE','PALAPAS'],'A morning follow-up must inherit the immediately previous both-venue schedule scope.');
$morningReply=hache_sharky_schedule_guard_multi_venue_reply('¿Solo hay un horario por la mañana?',$followupState,$loader)??'';
$mvPos=strpos($morningReply,'Colegio Monteverde');$palPos=strpos($morningReply,'Palapas Protudec');
schedule_scope_ok($mvPos!==false&&$palPos!==false&&$mvPos<$palPos,'The morning follow-up must answer both venues separately.');
$mvMorning=substr($morningReply,$mvPos,$palPos-$mvPos);
$palMorning=substr($morningReply,$palPos);
schedule_scope_ok(str_contains($mvMorning,'08:00–09:00')&&!str_contains($mvMorning,'06:00–07:00')&&!str_contains($mvMorning,'07:00–08:00'),'Monteverde morning intensive must contain only 08:00–09:00.');
schedule_scope_ok(str_contains($palMorning,'07:00–08:00')&&str_contains($palMorning,'08:00–09:00')&&str_contains($palMorning,'09:00–10:00'),'Palapas morning intensive must contain 07:00, 08:00 and 09:00 starts.');
schedule_scope_ok(!str_contains($morningReply,'19:00–20:00')&&!str_contains($morningReply,'20:00–21:00'),'A morning-only follow-up must not include evening schedules.');

$wrongPattyAnswer="Sí 😊 En la mañana, los horarios activos para intensivo son estos:\n\n"
    ."• Palapas Protudec: 07:00–08:00, 08:00–09:00 y 09:00–10:00\n"
    ."• Colegio Monteverde: 06:00–07:00, 07:00–08:00 y 08:00–09:00";
$repairedPatty=hache_sharky_schedule_guard_model_answer($wrongPattyAnswer,$followupState,'¿Solo hay un horario por la mañana?',$loader);
$mvPos=strpos($repairedPatty,'Colegio Monteverde');$palPos=strpos($repairedPatty,'Palapas Protudec');
schedule_scope_ok($mvPos!==false&&$palPos!==false,'The final firewall must replace the exact Patty-style mixed morning answer with backend-authoritative venue sections.');
$first=min($mvPos,$palPos);$second=max($mvPos,$palPos);
$sectionA=substr($repairedPatty,$first,$second-$first);$sectionB=substr($repairedPatty,$second);
$mvRepaired=$mvPos<$palPos?$sectionA:$sectionB;
$palRepaired=$palPos<$mvPos?$sectionA:$sectionB;
schedule_scope_ok(str_contains($mvRepaired,'08:00–09:00')&&!str_contains($mvRepaired,'06:00–07:00')&&!str_contains($mvRepaired,'07:00–08:00'),'The repaired Patty answer must not leak Monteverde regular-only morning slots.');
schedule_scope_ok(str_contains($palRepaired,'07:00–08:00')&&str_contains($palRepaired,'08:00–09:00')&&str_contains($palRepaired,'09:00–10:00'),'The repaired Patty answer must retain the correct Palapas morning intensive catalog.');

$nonSchedule='Las clases regulares funcionan con mensualidad; seguimos con tu intensivo cuando quieras.';
schedule_scope_ok(!hache_sharky_schedule_guard_scope_violation_from_hours($nonSchedule,'intensive',$mvIntensive),'A non-schedule explanation may mention regular classes without triggering the schedule firewall.');
schedule_scope_ok(hache_sharky_schedule_guard_requested_programs('¿Qué horarios tienen las regulares?','intensive')===['regular'],'An explicit lateral regular-schedule question must resolve to the regular product.');
schedule_scope_ok(hache_sharky_schedule_guard_cross_program_request('¿Qué horarios tienen las regulares?','intensive'),'An explicit lateral question about regular schedules must remain answerable without silently changing the active program.');
schedule_scope_ok(!hache_sharky_schedule_guard_cross_program_request('¿Qué horarios tienen?','intensive'),'A generic schedule question must stay scoped to the active intensive program.');
schedule_scope_ok(hache_sharky_schedule_guard_requested_programs('de las dos','intensive')===['intensive'],'Bare “de las dos” must not be reinterpreted as a request for both products.');

$lateral=hache_sharky_schedule_guard_model_answer('Las regulares son a las 10:00–11:00.',$state,'¿Qué horarios tienen las regulares?',$loader);
schedule_scope_ok(str_contains($lateral,'06:00–07:00')&&str_contains($lateral,'18:00–19:00')&&!str_contains($lateral,'10:00–11:00'),'Lateral Monteverde regular schedules must be rendered from backend authority instead of trusting the model.');

$failed=hache_sharky_schedule_guard_model_answer('El horario es de 6 a 7.',$state,'¿Qué horario hay?',static function(string $program,string $sede): array {throw new RuntimeException('db down');});
schedule_scope_ok(str_contains(hache_sharky_schedule_guard_normalize($failed),'no pude verificar los horarios'),'Schedule-like output must fail closed when backend availability cannot be loaded.');

$dispatcher=file_get_contents(__DIR__.'/../public/api/sharky-whatsapp-dispatch.php')?:'';
schedule_scope_ok(str_contains($dispatcher,'sharky-schedule-scope-guard.php'),'The WhatsApp dispatcher must load the schedule scope guard.');
schedule_scope_ok(str_contains($dispatcher,'hache_sharky_schedule_guard_multi_venue_reply($deterministicInput,$state)'),'Both-venue schedule questions must be intercepted before the generic deterministic single-venue schedule reply.');
schedule_scope_ok(strpos($dispatcher,'hache_sharky_schedule_guard_multi_venue_reply($deterministicInput,$state)')<strpos($dispatcher,'hache_sharky_deterministic_reply($deterministicInput,$state)'),'The multi-venue schedule guard must run before generic deterministic schedule handling.');
schedule_scope_ok(str_contains($dispatcher,'hache_sharky_schedule_guard_reply($deterministicInput,$state)'),'An invalid time selection must be intercepted before the model is called.');
schedule_scope_ok(str_contains($dispatcher,'hache_sharky_schedule_guard_model_answer($answer,$state,$message)'),'Model output must pass through the final schedule-scope firewall.');

fwrite(STDOUT,"SHARKY_SCHEDULE_SCOPE_GUARD_OK\n");
