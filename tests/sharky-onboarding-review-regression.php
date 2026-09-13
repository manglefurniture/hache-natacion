<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-lab-worker.php';

function onboarding_review_ok(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"SHARKY ONBOARDING REVIEW FAIL: $message\n");exit(1);}
}

foreach(['No entendí','No entiendo','Quiero información','Necesito información','Hola','Buenos días ubicación por favor'] as $phrase){
    onboarding_review_ok(hache_sharky_prospect_onboarding_name($phrase)===null,'Conversational phrase must not become a name: '.$phrase);
}
onboarding_review_ok(hache_sharky_prospect_onboarding_name('Roberto')==='Roberto','A normal first name must remain valid.');
onboarding_review_ok(hache_sharky_prospect_onboarding_name('José Luis')==='José Luis','A normal compound name must remain valid.');

foreach(['No sé nadar','No se nadar','Nada de nada','De cero','De ceros','Quiero aprender a nadar'] as $phrase){
    onboarding_review_ok(hache_sharky_prospect_onboarding_level($phrase)==='beginner','Documented beginner phrase must be recognized: '.$phrase);
}
onboarding_review_ok(hache_sharky_prospect_onboarding_level('Intermedio')==='intermediate','Intermediate free text must remain supported.');
onboarding_review_ok(hache_sharky_prospect_onboarding_level('Avanzado')==='advanced','Advanced free text must remain supported.');

onboarding_review_ok(hache_sharky_prospect_onboarding_yes_no('Sí','onboarding:self:yes','participant')===true,'Current participant Yes button must be accepted.');
onboarding_review_ok(hache_sharky_prospect_onboarding_yes_no('No','onboarding:self:no','participant')===false,'Current participant No button must be accepted.');
onboarding_review_ok(hache_sharky_prospect_onboarding_yes_no('Sí','onboarding:background:yes','participant')===null,'Training-history button must not answer participant step.');
onboarding_review_ok(hache_sharky_prospect_onboarding_yes_no('Sí','onboarding:self:yes','background')===null,'Stale participant button must not assert formal training.');
onboarding_review_ok(hache_sharky_prospect_onboarding_yes_no('Sí','onboarding:background:yes','background')===true,'Current background Yes button must be accepted.');
onboarding_review_ok(hache_sharky_prospect_onboarding_yes_no('No','onboarding:background:no','background')===false,'Current background No button must be accepted.');

$nameState=[
    'identity'=>['kind'=>'prospect','source'=>'whatsapp_unmatched','verified'=>false,'name'=>null],
    'commercial_context'=>[],
    'flow'=>['name'=>'prospect_onboarding','step'=>'name','data'=>['entry_bootstrap'=>false]],
    'referral'=>['latest'=>null],
];
$locationEvent=['from'=>'529981112233','text'=>"Buenos días\nUbicación por favor",'interactive_id'=>''];
onboarding_review_ok(hache_sharky_prospect_onboarding_input_matches_step($nameState,$locationEvent)===false,'Greeting plus location request must not satisfy the pending name step.');
onboarding_review_ok(hache_sharky_prospect_onboarding_side_question($nameState,$locationEvent)===true,'Greeting plus location request must be interpreted as a lateral information question.');
$resume=hache_sharky_prospect_onboarding_resume_decision($nameState);
onboarding_review_ok(($resume['kind']??null)==='prospect_name_prompt'&&str_contains((string)($resume['message']??''),'nombre'),'After a lateral question the same pending name prompt must be restored.');

$pdo=new PDO('sqlite::memory:');
$brainCalls=0;
$priceEvent=['from'=>'529981112233','text'=>"Buenos días\nPrecio por favor",'interactive_id'=>''];
$priceSide=hache_sharky_prospect_onboarding_side_question_response(
    $pdo,
    $nameState,
    $priceEvent,
    static function(string $text,string $instruction,array $state,array $context) use (&$brainCalls): string {
        $brainCalls++;
        onboarding_review_ok(str_contains($instruction,'duda lateral informativa'),'Brain must be constrained to a lateral informational answer.');
        return 'El precio depende del programa que corresponda a tu nivel.';
    }
);
onboarding_review_ok(is_array($priceSide)&&$brainCalls===1,'An open lateral price question must use the conversational layer exactly once.');
[$priceState,$priceDecision]=$priceSide;
onboarding_review_ok(($priceState['flow']['step']??null)==='name','A lateral answer must preserve the exact pending onboarding step.');
onboarding_review_ok(empty($priceState['commercial_context']['prospect_name']),'Brain must not manufacture a prospect name from a lateral question.');
onboarding_review_ok(($priceDecision['kind']??null)==='prospect_onboarding_side_question','Lateral onboarding response must use the non-mutating decision kind.');
onboarding_review_ok(str_contains((string)($priceDecision['message']??''),'depende del programa')&&str_contains((string)($priceDecision['message']??''),'nombre'),'The lateral answer must be followed by the still-pending onboarding question.');

fwrite(STDOUT,"SHARKY_ONBOARDING_REVIEW_OK\n");