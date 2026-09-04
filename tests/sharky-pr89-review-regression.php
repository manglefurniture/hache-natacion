<?php

declare(strict_types=1);

if(!function_exists('mb_substr')){function mb_substr(string $s,int $start,?int $length=null,?string $enc=null):string{return $length===null?substr($s,$start):substr($s,$start,$length);}}
if(!function_exists('mb_strlen')){function mb_strlen(string $s,?string $enc=null):int{return strlen($s);}}
if(!function_exists('mb_strtolower')){function mb_strtolower(string $s,?string $enc=null):string{return strtolower($s);}}

require_once __DIR__.'/../config/sharky-whatsapp-batching.php';
require_once __DIR__.'/../config/sharky-followup.php';

function pr89_review_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"PR89 REVIEW FAIL: $message\n");exit(1);}
}

foreach([
    'Gracias confirmo más tarde',
    'Gracias, te confirmo luego',
    'Luego te aviso',
    'Mañana te confirmo',
    'Déjame checar',
    'Déjame revisarlo y te aviso',
    'Lo pienso y te digo',
] as $text){
    pr89_review_ok(hache_sharky_followup_user_deferred($text),'Deferred close must suppress idle follow-ups: '.$text);
    pr89_review_ok(hache_sharky_followup_user_opted_out($text),'Deferred close must count as follow-up opt-out for the active session: '.$text);
}

pr89_review_ok(!hache_sharky_followup_user_deferred('Te confirmo más tarde, ¿me mandas la ubicación?'),'A deferred phrase with a substantive question must remain conversational.');
pr89_review_ok(!hache_sharky_followup_user_opted_out('No me escribas más tarde, mejor mañana'),'A timing preference must not become a permanent stop-contact command.');

$now=1788542400;
$state=hache_sharky_orchestrator_state(null,$now);
$state['identity']=array_replace($state['identity'],['kind'=>'prospect','verified'=>true,'source'=>'self_declared']);
$state['commercial_context']['program']='intensive';
$state['commercial_context']['sede_clave']='PALAPAS';
$event=['id'=>'pr89.defer','from'=>'529900000190','type'=>'text','text'=>'Te confirmo mañana','interactive_id'=>''];

$stale=hache_sharky_orchestrator_flow($state,'register_intensive','offer',['sede_clave'=>'PALAPAS'],$now-HACHE_SHARKY_FLOW_TTL-1);
pr89_review_ok(is_array($stale['flow']??null),'Regression setup must contain a stored flow.');
$expired=hache_sharky_orchestrator_expire_flow($stale,$now);
pr89_review_ok(!is_array($expired['flow']??null),'A stale controlled flow must expire before deferred-close eligibility.');
pr89_review_ok(hache_sharky_whatsapp_deferred_close_eligible($expired,$event),'A returning prospect with an expired flow must receive the deterministic deferred close.');

$active=hache_sharky_orchestrator_flow($state,'register_intensive','offer',['sede_clave'=>'PALAPAS'],$now);
$activeAfterExpiryCheck=hache_sharky_orchestrator_expire_flow($active,$now);
pr89_review_ok(is_array($activeAfterExpiryCheck['flow']??null),'A genuinely active controlled flow must remain active.');
pr89_review_ok(!hache_sharky_whatsapp_deferred_close_eligible($activeAfterExpiryCheck,$event),'An active controlled flow must not be short-circuited by deferred-close routing.');

$batching=file_get_contents(__DIR__.'/../config/sharky-whatsapp-batching.php')?:'';
$expirePos=strpos($batching,'$deferredState=hache_sharky_orchestrator_expire_flow($state,$now);');
$eligiblePos=strpos($batching,'if(hache_sharky_whatsapp_deferred_close_eligible($deferredState,$event))');
pr89_review_ok($expirePos!==false&&$eligiblePos!==false&&$expirePos<$eligiblePos,'Delivery-lock routing must expire stale flows before deferred-close eligibility.');

$follow=file_get_contents(__DIR__.'/../config/sharky-followup.php')?:'';
pr89_review_ok(str_contains($follow,'if(hache_sharky_followup_user_deferred($text))return true;'),'Idle follow-up opt-out must include deferred-close language.');
pr89_review_ok(str_contains($follow,'hache_sharky_followup_commercial_ready($state)'),'Due follow-ups must still revalidate commercial eligibility before send.');

echo "SHARKY_PR89_REVIEW_OK\n";
