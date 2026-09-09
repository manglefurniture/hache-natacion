<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-orchestrator.php';
require_once __DIR__.'/sharky-start-authority.php';

/**
 * Contexto de entrada. Fuente, interés de entrada y selección confirmada son
 * conceptos distintos: una campaña orienta el saludo, pero nunca sustituye una
 * elección explícita del usuario.
 *
 * @return array{source:string,interest:?string}
 */
function hache_sharky_entry_context(array $state,string $userText=''): array
{
    $normalize=static fn(string $value):string=>hache_sharky_orchestrator_normalize($value);
    $programFrom=static function(string $value) use($normalize): ?string {
        $t=$normalize($value);
        if(preg_match('/\b(?:curso\s+)?intensivo\b/u',$t)===1)return 'intensive';
        if(preg_match('/\bclases?\s+regulares?\b|\bcurso\s+regular\b/u',$t)===1)return 'regular';
        return null;
    };

    // Lo que el usuario escribe en el turno actual siempre tiene prioridad sobre
    // la inferencia de campaña. El referral conserva la fuente, no el control del funnel.
    $explicitInterest=$programFrom($userText);

    $ref=is_array($state['referral']['latest']??null)?$state['referral']['latest']
        :(is_array($state['referral']['first']??null)?$state['referral']['first']:null);
    if(is_array($ref)){
        $sourceType=$normalize((string)($ref['source_type']??''));
        $combined=implode(' ',array_filter([
            (string)($ref['headline']??''),(string)($ref['body']??''),(string)($ref['source_url']??''),
        ],static fn(string $v):bool=>trim($v)!==''));
        $interest=$explicitInterest??$programFrom($combined);
        $isMetaAd=$sourceType==='ad'||trim((string)($ref['ctwa_clid']??''))!=='';
        if($isMetaAd){
            // Regla operativa vigente 2026-09: el único anuncio Meta activo es de
            // intensivos. Solo funciona como fallback cuando el usuario no expresó
            // un interés distinto en su mensaje actual.
            $interest??='intensive';
            return ['source'=>'meta_ad','interest'=>$interest];
        }
        if($sourceType!==''||trim((string)($ref['source_url']??''))!==''){
            return ['source'=>'referral','interest'=>$interest];
        }
    }

    $t=$normalize($userText);
    $interest=$explicitInterest;
    $looksLikeWebPrefill=preg_match('/\bhola\s+hache\s+natacion\b/u',$t)===1
        &&preg_match('/\b(?:quiero|busco|necesito)\s+informacion\b/u',$t)===1;
    if($looksLikeWebPrefill&&$interest!==null)return ['source'=>'web','interest'=>$interest];
    return ['source'=>'direct','interest'=>$interest];
}

function hache_sharky_entry_apply(array $state,string $userText=''): array
{
    $entry=hache_sharky_entry_context($state,$userText);
    if(!is_array($state['commercial_context']??null))$state['commercial_context']=[];
    $state['commercial_context']['entry_source']=$entry['source'];
    $state['commercial_context']['entry_interest']=$entry['interest'];

    // Fuente/campaña e interés sirven para contextualizar y recomendar. Solo una
    // elección explícita del usuario puede alimentar preferred_program, porque el
    // adaptador interpreta ese campo como autorización para continuar con ese programa.
    $explicitProgram=hache_sharky_orchestrator_program_choice($userText);
    $flow=is_array($state['flow']??null)?$state['flow']:null;
    if(is_array($flow)&&($flow['name']??'')==='qualify_prospect'&&in_array($explicitProgram,['intensive','regular'],true)){
        if(!is_array($state['flow']['data']??null))$state['flow']['data']=[];
        if(empty($state['flow']['data']['preferred_program']))$state['flow']['data']['preferred_program']=$explicitProgram;
    }
    return $state;
}

/**
 * Bootstrap exclusivo para el primer turno REAL de un número que WhatsApp ya
 * clasificó como prospecto no identificado. No confirma una campaña ni un simple
 * interés informativo como programa elegido; solo conserva preferred_program si
 * el usuario hizo una elección explícita en su propio texto.
 */
function hache_sharky_entry_guided_first_prospect(array $state,string $userText='',int $now=0): array
{
    if(($state['identity']['kind']??'unknown')!=='prospect')return $state;
    if(($state['identity']['source']??'')!=='whatsapp_unmatched')return $state;
    if(is_array($state['flow']??null))return $state;
    if(trim((string)($state['last_user_text']??''))!=='')return $state;

    $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    foreach(['program','sede_clave','swim_level'] as $key)if(!empty($commercial[$key]))return $state;

    $state=hache_sharky_entry_apply($state,$userText);
    $explicitProgram=hache_sharky_orchestrator_program_choice($userText);
    $data=['entry_bootstrap'=>true];
    if(in_array($explicitProgram,['intensive','regular'],true))$data['preferred_program']=$explicitProgram;
    return hache_sharky_orchestrator_flow($state,'qualify_prospect','swim',$data,$now>0?$now:time());
}

function hache_sharky_entry_intro(array $state,string $userText=''): string
{
    $entry=hache_sharky_entry_context($state,$userText);
    $base='Soy Sharky 🦈, el asistente IA de Hache Natación.';
    // Un alumno ya identificado no necesita el bloque comercial de captación.
    if(($state['identity']['kind']??'unknown')==='student')return $base;
    if($entry['source']==='meta_ad'&&$entry['interest']==='intensive'){
        return $base."\n\n".'Veo que llegaste desde nuestro anuncio del curso intensivo. Te doy una previa y te voy guiando desde aquí.';
    }
    if($entry['source']==='meta_ad'&&$entry['interest']==='regular'){
        return $base."\n\n".'Veo que llegaste desde uno de nuestros anuncios y ahora buscas información sobre las clases regulares. Te voy guiando desde aquí.';
    }
    if($entry['source']==='web'&&$entry['interest']==='intensive'){
        return $base."\n\n".'Veo que vienes desde nuestra página buscando información sobre el curso intensivo. Te voy guiando desde aquí.';
    }
    if($entry['source']==='web'&&$entry['interest']==='regular'){
        return $base."\n\n".'Veo que vienes desde nuestra página buscando información sobre las clases regulares. Te voy guiando desde aquí.';
    }
    if($entry['interest']==='intensive'){
        return $base."\n\n".'Veo que vienes buscando información sobre el curso intensivo. Te voy guiando paso a paso.';
    }
    if($entry['interest']==='regular'){
        return $base."\n\n".'Veo que vienes buscando información sobre las clases regulares. Te voy guiando paso a paso.';
    }
    return $base."\n\n".'En Hache Natación tenemos opciones para quien empieza desde cero y para quien ya nada y quiere mejorar. Te voy guiando paso a paso.';
}

function hache_sharky_relative_monday_question(string $text): bool
{
    $t=hache_sharky_orchestrator_normalize($text);
    if(preg_match('/\b(?:proximo\s+lunes|lunes\s+que\s+viene)\b/u',$t)!==1)return false;
    return str_contains($text,'?')||str_contains($text,'¿')
        ||preg_match('/\b(?:no\s+se|dime|decirme|saber|cual|que)\b.{0,36}\b(?:fecha|dia|cuando)\b/u',$t)===1
        ||preg_match('/\b(?:que\s+dia|que\s+fecha|cuando)\s+(?:es|cae)\b/u',$t)===1;
}

function hache_sharky_human_date_es(DateTimeImmutable $date): string
{
    $months=[1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'];
    $days=[1=>'lunes',2=>'martes',3=>'miércoles',4=>'jueves',5=>'viernes',6=>'sábado',7=>'domingo'];
    return $days[(int)$date->format('N')].' '.(int)$date->format('j').' de '.$months[(int)$date->format('n')].' de '.$date->format('Y');
}

function hache_sharky_relative_date_answer(string $text,array $state,array $context): ?string
{
    if(!hache_sharky_relative_monday_question($text))return null;
    $today=(string)($context['today']??'');
    $tz=new DateTimeZone('America/Cancun');
    $reference=DateTimeImmutable::createFromFormat('!Y-m-d',$today,$tz);
    if(!$reference)return null;
    $date=hache_sharky_start_authority_parse_date('el proximo lunes',$reference);
    if(!$date)return null;
    $human=hache_sharky_human_date_es($date);
    $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    $sede=(string)($commercial['sede_clave']??'');
    $available=false;
    foreach($context['intensive_options']??[] as $option){
        if(!is_array($option))continue;
        if((string)($option['fecha_inicio']??'')!==$date->format('Y-m-d'))continue;
        if(in_array($sede,['MONTEVERDE','PALAPAS'],true)&&strtoupper((string)($option['sede_clave']??''))!==$sede)continue;
        $available=true;break;
    }
    if($available)return 'El próximo lunes es '.$human.'. Esa fecha está disponible para el curso intensivo'.($sede==='MONTEVERDE'?' en Colegio Monteverde':($sede==='PALAPAS'?' en Palapas Protudec':'')).'.';
    return 'El próximo lunes es '.$human.'. Si quieres, te muestro los inicios disponibles que tiene el sistema.';
}
