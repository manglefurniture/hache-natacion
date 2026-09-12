<?php

declare(strict_types=1);

function hache_sharky_learning_guard_normalize(string $text): string
{
    $text=mb_strtolower(trim($text),'UTF-8');
    return strtr($text,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
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

    // Un concepto con autoridad propia debe conservar su flujo normal. Los medios
    // de pago se tratan aparte porque también pueden aparecer como modificadores
    // de una pregunta explícita sobre el precio del curso activo.
    $explicitIndependentSubject=preg_match(
        '/\b(?:kit|gorro|gorros|goggle|goggles|lentes|inscripcion|inscribirme|inscribirse|registro|registrarme|recargo|comision|mensualidad|plan\s+regular|clases\s+regulares)\b/u',
        $t
    )===1;
    $paymentContext=preg_match(
        '/\b(?:tarjeta|pago|pagos|pagar|transferencia|efectivo)\b/u',
        $t
    )===1;
    $explicitCoursePrice=$asksPrice&&preg_match(
        '/\b(?:curso(?:\s+intensivo)?|intensivo)\b/u',
        $t
    )===1;
    $operatingHours=preg_match(
        '/\b(?:abren|abre|apertura|cierran|cierra|cierre|atienden|atencion)\b/u',
        $t
    )===1;

    if($explicitIndependentSubject||$operatingHours)return null;
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
            $replaced=true;
            continue;
        }
        $kept[]=$part;
    }
    return $replaced?trim(implode("\n\n",$kept)):$answer;
}
