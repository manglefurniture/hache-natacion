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

final class DeterministicStatement extends PDOStatement
{
    public function __construct(private array $rows){}
    public function execute(?array $params=null): bool{return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,...$args): array{return $this->rows;}
}
final class DeterministicPdo extends PDO
{
    public string $lastQuery='';
    public function __construct(private array $rows){}
    public function prepare(string $query,array $options=[]): PDOStatement|false{$this->lastQuery=$query;return new DeterministicStatement($this->rows);}
}
$pdo=new DeterministicPdo([
    ['hora_inicio'=>'08:00:00','hora_fin'=>'09:00:00'],
    ['hora_inicio'=>'19:00:00','hora_fin'=>'20:00:00'],
    ['hora_inicio'=>'20:00:00','hora_fin'=>'21:00:00'],
]);
$hours=hache_sharky_deterministic_active_schedules($pdo,'intensive','MONTEVERDE');
deterministic_ok($hours===['08:00–09:00','19:00–20:00','20:00–21:00'],'Schedule lookup must format active rows deterministically.');
deterministic_ok(str_contains($pdo->lastQuery,'s.clave=:c'),'Schedule lookup must remain venue-scoped.');
deterministic_ok(str_contains($pdo->lastQuery,'h.intensivo=1'),'Intensive requests must query only intensive schedules.');

$dispatcher=file_get_contents(__DIR__.'/../public/api/sharky-whatsapp-dispatch.php')?:'';
$wrapper=file_get_contents(__DIR__.'/../api/sharky.php')?:'';
deterministic_ok(str_contains($wrapper,'sharky-whatsapp-dispatch.php'),'Public Sharky wrapper must route through the WhatsApp dispatcher.');
deterministic_ok(str_contains($dispatcher,"source'=>'deterministic'"),'Dispatcher must expose deterministic responses without calling the LLM.');
deterministic_ok(str_contains($dispatcher,'hache_sharky_reply_looks_incomplete'),'Dispatcher must guard incomplete model answers.');
deterministic_ok(str_contains($dispatcher,'hache_sharky_dispatcher_clean_model_answer'),'Dispatcher must clean repeated greetings and empty bullets.');

echo "SHARKY_DETERMINISTIC_REPLIES_OK\n";
