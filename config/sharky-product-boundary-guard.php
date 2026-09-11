<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-deterministic-replies.php';

function hache_sharky_product_boundary_normalize(string $text): string
{
    return hache_sharky_deterministic_normalize($text);
}

function hache_sharky_product_boundary_explicit_regular_choice(string $text): bool
{
    if(str_contains($text,'?')||str_contains($text,'¿'))return false;
    $t=hache_sharky_product_boundary_normalize($text);
    if(preg_match('/^[.!¡!\s]*(?:clases?\s+)?regulares?[.!¡!\s]*$/u',$t)===1)return true;
    if(preg_match('/^[.!¡!\s]*mensualidad(?:es)?[.!¡!\s]*$/u',$t)===1)return true;
    return preg_match('/\b(?:quiero|prefiero|elijo|escojo|mejor|vamos\s+con|me\s+quedo\s+con|deseo|quiero\s+cambiar\s+a|cambiar\s+a)\b.{0,36}\b(?:clases?\s+regulares?|regulares?|mensualidad(?:es)?)\b/u',$t)===1;
}

function hache_sharky_product_boundary_explicit_intensive_choice(string $text): bool
{
    if(str_contains($text,'?')||str_contains($text,'¿'))return false;
    $t=hache_sharky_product_boundary_normalize($text);
    if(preg_match('/^[.!¡!\s]*(?:curso\s+)?intensiv[oa][.!¡!\s]*$/u',$t)===1)return true;
    return preg_match('/\b(?:quiero|prefiero|elijo|escojo|mejor|vamos\s+con|me\s+quedo\s+con|deseo)\b.{0,36}\b(?:curso\s+)?intensiv[oa]\b/u',$t)===1;
}

function hache_sharky_product_boundary_weekly_frequency(string $text): ?int
{
    $t=hache_sharky_product_boundary_normalize($text);
    $number='(?<n>[1-7]|una?|dos|tres|cuatro|cinco|seis|siete)';
    if(preg_match('/\b'.$number.'\s*(?:veces?|dias?|clases?|sesiones?)\s*(?:por|a\s+la|x)\s*(?:semana|semanal(?:es)?)\b/u',$t,$m)!==1)return null;
    $raw=(string)($m['n']??'');
    $map=['un'=>1,'una'=>1,'uno'=>1,'dos'=>2,'tres'=>3,'cuatro'=>4,'cinco'=>5,'seis'=>6,'siete'=>7];
    $value=ctype_digit($raw)?(int)$raw:($map[$raw]??0);
    return $value>=1&&$value<=7?$value:null;
}

/**
 * Detect only statements about the person's swimming-training history.
 * Local absences such as "no he tomado clases esta semana" or schedule
 * qualifiers such as "nunca he tomado clases en la mañana" are deliberately
 * not lifetime/no-formal signals.
 */
function hache_sharky_product_boundary_no_formal_signal(string $text): bool
{
    $t=hache_sharky_product_boundary_normalize($text);
    if($t==='')return false;

    if(preg_match('/\b(?:nunca|jamas|no)\s+(?:he\s+)?(?:tomado|recibido|tenido)\s+clases?(?:\s+formales?)?\s+de\s+natacion\b/u',$t)===1)return true;
    if(preg_match('/\b(?:sin|ninguna?)\s+clases?(?:\s+formales?)?\s+(?:de\s+)?natacion\b/u',$t)===1)return true;

    $bareNoClasses=preg_match('/\b(?:nunca|jamas|no)\s+(?:he\s+)?(?:tomado|recibido|tenido)\s+clases?(?:\s+formales?)?\b/u',$t)===1;
    if($bareNoClasses){
        $localQualifier=preg_match('/\bclases?\s+(?:esta\s+semana|este\s+(?:mes|ano)|hoy|ayer|ultimamente|en\s+la\s+(?:manana|tarde|noche)|por\s+la\s+(?:manana|tarde|noche))\b/u',$t)===1;
        if(!$localQualifier){
            if(preg_match('/\b(?:nunca|jamas|no)\s+(?:he\s+)?(?:tomado|recibido|tenido)\s+clases?(?:\s+formales?)?(?:\s+antes)?[.!¡!\s]*$/u',$t)===1)return true;
            if(preg_match('/\b(?:nado|nadar|nadado|natacion|floto|flotar|estilo|estilos)\b/u',$t)===1)return true;
        }
    }

    if(preg_match('/\b(?:aprendi|nado|he\s+nadado)\b.{0,30}\b(?:solo|sola|por\s+mi\s+cuenta|autodidacta)\b/u',$t)===1)return true;
    return false;
}

function hache_sharky_product_boundary_regular_restricted(array $state,string $userText=''): bool
{
    $c=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    if(($c['swim_level']??null)==='beginner')return true;
    if(in_array(($c['background']??null),['self_taught','no_formal'],true))return true;
    return $userText!==''&&hache_sharky_product_boundary_no_formal_signal($userText);
}

function hache_sharky_product_boundary_regular_pending_background(array $state): bool
{
    $c=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    $background=(string)($c['background']??'');
    if($background==='formal')return false;
    if(in_array($background,['self_taught','no_formal'],true))return false;
    return true;
}

function hache_sharky_product_boundary_intensive_context(array $state): bool
{
    $c=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    $program=(string)($c['program']??'');
    if($program==='regular')return false;
    if($program==='intensive')return true;
    if((string)($c['recommended_program']??'')==='intensive')return true;
    return (string)($c['entry_interest']??'')==='intensive';
}

function hache_sharky_product_boundary_user_requests_regular(string $text): bool
{
    return hache_sharky_product_boundary_explicit_regular_choice($text)
        || hache_sharky_product_boundary_weekly_frequency($text)!==null;
}

function hache_sharky_product_boundary_human_exception_message(): string
{
    return 'Por lo que me dices, las clases regulares no son una opción que yo pueda autorizar automáticamente. Si estás empezando desde cero o no has tomado clases formales de natación, en Hache te orientamos primero al curso intensivo de 3 semanas, lunes a viernes. Si quieres valorar una excepción para clases regulares, esa decisión debe revisarla una persona del equipo.';
}

function hache_sharky_product_boundary_background_question(): string
{
    return 'Antes de ofrecerte clases regulares necesito confirmar algo: ¿has tomado clases formales de natación con un profesor o entrenador? Si no has tomado clases formales, el camino automático es el curso intensivo.';
}

function hache_sharky_product_boundary_intensive_leak_recovery(array $state): string
{
    $c=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    $level=(string)($c['swim_level']??'');
    $background=(string)($c['background']??'');

    if($level===''){
        return 'Veo que llegaste por el curso intensivo. Para orientarte bien: ¿ya sabes nadar o estás empezando desde cero?';
    }
    if($level==='swims'&&$background===''){
        return 'Para orientarte bien sin mezclar productos: ¿has tomado clases formales de natación con un profesor o entrenador, o aprendiste por tu cuenta?';
    }
    if($level==='beginner'||in_array($background,['self_taught','no_formal'],true)){
        return 'Por lo que ya me contaste, seguimos con el curso intensivo. No voy a cambiarte a clases regulares por una referencia ambigua.';
    }
    return 'Seguimos con el curso intensivo como producto activo. Si quieres cambiar a clases regulares, dímelo de forma explícita y reviso si aplica.';
}

function hache_sharky_product_boundary_regular_offer(string $answer): bool
{
    $t=hache_sharky_product_boundary_normalize($answer);
    if($t==='')return false;
    if(preg_match('/\b(?:clases?\s+regulares?|mensualidad(?:es)?|plan(?:es)?\s+(?:mensual(?:es)?|regular(?:es)?)|regular\s+[35])\b/u',$t)===1)return true;
    if(preg_match('/\b[235]\s+(?:clases?|dias?|veces?|sesiones?)\s+(?:por|a\s+la)\s+semana\b/u',$t)===1)return true;
    return false;
}

function hache_sharky_product_boundary_sanitize_state(array $state,string $userText=''): array
{
    if(!is_array($state['commercial_context']??null))$state['commercial_context']=[];
    $c=&$state['commercial_context'];
    if(($c['swim_level']??null)==='swims'&&($c['background']??null)==='formal'){
        $c['recommended_program']='regular';
        $c['program']='regular';
        foreach(['course_id','fecha_inicio','course_price','date_preference'] as $key)unset($c[$key]);
        return $state;
    }
    if(!hache_sharky_product_boundary_regular_restricted($state,$userText))return $state;
    if($userText!==''&&hache_sharky_product_boundary_no_formal_signal($userText))$c['background']='no_formal';
    $c['recommended_program']='intensive';
    $c['program']='intensive';
    foreach(['plan_id','plan_name','sessions_per_week','plan_price'] as $key)unset($c[$key]);
    return $state;
}

function hache_sharky_product_boundary_reply(string $text,array $state): ?string
{
    $restricted=hache_sharky_product_boundary_regular_restricted($state,$text);
    $pendingBackground=hache_sharky_product_boundary_regular_pending_background($state);
    $explicitRegular=hache_sharky_product_boundary_explicit_regular_choice($text);
    $frequency=hache_sharky_product_boundary_weekly_frequency($text);

    if($restricted&&($explicitRegular||$frequency!==null))return hache_sharky_product_boundary_human_exception_message();
    if($pendingBackground&&($explicitRegular||$frequency!==null))return hache_sharky_product_boundary_background_question();

    if(!hache_sharky_product_boundary_intensive_context($state))return null;
    if($explicitRegular)return null;
    if($frequency===null)return null;

    if($frequency===5){
        return 'El curso intensivo y las clases regulares son productos diferentes. El intensivo dura 3 semanas y se toma de lunes a viernes. Si con “5 veces por semana” te refieres a un plan mensual, eso ya sería clases regulares. ¿Quieres seguir con el intensivo o cambiar a clases regulares?';
    }

    return 'El curso intensivo y las clases regulares son productos diferentes. El intensivo dura 3 semanas y se toma de lunes a viernes; no se convierte en un plan de '.$frequency.' veces por semana. Si necesitas una frecuencia semanal, eso corresponde a clases regulares. ¿Quieres cambiar a clases regulares o prefieres seguir con el intensivo?';
}

function hache_sharky_product_boundary_model_answer(string $answer,array $state,string $userText=''): string
{
    if(!hache_sharky_product_boundary_regular_offer($answer))return $answer;

    $userRequestsRegular=hache_sharky_product_boundary_user_requests_regular($userText);
    $restricted=hache_sharky_product_boundary_regular_restricted($state,$userText);
    $pending=hache_sharky_product_boundary_regular_pending_background($state);

    // A model-written regular mention must never manufacture a product switch.
    // Human exception is only for a real user request for regular classes.
    if(hache_sharky_product_boundary_intensive_context($state)&&!$userRequestsRegular){
        return hache_sharky_product_boundary_intensive_leak_recovery($state);
    }
    if($restricted){
        return $userRequestsRegular
            ?hache_sharky_product_boundary_human_exception_message()
            :hache_sharky_product_boundary_intensive_leak_recovery($state);
    }
    if($pending)return hache_sharky_product_boundary_background_question();
    return $answer;
}
