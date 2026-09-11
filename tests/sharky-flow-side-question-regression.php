<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-whatsapp-batching.php';

function sideq_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY SIDE QUESTION FAIL: $message\n");exit(1);}
}

$price='Precio para las clases, es mensual. O como manejan con el pago.';
$equipment='Y que equipos piden.';

sideq_ok(hache_sharky_whatsapp_batch_question_like($price),'A natural price/payment question without question marks must be recognized.');
sideq_ok(hache_sharky_whatsapp_batch_question_like($equipment),'A leading “Y que…” equipment question must be recognized.');
sideq_ok(str_ends_with(hache_sharky_whatsapp_batch_question_text($price),'?'),'Question-like commercial text must receive a synthetic question marker.');
sideq_ok(str_ends_with(hache_sharky_whatsapp_batch_question_text($equipment),'?'),'Equipment text must receive a synthetic question marker.');
sideq_ok(hache_sharky_whatsapp_batch_question_text('Palapas Protudec')==='Palapas Protudec','A valid venue answer must not be rewritten as a question.');
sideq_ok(!hache_sharky_whatsapp_batch_question_like('Desde cero'),'A guided-flow answer must not be mistaken for a side question.');

$paymentChoice='Pago el 50%';
$daypartChoice='Horario matutino';
sideq_ok(hache_sharky_whatsapp_batch_question_text($paymentChoice)===$paymentChoice,'A valid payment choice must not be rewritten as a question.');
sideq_ok(hache_sharky_whatsapp_payment_choice(hache_sharky_whatsapp_batch_question_text($paymentChoice))!==null,'The preserved payment choice must still reach the existing payment parser.');
sideq_ok(hache_sharky_whatsapp_batch_question_text($daypartChoice)===$daypartChoice,'A valid daypart choice must not be rewritten as a question.');
sideq_ok(hache_sharky_whatsapp_daypart(hache_sharky_whatsapp_batch_question_text($daypartChoice),'')==='morning','The preserved daypart choice must still reach the existing daypart parser.');

$now=1788732000;
$state=hache_sharky_orchestrator_state(null,$now);
$state['identity']=array_replace($state['identity'],['kind'=>'prospect','verified'=>true,'source'=>'self_declared']);
$state['commercial_context']['program']='intensive';
$state['commercial_context']['swim_level']='beginner';
$state=hache_sharky_orchestrator_flow($state,'qualify_prospect','sede',[],$now);

$priceEvent=['text'=>hache_sharky_whatsapp_batch_question_text($price),'interactive_id'=>''];
$equipmentEvent=['text'=>hache_sharky_whatsapp_batch_question_text($equipment),'interactive_id'=>''];
sideq_ok(hache_sharky_whatsapp_is_side_question($state,$priceEvent),'The normalized price question must bypass the strict venue validator.');
sideq_ok(hache_sharky_whatsapp_is_side_question($state,$equipmentEvent),'The normalized equipment question must bypass the strict venue validator.');
sideq_ok(!hache_sharky_whatsapp_is_side_question($state,['text'=>'Palapas','interactive_id'=>'']),'An actual venue selection must still reach the venue step.');

$contact='529983376810';
$answer='El curso intensivo cuesta $1,200 MXN. El pago corresponde al curso, no a una mensualidad.';
$sideResult=[
    'skip'=>false,
    'state'=>$state,
    'decision'=>[
        'kind'=>'side_question',
        'message'=>$answer."\n\nCuando quieras, seguimos donde lo dejamos.",
        'ui'=>[],
        'action'=>null,
    ],
    'payload'=>hache_sharky_whatsapp_text_payload($contact,$answer),
    'action_result'=>null,
];

$pdo=new PDO('sqlite::memory:');
$resumed=hache_sharky_whatsapp_batch_resume_qualification_controls($pdo,$contact,$sideResult,['now'=>$now,'min_age'=>12]);
$decision=is_array($resumed['decision']??null)?$resumed['decision']:[];
sideq_ok(($decision['kind']??'')==='side_question','The response must remain classified as a side question.');
sideq_ok(($decision['ui']['type']??'')==='buttons','The pending guided step must resume with its WhatsApp buttons.');
$buttonIds=array_column($decision['ui']['buttons']??[],'id');
sideq_ok($buttonIds===['sede:monteverde','sede:palapas'],'The resumed venue step must expose the two current venue choices.');
$message=(string)($decision['message']??'');
sideq_ok(str_contains($message,'$1,200 MXN'),'The useful answer must stay before the resumed flow prompt.');
sideq_ok(str_contains($message,'Te propongo primero Colegio Monteverde'),'The response must immediately resume the pending Monteverde-first venue question.');
sideq_ok(!str_contains($message,'Cuando quieras, seguimos donde lo dejamos.'),'The generic deferred-resume sentence must be removed when controls resume immediately.');
sideq_ok(($resumed['state']['flow']['name']??'')==='qualify_prospect'&&($resumed['state']['flow']['step']??'')==='sede','A side question must not advance, reset or lose the controlled flow.');

echo "SHARKY_FLOW_SIDE_QUESTION_OK\n";
