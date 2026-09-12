<?php

declare(strict_types=1);

function hache_sharky_learning_guard_normalize(string $text): string
{
    $text=mb_strtolower(trim($text),'UTF-8');
    return strtr($text,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
}

function hache_sharky_learning_guard_canonicalize_daypart_followup(string $text): string
{
    $t=hache_sharky_learning_guard_normalize($text);
    if(preg_match('/^[¿?¡!\s]*(?:(?:en|por)\s+la\s+)?(?:manana|matutino|matutina|tarde|noche|vespertino|vespertina|nocturno|nocturna)[¿?¡!.\s]*$/u',$t)!==1)return $text;
    return trim(preg_replace('/[¿?¡!]+/u','',$text)??$text);
}

function hache_sharky_learning_guard_polite_close_reply(string $message,array $state): ?string
{
    if(str_contains($message,'?')||str_contains($message,'¿'))return null;
    $t=preg_replace('/\s+/u',' ',trim(hache_sharky_learning_guard_normalize($message)))??'';
    if($t==='')return null;

    $pureThanks=preg_match('/^(?:muchas\s+)?gracias(?:\s+(?:por\s+todo|por\s+la\s+informacion|por\s+la\s+info))?[.!\s☺️🙂🙏]*$/u',$t)===1;
    $distanceClose=preg_match('/\b(?:me\s+queda|me\s+quedan|esta|estan)\s+(?:muy\s+)?(?:retirad[oa]s?|lejos)\b/u',$t)===1
        && preg_match('/\bgracias\b/u',$t)===1;
    $explicitClose=preg_match('/\b(?:por\s+ahora\s+lo\s+dejamos|lo\s+dejamos\s+por\s+ahora|hasta\s+aqui|no\s+me\s+interesa\s+por\s+ahora)\b/u',$t)===1;
    if(!$pureThanks&&!$distanceClose&&!$explicitClose)return null;

    return 'Con gusto 😊. Lo dejamos aquí por ahora. Cuando quieras retomarlo, seguimos desde donde quedamos.';
}

function hache_sharky_learning_guard_natural_venue_preference(string $text): ?string
{
    if(str_contains($text,'?')||str_contains($text,'¿'))return null;
    $t=hache_sharky_learning_guard_normalize($text);
    $mv=preg_match('/\bmonteverde\b/u',$t)===1;
    $pal=preg_match('/\bpalapas(?:\s+protudec)?\b/u',$t)===1;
    if($mv===$pal)return null;
    $preference=preg_match('/\b(?:prefiero|elijo|escojo|me\s+conviene|me\s+queda(?:ria)?\s+(?:mejor|mas\s+cerca)|me\s+quedaria\s+mas\s+cerca)\b/u',$t)===1;
    if(!$preference)return null;
    return $pal?'PALAPAS':'MONTEVERDE';
}

function hache_sharky_learning_guard_recover_recent_venue(array $data,array $state): array
{
    $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    if(in_array(($commercial['sede_clave']??null),['MONTEVERDE','PALAPAS'],true))return $state;
    $history=is_array($data['history']??null)?$data['history']:[];
    foreach(array_reverse(array_slice($history,-12)) as $turn){
        if(!is_array($turn)||($turn['role']??'')!=='user')continue;
        $venue=hache_sharky_learning_guard_natural_venue_preference(trim((string)($turn['content']??'')));
        if($venue===null)continue;
        if(!isset($state['commercial_context'])||!is_array($state['commercial_context']))$state['commercial_context']=[];
        $state['commercial_context']['sede_clave']=$venue;
        return $state;
    }
    return $state;
}

function hache_sharky_learning_guard_pending_location_reply(string $message,array $state): ?string
{
    if(!function_exists('hache_sharky_deterministic_location_request')
        ||!function_exists('hache_sharky_deterministic_detect_explicit_sede')
        ||!function_exists('hache_sharky_deterministic_location_message'))return null;

    $t=hache_sharky_learning_guard_normalize($message);
    $currentVenue=hache_sharky_deterministic_detect_explicit_sede($message);
    $venueOnly=$currentVenue!==null
        && preg_match('/^[¿?¡!\s]*(?:colegio\s+)?(?:monteverde|palapas(?:\s+protudec)?)[?!.¿¡\s]*$/u',$t)===1;
    $previousUser=trim((string)($state['previous_user_text']??''));
    $previousAssistant=trim((string)($state['previous_assistant_text']??''));

    if($venueOnly&&(
        ($previousUser!==''&&hache_sharky_deterministic_location_request($previousUser))
        ||($previousAssistant!==''&&hache_sharky_deterministic_location_request($previousAssistant))
    ))return hache_sharky_deterministic_location_message($message,$state);

    if(hache_sharky_deterministic_location_request($message)&&$currentVenue===null){
        $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
        if(!in_array(($commercial['sede_clave']??null),['MONTEVERDE','PALAPAS'],true)){
            $proposed=$previousAssistant!==''?hache_sharky_deterministic_detect_explicit_sede($previousAssistant):null;
            if($proposed!==null)return hache_sharky_deterministic_location_message($proposed,$state);

            $singularReference=preg_match('/\b(?:donde\s+esta|donde\s+queda|en\s+donde\s+esta)\b/u',$t)===1
                && preg_match('/\b(?:sedes|ambas|las\s+dos|monteverde.*palapas|palapas.*monteverde)\b/u',$t)!==1;
            if($singularReference&&in_array(($commercial['program']??null),['intensive','regular'],true)){
                return hache_sharky_deterministic_location_message('Monteverde',$state);
            }
        }
    }
    return null;
}

function hache_sharky_learning_guard_prevenue_reply(string $message,array $state,float $price): ?string
{
    $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    if(($commercial['program']??null)!=='intensive')return null;
    if(in_array(($commercial['sede_clave']??null),['MONTEVERDE','PALAPAS'],true))return null;

    $t=hache_sharky_learning_guard_normalize($message);
    $asksPrice=preg_match('/\b(?:precio|precios|costo|costos|cuanto\s+cuesta|cuanto\s+sale)\b/u',$t)===1;
    $asksSchedule=preg_match('/\b(?:horario|horarios|hora|horas)\b/u',$t)===1;
    if(!$asksPrice&&!$asksSchedule)return null;

    $explicitIndependentSubject=preg_match(
        '/\b(?:kit|gorro|gorros|goggle|goggles|lentes|inscripcion|inscribirme|inscribirse|registro|registrarme|recargo|comision|mensualidad|plan\s+regular|clases\s+regulares)\b/u',
        $t
    )===1;
    $paymentContext=preg_match('/\b(?:tarjeta|pago|pagos|pagar|transferencia|efectivo)\b/u',$t)===1;
    $cardContext=preg_match('/\b(?:tarjeta|credito|debito)\b/u',$t)===1;
    $explicitCoursePrice=$asksPrice&&preg_match('/\b(?:curso(?:\s+intensivo)?|intensivo)\b/u',$t)===1;
    $operatingHours=preg_match('/\b(?:abren|abre|apertura|cierran|cierra|cierre|atienden|atencion)\b/u',$t)===1;

    if($explicitIndependentSubject||$operatingHours||$cardContext)return null;
    if($paymentContext&&!$explicitCoursePrice)return null;

    $parts=[];
    if($asksPrice){
        $priceText=number_format(max(0,$price),0,'.',',');
        $parts[]='💰 El curso intensivo dura 3 semanas, de lunes a viernes, y tiene un precio total de $'.$priceText.' MXN.';
    }
    if($asksSchedule)$parts[]='🕒 Los horarios dependen de la sede, así que no voy a mezclar los de ambas.';
    $parts[]='📍 Te propongo primero Colegio Monteverde. Si te funciona, responde “Monteverde”; si prefieres la otra sede, responde “Palapas”.';
    return implode("\n\n",$parts);
}

function hache_sharky_learning_guard_enforce_confirmed_swim(string $answer,array $state): string
{
    $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    if(!in_array(($commercial['swim_level']??null),['beginner','swims'],true))return $answer;
    $parts=preg_split('/\n+|(?<=[.!?;])\s+|(?=¿)/u',trim($answer))?:[];
    $kept=[];$removed=false;
    foreach($parts as $part){
        $part=trim((string)$part);if($part==='')continue;
        $t=hache_sharky_learning_guard_normalize($part);
        $question=(str_contains($part,'?')||str_contains($part,'¿'))
            &&preg_match('/\b(?:sabes\s+nadar|se\s+nadar|empiezas\s+desde\s+cero|estas\s+empezando\s+desde\s+cero|desde\s+cero\s+o|nadas\s+aunque\s+sea)\b/u',$t)===1;
        if($question){$removed=true;continue;}
        $kept[]=$part;
    }
    if(!$removed)return $answer;
    $safe=trim(implode("\n\n",$kept));
    return $safe!==''?$safe:'Ya tengo confirmado tu nivel.';
}

function hache_sharky_learning_guard_enforce_venue_priority(string $answer,array $state): string
{
    $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    if(!in_array(($commercial['program']??null),['intensive','regular'],true))return $answer;
    if(in_array(($commercial['sede_clave']??null),['MONTEVERDE','PALAPAS'],true))return $answer;
    $parts=preg_split('/\n+|(?<=[.!?;])\s+|(?=¿)/u',trim($answer))?:[];
    $kept=[];$replaced=false;
    foreach($parts as $part){
        $part=trim((string)$part);if($part==='')continue;
        $t=hache_sharky_learning_guard_normalize($part);
        $question=str_contains($part,'?')||str_contains($part,'¿');
        $asksVenue=$question&&(
            preg_match('/\b(?:en\s+que|cual|que)\s+sede\b|\bdonde\s+(?:quieres|prefieres)\b/u',$t)===1
            || (str_contains($t,'monteverde')&&str_contains($t,'palapas'))
        );
        if($asksVenue){
            if(!$replaced)$kept[]='📍 Te propongo primero Colegio Monteverde. ¿Te funciona esta sede?';
            $replaced=true;continue;
        }
        $kept[]=$part;
    }
    return $replaced?trim(implode("\n\n",$kept)):$answer;
}
