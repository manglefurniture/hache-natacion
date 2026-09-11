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

// Current commercial truth for Hache Natación.
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

schedule_scope_ok(hache_sharky_schedule_guard_venue_selection('Colegio monte verde')==='MONTEVERDE','Spaced “monte verde” must resolve to MONTEVERDE.');
schedule_scope_ok(hache_sharky_schedule_guard_venue_selection('Prefiero Palapas Protudec')==='PALAPAS','Explicit Palapas preference must resolve.');
schedule_scope_ok(hache_sharky_schedule_guard_venue_selection('¿Dónde queda Monteverde?')===null,'Location question must not become a venue selection.');

$mvIntensive=$catalogs['intensive']['MONTEVERDE'];
$invalid=hache_sharky_schedule_guard_invalid_selection_from_hours('De 6 a 7',$state,$mvIntensive)??'';
schedule_scope_ok($invalid!=='','06:00–07:00 is regular-only in Monteverde and must be rejected for intensive.');
schedule_scope_ok(str_contains($invalid,'08:00–09:00')&&str_contains($invalid,'19:00–20:00')&&str_contains($invalid,'20:00–21:00'),'Correction must offer the three real Monteverde intensive schedules.');
schedule_scope_ok(!str_contains($invalid,'06:00–07:00')&&!str_contains($invalid,'07:00–08:00')&&!str_contains($invalid,'18:00–19:00'),'Correction must not leak Monteverde regular-only schedules.');
schedule_scope_ok(hache_sharky_schedule_guard_invalid_selection_from_hours('De 19 a 20',$state,$mvIntensive)===null,'19:00–20:00 is a valid Monteverde intensive time.');

schedule_scope_ok(hache_sharky_schedule_guard_answer_ranges('El horario es de 6 a 7.')===['06:00–07:00'],'Natural “de 6 a 7” must be parsed.');
schedule_scope_ok(hache_sharky_schedule_guard_answer_ranges('Horario de 6:00 p. m. a 7:00 p. m.')===['18:00–19:00'],'Spanish p. m. notation must normalize before validation.');
schedule_scope_ok(hache_sharky_schedule_guard_answer_ranges('Horario de 7 a 8 p.m.')===['19:00–20:00'],'Trailing meridiem must scope both ends.');
schedule_scope_ok(hache_sharky_schedule_guard_answer_ranges('Tenemos planes de 3 a 5 clases por semana.')===[],'Weekly-plan prose must not be parsed as clock time.');

// Patty 20:11: this answer was correct. “Ambos” followed Sharky's question
// about morning/tarde; it must not be silently reinterpreted as both venues.
$correctPattyFirst="¡Claro! Para curso intensivo (Colegio Monteverde) los horarios activos son:\n\n"
    ."• 08:00–09:00\n"
    ."• 19:00–20:00\n"
    ."• 20:00–21:00";
schedule_scope_ok(!hache_sharky_schedule_guard_scope_violation_from_hours($correctPattyFirst,'intensive',$mvIntensive),'Patty 20:11 is the correct Monteverde intensive catalog.');
schedule_scope_ok(hache_sharky_schedule_guard_requested_venues('¿Cuáles son los horarios de ambos?',$state)===['MONTEVERDE'],'Bare “ambos” is ambiguous and must preserve the active Monteverde venue instead of inventing a both-venue scope.');
schedule_scope_ok(hache_sharky_schedule_guard_scoped_reply('¿Cuáles son los horarios de ambos?',$state,$loader)===null,'Ambiguous “ambos” without a daypart/product/venue qualifier must fall through to the normal active-venue schedule reply.');

// Patty 20:12: this is the actual failure. The follow-up asks whether there is
// only one morning time; it must stay on Monteverde + intensive.
$followupState=$state;
$followupState['previous_user_text']='¿Cuáles son los horarios de ambos?';
schedule_scope_ok(hache_sharky_schedule_guard_requested_venues('¿Solo hay un horario por la mañana?',$followupState)===['MONTEVERDE'],'The morning follow-up must remain scoped to the active Monteverde venue.');
$morningReply=hache_sharky_schedule_guard_scoped_reply('¿Solo hay un horario por la mañana?',$followupState,$loader)??'';
schedule_scope_ok(str_contains($morningReply,'Colegio Monteverde'),'Morning follow-up must answer for Monteverde.');
schedule_scope_ok(str_contains($morningReply,'08:00–09:00'),'Monteverde intensive has exactly 08:00–09:00 in the morning.');
schedule_scope_ok(!str_contains($morningReply,'Palapas Protudec'),'Morning follow-up must not jump to Palapas.');
schedule_scope_ok(!str_contains($morningReply,'06:00–07:00')&&!str_contains($morningReply,'07:00–08:00'),'Monteverde regular-only morning slots must never appear as intensive.');
schedule_scope_ok(!str_contains($morningReply,'19:00–20:00')&&!str_contains($morningReply,'20:00–21:00'),'Morning-only reply must filter out evening intensive times.');

$wrongPattyAnswer="Sí 😊 En la mañana, los horarios activos para intensivo son estos:\n\n"
    ."• Palapas Protudec: 07:00–08:00, 08:00–09:00 y 09:00–10:00\n"
    ."• Colegio Monteverde: 06:00–07:00, 07:00–08:00 y 08:00–09:00";
$repairedPatty=hache_sharky_schedule_guard_model_answer($wrongPattyAnswer,$followupState,'¿Solo hay un horario por la mañana?',$loader);
schedule_scope_ok(str_contains($repairedPatty,'Colegio Monteverde')&&str_contains($repairedPatty,'08:00–09:00'),'Final firewall must repair the exact Patty-style answer to the verified Monteverde morning intensive schedule.');
schedule_scope_ok(!str_contains($repairedPatty,'Palapas Protudec')&&!str_contains($repairedPatty,'06:00–07:00')&&!str_contains($repairedPatty,'07:00–08:00'),'Final firewall must remove the venue jump and Monteverde regular-only morning times.');

// Explicit requests for both venues remain supported, but must say the venue
// scope clearly instead of relying on a bare “ambos”.
schedule_scope_ok(hache_sharky_schedule_guard_requested_venues('¿Cuáles son los horarios de ambas sedes?',$state)===['MONTEVERDE','PALAPAS'],'Explicit “ambas sedes” must resolve to both venues.');
$bothVenues=hache_sharky_schedule_guard_scoped_reply('¿Cuáles son los horarios de ambas sedes?',$state,$loader)??'';
$mvPos=strpos($bothVenues,'Colegio Monteverde');$palPos=strpos($bothVenues,'Palapas Protudec');
schedule_scope_ok($mvPos!==false&&$palPos!==false&&$mvPos<$palPos,'Explicit both-venue answer must contain separate venue sections.');
$mvSection=substr($bothVenues,$mvPos,$palPos-$mvPos);$palSection=substr($bothVenues,$palPos);
schedule_scope_ok(str_contains($mvSection,'08:00–09:00')&&str_contains($mvSection,'19:00–20:00')&&str_contains($mvSection,'20:00–21:00'),'Both-venue answer must preserve all three Monteverde intensive schedules.');
schedule_scope_ok(!str_contains($mvSection,'06:00–07:00')&&!str_contains($mvSection,'07:00–08:00')&&!str_contains($mvSection,'18:00–19:00'),'Both-venue answer must exclude Monteverde regular-only schedules.');
schedule_scope_ok(str_contains($palSection,'07:00–08:00')&&str_contains($palSection,'08:00–09:00')&&str_contains($palSection,'09:00–10:00')&&str_contains($palSection,'20:00–21:00'),'Both-venue answer must preserve all four Palapas intensive schedules.');

$palState=$state;$palState['commercial_context']['sede_clave']='PALAPAS';
$palMorning=hache_sharky_schedule_guard_scoped_reply('¿Qué horarios hay por la mañana?',$palState,$loader)??'';
schedule_scope_ok(str_contains($palMorning,'07:00–08:00')&&str_contains($palMorning,'08:00–09:00')&&str_contains($palMorning,'09:00–10:00'),'Palapas intensive morning schedules must be 07:00, 08:00 and 09:00 starts.');
schedule_scope_ok(!str_contains($palMorning,'20:00–21:00'),'Palapas morning filter must omit the night schedule.');

schedule_scope_ok(hache_sharky_schedule_guard_requested_programs('¿Qué horarios tienen las regulares?','intensive')===['regular'],'Explicit lateral regular-schedule question must resolve to regular.');
$lateral=hache_sharky_schedule_guard_scoped_reply('¿Qué horarios tienen las regulares?',$state,$loader)??'';
schedule_scope_ok(str_contains($lateral,'clases regulares')&&str_contains($lateral,'06:00–07:00')&&str_contains($lateral,'18:00–19:00'),'Lateral regular schedules must come from the backend catalog rather than the active intensive catalog.');

$failed=hache_sharky_schedule_guard_model_answer('El horario es de 6 a 7.',$state,'¿Qué horario hay?',static function(string $program,string $sede): array {throw new RuntimeException('db down');});
schedule_scope_ok(str_contains(hache_sharky_schedule_guard_normalize($failed),'no pude verificar los horarios'),'Schedule-like output must fail closed when backend availability cannot be loaded.');

$dispatcher=file_get_contents(__DIR__.'/../public/api/sharky-whatsapp-dispatch.php')?:'';
schedule_scope_ok(str_contains($dispatcher,'sharky-schedule-scope-guard.php'),'WhatsApp dispatcher must load the schedule scope guard.');
schedule_scope_ok(str_contains($dispatcher,'hache_sharky_schedule_guard_scoped_reply($deterministicInput,$state)'),'Daypart/cross-product/explicit multi-venue schedule questions must be intercepted before the generic reply.');
schedule_scope_ok(strpos($dispatcher,'hache_sharky_schedule_guard_scoped_reply($deterministicInput,$state)')<strpos($dispatcher,'hache_sharky_deterministic_reply($deterministicInput,$state)'),'Scoped schedule guard must run before generic deterministic schedule handling.');
schedule_scope_ok(str_contains($dispatcher,'hache_sharky_schedule_guard_reply($deterministicInput,$state)'),'Invalid time selections must be intercepted before the model.');
schedule_scope_ok(str_contains($dispatcher,'hache_sharky_schedule_guard_model_answer($answer,$state,$message)'),'Model output must pass through the final schedule firewall.');

fwrite(STDOUT,"SHARKY_SCHEDULE_SCOPE_GUARD_OK\n");
