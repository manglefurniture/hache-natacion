<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-deterministic-replies.php';

function hache_sharky_schedule_guard_canonicalize_venue_spacing(string $text): string
{
    return preg_replace('/\bmonte\s+verde\b/ui','Monteverde',$text)??$text;
}

function hache_sharky_schedule_guard_normalize(string $text): string
{
    return hache_sharky_deterministic_normalize(hache_sharky_schedule_guard_canonicalize_venue_spacing($text));
}

function hache_sharky_schedule_guard_venue_selection(string $text): ?string
{
    if(str_contains($text,'?')||str_contains($text,'¿'))return null;
    $t=hache_sharky_schedule_guard_normalize($text);
    if($t==='')return null;
    $choice='(?:quiero|prefiero|elijo|escojo|me\s+quedo\s+con|voy\s+con|mejor|me\s+sirve)';
    $mv=preg_match('/^(?:(?:colegio|sede)\s+)?monteverde[.! ]*$/u',$t)===1
        || preg_match('/\b'.$choice.'\b.{0,28}\bmonteverde\b/u',$t)===1;
    $pal=preg_match('/^(?:(?:sede)\s+)?palapas(?:\s+protudec)?[.! ]*$/u',$t)===1
        || preg_match('/\b'.$choice.'\b.{0,28}\bpalapas(?:\s+protudec)?\b/u',$t)===1;
    if($mv&&!$pal)return 'MONTEVERDE';
    if($pal&&!$mv)return 'PALAPAS';
    return null;
}

function hache_sharky_schedule_guard_cross_program_request(string $text,string $activeProgram): bool
{
    $t=hache_sharky_schedule_guard_normalize($text);
    if(preg_match('/\b(?:ambos|ambas|los\s+dos|las\s+dos)\b/u',$t)===1)return true;
    if($activeProgram==='intensive'){
        return preg_match('/\b(?:regular|regulares|mensualidad|mensualidades)\b/u',$t)===1;
    }
    if($activeProgram==='regular'){
        return preg_match('/\b(?:curso\s+)?intensiv[oa]s?\b/u',$t)===1;
    }
    return false;
}

/** @return list<string> */
function hache_sharky_schedule_guard_answer_ranges(string $answer): array
{
    $out=[];
    if(preg_match_all('/(?<!\d)(\d{1,2}):([0-5]\d)\s*[–-]\s*(\d{1,2}):([0-5]\d)(?!\d)/u',$answer,$matches,PREG_SET_ORDER)!==false){
        foreach($matches as $m){
            $h1=(int)$m[1];$h2=(int)$m[3];
            if($h1>23||$h2>23)continue;
            $out[]=sprintf('%02d:%02d–%02d:%02d',$h1,(int)$m[2],$h2,(int)$m[4]);
        }
    }
    return array_values(array_unique($out));
}

function hache_sharky_schedule_guard_scope_violation_from_hours(string $answer,string $activeProgram,array $allowedHours): bool
{
    if(!in_array($activeProgram,['intensive','regular'],true))return false;
    $t=hache_sharky_schedule_guard_normalize($answer);
    $ranges=hache_sharky_schedule_guard_answer_ranges($answer);
    $scheduleLike=$ranges!==[]||preg_match('/\b(?:horario|horarios|hora|horas)\b/u',$t)===1;
    if(!$scheduleLike)return false;

    if($activeProgram==='intensive'&&preg_match('/\bregular(?:es)?\b/u',$t)===1)return true;
    if($activeProgram==='regular'&&preg_match('/\bintensiv[oa]s?\b/u',$t)===1)return true;

    $allowed=array_fill_keys(array_values(array_map('strval',$allowedHours)),true);
    foreach($ranges as $range)if(!isset($allowed[$range]))return true;
    return false;
}

function hache_sharky_schedule_guard_invalid_selection_from_hours(string $text,array $state,array $allowedHours): ?string
{
    $range=hache_sharky_deterministic_time_range($text);if($range===null)return null;
    $commercial=hache_sharky_deterministic_commercial($state);if($commercial===null)return null;
    $needle=$range[0].'–'.$range[1];
    if(in_array($needle,$allowedHours,true))return null;

    $label=hache_sharky_deterministic_sede_label($commercial['sede']);
    $programLabel=$commercial['program']==='regular'?'clases regulares':'curso intensivo';
    if(!$allowedHours)return 'Ahora mismo no encuentro horarios activos de '.$programLabel.' en '.$label.'. Prefiero no inventarte uno; puedo dejarte con el equipo para revisarlo.';
    return 'Ese horario no está activo para '.$programLabel.' en '.$label.'. Los horarios vigentes son:'."\n\n"
        .implode("\n",array_map(static fn(string $h):string=>'• '.$h,$allowedHours))
        ."\n\n".'Elige uno de estos horarios para continuar.';
}

function hache_sharky_schedule_guard_reply(string $text,array $state): ?string
{
    $commercial=hache_sharky_deterministic_commercial($state);if($commercial===null)return null;

    $venue=hache_sharky_schedule_guard_venue_selection($text);
    if($venue!==null&&$venue===$commercial['sede']){
        return hache_sharky_deterministic_schedule_message($state);
    }

    if(hache_sharky_deterministic_time_range($text)===null)return null;
    $pdo=hache_sharky_pdo();
    if(!$pdo instanceof PDO)return 'No pude verificar ese horario contra la disponibilidad actual. Prefiero no inventarte una opción; intenta de nuevo en unos minutos.';
    try{$hours=hache_sharky_deterministic_active_schedules($pdo,$commercial['program'],$commercial['sede']);}
    catch(Throwable $e){return 'No pude verificar ese horario contra la disponibilidad actual. Prefiero no inventarte una opción; intenta de nuevo en unos minutos.';}
    return hache_sharky_schedule_guard_invalid_selection_from_hours($text,$state,$hours);
}

function hache_sharky_schedule_guard_model_answer(string $answer,array $state,string $userText=''): string
{
    $commercial=hache_sharky_deterministic_commercial($state);if($commercial===null)return $answer;
    if(hache_sharky_schedule_guard_cross_program_request($userText,$commercial['program']))return $answer;

    $pdo=hache_sharky_pdo();if(!$pdo instanceof PDO)return $answer;
    try{$hours=hache_sharky_deterministic_active_schedules($pdo,$commercial['program'],$commercial['sede']);}
    catch(Throwable $e){return $answer;}
    if(!hache_sharky_schedule_guard_scope_violation_from_hours($answer,$commercial['program'],$hours))return $answer;

    $replacement=hache_sharky_deterministic_schedule_message($state);
    return is_string($replacement)&&trim($replacement)!==''?$replacement:$answer;
}
