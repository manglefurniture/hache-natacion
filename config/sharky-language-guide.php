<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-orchestrator.php';
require_once __DIR__.'/sharky-orchestrator-db.php';

/**
 * Capa lingüística ligera para WhatsApp.
 *
 * Convierte expresiones coloquiales o faltas frecuentes en una formulación
 * canónica que el orquestador ya sabe interpretar. No decide producto, precio,
 * sede ni acciones sensibles: solo traduce lenguaje natural inequívoco a la
 * misma semántica determinística existente.
 */
function hache_sharky_language_normalized(string $text): string
{
    return trim(preg_replace('/\s+/u',' ',hache_sharky_orchestrator_normalize($text))??'');
}

function hache_sharky_language_swim_step_text(array $state,string $text): ?string
{
    $flow=is_array($state['flow']??null)?$state['flow']:[];
    if(($flow['name']??'')!=='qualify_prospect'||($flow['step']??'')!=='swim')return null;
    $t=hache_sharky_language_normalized($text);
    if($t==='')return null;

    // Expresiones inequívocas de persona que empieza desde cero. La lista es
    // deliberadamente corta y basada en lenguaje real; no es un corrector global.
    $beginner=[
        '/^(?:de|desde|en)\s+ceros?[.! ]*$/u',
        '/^(?:cero|ceros)[.! ]*$/u',
        '/\bnada\s+de\s+nada\b/u',
        '/\bno\s+(?:se|ce)\s+nadar\b/u',
        '/\bno\s+(?:se|ce)\s+(?:nada\s+)?de\s+nadar\b/u',
        '/\bno\s+(?:se|ce)\s+nada\s+de\s+nada\b/u',
        '/\bnunca\s+he\s+nadado\b/u',
        '/\bno\s+(?:se|ce)\s+flotar\b/u',
        '/^quiero\s+aprender\s+a\s+nadar(?:\s+y\s+flotar)?[.! ]*$/u',
        '/\b(?:empiezo|empezando|voy)\s+(?:de|desde|en)\s+ceros?\b/u',
    ];
    foreach($beginner as $pattern)if(preg_match($pattern,$t)===1)return 'Desde cero';

    // Suficiente para confirmar que ya nada, pero no para asumir formación.
    // El siguiente paso determinístico seguirá preguntando formal vs. autodidacta.
    $swims=[
        '/\b(?:ya\s+)?(?:se|ce)\s+nadar\b/u',
        '/\bnado\s+(?:un\s+)?poco\b/u',
        '/\bnado\s+perrito\b/u',
        '/\bme\s+defiendo(?:\s+nadando|\s+en\s+el\s+agua)?\b/u',
        '/\bme\s+mantengo\s+a\s+flote\b/u',
        '/\bse\s+flotar\b/u',
    ];
    foreach($swims as $pattern)if(preg_match($pattern,$t)===1)return 'Ya sé nadar';
    return null;
}

function hache_sharky_language_background_step_text(array $state,string $text): ?string
{
    $flow=is_array($state['flow']??null)?$state['flow']:[];
    if(($flow['name']??'')!=='qualify_prospect'||($flow['step']??'')!=='background')return null;
    $t=hache_sharky_language_normalized($text);
    if($t==='')return null;

    $selfTaught=[
        '/^nunca[.! ]*$/u',
        '/\bpor\s+mi\s+cuenta\b/u',
        '/\baprendi\s+(?:yo\s+)?sol[oa]\b/u',
        '/\bnadie\s+me\s+enseno\b/u',
        '/\bsin\s+(?:profesor|entrenador|clases?)\b/u',
        '/\bnunca\s+(?:he\s+)?tomado\s+clases\b/u',
        '/\bno\s+(?:he\s+)?tomado\s+clases\b/u',
        '/\bautodidacta\b/u',
    ];
    foreach($selfTaught as $pattern)if(preg_match($pattern,$t)===1)return 'Por mi cuenta';

    $formal=[
        '/\b(?:si\s+)?he\s+tomado\s+clases\b/u',
        '/\btome\s+clases\b/u',
        '/\bfui\s+a\s+clases\b/u',
        '/\bcon\s+(?:un\s+)?(?:profesor|entrenador)\b/u',
        '/\bclases\s+formales\b/u',
    ];
    foreach($formal as $pattern)if(preg_match($pattern,$t)===1)return 'He tomado clases';
    return null;
}

function hache_sharky_language_venue_step_text(array $state,string $text): ?string
{
    $flow=is_array($state['flow']??null)?$state['flow']:[];
    if(($flow['name']??'')!=='qualify_prospect'||($flow['step']??'')!=='sede')return null;
    if(($flow['data']['venue_proposal']??null)!=='MONTEVERDE')return null;
    $t=hache_sharky_language_normalized($text);
    if($t==='')return null;

    // Una afirmación corta solo es selección de Monteverde cuando la pregunta
    // pendiente ya propone explícitamente esa sede. Fuera de este paso sigue
    // siendo una respuesta ambigua y no debe cambiar sede por sí sola.
    if(preg_match('/^(?:si|si\s+me\s+funciona|me\s+funciona|esta\s+bien|ok|okay|vale)[.! ]*$/u',$t)===1)return 'Monteverde';
    return null;
}

function hache_sharky_language_daypart(string $text): ?string
{
    $t=hache_sharky_language_normalized($text);
    if(preg_match('/\b(?:manana|matutino|matutina|temprano)\b/u',$t)===1)return 'morning';
    if(preg_match('/\b(?:tarde|noche|vespertino|vespertina)\b/u',$t)===1)return 'evening';
    return null;
}

function hache_sharky_language_more_schedule_request(string $text): bool
{
    if(str_contains($text,'?')||str_contains($text,'¿'))$question=true;else $question=false;
    $t=hache_sharky_language_normalized($text);
    if($t==='')return false;
    $matches=preg_match('/\b(?:otro|otros|otra|otras|mas|diferente|diferentes)\s+horarios?\b/u',$t)===1
        ||preg_match('/\bhorarios?\s+(?:otro|otros|diferente|diferentes|mas)\b/u',$t)===1
        ||preg_match('/\b(?:no\s+)?(?:tienes|tienen|hay|manejan|tendras|tendran)\b.{0,26}\b(?:otro|otros|mas)\b.{0,14}\bhorarios?\b/u',$t)===1;
    if(!$matches)return false;
    // Una selección explícita de sede no se reinterpreta como fallback.
    if(preg_match('/\b(?:monteverde|palapas|protudec|misma\s+sede)\b/u',$t)===1)return false;
    return $question||preg_match('/^(?:no\s+)?(?:tienes|tienen|hay|manejan|otro|otros|mas)\b/u',$t)===1;
}

function hache_sharky_language_schedule_fallback_text(array $state,string $text): ?string
{
    $c=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    if(($c['program']??'')!=='intensive')return null;
    $sede=(string)($c['sede_clave']??'');
    if(!in_array($sede,['MONTEVERDE','PALAPAS'],true))return null;
    if(!hache_sharky_language_more_schedule_request($text))return null;

    $daypart=hache_sharky_language_daypart($text);
    if($daypart===null)$daypart=hache_sharky_language_daypart((string)($state['last_user_text']??''));
    if($daypart==='morning')return '¿Qué horarios de la mañana tiene la otra sede?';
    if($daypart==='evening')return '¿Qué horarios de la tarde o noche tiene la otra sede?';
    return '¿Qué horarios tiene la otra sede?';
}

function hache_sharky_language_prepare_text(array $state,string $text): string
{
    $qualification=hache_sharky_language_swim_step_text($state,$text);
    if($qualification!==null)return $qualification;
    $background=hache_sharky_language_background_step_text($state,$text);
    if($background!==null)return $background;
    $venue=hache_sharky_language_venue_step_text($state,$text);
    if($venue!==null)return $venue;
    $schedule=hache_sharky_language_schedule_fallback_text($state,$text);
    if($schedule!==null)return $schedule;
    return $text;
}

function hache_sharky_language_prepare_event(PDO $pdo,array $event): array
{
    if((string)($event['type']??'')!=='text')return $event;
    if(trim((string)($event['interactive_id']??''))!=='')return $event;
    if(trim((string)($event['group_id']??''))!=='')return $event;
    if(($event['kind']??'message')==='echo')return $event;
    $contact=preg_replace('/\D+/','',(string)($event['from']??''))?:'';
    $text=trim((string)($event['text']??''));
    if($contact===''||$text==='')return $event;
    try{$state=hache_sharky_db_state_load($pdo,$contact);}catch(Throwable $e){return $event;}
    if(($state['identity']['kind']??'unknown')!=='prospect')return $event;
    $prepared=hache_sharky_language_prepare_text($state,$text);
    if($prepared===$text)return $event;
    $event['_language_original_text']=$text;
    $event['text']=$prepared;
    if(function_exists('hache_sharky_metric_increment'))hache_sharky_metric_increment('language_normalized');
    return $event;
}
