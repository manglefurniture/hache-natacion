<?php

declare(strict_types=1);

if(!function_exists('mb_substr')){function mb_substr(string $s,int $start,?int $length=null,?string $enc=null):string{return $length===null?substr($s,$start):substr($s,$start,$length);}}
if(!function_exists('mb_strlen')){function mb_strlen(string $s,?string $enc=null):int{return strlen($s);}}
if(!function_exists('mb_strtolower')){function mb_strtolower(string $s,?string $enc=null):string{return strtolower($s);}}
require __DIR__.'/../config/sharky-whatsapp-batching.php';

function deferred_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}
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
    deferred_ok(hache_sharky_whatsapp_deferred_close_request($text),$text.' must be recognized as a pure deferred close.');
}

foreach([
    'Confirmo',
    'Sí, confirmo',
    'Te confirmo más tarde si el lunes qué horarios de tarde tiene?',
    'Te confirmo más tarde, ¿me mandas la ubicación?',
    '¿Cuánto cuesta?',
    'Ubicación por favor',
] as $text){
    deferred_ok(!hache_sharky_whatsapp_deferred_close_request($text),$text.' must stay in the substantive/confirmation path.');
}

$state=hache_sharky_orchestrator_state(null,1788537600);
$state['identity']=array_replace($state['identity'],['kind'=>'prospect','verified'=>true,'source'=>'self_declared']);
$state['commercial_context']['program']='intensive';
$state['commercial_context']['sede_clave']='PALAPAS';
$event=['id'=>'defer.1','from'=>'529900000100','type'=>'text','text'=>'Gracias confirmo más tarde','interactive_id'=>''];

deferred_ok(hache_sharky_whatsapp_deferred_close_eligible($state,$event),'Ready commercial context with no active flow must use deterministic deferred close.');
$message=hache_sharky_whatsapp_deferred_close_message($state);
deferred_ok(str_contains($message,'curso intensivo'),'Deferred response must preserve intensive program.');
deferred_ok(str_contains($message,'Palapas Protudec'),'Deferred response must preserve Palapas venue.');
deferred_ok(!str_contains($message,'¿'),'Deferred response must not reopen discovery with a new question.');

$regular=$state;
$regular['commercial_context']['program']='regular';
$regular['commercial_context']['sede_clave']='MONTEVERDE';
$regularMessage=hache_sharky_whatsapp_deferred_close_message($regular);
deferred_ok(str_contains($regularMessage,'clases regulares'),'Regular context must be preserved in deferred response.');
deferred_ok(str_contains($regularMessage,'Colegio Monteverde'),'User-facing Monteverde label must stay normalized.');

$withFlow=$state;
$withFlow=hache_sharky_orchestrator_flow($withFlow,'register_intensive','offer',['sede_clave'=>'PALAPAS'],1788537600);
deferred_ok(!hache_sharky_whatsapp_deferred_close_eligible($withFlow,$event),'Controlled flows must not be silently short-circuited by the soft-close guard.');

$incomplete=$state;
$incomplete['commercial_context']['sede_clave']=null;
deferred_ok(!hache_sharky_whatsapp_deferred_close_eligible($incomplete,$event),'Incomplete commercial context must not use the contextual deferred-close response.');

$source=file_get_contents(__DIR__.'/../config/sharky-whatsapp-batching.php')?:'';
$callPos=strpos($source,'if(hache_sharky_whatsapp_deferred_close_eligible($state,$event))');
$adapterPos=strpos($source,'$result=hache_sharky_whatsapp_process($pdo,$event,$conversationAnswer,$extraContext);');
deferred_ok($callPos!==false&&$adapterPos!==false&&$callPos<$adapterPos,'Deferred-close routing must happen before the free-form adapter/model path.');

fwrite(STDOUT,"Sharky deferred close regression: OK\n");
