<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-regular-enrollment.php';

/**
 * Sharky 3.0 — funnel determinístico exclusivo para prospectos provenientes
 * de anuncios Meta (Facebook/Instagram -> WhatsApp).
 *
 * Este flujo no usa Brain ni interpretación de texto libre para avanzar pasos.
 * Los únicos atajos permitidos son solicitud explícita de humano/alumno, que
 * terminan en takeover. Web y WhatsApp directo conservan el flujo vigente.
 */

const HACHE_SHARKY_META_FLOW='meta_ad_onboarding';
const HACHE_SHARKY_META_IMAGE_LEARN='https://hnatacion.com/assets/sharky/meta-aprende-a-nadar.jpg';
const HACHE_SHARKY_META_IMAGE_REGULAR='https://hnatacion.com/assets/sharky/meta-clases-regulares.jpg';
const HACHE_SHARKY_META_IMAGE_MONTEVERDE='https://hnatacion.com/assets/sharky/sede-monteverde.jpg';
const HACHE_SHARKY_META_IMAGE_PALAPAS='https://hnatacion.com/assets/sharky/sede-palapas.jpg';

function hache_sharky_meta_active(array $state): bool
{
    $flow=$state['flow']??null;
    return is_array($flow)&&($flow['name']??'')===HACHE_SHARKY_META_FLOW;
}

function hache_sharky_meta_button(string $id,string $title): array
{
    return hache_sharky_orchestrator_button($id,$title);
}

function hache_sharky_meta_program_ui(bool $images=false): array
{
    $ui=['type'=>'buttons','buttons'=>[
        hache_sharky_meta_button('meta:program:learn','Aprende a nadar'),
        hache_sharky_meta_button('meta:program:regular','Clases regulares'),
    ]];
    if($images)$ui['images']=[HACHE_SHARKY_META_IMAGE_LEARN,HACHE_SHARKY_META_IMAGE_REGULAR];
    return $ui;
}

function hache_sharky_meta_venue_ui(bool $images=false): array
{
    $ui=['type'=>'buttons','buttons'=>[
        hache_sharky_meta_button('meta:venue:monteverde','Colegio Monteverde'),
        hache_sharky_meta_button('meta:venue:palapas','Palapas Protudec'),
    ]];
    if($images)$ui['images']=[HACHE_SHARKY_META_IMAGE_MONTEVERDE,HACHE_SHARKY_META_IMAGE_PALAPAS];
    return $ui;
}

function hache_sharky_meta_welcome(bool $images=true): array
{
    $message="¡Hola! Soy Sharky 🦈, el asistente IA de Hache Natación.\n"
        ."Te ayudo a encontrar la opción que buscas.\n\n"
        ."Importante: nuestros cursos y clases son para personas de 12 a 65 años.\n\n"
        ."¿Qué te interesa? 👇";
    return hache_sharky_orchestrator_decision('meta_program_prompt',$message,hache_sharky_meta_program_ui($images));
}

function hache_sharky_meta_program_retry(): array
{
    return hache_sharky_orchestrator_decision('meta_program_prompt','Para orientarte mejor, elige una de estas dos opciones 👇',hache_sharky_meta_program_ui(false));
}

function hache_sharky_meta_regular_background_prompt(): array
{
    return hache_sharky_orchestrator_decision('meta_regular_background_prompt','¿Ya has tomado clases de natación anteriormente, en alguna escuela?',['type'=>'buttons','buttons'=>[
        hache_sharky_meta_button('meta:regular:yes','Sí, continuar'),
        hache_sharky_meta_button('meta:regular:no','No, curso básico'),
    ]]);
}

function hache_sharky_meta_intensive_info(PDO $pdo,bool $images=true): array
{
    $business=function_exists('hache_sharky_business_values')?hache_sharky_business_values($pdo):[];
    $price=is_numeric($business['sharky_precio_intensivo']??null)?(int)$business['sharky_precio_intensivo']:1200;
    $money=number_format($price,0,'.',',');
    $message="Curso básico para aprender a nadar\n\n"
        ."• Duración: 3 semanas\n"
        ."• Clases: de lunes a viernes, todos los días\n"
        ."• Precio total (todo el curso): $".$money." MXN\n"
        ."• Dirigido a: personas que empiezan desde cero o nunca han tomado clases de natación\n"
        ."• Tu lugar queda confirmado una vez realizado el pago.\n\n"
        ."No es mensualidad: los $".$money." cubren las 3 semanas completas del curso. Para este curso no se paga inscripción; únicamente el costo del curso.\n\n"
        ."Para tomar las clases necesitas:\n"
        ."• traje de baño cómodo para moverte en el agua;\n"
        ."• no debe ser de algodón ni mezclilla;\n"
        ."• gorro de natación;\n"
        ."• goggles para natación.\n\n"
        ."Elige la sede con la que prefieres continuar 👇";
    return hache_sharky_orchestrator_decision('meta_intensive_info',$message,hache_sharky_meta_venue_ui($images));
}

function hache_sharky_meta_regular_info(PDO $pdo,bool $images=true): array
{
    $business=function_exists('hache_sharky_business_values')?hache_sharky_business_values($pdo):[];
    $price3=is_numeric($business['sharky_precio_regular_3']??null)?(int)$business['sharky_precio_regular_3']:1000;
    $price5=is_numeric($business['sharky_precio_regular_5']??null)?(int)$business['sharky_precio_regular_5']:1200;
    $message="Clases regulares de natación\n\n"
        ."• Modalidad: clases continuas por mensualidad.\n"
        ."• Plan 3x (3 clases por semana): $".number_format($price3,0,'.',',')." MXN al mes.\n"
        ."• Plan 5x (5 clases por semana): $".number_format($price5,0,'.',',')." MXN al mes.\n"
        ."• Dirigido a: personas que ya han tomado clases de natación y tienen nivel intermedio o avanzado.\n"
        ."• Estos planes llevan un pago de inscripción, cuyo costo depende de la sede que elijas.\n\n"
        ."Para tomar las clases necesitas:\n"
        ."• traje de baño cómodo para moverte en el agua;\n"
        ."• no debe ser de algodón ni mezclilla;\n"
        ."• gorro de natación;\n"
        ."• goggles para natación.\n\n"
        ."Elige la sede con la que prefieres continuar 👇";
    return hache_sharky_orchestrator_decision('meta_regular_info',$message,hache_sharky_meta_venue_ui($images));
}

function hache_sharky_meta_venue_retry(array $state): array
{
    $program=(string)($state['commercial_context']['program']??'');
    return hache_sharky_orchestrator_decision('meta_venue_prompt','Para continuar con '.($program==='regular'?'las clases regulares':'el curso').', elige una sede 👇',hache_sharky_meta_venue_ui(false));
}

function hache_sharky_meta_schedule_label(string $start,string $end): string
{
    $a=DateTimeImmutable::createFromFormat('!H:i',substr($start,0,5));$b=DateTimeImmutable::createFromFormat('!H:i',substr($end,0,5));
    if(!$a||!$b)return substr($start,0,5).'–'.substr($end,0,5);
    return $a->format('g:i').'–'.$b->format('g:i A');
}

function hache_sharky_meta_schedules(PDO $pdo,string $sede,string $program): array
{
    $column=$program==='regular'?'regular':'intensivo';
    $st=$pdo->prepare("SELECT h.hora_inicio,h.hora_fin FROM horarios h JOIN sedes s ON s.id=h.sede_id WHERE s.clave=:c AND s.activo=1 AND h.activo=1 AND h.{$column}=1 ORDER BY h.hora_inicio");$st->execute([':c'=>$sede]);
    $out=['morning'=>[],'evening'=>[]];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){$start=substr((string)($row['hora_inicio']??''),0,5);$end=substr((string)($row['hora_fin']??''),0,5);if($start===''||$end==='')continue;$bucket=(int)substr($start,0,2)<12?'morning':'evening';$out[$bucket][]=hache_sharky_meta_schedule_label($start,$end);}
    $out['morning']=array_values(array_unique($out['morning']));$out['evening']=array_values(array_unique($out['evening']));return $out;
}

function hache_sharky_meta_business_int(PDO $pdo,string $key,int $fallback): int
{
    $business=function_exists('hache_sharky_business_values')?hache_sharky_business_values($pdo):[];$value=$business[$key]??null;return is_numeric($value)?max(0,(int)$value):$fallback;
}

function hache_sharky_meta_venue_detail(PDO $pdo,array $state,string $sede): array
{
    $program=(string)($state['commercial_context']['program']??'');$business=function_exists('hache_sharky_business_values')?hache_sharky_business_values($pdo):[];$isMonteverde=$sede==='MONTEVERDE';
    $name=$isMonteverde?'Colegio Monteverde':'Palapas Protudec';$location=$isMonteverde?'Av. Bonampak, Cancún.':'Calle Alcatraces, Centro, Cancún.';
    $maps=trim((string)($business[$isMonteverde?'sharky_maps_monteverde':'sharky_maps_palapas']??''));if($maps==='')$maps=$isMonteverde?'https://maps.app.goo.gl/Ld75bhLforGm2Tk68':'https://maps.app.goo.gl/L7aEf9phtXtciUj78';
    $reference=$isMonteverde?'La alberca está al final del estacionamiento del colegio. No necesitas entrar a la escuela; solo ingresa al estacionamiento por Av. Bonampak. Puedes utilizar el estacionamiento durante tu clase.':'Entra por calle Alcatraces, viniendo desde Av. Cobá, por la zona del IMSS. Estamos aproximadamente a 100 metros del Parque de las Palapas.';
    $hours=hache_sharky_meta_schedules($pdo,$sede,$program);$morning=$hours['morning']?implode("\n",array_map(static fn(string $h):string=>'• '.$h,$hours['morning'])):'• Sin horarios activos en este momento';$evening=$hours['evening']?implode("\n",array_map(static fn(string $h):string=>'• '.$h,$hours['evening'])):'• Sin horarios activos en este momento';
    $message=$name."\n\n";
    if($program==='regular'){$fee=hache_sharky_meta_business_int($pdo,$isMonteverde?'sharky_inscripcion_monteverde':'sharky_inscripcion_palapas',$isMonteverde?500:400);$message.='Inscripción: $'.number_format($fee,0,'.',',')." MXN.\n\n";}
    $message.="Ubicación: ".$location."\n".$maps."\n\nReferencia para llegar:\n".$reference."\n\nMATUTINOS:\n".$morning."\n\nVESPERTINOS:\n".$evening;
    if($program==='intensive')$message.="\n\nIniciamos el próximo lunes.";
    $registerId=$program==='regular'?'meta:register:regular':'meta:register:intensive';
    return hache_sharky_orchestrator_decision('meta_venue_detail',$message,['type'=>'buttons','buttons'=>[hache_sharky_meta_button($registerId,'Inscribirme'),hache_sharky_meta_button('meta:venue:other','Ver otra sede')]]);
}

function hache_sharky_meta_regular_flow_data(PDO $pdo,string $sede,int $minAge=12,int $maxAge=65,?string $today=null): ?array
{
    if(!in_array($sede,['MONTEVERDE','PALAPAS'],true))return null;
    $st=$pdo->prepare('SELECT id,nombre FROM sedes WHERE clave=:c AND activo=1 LIMIT 1');$st->execute([':c'=>$sede]);$site=$st->fetch(PDO::FETCH_ASSOC);if(!$site)return null;$sid=(string)$site['id'];
    $plans=[];$st=$pdo->prepare('SELECT id,nombre,sesiones_semana,precio FROM planes WHERE sede_id=:s AND activo=1 AND sesiones_semana IN (3,5) ORDER BY sesiones_semana,precio,id');$st->execute([':s'=>$sid]);
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){$sessions=(int)($row['sesiones_semana']??0);if(!in_array($sessions,[3,5],true))continue;$plans[]=['id'=>(string)$row['id'],'title'=>'Plan '.$sessions.'x · $'.number_format((float)($row['precio']??0),0,'.',',').'/mes'];}
    $schedules=[];$st=$pdo->prepare('SELECT id,hora_inicio,hora_fin FROM horarios WHERE sede_id=:s AND activo=1 AND regular=1 ORDER BY hora_inicio,id');$st->execute([':s'=>$sid]);
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row)$schedules[]=['id'=>(string)$row['id'],'title'=>hache_sharky_meta_schedule_label((string)$row['hora_inicio'],(string)$row['hora_fin'])];
    if(!$plans||!$schedules)return null;
    $tz=new DateTimeZone('America/Cancun');$todayObj=DateTimeImmutable::createFromFormat('!Y-m-d',$today?:date('Y-m-d'),$tz)?:new DateTimeImmutable('today',$tz);$minAge=max(1,$minAge);$maxAge=max($minAge,$maxAge);
    return ['venue_key'=>$sede,'venue_label'=>$sede==='MONTEVERDE'?'Colegio Monteverde':'Palapas Protudec','min_birthdate'=>$todayObj->modify('-'.($maxAge+1).' years')->modify('+1 day')->format('Y-m-d'),'max_birthdate'=>$todayObj->modify('-'.$minAge.' years')->format('Y-m-d'),'profiles'=>[['id'=>'intermediate','title'=>'Intermedio'],['id'=>'advanced','title'=>'Avanzado']],'plans'=>$plans,'schedules'=>$schedules];
}

function hache_sharky_meta_regular_form(PDO $pdo,array $state,int $now,array $extraContext=[]): array
{
    $sede=(string)($state['commercial_context']['sede_clave']??'');$minAge=(int)($extraContext['min_age']??12);$maxAge=(int)($extraContext['max_age']??65);
    $data=hache_sharky_meta_regular_flow_data($pdo,$sede,$minAge,$maxAge,(string)($extraContext['today']??''));$flowId=hache_sharky_regular_flow_cached_id();
    if(!is_array($data)||$flowId===null){$state=hache_sharky_orchestrator_clear_flow($state);return [$state,hache_sharky_orchestrator_decision('regular_enrollment_unavailable','No pude abrir el formulario de inscripción de forma segura. Te dejo con una persona del equipo para continuar sin hacerte repetir información.',[],['type'=>'human_takeover'])];}
    $state=hache_sharky_orchestrator_flow($state,'register_regular','form',['sede_clave'=>$sede],$now);
    $payload=hache_sharky_commerce_flow_payload((string)($extraContext['contact']??''),'Completa tus datos para inscribirte a clases regulares. La sede ya queda fija en '.$data['venue_label'].'.',$flowId,'REGULAR_ENROLLMENT','Completar inscripción',$data);
    return [$state,hache_sharky_orchestrator_decision('regular_enrollment_form','Completa tus datos para continuar.',['type'=>'raw_payload','payload'=>$payload])];
}

function hache_sharky_meta_human_takeover(array $state,string $message='Te dejo con una persona del equipo de Hache Natación para que continúe contigo por este mismo chat.'): array
{
    $state=hache_sharky_orchestrator_clear_flow($state);return [$state,hache_sharky_orchestrator_decision('human_takeover',$message,[],['type'=>'human_takeover'])];
}

function hache_sharky_meta_handle(PDO $pdo,array $state,array $event,int $now,array $extraContext=[]): ?array
{
    if(!hache_sharky_meta_active($state))return null;if(($state['identity']['kind']??'unknown')!=='prospect')return null;if(($state['commercial_context']['entry_source']??'')!=='meta_ad')return null;if(!is_array($state['commercial_context']??null))$state['commercial_context']=[];
    $text=trim((string)($event['text']??''));$id=strtolower(trim((string)($event['interactive_id']??'')));$intent=hache_sharky_orchestrator_contextual_intent($state,$text,$id);
    if($id==='action:human'||$intent==='human')return hache_sharky_meta_human_takeover($state);if($intent==='student_claim')return hache_sharky_meta_human_takeover($state,'Como indicas que ya eres alumno, te dejo directamente con una persona del equipo para revisar tu expediente por este mismo chat.');
    $flow=$state['flow'];$step=(string)($flow['step']??'');$data=is_array($flow['data']??null)?$flow['data']:[];
    if($step==='program'){
        if(($data['entry_bootstrap']??false)===true){$data['entry_bootstrap']=false;$state=hache_sharky_orchestrator_flow($state,HACHE_SHARKY_META_FLOW,'program',$data,$now);return [$state,hache_sharky_meta_welcome(true)];}
        if($id==='meta:program:learn'){$state['commercial_context']['program']='intensive';$state['commercial_context']['recommended_program']='intensive';unset($state['commercial_context']['background']);$state=hache_sharky_orchestrator_flow($state,HACHE_SHARKY_META_FLOW,'venue',['program'=>'intensive'],$now);return [$state,hache_sharky_meta_intensive_info($pdo,true)];}
        if($id==='meta:program:regular'){$state=hache_sharky_orchestrator_flow($state,HACHE_SHARKY_META_FLOW,'regular_background',[],$now);return [$state,hache_sharky_meta_regular_background_prompt()];}
        return [$state,hache_sharky_meta_program_retry()];
    }
    if($step==='regular_background'){
        if($id==='meta:regular:no'){$state['commercial_context']['program']='intensive';$state['commercial_context']['recommended_program']='intensive';$state['commercial_context']['background']='no_formal';$state=hache_sharky_orchestrator_flow($state,HACHE_SHARKY_META_FLOW,'venue',['program'=>'intensive'],$now);return [$state,hache_sharky_meta_intensive_info($pdo,true)];}
        if($id==='meta:regular:yes'){$state['commercial_context']['program']='regular';$state['commercial_context']['recommended_program']='regular';$state['commercial_context']['background']='formal';$state=hache_sharky_orchestrator_flow($state,HACHE_SHARKY_META_FLOW,'venue',['program'=>'regular'],$now);return [$state,hache_sharky_meta_regular_info($pdo,true)];}
        return [$state,hache_sharky_meta_regular_background_prompt()];
    }
    if($step==='venue'){
        $sede=$id==='meta:venue:monteverde'?'MONTEVERDE':($id==='meta:venue:palapas'?'PALAPAS':'');if($sede==='')return [$state,hache_sharky_meta_venue_retry($state)];$state['commercial_context']['sede_clave']=$sede;$state=hache_sharky_orchestrator_flow($state,HACHE_SHARKY_META_FLOW,'venue_detail',['sede_clave'=>$sede],$now);return [$state,hache_sharky_meta_venue_detail($pdo,$state,$sede)];
    }
    if($step==='venue_detail'){
        $current=(string)($state['commercial_context']['sede_clave']??'');
        if($id==='meta:venue:other'){$other=$current==='MONTEVERDE'?'PALAPAS':'MONTEVERDE';$state['commercial_context']['sede_clave']=$other;$state=hache_sharky_orchestrator_flow($state,HACHE_SHARKY_META_FLOW,'venue_detail',['sede_clave'=>$other],$now);return [$state,hache_sharky_meta_venue_detail($pdo,$state,$other)];}
        if($id==='meta:register:intensive'&&($state['commercial_context']['program']??'')==='intensive'){$state=hache_sharky_orchestrator_clear_flow($state);$context=function_exists('hache_sharky_whatsapp_context')?hache_sharky_whatsapp_context($pdo,(string)($event['from']??''),$extraContext):$extraContext;return hache_sharky_whatsapp_registration_form_from_context($state,$context,$now,'Perfecto.');}
        if($id==='meta:register:regular'&&($state['commercial_context']['program']??'')==='regular'){$extraContext['contact']=(string)($event['from']??'');return hache_sharky_meta_regular_form($pdo,$state,$now,$extraContext);}
        return [$state,hache_sharky_meta_venue_detail($pdo,$state,$current)];
    }
    return hache_sharky_meta_human_takeover($state,'No pude recuperar este paso del proceso. Te dejo con una persona del equipo para continuar sin hacerte repetir información.');
}

function hache_sharky_meta_render(string $to,array $decision): array
{
    $ui=is_array($decision['ui']??null)?$decision['ui']:[];if(($ui['type']??'')==='raw_payload'&&is_array($ui['payload']??null))return $ui['payload'];
    $payload=hache_sharky_whatsapp_render($to,$decision);$images=is_array($ui['images']??null)?array_values(array_filter($ui['images'],'is_string')):[];if(!$images)return $payload;$sequence=[];
    foreach($images as $url)$sequence[]=['messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$to,'type'=>'image','image'=>['link'=>$url]];$sequence[]=$payload;return ['_sharky_sequence'=>$sequence,'to'=>$to];
}
