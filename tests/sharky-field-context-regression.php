<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-whatsapp-adapter.php';
require_once __DIR__.'/../config/sharky-schedule-scope-guard.php';

function field_context_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY FIELD CONTEXT FAIL: {$message}\n");exit(1);}
}

function field_context_state(array $commercial=[],?array $flow=null): array
{
    $state=hache_sharky_orchestrator_state(null,1789093000);
    $state['identity']=array_replace($state['identity'],[
        'kind'=>'prospect','verified'=>false,'source'=>'whatsapp_unmatched',
    ]);
    $state['commercial_context']=array_replace($state['commercial_context']??[],$commercial);
    $state['flow']=$flow;
    return $state;
}

// Real-world natural phrases must map to structured state, not only to model prose.
field_context_ok(hache_sharky_whatsapp_detect_venue_preference('Monteverde me gusta.')==='MONTEVERDE','“Monteverde me gusta” must confirm Monteverde.');
field_context_ok(hache_sharky_whatsapp_detect_venue_browse_scope('Y en Palapas.')==='PALAPAS','“Y en Palapas” must move the browse scope to Palapas.');
field_context_ok(hache_sharky_whatsapp_detect_venue_browse_scope('Quiero ver los de Palapas.')==='PALAPAS','“Quiero ver los de Palapas” must move the browse scope to Palapas.');
field_context_ok(hache_sharky_whatsapp_daypart('En la mañana')==='morning','“En la mañana” must be a morning preference.');

$venueState=field_context_state([
    'program'=>'intensive','sede_clave'=>'MONTEVERDE',
    'schedule_id'=>'mv-8','schedule_label'=>'08:00–09:00',
    'course_id'=>'mv-course','fecha_inicio'=>'2026-09-14','course_price'=>1200,
]);
$beforeVenue=$venueState['commercial_context'];
$venueState=hache_sharky_whatsapp_apply_natural_venue_preference($venueState,'Quiero ver los de Palapas.');
$venueState=hache_sharky_commercial_invalidate($venueState,$beforeVenue);
field_context_ok(($venueState['commercial_context']['sede_clave']??null)==='PALAPAS','Venue browse must update structured venue.');
field_context_ok(empty($venueState['commercial_context']['schedule_id'])&&empty($venueState['commercial_context']['course_id']),'Changing venue must invalidate stale schedule/course selections.');
field_context_ok(($venueState['commercial_context']['program']??null)==='intensive','Changing venue must preserve the active program.');

// A swimmer with unresolved training history must not skip directly to venue.
$swimmer=field_context_state(['swim_level'=>'swims','program'=>'intensive']);
[$swimmerStarted,$swimmerDecision]=hache_sharky_whatsapp_qualification_start($swimmer,1789093001);
field_context_ok(($swimmerStarted['flow']['name']??null)==='qualify_prospect'&&($swimmerStarted['flow']['step']??null)==='background','A swimmer without confirmed training history must stay on the background step.');
field_context_ok(($swimmerDecision['kind']??'')==='prospect_background_prompt','Background must be the next qualification prompt.');

// The exact short reply observed in the field must close background and advance.
$backgroundState=$swimmerStarted;
[$afterNo,$afterNoDecision]=hache_sharky_whatsapp_qualification_input(new PDO('sqlite::memory:'),$backgroundState,['text'=>'No','interactive_id'=>''],1789093002,12);
field_context_ok(($afterNo['commercial_context']['background']??null)==='no_formal','Bare “No” on the background step must persist no_formal.');
field_context_ok(($afterNo['commercial_context']['program']??null)==='intensive','No formal training must force intensive.');
field_context_ok(($afterNo['flow']['step']??null)==='sede','After background=No, qualification must advance to venue instead of repeating background.');
field_context_ok(($afterNoDecision['kind']??'')==='prospect_program_recommendation','After background=No, the response must advance to venue selection.');

// Commercial memory must also resolve a short No when Brain already preserved a product.
$memoryState=field_context_state(['swim_level'=>'swims','program'=>'intensive']);
$memoryState=hache_sharky_commercial_reconcile_guidance($memoryState,'No');
field_context_ok(($memoryState['commercial_context']['background']??null)==='no_formal','Commercial memory must resolve No even when program is already intensive.');
field_context_ok(($memoryState['flow']['step']??null)==='sede','Commercial memory must advance to venue after resolving No.');

$regularPending=field_context_state(['program'=>'regular','sede_clave'=>'MONTEVERDE','swim_level'=>'swims']);
field_context_ok(!hache_sharky_whatsapp_commercial_ready($regularPending),'Regular must not be commercially ready without formal background.');
$regularFormal=$regularPending;$regularFormal['commercial_context']['background']='formal';
field_context_ok(hache_sharky_whatsapp_commercial_ready($regularFormal),'Regular may be commercially ready only after formal background is confirmed.');

// Confirmed context must prevent Brain from asking background or venue again.
$confirmed=field_context_state([
    'program'=>'intensive','sede_clave'=>'MONTEVERDE','swim_level'=>'swims','background'=>'no_formal',
]);
$guarded=hache_sharky_whatsapp_enforce_confirmed_context(
    'Sí, seguimos con intensivo. ¿Has tomado clases de natación antes? ¿En qué sede te gustaría hacerlo, Colegio Monteverde o Palapas Protudec?',
    $confirmed
);
field_context_ok(!str_contains(hache_sharky_orchestrator_normalize($guarded),'has tomado clases'),'Confirmed background must not be asked again.');
field_context_ok(!str_contains(hache_sharky_orchestrator_normalize($guarded),'en que sede'),'Confirmed venue must not be asked again.');

// Use a tiny in-memory backend to verify program+venue filtering in deterministic UI.
$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE sedes(id TEXT PRIMARY KEY, clave TEXT, activo INTEGER)');
$pdo->exec('CREATE TABLE horarios(id TEXT PRIMARY KEY, sede_id TEXT, hora_inicio TEXT, hora_fin TEXT, activo INTEGER, intensivo INTEGER, regular INTEGER)');
$pdo->exec("INSERT INTO sedes VALUES ('mv','MONTEVERDE',1),('pal','PALAPAS',1)");
$insert=$pdo->prepare('INSERT INTO horarios VALUES (?,?,?,?,1,?,?)');
foreach([
    ['mv-r6','mv','06:00:00','07:00:00',0,1],
    ['mv-r7','mv','07:00:00','08:00:00',0,1],
    ['mv-i8','mv','08:00:00','09:00:00',1,1],
    ['mv-i19','mv','19:00:00','20:00:00',1,1],
    ['mv-i20','mv','20:00:00','21:00:00',1,1],
    ['pal-i7','pal','07:00:00','08:00:00',1,1],
    ['pal-i8','pal','08:00:00','09:00:00',1,1],
    ['pal-i9','pal','09:00:00','10:00:00',1,1],
    ['pal-i20','pal','20:00:00','21:00:00',1,1],
] as $row)$insert->execute($row);

$mv=field_context_state(['program'=>'intensive','sede_clave'=>'MONTEVERDE','background'=>'no_formal','swim_level'=>'swims']);
$mvMorning=hache_sharky_whatsapp_intensive_schedule_decision($pdo,$mv,'morning');
$mvBody=(string)($mvMorning['message']??'');
$mvTitles=array_column($mvMorning['ui']['buttons']??[],'title');
field_context_ok(str_contains($mvBody,'08:00–09:00')&&!str_contains($mvBody,'06:00–07:00')&&!str_contains($mvBody,'07:00–08:00'),'Monteverde intensive morning must contain only 08:00–09:00.');
field_context_ok($mvTitles===['08:00–09:00'],'Monteverde morning controls must contain only the intensive schedule.');

$pal=field_context_state(['program'=>'intensive','sede_clave'=>'PALAPAS','background'=>'no_formal','swim_level'=>'swims']);
$palMorning=hache_sharky_whatsapp_intensive_schedule_decision($pdo,$pal,'morning');
$palBody=(string)($palMorning['message']??'');
$palOptions=$palMorning['ui']['options']??[];$palTitles=array_column($palOptions,'title');
field_context_ok($palTitles===['07:00–08:00','08:00–09:00','09:00–10:00'],'Palapas morning controls must be rebuilt from Palapas intensive schedules.');
field_context_ok(!str_contains($palBody,'19:00–20:00'),'Palapas response must not retain stale Monteverde evening controls.');

// The schedule firewall must also own a bare daypart follow-up.
$loader=static function(string $program,string $sede): array {
    if($program!=='intensive')return [];
    if($sede==='MONTEVERDE')return ['08:00–09:00','19:00–20:00','20:00–21:00'];
    return ['07:00–08:00','08:00–09:00','09:00–10:00','20:00–21:00'];
};
$scoped=hache_sharky_schedule_guard_scoped_reply('En la mañana',$mv,$loader);
field_context_ok(is_string($scoped)&&str_contains($scoped,'08:00–09:00')&&!str_contains($scoped,'19:00–20:00'),'Bare morning follow-up must be deterministically scoped to Monteverde intensive morning.');
$repaired=hache_sharky_schedule_guard_model_answer(
    'Los horarios son 06:00–07:00, 07:00–08:00 y 08:00–09:00.',
    $mv,
    'En la mañana',
    $loader
);
field_context_ok(str_contains($repaired,'08:00–09:00')&&!str_contains($repaired,'06:00–07:00')&&!str_contains($repaired,'07:00–08:00'),'Final firewall must repair a wrong bare-daypart model answer.');

fwrite(STDOUT,"SHARKY_FIELD_CONTEXT_OK\n");
