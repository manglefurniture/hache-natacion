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
    return preg_match('/\b(?:quiero|prefiero|elijo|escojo|mejor|vamos\s+con|me\s+quedo\s+con|deseo|quiero\s+cambiar\s+a)\b.{0,36}\b(?:clases?\s+regulares?|regulares?|mensualidad(?:es)?)\b/u',$t)===1;
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

function hache_sharky_product_boundary_ambiguous_plural(string $text): bool
{
    $t=trim(hache_sharky_product_boundary_normalize($text));
    return preg_match('/^(?:de\s+)?(?:ambos|ambas|los\s+dos|las\s+dos|de\s+los\s+dos|de\s+las\s+dos)[.! ]*$/u',$t)===1;
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

/**
 * Prevents a weekly-frequency constraint or a bare plural reference from
 * silently turning the intensive course into regular classes.
 */
function hache_sharky_product_boundary_reply(string $text,array $state): ?string
{
    if(!hache_sharky_product_boundary_intensive_context($state))return null;
    if(hache_sharky_product_boundary_explicit_regular_choice($text))return null;

    if(hache_sharky_product_boundary_ambiguous_plural($text)){
        return 'Entiendo. “Las dos” por sí solo no cambia el tipo de servicio. Mantengo el curso intensivo como la opción que veníamos trabajando. Si te refieres a las dos sedes u otras dos opciones, seguimos con esas; si quieres cambiar a clases regulares, dímelo explícitamente.';
    }

    $frequency=hache_sharky_product_boundary_weekly_frequency($text);
    if($frequency===null)return null;

    if($frequency===5){
        return 'El curso intensivo y las clases regulares son productos diferentes. El intensivo dura 3 semanas y se toma de lunes a viernes. Si con “5 veces por semana” te refieres a un plan mensual, eso ya sería clases regulares. ¿Quieres seguir con el intensivo o cambiar a clases regulares?';
    }

    return 'El curso intensivo y las clases regulares son productos diferentes. El intensivo dura 3 semanas y se toma de lunes a viernes; no se convierte en un plan de '.$frequency.' veces por semana. Si necesitas una frecuencia semanal, eso corresponde a clases regulares. ¿Quieres cambiar a clases regulares o prefieres seguir con el intensivo?';
}
