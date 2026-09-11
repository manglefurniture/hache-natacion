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
deterministic_ok(hache_sharky_deterministic_amenities_request('Dudas, manejan regaderas?'),'Natural shower availability questions must be deterministic.');
deterministic_ok(hache_sharky_deterministic_amenities_request('¿Palapas Protudec cuenta con baños?'),'Bathroom availability questions must be deterministic.');
deterministic_ok(hache_sharky_deterministic_amenities_request('¿Tienen regaderas?'),'Bare amenity availability questions must be deterministic.');
deterministic_ok(!hache_sharky_deterministic_amenities_request('¿Los baños son accesibles?'),'Accessibility details must continue to the normal answer path.');
deterministic_ok(!hache_sharky_deterministic_amenities_request('¿Qué costo tienen las regaderas?'),'Amenity price details must continue to the normal answer path.');
deterministic_ok(!hache_sharky_deterministic_amenities_request('¿Me mandas la ubicación de los baños?'),'Amenity location details must continue to the normal answer path.');
deterministic_ok(hache_sharky_deterministic_detect_explicit_sede('Quiero la ubicación de Monteverde')==='MONTEVERDE','Explicit Monteverde must override current venue context.');
deterministic_ok(hache_sharky_deterministic_detect_explicit_sede('Maps de Palapas Protudec')==='PALAPAS','Explicit Palapas must be recognized.');
deterministic_ok(hache_sharky_deterministic_route_followup('En coche'),'Route follow-ups from the historical screenshot must be caught.');
deterministic_ok(hache_sharky_deterministic_time_range('De 19:00 a 20:00')===['19:00','20:00'],'Time ranges must normalize to HH:MM.');
deterministic_ok(hache_sharky_deterministic_sede_label('MONTEVERDE')==='Colegio Monteverde','All deterministic user-facing Monteverde labels must use Colegio Monteverde.');

$palapasAmenities=hache_sharky_deterministic_reply('Palapas protudec, cuenta con servicio de regaderas ?',$state)??'';
deterministic_ok($palapasAmenities==='Sí. Palapas Protudec cuenta con baños y regaderas.','Explicit Palapas amenities question must answer the confirmed fact directly.');
$monteverdeAmenities=hache_sharky_deterministic_reply('¿Hay baños en Monteverde?',$state)??'';
deterministic_ok($monteverdeAmenities==='Sí. Colegio Monteverde cuenta con baños y regaderas.','Explicit Monteverde amenities question must answer the confirmed fact directly.');
$genericAmenities=hache_sharky_deterministic_amenities_message('¿Tienen regaderas?',['identity'=>['kind'=>'prospect'],'commercial_context'=>['program'=>null,'sede_clave'=>null]])??'';
deterministic_ok(str_contains($genericAmenities,'Colegio Monteverde')&&str_contains($genericAmenities,'Palapas Protudec')&&str_contains($genericAmenities,'baños y regaderas'),'Generic amenities question must state that both venues have bathrooms and showers.');
$bothAmenities=hache_sharky_deterministic_reply('¿Monteverde y Palapas tienen regaderas?',$state)??'';
deterministic_ok(str_contains($bothAmenities,'Colegio Monteverde')&&str_contains($bothAmenities,'Palapas Protudec'),'Explicit both-venue scope must beat the remembered Monteverde venue.');

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
deterministic_ok(str_contains($dispatcher,'$deterministicSource')&&str_contains($dispatcher,"'source'=>\$deterministicSource"),'Dispatcher must expose deterministic/guarded deterministic responses without calling the LLM.');
deterministic_ok(str_contains($dispatcher,"sede: colegio monteverde"),'Dispatcher must recover Colegio Monteverde from the system context before deterministic price/schedule handling.');
deterministic_ok(str_contains($dispatcher,'hache_sharky_reply_looks_incomplete'),'Dispatcher must guard incomplete model answers.');
deterministic_ok(str_contains($dispatcher,'hache_sharky_dispatcher_clean_model_answer'),'Dispatcher must clean repeated greetings and empty bullets.');

$regularMonteverde=[
    'identity'=>['kind'=>'prospect'],
    'commercial_context'=>['program'=>'regular','sede_clave'=>'MONTEVERDE','age'=>null],
];
$regularPrice=hache_sharky_deterministic_price_message($regularMonteverde)??'';
deterministic_ok(str_contains($regularPrice,'Clases regulares en Colegio Monteverde'),'Regular Monteverde price must answer directly with the already-confirmed venue.');
deterministic_ok(str_contains($regularPrice,'$1,000')&&str_contains($regularPrice,'$1,200'),'Regular price reply must include both configured monthly plans.');
deterministic_ok(str_contains($regularPrice,'Inscripción: $500 MXN'),'Every regular Monteverde price reply must include the $500 enrollment fee.');

$regularPalapas=$regularMonteverde;
$regularPalapas['commercial_context']['sede_clave']='PALAPAS';
$regularPalapasPrice=hache_sharky_deterministic_price_message($regularPalapas)??'';
deterministic_ok(str_contains($regularPalapasPrice,'Clases regulares en Palapas Protudec')&&str_contains($regularPalapasPrice,'Inscripción: $400 MXN'),'Every regular Palapas price reply must include the $400 enrollment fee.');

$selectedRegular=$regularMonteverde;
$selectedRegular['commercial_context']=array_replace($selectedRegular['commercial_context'],[
    'plan_id'=>'regular-5','plan_name'=>'Regular 5','sessions_per_week'=>5,'plan_price'=>1200,
]);
$selectedRegularPrice=hache_sharky_deterministic_price_message($selectedRegular)??'';
deterministic_ok(str_contains($selectedRegularPrice,'Regular 5')&&str_contains($selectedRegularPrice,'$1,200.00')&&!str_contains($selectedRegularPrice,'$1,000'),'Selected regular plan reply must keep only the confirmed monthly plan.');
deterministic_ok(str_contains($selectedRegularPrice,'Inscripción: $500 MXN'),'Selected regular plan reply must still include the venue enrollment fee.');

$followupState=$state;
$followupState['previous_user_text']='Me envías la ubicación';
deterministic_ok(hache_sharky_deterministic_location_followup_request('¿Y la de Monteverde?',$followupState),'Elliptical Monteverde location follow-up must inherit the previous location topic.');
deterministic_ok(!hache_sharky_deterministic_location_followup_request('¿Y la de Monteverde?',array_merge($followupState,['previous_user_text'=>'¿Qué horarios hay?'])),'Venue-only follow-up must not become location without a location topic.');
$selectedPriceState=$state;$selectedPriceState['selected_course_price']=1350.0;
$selectedPrice=hache_sharky_deterministic_price_message($selectedPriceState)??'';
deterministic_ok(str_contains($selectedPrice,'$1,350')&&str_contains($selectedPrice,'curso seleccionado'),'Selected backend intensive price must override the general price.');
$dispatcher=file_get_contents(__DIR__.'/../public/api/sharky-whatsapp-dispatch.php')?:'';
deterministic_ok(str_contains($dispatcher,"response_status']??'completed')==='incomplete'"),'Dispatcher must reject Responses API incomplete output before WhatsApp delivery.');
deterministic_ok(!str_contains($dispatcher,"__DIR__.'/sharky-v2.php'"),'Dispatcher must avoid the false local-route literal caught by the global static regression.');

echo "SHARKY_DETERMINISTIC_REPLIES_OK\n";