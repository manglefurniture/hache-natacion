<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-whatsapp-batching.php';

function safe_side_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY SAFE SIDE QUESTION FAIL: $message\n");exit(1);}
}

function safe_side_meta_state(string $step,array $commercial,int $now): array
{
    $state=hache_sharky_orchestrator_state(null,$now);
    $state['identity']=array_replace($state['identity'],['kind'=>'prospect','verified'=>true,'source'=>'self_declared']);
    $state['commercial_context']=array_replace($state['commercial_context'],['entry_source'=>'direct'],$commercial);
    return hache_sharky_meta_flow($state,$step,[],$now);
}

function safe_side_meta(PDO $pdo,array $state,string $question): array
{
    $before=$state;
    $result=hache_sharky_meta_handle($pdo,$state,['from'=>'anon','text'=>$question,'interactive_id'=>'meta:free_text'],(int)$state['updated_at']+1);
    safe_side_ok(is_array($result),'A recognized lateral question must be handled.');
    safe_side_ok(($result[0]??null)===$before,'Answering a lateral question must preserve the complete state and exact cursor.');
    safe_side_ok(($result[1]['kind']??'')==='meta_side_question','The decision must remain explicitly informational.');
    safe_side_ok(empty($result[1]['ui']['images']??[]),'Resuming after an answer must not repeat prior images.');
    return $result[1];
}

$pdo=new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE configuracion (clave TEXT PRIMARY KEY, valor TEXT NOT NULL)');
$pdo->exec("INSERT INTO configuracion(clave,valor) VALUES ('sharky_precio_intensivo','1350'),('sharky_maps_monteverde','https://maps.example/mv'),('sharky_maps_palapas','https://maps.example/pal')");
$pdo->exec('CREATE TABLE sedes (id INTEGER PRIMARY KEY, clave TEXT, nombre TEXT, activo INTEGER)');
$pdo->exec("INSERT INTO sedes VALUES (1,'MONTEVERDE','Colegio Monteverde',1),(2,'PALAPAS','Palapas Protudec',1)");
$pdo->exec('CREATE TABLE horarios (id INTEGER PRIMARY KEY, sede_id INTEGER, hora_inicio TEXT, hora_fin TEXT, activo INTEGER, regular INTEGER, intensivo INTEGER)');
$pdo->exec("INSERT INTO horarios VALUES (1,1,'08:00:00','09:00:00',1,0,1),(2,1,'18:00:00','19:00:00',1,1,0),(3,2,'20:00:00','21:00:00',1,1,0)");
$pdo->exec('CREATE TABLE planes (id INTEGER PRIMARY KEY, sede_id INTEGER, sesiones_semana INTEGER, precio NUMERIC, activo INTEGER)');
$pdo->exec('INSERT INTO planes VALUES (1,1,3,1100,1),(2,1,5,1450,1)');

$now=1789400000;

$product=safe_side_meta($pdo,safe_side_meta_state('program',[],$now),'¿Cuánto cuesta?');
safe_side_ok(str_contains($product['message'],'depende del producto'),'A price question before product selection must fail safely without inventing a price.');
safe_side_ok(array_column($product['ui']['buttons']??[],'id')===['meta:program:learn','meta:program:regular'],'Product controls must remain pending.');

$level=safe_side_meta($pdo,safe_side_meta_state('regular_background',[],$now+1),'¿Necesito saber nadar?');
safe_side_ok(str_contains($level['message'],'No necesitas saber nadar'),'A requirement question during level/background selection must be answered first.');
safe_side_ok(array_column($level['ui']['buttons']??[],'id')===['meta:regular:yes','meta:regular:no'],'Level/background controls must remain pending.');

$duration=safe_side_meta($pdo,safe_side_meta_state('venue',['program'=>'intensive'],$now+2),'¿Cuánto dura?');
safe_side_ok(str_contains($duration['message'],'3 semanas'),'Duration must come from the confirmed intensive rule.');

$price=safe_side_meta($pdo,safe_side_meta_state('venue',['program'=>'intensive'],$now+3),'¿Son 1200 mensual?');
safe_side_ok(str_contains($price['message'],'$1,350 MXN')&&!str_contains($price['message'],'$1,200'),'Price must come from backend configuration, never the number in the question or a hardcoded fallback.');
safe_side_ok(str_contains($price['message'],'No es mensualidad'),'The real monthly-price regression must be answered directly.');

$location=safe_side_meta($pdo,safe_side_meta_state('venue',['program'=>'intensive'],$now+4),'¿Dónde está Palapas?');
safe_side_ok(str_contains($location['message'],'https://maps.example/pal'),'Location must use the configured authority.');
safe_side_ok(array_column($location['ui']['buttons']??[],'id')===['meta:venue:monteverde','meta:venue:palapas'],'Asking about Palapas must not select it.');

$schedule=safe_side_meta($pdo,safe_side_meta_state('venue_detail',['program'=>'regular','sede_clave'=>'MONTEVERDE'],$now+5),'¿Qué horarios tienen?');
safe_side_ok(str_contains($schedule['message'],'18:00–19:00'),'Schedules must come from the active backend catalog.');
safe_side_ok(array_column($schedule['ui']['buttons']??[],'id')===['meta:register:regular','meta:venue:other'],'Venue-detail actions must remain pending after the answer.');

$requirements=safe_side_meta($pdo,safe_side_meta_state('venue',['program'=>'intensive'],$now+6),'¿Qué requisitos y equipo necesito?');
safe_side_ok(str_contains($requirements['message'],'gorro de natación')&&str_contains($requirements['message'],'goggles'),'Confirmed requirements must be answered without Brain.');

$recent=safe_side_meta($pdo,safe_side_meta_state('program',[],$now+7),'¿Cuál es la diferencia entre intensivo y regular?');
safe_side_ok(str_contains($recent['message'],'El intensivo')&&str_contains($recent['message'],'clases regulares'),'A question about recently shown products must be answered before resuming.');

$ambiguous=safe_side_meta($pdo,safe_side_meta_state('venue',['program'=>'intensive'],$now+8),'¿Y eso cómo funciona?');
safe_side_ok(str_contains($ambiguous['message'],'No tengo información confirmada suficiente'),'Ambiguous questions must fail safely and retain the cursor.');

$previousPrompt=hache_sharky_meta_venue_retry(safe_side_meta_state('venue',['program'=>'intensive'],$now+9));
$answered=safe_side_meta($pdo,safe_side_meta_state('venue',['program'=>'intensive'],$now+9),'¿Cuánto dura?');
safe_side_ok($answered['message']!==$previousPrompt['message']&&str_starts_with($answered['message'],'📅 El curso intensivo dura'),'Sharky must answer the question instead of merely repeating the previous block.');

foreach(['swim'=>'¿Cuánto dura el intensivo?','daypart'=>'¿Necesito goggles?'] as $step=>$question){
    $state=hache_sharky_orchestrator_state(null,$now+10);$state['identity']['kind']='prospect';
    $state['commercial_context']=array_replace($state['commercial_context'],['program'=>'regular','sede_clave'=>'MONTEVERDE']);
    $state=hache_sharky_orchestrator_flow($state,'qualify_prospect',$step,[],$now+10);$before=$state;
    $answer=hache_sharky_safe_side_answer($pdo,$state,$question);
    safe_side_ok(is_string($answer)&&$answer!=='','Legacy level/turn cursors must receive a deterministic informational answer.');
    $result=['state'=>$state,'decision'=>['kind'=>'side_question','message'=>$answer."\n\nCuando quieras, seguimos donde lo dejamos.",'ui'=>[],'action'=>null]];
    $resumed=hache_sharky_whatsapp_batch_resume_qualification_controls($pdo,'anon',$result,['now'=>$now+11,'min_age'=>12]);
    safe_side_ok(($resumed['state']??null)===$before,'Legacy level/turn side questions must preserve the exact pending state.');
    safe_side_ok(($resumed['decision']['ui']['type']??'')==='buttons','Legacy level/turn controls must be restored.');
}

safe_side_ok(hache_sharky_safe_side_answer($pdo,safe_side_meta_state('program',[],$now+12),'¿Me puedes inscribir?')===null,'Sensitive operations must stay outside the informational layer.');
$source=(string)file_get_contents(__DIR__.'/../config/sharky-safe-side-question.php');
safe_side_ok(!str_contains($source,'hache_sharky_lab_answer')&&!str_contains($source,'answer_user')&&!str_contains($source,'continue_discovery'),'The safe layer must not depend on Brain or open-conversation actions.');
safe_side_ok(!preg_match('/(?:fallback|\?\?)\s*[^\n;]*1200/u',$source),'The safe layer must not contain a hardcoded price fallback.');

echo "SHARKY_SAFE_SIDE_QUESTION_OK\n";
