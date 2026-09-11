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

function hache_sharky_schedule_guard_requested_programs(string $text,string $activeProgram): array
{
    if(!in_array($activeProgram,['intensive','regular'],true))return [];
    $t=hache_sharky_schedule_guard_normalize($text);
    $mentionsIntensive=preg_match('/\b(?:curso\s+)?intensiv[oa]s?\b/u',$t)===1;
    $mentionsRegular=preg_match('/\b(?:clases?\s+)?regulares?\b|\bmensualidad(?:es)?\b/u',$t)===1;
    if($mentionsIntensive&&$mentionsRegular)return ['intensive','regular'];
    if($mentionsRegular)return ['regular'];
    if($mentionsIntensive)return ['intensive'];
    return [$activeProgram];
}

function hache_sharky_schedule_guard_cross_program_request(string $text,string $activeProgram): bool
{
    $programs=hache_sharky_schedule_guard_requested_programs($text,$activeProgram);
    return $programs!==[]&&($programs!==[$activeProgram]);
}

function hache_sharky_schedule_guard_both_venues_reference(string $text): bool
{
    $t=hache_sharky_schedule_guard_normalize($text);
    return preg_match('/\b(?:ambas?\s+sedes?|las\s+dos\s+sedes|los\s+dos\s+(?:lugares|centros|planteles)|ambos\s+(?:lugares|centros|planteles))\b/u',$t)===1;
}

/** @return list<string> */
function hache_sharky_schedule_guard_requested_venues(string $text,array $state): array
{
    $commercial=hache_sharky_deterministic_commercial($state);if($commercial===null)return [];
    $t=hache_sharky_schedule_guard_normalize($text);
    $mv=preg_match('/\bmonteverde\b/u',$t)===1;
    $pal=preg_match('/\bpalapas(?:\s+protudec)?\b/u',$t)===1;
    if($mv&&$pal)return ['MONTEVERDE','PALAPAS'];
    if($mv)return ['MONTEVERDE'];
    if($pal)return ['PALAPAS'];
    if(hache_sharky_schedule_guard_both_venues_reference($text))return ['MONTEVERDE','PALAPAS'];

    // Preserve an explicit previous both-venue scope only when the user really
    // named both venues/sedes. Bare words such as "ambos" are intentionally
    // ambiguous: they may refer to morning/tarde, plans or other options.
    $previous=trim((string)($state['previous_user_text']??''));
    if($previous!==''){
        $p=hache_sharky_schedule_guard_normalize($previous);
        $previousMv=preg_match('/\bmonteverde\b/u',$p)===1;
        $previousPal=preg_match('/\bpalapas(?:\s+protudec)?\b/u',$p)===1;
        if(($previousMv&&$previousPal)||hache_sharky_schedule_guard_both_venues_reference($previous)){
            return ['MONTEVERDE','PALAPAS'];
        }
    }
    return [$commercial['sede']];
}

function hache_sharky_schedule_guard_daypart(string $text): ?string
{
    $t=hache_sharky_schedule_guard_normalize($text);
    if(preg_match('/\b(?:manana|matutino|matutina)\b/u',$t)===1)return 'morning';
    if(preg_match('/\b(?:tarde|noche|vespertino|vespertina|nocturno|nocturna)\b/u',$t)===1)return 'evening';
    return null;
}

/** @return list<string> */
function hache_sharky_schedule_guard_filter_daypart(array $hours,?string $daypart): array
{
    if(!in_array($daypart,['morning','evening'],true))return array_values($hours);
    return array_values(array_filter($hours,static function(string $range) use($daypart): bool {
        if(preg_match('/^(\d{2}):(\d{2})–/u',$range,$m)!==1)return false;
        $hour=(int)$m[1];
        return $daypart==='morning'?$hour<12:$hour>=12;
    }));
}

function hache_sharky_schedule_guard_meridiem_hour(int $hour,string $meridiem): ?int
{
    $meridiem=strtolower(preg_replace('/[.\s]+/u','',$meridiem)??'');
    if($meridiem==='')return ($hour>=0&&$hour<=23)?$hour:null;
    if(!in_array($meridiem,['am','pm'],true)||$hour<1||$hour>12)return null;
    if($meridiem==='am')return $hour===12?0:$hour;
    return $hour===12?12:$hour+12;
}

/** @return list<string> */
function hache_sharky_schedule_guard_answer_ranges(string $answer): array
{
    $out=[];
    $t=hache_sharky_schedule_guard_normalize($answer);
    $hasScheduleWord=preg_match('/\b(?:horario|horarios|hora|horas)\b/u',$t)===1;
    $mer='(?:a\.?\s*m\.?|p\.?\s*m\.?)';
    $pattern='/(?<!\d)(?:de\s+|desde\s+)?(\d{1,2})(?::([0-5]\d))?\s*('.$mer.')?\s*(?:a|–|-)\s*(\d{1,2})(?::([0-5]\d))?\s*('.$mer.')?(?!\d)/ui';
    if(preg_match_all($pattern,$answer,$matches,PREG_SET_ORDER)!==false){
        foreach($matches as $m){
            $m1=(isset($m[2])&&$m[2]!=='')?(int)$m[2]:0;
            $m2=(isset($m[5])&&$m[5]!=='')?(int)$m[5]:0;
            $mer1=trim((string)($m[3]??''));$mer2=trim((string)($m[6]??''));
            if($mer1===''&&$mer2!=='')$mer1=$mer2;
            elseif($mer2===''&&$mer1!=='')$mer2=$mer1;
            $h1=hache_sharky_schedule_guard_meridiem_hour((int)$m[1],$mer1);
            $h2=hache_sharky_schedule_guard_meridiem_hour((int)$m[4],$mer2);
            if($h1===null||$h2===null||$m1>59||$m2>59)continue;
            $hasMinutes=(isset($m[2])&&$m[2]!=='')||(isset($m[5])&&$m[5]!=='');
            $hasMeridiem=$mer1!==''||$mer2!=='';
            if(!$hasMinutes&&!$hasMeridiem&&!$hasScheduleWord)continue;
            $out[]=sprintf('%02d:%02d–%02d:%02d',$h1,$m1,$h2,$m2);
        }
    }
    return array_values(array_unique($out));
}

function hache_sharky_schedule_guard_answer_is_schedule_like(string $answer): bool
{
    $t=hache_sharky_schedule_guard_normalize($answer);
    return hache_sharky_schedule_guard_answer_ranges($answer)!==[]
        || preg_match('/\b(?:horario|horarios|hora|horas)\b/u',$t)===1;
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

function hache_sharky_schedule_guard_program_label(string $program): string
{
    return $program==='regular'?'clases regulares':'curso intensivo';
}

function hache_sharky_schedule_guard_message_from_hours(string $program,string $sede,array $hours,?string $daypart=null): string
{
    $label=hache_sharky_deterministic_sede_label($sede);
    $programLabel=hache_sharky_schedule_guard_program_label($program);
    $dayLabel=$daypart==='morning'?' de la mañana':($daypart==='evening'?' de la tarde/noche':'');
    if(!$hours)return 'Ahora mismo no encuentro horarios'.$dayLabel.' activos de '.$programLabel.' en '.$label.'. Prefiero no inventarte uno; puedo dejarte con el equipo para revisarlo.';
    return '🕐 Horarios'.$dayLabel.' vigentes de '.$programLabel.' en '.$label.':'."\n\n"
        .implode("\n",array_map(static fn(string $h):string=>'• '.$h,$hours));
}

function hache_sharky_schedule_guard_unavailable_message(): string
{
    return 'Ahora mismo no pude verificar los horarios vigentes en el backend. Prefiero no darte un horario sin confirmar; intenta de nuevo en unos minutos.';
}

function hache_sharky_schedule_guard_invalid_selection_from_hours(string $text,array $state,array $allowedHours): ?string
{
    $range=hache_sharky_deterministic_time_range($text);if($range===null)return null;
    $commercial=hache_sharky_deterministic_commercial($state);if($commercial===null)return null;
    $needle=$range[0].'–'.$range[1];
    if(in_array($needle,$allowedHours,true))return null;

    $label=hache_sharky_deterministic_sede_label($commercial['sede']);
    $programLabel=hache_sharky_schedule_guard_program_label($commercial['program']);
    if(!$allowedHours)return 'Ahora mismo no encuentro horarios activos de '.$programLabel.' en '.$label.'. Prefiero no inventarte uno; puedo dejarte con el equipo para revisarlo.';
    return 'Ese horario no está activo para '.$programLabel.' en '.$label.'. Los horarios vigentes son:'."\n\n"
        .implode("\n",array_map(static fn(string $h):string=>'• '.$h,$allowedHours))
        ."\n\n".'Elige uno de estos horarios para continuar.';
}

function hache_sharky_schedule_guard_render_verified(array $programs,array $venues,array $verified,?string $daypart): string
{
    $parts=[];
    foreach($programs as $program){
        foreach($venues as $sede){
            $hours=hache_sharky_schedule_guard_filter_daypart($verified[$program][$sede]??[],$daypart);
            $parts[]=hache_sharky_schedule_guard_message_from_hours($program,$sede,$hours,$daypart);
        }
    }
    return implode("\n\n",$parts);
}

function hache_sharky_schedule_guard_scoped_reply(string $text,array $state,?callable $scheduleLoader=null): ?string
{
    $commercial=hache_sharky_deterministic_commercial($state);if($commercial===null)return null;
    if(!hache_sharky_deterministic_schedule_request($text))return null;
    $venues=hache_sharky_schedule_guard_requested_venues($text,$state);
    if($venues===[])$venues=[$commercial['sede']];
    $programs=hache_sharky_schedule_guard_requested_programs($text,$commercial['program']);
    if($programs===[])$programs=[$commercial['program']];
    $daypart=hache_sharky_schedule_guard_daypart($text);

    // Plain "horarios" for the active product/sede is already handled by the
    // generic deterministic reply. This guard owns only a narrower scope:
    // daypart, explicit cross-program query, or explicit multi-sede query.
    if($daypart===null&&$venues===[$commercial['sede']]&&$programs===[$commercial['program']])return null;

    $loader=$scheduleLoader;
    if($loader===null){
        $pdo=hache_sharky_pdo();
        if(!$pdo instanceof PDO)return hache_sharky_schedule_guard_unavailable_message();
        $loader=static fn(string $program,string $sede):array=>hache_sharky_deterministic_active_schedules($pdo,$program,$sede);
    }
    $verified=[];
    try{
        foreach($programs as $program){
            foreach($venues as $sede)$verified[$program][$sede]=$loader($program,$sede);
        }
    }catch(Throwable $e){
        return hache_sharky_schedule_guard_unavailable_message();
    }
    return hache_sharky_schedule_guard_render_verified($programs,$venues,$verified,$daypart);
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
    if(!$pdo instanceof PDO)return hache_sharky_schedule_guard_unavailable_message();
    try{$hours=hache_sharky_deterministic_active_schedules($pdo,$commercial['program'],$commercial['sede']);}
    catch(Throwable $e){return hache_sharky_schedule_guard_unavailable_message();}
    return hache_sharky_schedule_guard_invalid_selection_from_hours($text,$state,$hours);
}

function hache_sharky_schedule_guard_model_answer(string $answer,array $state,string $userText='',?callable $scheduleLoader=null): string
{
    $commercial=hache_sharky_deterministic_commercial($state);if($commercial===null)return $answer;
    $scheduleLike=hache_sharky_schedule_guard_answer_is_schedule_like($answer)
        || hache_sharky_deterministic_schedule_request($userText)
        || hache_sharky_deterministic_time_range($userText)!==null;
    if(!$scheduleLike)return $answer;

    $loader=$scheduleLoader;
    if($loader===null){
        $pdo=hache_sharky_pdo();
        if(!$pdo instanceof PDO)return hache_sharky_schedule_guard_unavailable_message();
        $loader=static fn(string $program,string $sede):array=>hache_sharky_deterministic_active_schedules($pdo,$program,$sede);
    }

    $programs=hache_sharky_schedule_guard_requested_programs($userText,$commercial['program']);
    if($programs===[])$programs=[$commercial['program']];
    $venues=hache_sharky_schedule_guard_requested_venues($userText,$state);
    if($venues===[])$venues=[$commercial['sede']];
    $daypart=hache_sharky_schedule_guard_daypart($userText);

    $verified=[];
    try{
        foreach($programs as $program){
            foreach($venues as $sede)$verified[$program][$sede]=$loader($program,$sede);
        }
    }catch(Throwable $e){
        return hache_sharky_schedule_guard_unavailable_message();
    }

    if($programs!==[$commercial['program']]||$venues!==[$commercial['sede']]||$daypart!==null){
        return hache_sharky_schedule_guard_render_verified($programs,$venues,$verified,$daypart);
    }

    $hours=$verified[$commercial['program']][$commercial['sede']]??[];
    if(!hache_sharky_schedule_guard_scope_violation_from_hours($answer,$commercial['program'],$hours))return $answer;
    return hache_sharky_schedule_guard_message_from_hours($commercial['program'],$commercial['sede'],$hours);
}
