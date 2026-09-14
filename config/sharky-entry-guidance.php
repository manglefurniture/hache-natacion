<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-orchestrator.php';
require_once __DIR__.'/sharky-start-authority.php';

/**
 * Contexto de entrada. Fuente, interés de entrada y selección confirmada son
 * conceptos distintos: una campaña o prefill orientan atribución, pero nunca
 * sustituyen una elección explícita del usuario.
 *
 * @return array{source:string,interest:?string}
 */
function hache_sharky_entry_context(array $state,string $userText=''): array
{
    $normalize=static fn(string $value):string=>hache_sharky_orchestrator_normalize($value);
    $programFrom=static function(string $value) use($normalize): ?string {
        $t=$normalize($value);
        if(preg_match('/\b(?:curso\s+)?intensivo\b|\b(?:aprende|aprender)\s+a\s+nadar\b/u',$t)===1)return 'intensive';
        if(preg_match('/\bclases?\s+regulares?\b|\bcurso\s+regular\b/u',$t)===1)return 'regular';
        return null;
    };

    // Lo que el usuario escribe en el turno actual siempre tiene prioridad sobre
    // la inferencia de campaña. La fuente se conserva para atribución, no para
    // escoger producto dentro del funnel determinístico.
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
            return ['source'=>'meta_ad','interest'=>$interest];
        }
        if($sourceType!==''||trim((string)($ref['source_url']??''))!==''){
            return ['source'=>'referral','interest'=>$interest];
        }
    }

    $t=$normalize($userText);
    $interest=$explicitInterest;
    // Los enlaces de WhatsApp de la web usan prefills que empiezan con
    // “Hola Hache Natación…” y pueden decir información, inscribirme,
    // orientación o una sede. Esa firma conserva la atribución web aunque no
    // venga acompañada por referral de Meta.
    $looksLikeWebPrefill=preg_match('/\bhola\s+hache\s+natacion\b/u',$t)===1
        &&preg_match('/\b(?:quiero|busco|necesito|quisiera|me\s+interesa)\b/u',$t)===1;
    if($looksLikeWebPrefill)return ['source'=>'web','interest'=>$interest];
    return ['source'=>'direct','interest'=>$interest];
}

function hache_sharky_entry_apply(array $state,string $userText=''): array
{
    $entry=hache_sharky_entry_context($state,$userText);
    if(!is_array($state['commercial_context']??null))$state['commercial_context']=[];
    $state['commercial_context']['entry_source']=$entry['source'];
    $state['commercial_context']['entry_interest']=$entry['interest'];

    // Fuente e interés sirven para atribución/contexto. Solo una elección
    // explícita del usuario puede convertirse en producto confirmado.
    $explicitProgram=hache_sharky_orchestrator_program_choice($userText);
    $flow=is_array($state['flow']??null)?$state['flow']:null;
    if(is_array($flow)&&($flow['name']??'')==='qualify_prospect'&&in_array($explicitProgram,['intensive','regular'],true)){
        if(!is_array($state['flow']['data']??null))$state['flow']['data']=[];
        if(empty($state['flow']['data']['preferred_program']))$state['flow']['data']['preferred_program']=$explicitProgram;
    }
    return $state;
}

function hache_sharky_entry_uses_deterministic_prospect_flow(string $source): bool
{
    return in_array($source,['meta_ad','web','direct'],true);
}

/**
 * Bootstrap exclusivo para el primer turno REAL de un número que WhatsApp ya
 * clasificó como prospecto no identificado.
 *
 * Meta Ads, web y WhatsApp directo comparten el funnel Sharky 3.0 cerrado.
 * Referral no publicitario conserva temporalmente el onboarding por perfil
 * mientras no exista una decisión explícita que lo sustituya.
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
    $now=$now>0?$now:time();
    if(hache_sharky_entry_uses_deterministic_prospect_flow((string)($state['commercial_context']['entry_source']??''))){
        return hache_sharky_orchestrator_flow(
            $state,
            'meta_ad_onboarding',
            'program',
            ['entry_bootstrap'=>true],
            $now
        );
    }
    return hache_sharky_orchestrator_flow(
        $state,
        'prospect_onboarding',
        'name',
        ['entry_bootstrap'=>true],
        $now
    );
}

function hache_sharky_entry_intro(array $state,string $userText=''): string
{
    $entry=hache_sharky_entry_context($state,$userText);
    $legacyBase='Soy Sharky 🦈, el asistente IA de Hache Natación.';
    $flow=is_array($state['flow']??null)?$state['flow']:null;
    // Referral no publicitario que todavía use onboarding por perfil conserva
    // su presentación neutral histórica.
    if(is_array($flow)&&($flow['name']??'')==='prospect_onboarding'){
        return 'Hola, soy Sharky, asistente IA de Hache Natación.';
    }
    // El boundary de WhatsApp elimina cualquier presentación escrita en el
    // payload antes de aplicar una sola presentación determinística. El flujo
    // cerrado común debe reponerla para Meta, web y directo.
    if(is_array($flow)&&($flow['name']??'')==='meta_ad_onboarding'){
        return 'Hola, soy Sharky 🦈, asistente IA de Hache Natación.';
    }
    // Un alumno ya identificado no necesita el bloque comercial de captación.
    if(($state['identity']['kind']??'unknown')==='student')return $legacyBase;
    if($entry['source']==='meta_ad'&&$entry['interest']==='intensive'){
        return $legacyBase."\n\n".'Veo que llegaste desde nuestro anuncio del curso intensivo. Te doy una previa y te voy guiando desde aquí.';
    }
    if($entry['source']==='meta_ad'&&$entry['interest']==='regular'){
        return $legacyBase."\n\n".'Veo que llegaste desde uno de nuestros anuncios y ahora buscas información sobre las clases regulares. Te voy guiando desde aquí.';
    }
    if($entry['source']==='web'&&$entry['interest']==='intensive'){
        return $legacyBase."\n\n".'Veo que vienes desde nuestra página buscando información sobre el curso intensivo. Te voy guiando desde aquí.';
    }
    if($entry['source']==='web'&&$entry['interest']==='regular'){
        return $legacyBase."\n\n".'Veo que vienes desde nuestra página buscando información sobre las clases regulares. Te voy guiando desde aquí.';
    }
    if($entry['interest']==='intensive'){
        return $legacyBase."\n\n".'Veo que vienes buscando información sobre el curso intensivo. Te voy guiando paso a paso.';
    }
    if($entry['interest']==='regular'){
        return $legacyBase."\n\n".'Veo que vienes buscando información sobre las clases regulares. Te voy guiando paso a paso.';
    }
    return $legacyBase."\n\n".'En Hache Natación tenemos opciones para quien empieza desde cero y para quien ya nada y quiere mejorar. Te voy guiando paso a paso.';
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