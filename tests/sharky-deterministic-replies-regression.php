<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-deterministic-replies.php';

function deterministic_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"DETERMINISTIC FAIL: $message\n");exit(1);}
}

$state=[
    'identity'=>['kind'=>'prospect'],
    'commercial_context'=>['program'=>'intensive','sede_clave'=>'MONTEVERDE','age'=>59],
];

deterministic_ok(hache_sharky_deterministic_schedule_request('Horarios'),'The commercial Horarios button title must be deterministic.');
deterministic_ok(hache_sharky_deterministic_schedule_request('¿Qué horarios tienen?'),'Natural schedule questions must be deterministic.');
deterministic_ok(!hache_sharky_deterministic_schedule_request('De 19:00 a 20:00'),'A concrete time selection is not a generic schedule request.');
deterministic_ok(hache_sharky_deterministic_price_request('Precio de favor'),'Natural price wording must be deterministic.');
deterministic_ok(hache_sharky_deterministic_location_request('Me envías la ubicación'),'Location requests must be deterministic.');
deterministic_ok(hache_sharky_deterministic_detect_explicit_sede('Quiero la ubicación de Monteverde')==='MONTEVERDE','Explicit Monteverde must override current venue context.');
deterministic_ok(hache_sharky_deterministic_detect_explicit_sede('Maps de Palapas Protudec')==='PALAPAS','Explicit Palapas must be recognized.');
deterministic_ok(hache_sharky_deterministic_route_followup('En coche'),'Route follow-ups from the historical screenshot must be caught.');
deterministic_ok(hache_sharky_deterministic_time_range('De 19:00 a 20:00')===['19:00','20:00'],'Time ranges must normalize to HH:MM.');

deterministic_ok(hache_sharky_reply_looks_incomplete('¡Claro! 😊'),'Greeting plus emoji only must be rejected as incomplete.');
deterministic_ok(hache_sharky_reply_looks_incomplete('¡Hola! 💰'),'Repeated greeting plus emoji only must be rejected as incomplete.');
deterministic_ok(hache_sharky_reply_looks_incomplete('Para orientarte bien:'),'A dangling colon must be rejected as incomplete.');
deterministic_ok(hache_sharky_reply_looks_incomplete("¡Claro!\n\n📍"),'A promise plus marker without payload must be rejected.');
deterministic_ok(!hache_sharky_reply_looks_incomplete('El curso intensivo cuesta $1,200 MXN.'),'A complete short factual answer must remain valid.');

$pdo=new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE sedes (id INTEGER PRIMARY KEY, clave TEXT, activo INTEGER)');
$pdo->exec('CREATE TABLE horarios (id INTEGER PRIMARY KEY, sede_id INTEGER, hora_inicio TEXT, hora_fin TEXT, activo INTEGER, regular INTEGER, intensivo INTEGER)');
$pdo->exec("INSERT INTO sedes(id,clave,activo) VALUES(1,'MONTEVERDE',1),(2,'PALAPAS',1)");
$pdo->exec("INSERT INTO horarios(sede_id,hora_inicio,hora_fin,activo,regular,intensivo) VALUES
(1,'08:00:00','09:00:00',1,0,1),
(1,'19:00:00','20:00:00',1,0,1),
(1,'20:00:00','21:00:00',1,0,1),
(1,'06:00:00','07:00:00',1,1,0),
(2,'08:00:00','09:00:00',1,0,1)");
$hours=hache_sharky_deterministic_active_schedules($pdo,'intensive','MONTEVERDE');
deterministic_ok($hours===['08:00–09:00','19:00–20:00','20:00–21:00'],'Schedule lookup must filter by current program and venue.');
deterministic_ok(!in_array('06:00–07:00',$hours,true),'Regular hours must not leak into intensive replies.');

$dispatcher=file_get_contents(__DIR__.'/../public/api/sharky-whatsapp-dispatch.php')?:'';
$wrapper=file_get_contents(__DIR__.'/../api/sharky.php')?:'';
deterministic_ok(str_contains($wrapper,'sharky-whatsapp-dispatch.php'),'Public Sharky wrapper must route through the WhatsApp dispatcher.');
deterministic_ok(str_contains($dispatcher,"source'=>'deterministic'"),'Dispatcher must expose deterministic responses without calling the LLM.');
deterministic_ok(str_contains($dispatcher,'hache_sharky_reply_looks_incomplete'),'Dispatcher must guard incomplete model answers.');
deterministic_ok(str_contains($dispatcher,'hache_sharky_dispatcher_clean_model_answer'),'Dispatcher must clean repeated greetings and empty bullets.');

echo "SHARKY_DETERMINISTIC_REPLIES_OK\n";
