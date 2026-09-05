<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-whatsapp-batching.php';

function underage_venue_ok(bool $condition,string $message): void
{
    if(!$condition){
        fwrite(STDERR,"FAIL: {$message}\n");
        exit(1);
    }
}

$state=hache_sharky_orchestrator_state(null,1788640000);
$state['identity']=array_replace($state['identity'],[
    'kind'=>'prospect',
    'verified'=>true,
    'source'=>'self_declared',
]);
$state['commercial_context']['program']='intensive';
$state['commercial_context']['sede_clave']='MONTEVERDE';
$state['commercial_context']['swim_level']='beginner';
$state['commercial_context']['age']=8;
$event=[
    'interactive_id'=>'sede:palapas',
    'text'=>'Palapas Protudec',
];

$blocked=hache_sharky_whatsapp_guarded_historical_venue_reselection($state,$event,12);
underage_venue_ok(is_array($blocked),'Historical venue navigation must be recognized before applying the age policy.');
underage_venue_ok(($blocked[2]??'')==='PROSPECT_AGE_REJECTED','A remembered underage prospect must be rejected before venue reselection is accepted.');
underage_venue_ok(($blocked[1]['kind']??'')==='prospect_age_rejected','Underage venue navigation must reuse the normal Sharky age-rejection decision.');
underage_venue_ok(($blocked[0]['commercial_context']['sede_clave']??'')==='MONTEVERDE','Rejected venue navigation must not mutate the remembered venue.');

$adult=$state;
$adult['commercial_context']['age']=59;
$allowed=hache_sharky_whatsapp_guarded_historical_venue_reselection($adult,$event,12);
underage_venue_ok(is_array($allowed)&&($allowed[2]??'')==='VENUE_RESELECTED','An eligible adult prospect must keep durable historical venue navigation.');
underage_venue_ok(($allowed[0]['commercial_context']['sede_clave']??'')==='PALAPAS','Eligible venue reselection must still update the venue.');
underage_venue_ok(($allowed[1]['kind']??'')==='commercial_next_action','Eligible venue reselection must continue to the commercial next-action menu.');

$configuredMinimum=$adult;
$configuredMinimum['commercial_context']['age']=14;
$configuredBlocked=hache_sharky_whatsapp_guarded_historical_venue_reselection($configuredMinimum,$event,15);
underage_venue_ok(is_array($configuredBlocked)&&($configuredBlocked[2]??'')==='PROSPECT_AGE_REJECTED','Historical venue navigation must honor the configured minimum age instead of hard-coding 12.');
underage_venue_ok(($configuredBlocked[0]['commercial_context']['sede_clave']??'')==='MONTEVERDE','Configured-age rejection must leave venue memory untouched.');

echo "OK: Sharky underage historical venue reselection regression\n";
