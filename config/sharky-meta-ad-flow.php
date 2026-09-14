<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-regular-enrollment.php';
require_once __DIR__.'/sharky-safe-side-question.php';

/**
 * Sharky 3.0 — funnel determinístico común para prospectos nuevos provenientes
 * de Meta Ads, enlaces de la web y WhatsApp directo.
 *
 * El nombre interno `meta_ad_onboarding` se conserva por compatibilidad con
 * conversaciones ya abiertas. La fuente real permanece en `entry_source` para
 * atribución y analítica.
 *
 * Este flujo no usa Brain ni interpretación de texto libre para avanzar pasos.
 * El texto libre sí puede recibir una respuesta lateral informativa segura sin
 * mutar producto/sede/plan ni mover el cursor; después se reponen los controles.
 * Solicitudes explícitas de humano/alumno terminan en takeover.
 */

const HACHE_SHARKY_META_FLOW='meta_ad_onboarding';
const HACHE_SHARKY_META_IMAGE_LEARN='https://hnatacion.com/assets/Aprende%20a%20nadar%20en%20tres%20semanas.png';
const HACHE_SHARKY_META_IMAGE_REGULAR='https://hnatacion.com/assets/Clases%20regulares%20de%20nataci%C3%B3n%20nocturna.png';
const HACHE_SHARKY_META_IMAGE_MONTEVERDE='https://hnatacion.com/assets/Sede%20Monteverde.png';
const HACHE_SHARKY_META_IMAGE_PALAPAS='https://hnatacion.com/assets/SEDE%20Palapas%20PROTUDEC.png';

function hache_sharky_meta_supported_source(array $state): bool
{
    return in_array((string)($state['commercial_context']['entry_source']??''),['meta_ad','web','direct'],true);
}

function hache_sharky_meta_active(array $state): bool
{
    if(($state['identity']['kind']??'unknown')!=='prospect'||!hache_sharky_meta_supported_source($state))return false;
    $flow=$state['flow']??null;
    if(is_array($flow))return ($flow['name']??'')===HACHE_SHARKY_META_FLOW;
    $step=(string)($state['commercial_context']['meta_step']??'');
    return in_array($step,['program','regular_background','venue','venue_detail'],true);
}

function hache_sharky_meta_flow(array $state,string $step,array $data,int $now): array
{
    $state=hache_sharky_orchestrator_flow($state,HACHE_SHARKY_META_FLOW,$step,$data,$now);
    if(!is_array($state['commercial_context']??null))$state['commercial_context']=[];
    $state['commercial_context']['meta_step']=$step;
    return $state;
}

function hache_sharky_meta_restore_expired(array $state,int $now): array
{
    if(is_array($state['flow']??null))return $state;
    $step=(string)($state['commercial_context']['meta_step']??'program');
    if(!in_array($step,['program','regular_background','venue','venue_detail'],true))$step='program';
    $data=[];
    if($step==='venue')$data['program']=(string)($state['commercial_context']['program']??'');
    if($step==='venue_detail')$data['sede_clave']=(string)($state['commercial_context']['sede_clave']??'');
    return hache_sharky_meta_flow($state,$step,$data,$now);
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

function hache_sharky_meta_welcome(PDO $pdo,bool $images=true): array
{
    $business=function_exists('hache_sharky_business_values')?hache_sharky_business_values($pdo):[];
    $policy=hache_sharky_age_policy($pdo,$business);$min=(int)$policy['min'];$max=(int)$policy['max'];
    $message="¡Hola! Soy Sharky 🦈, el asistente IA de Hache Natación.\n"
        ."Te ayudo a encontrar la opción que buscas.\n\n"
        ."Importante: nuestros cursos y clases son para personas de ".$min." a ".$max." años.\n\n"
        ."¿Qué te interesa? 👇";
    return hache_sharky_orchestrator_decision('meta_program_prompt',$message,hache_sharky_meta_program_ui($images));
}

function hache_sharky_meta_program_retry(): array
{
    return hache_sharky_orchestrator_decision('meta_program_prompt','🏊‍♂️ Para orientarte mejor, elige una de estas dos opciones 👇',hache_sharky_meta_program_ui(false));
}

function hache_sharky_meta_regular_background_prompt(): array
{
    return hache_sharky_orchestrator_decision('meta_regular_background_prompt','🏊‍♂️ Antes de continuar, ¿ya has tomado clases de natación anteriormente, en alguna escuela? 👇',['type'=>'buttons','buttons'=>[
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
        ."📅 Duración: 3 semanas, de lunes a viernes, todos los días.\n"
        ."💰 Precio total (todo el curso): $".$money." MXN.\n"
        ."• Dirigido a: personas que empiezan desde cero o nunca han tomado clases de natación.\n"
        ."• Tu lugar queda confirmado una vez realizado el pago.\n\n"
        ."No es mensualidad: los $".$money." cubren las 3 semanas completas del curso. Para este curso no se paga inscripción; únicamente el costo del curso.\n\n"
        ."🎒 Para tomar las clases necesitas:\n"
        ."• traje de baño cómodo para moverte en el agua;\n"
        ."• no debe ser de algodón ni mezclilla;\n"
        ."• gorro de natación;\n"
        ."• goggles para natación.\n\n"
        ."📍 Elige la sede con la que prefieres continuar 👇";
    return hache_sharky_orchestrator_decision('meta_intensive_info',$message,hache_sharky_meta_venue_ui($images));
}

function hache_sharky_meta_regular_price_line(PDO $pdo,int $sessions): string
{
    try{
        $st=$pdo->prepare('SELECT MIN(p.precio) min_price,MAX(p.precio) max_price,COUNT(*) total FROM planes p JOIN sedes s ON s.id=p.sede_id WHERE p.activo=1 AND s.activo=1 AND p.sesiones_semana=:n');$st->execute([':n'=>$sessions]);$row=$st->fetch(PDO::FETCH_ASSOC);
        if(is_array($row)&&(int)($row['total']??0)>0){
            $min=(float)$row['min_price'];$max=(float)$row['max_price'];
            if(abs($min-$max)<0.01)return '• Plan '.$sessions.'x ('.$sessions.' clases por semana): $'.number_format($min,0,'.',',').' MXN al mes.';
            return '• Plan '.$sessions.'x ('.$sessions.' clases por semana): el precio se muestra según la sede que elijas.';
        }
    }catch(Throwable $e){error_log('[sharky-meta] regular price summary failed');}
    return '• Plan '.$sessions.'x ('.$sessions.' clases por semana): precio disponible al elegir sede.';
}

function hache_sharky_meta_regular_info(PDO $pdo,bool $images=true): array
{
    $message="Clases regulares de natación\n\n"
        ."💰 Modalidad y mensualidades:\n"
        .hache_sharky_meta_regular_price_line($pdo,3)."\n"
        .hache_sharky_meta_regular_price_line($pdo,5)."\n"
        ."✅ Dirigido a: personas que ya han tomado clases de natación y tienen nivel intermedio o avanzado.\n"
        ."• Estos planes llevan un pago de inscripción, cuyo costo depende de la sede que elijas.\n\n"
        ."🎒 Para tomar las clases necesitas:\n"
        ."• traje de baño cómodo para moverte en el agua;\n"
        ."• no debe ser de algodón ni mezclilla;\n"
        ."• gorro de natación;\n"
        ."• goggles para natación.\n\n"
        ."📍 Elige la sede con la que prefieres continuar 👇";
    return hache_sharky_orchestrator_decision('meta_regular_info',$message,hache_sharky_meta_venue_ui($images));
}

function hache_sharky_meta_venue_retry(array $state): array
{
    $program=(string)($state['commercial_context']['program']??'');
    return hache_sharky_orchestrator_decision('meta_venue_prompt','📍 Para continuar con '.($program==='regular'?'las clases regulares':'el curso').', elige una sede 👇',hache_sharky_meta_venue_ui(false));
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

function hache_sharky_meta_regular_plan_lines(PDO $pdo,string $sede): array
{
    if(!in_array($sede,['MONTEVERDE','PALAPAS'],true))return [];
    try{
        $st=$pdo->prepare('SELECT p.sesiones_semana,p.precio FROM planes p JOIN sedes s ON s.id=p.sede_id WHERE s.clave=:c AND s.activo=1 AND p.activo=1 AND p.sesiones_semana IN (3,5) ORDER BY p.sesiones_semana,p.precio,p.id');$st->execute([':c'=>$sede]);$lines=[];
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){$sessions=(int)($row['sesiones_semana']??0);if(!in_array($sessions,[3,5],true))continue;$line='• Plan '.$sessions.'x: $'.number_format((float)($row['precio']??0),0,'.',',').' MXN/mes';$lines[$line]=$line;}
        return array_values($lines);
    }catch(Throwable $e){error_log('[sharky-meta] regular venue plans failed');return [];}
}

function hache_sharky_meta_venue_detail(PDO $pdo,array $state,string $sede): array
{
    $program=(string)($state['commercial_context']['program']??'');$business=function_exists('hache_sharky_business_values')?hache_sharky_business_values($pdo):[];$isMonteverde=$sede==='MONTEVERDE';
    $name=$isMonteverde?'Colegio Monteverde':'Palapas Protudec';$location=$isMonteverde?'Av. Bonampak, Cancún.':'Calle Alcatraces, Centro, Cancún.';
    $maps=trim((string)($business[$isMonteverde?'sharky_maps_monteverde':'sharky_maps_palapas']??''));if($maps==='')$maps=$isMonteverde?'https://maps.app.goo.gl/Ld75bhLforGm2Tk68':'https://maps.app.goo.gl/L7aEf9phtXtciUj78';
    $reference=$isMonteverde?'La alberca está al final del estacionamiento del colegio. No necesitas entrar a la escuela; solo ingresa al estacionamiento por Av. Bonampak. Puedes utilizar el estacionamiento durante tu clase.':'Entra por calle Alcatraces, viniendo desde Av. Cobá, por la zona del IMSS. Estamos aproximadamente a 100 metros del Parque de las Palapas.';
    $hours=hache_sharky_meta_schedules($pdo,$sede,$program);$morning=$hours['morning']?implode("\n",array_map(static fn(string $h):string=>'• '.$h,$hours['morning'])):'• Sin horarios activos en este momento';$evening=$hours['evening']?implode("\n",array_map(static fn(string $h):string=>'• '.$h,$hours['evening'])):'• Sin horarios activos en este momento';
    $message='📍 '.$name."\n\n";
    if($program==='regular'){
        $plans=hache_sharky_meta_regular_plan_lines($pdo,$sede);if($plans)$message.="💰 Mensualidades:\n".implode("\n",$plans)."\n\n";
        $fee=hache_sharky_meta_business_int($pdo,$isMonteverde?'sharky_inscripcion_monteverde':'sharky_inscripcion_palapas',$isMonteverde?500:400);$message.='✍️ Inscripción: $'.number_format($fee,0,'.',',')." MXN.\n\n";
    }
    $message.="Ubicación: ".$location."\n".$maps."\n\nReferencia para llegar:\n".$reference."\n\n🕒 MATUTINOS:\n".$morning."\n\n🌙 VESPERTINOS:\n".$evening;
    if($program==='intensive')$message.="\n\n📅 Iniciamos el próximo lunes.";
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
    return ['venue_key'=>$sede,'venue_label'=>$sede==='MONTEVERDE'?'Colegio Monteverde':'Palapas Protudec','age_helper'=>'Clases de '.$minAge.' a '.$maxAge.' años','min_birthdate'=>$todayObj->modify('-'.($maxAge+1).' years')->modify('+1 day')->format('Y-m-d'),'max_birthdate'=>$todayObj->modify('-'.$minAge.' years')->format('Y-m-d'),'profiles'=>[['id'=>'intermediate','title'=>'Intermedio'],['id'=>'advanced','title'=>'Avanzado']],'plans'=>$plans,'schedules'=>$schedules];
}

function hache_sharky_meta_regular_form(PDO $pdo,array $state,int $now,array $extraContext=[]): array
{
    $sede=(string)($state['commercial_context']['sede_clave']??'');$business=function_exists('hache_sharky_business_values')?hache_sharky_business_values($pdo):[];$policy=hache_sharky_age_policy($pdo,$business);$minAge=(int)$policy['min'];$maxAge=(int)$policy['max'];
    $data=hache_sharky_meta_regular_flow_data($pdo,$sede,$minAge,$maxAge,(string)($extraContext['today']??''));$flowId=hache_sharky_regular_flow_cached_id();
    if(!is_array($data)||$flowId===null){unset($state['commercial_context']['meta_step']);$state=hache_sharky_orchestrator_clear_flow($state);return [$state,hache_sharky_orchestrator_decision('regular_enrollment_unavailable','⚠️ No pude abrir el formulario de inscripción de forma segura. 👤 Te dejo con una persona del equipo para continuar sin hacerte repetir información.',[],['type'=>'human_takeover'])];}
    unset($state['commercial_context']['meta_step']);$state=hache_sharky_orchestrator_flow($state,'register_regular','form',['sede_clave'=>$sede],$now);
    $payload=hache_sharky_commerce_flow_payload((string)($extraContext['contact']??''),'✍️ Completa tus datos para inscribirte a clases regulares. ✅ La sede ya queda fija en '.$data['venue_label'].'.',$flowId,'REGULAR_ENROLLMENT','Completar inscripción',$data);
    return [$state,hache_sharky_orchestrator_decision('regular_enrollment_form','✍️ Completa tus datos para continuar. ✅',['type'=>'raw_payload','payload'=>$payload])];
}

function hache_sharky_meta_side_question_like(array $event): bool
{
    if(strtolower(trim((string)($event['interactive_id']??'')))!=='meta:free_text')return false;
    $text=trim((string)($event['text']??''));if($text==='')return false;
    if(function_exists('hache_sharky_whatsapp_batch_question_like'))return hache_sharky_whatsapp_batch_question_like($text);
    return str_contains($text,'?')||str_contains($text,'¿');
}

function hache_sharky_meta_resume_decision(array $state): array
{
    $flow=is_array($state['flow']??null)?$state['flow']:[];$step=(string)($flow['step']??($state['commercial_context']['meta_step']??''));
    if($step==='program')return hache_sharky_meta_program_retry();
    if($step==='regular_background')return hache_sharky_meta_regular_background_prompt();
    if($step==='venue')return hache_sharky_meta_venue_retry($state);
    if($step==='venue_detail'){
        $program=(string)($state['commercial_context']['program']??'');
        $registerId=$program==='regular'?'meta:register:regular':'meta:register:intensive';
        return hache_sharky_orchestrator_decision('meta_venue_detail_resume','📍 Si quieres continuar, elige una opción 👇',['type'=>'buttons','buttons'=>[
            hache_sharky_meta_button($registerId,'Inscribirme'),hache_sharky_meta_button('meta:venue:other','Ver otra sede')
        ]]);
    }
    return hache_sharky_orchestrator_decision('meta_resume','👇 Cuando quieras, continúa con la opción pendiente.');
}

function hache_sharky_meta_safe_side_question(PDO $pdo,array $state,array $event,array $extraContext=[]): ?array
{
    if(!hache_sharky_meta_side_question_like($event))return null;
    $text=trim((string)($event['text']??''));$answer=hache_sharky_safe_side_answer($pdo,$state,$text);
    if($answer===null)return null;
    $resume=hache_sharky_meta_resume_decision($state);$message=rtrim($answer);$prompt=trim((string)($resume['message']??''));if($prompt!=='')$message.="\n\n".$prompt;
    return [$state,hache_sharky_orchestrator_decision('meta_side_question',$message,is_array($resume['ui']??null)?$resume['ui']:[])];
}

function hache_sharky_meta_human_takeover(array $state,string $message='👤 Te dejo con una persona del equipo de Hache Natación para que continúe contigo por este mismo chat. 💬'): array
{
    unset($state['commercial_context']['meta_step']);$state=hache_sharky_orchestrator_clear_flow($state);return [$state,hache_sharky_orchestrator_decision('human_takeover',$message,[],['type'=>'human_takeover'])];
}

function hache_sharky_meta_handle(PDO $pdo,array $state,array $event,int $now,array $extraContext=[]): ?array
{
    if(!hache_sharky_meta_active($state))return null;if(($state['identity']['kind']??'unknown')!=='prospect')return null;if(!hache_sharky_meta_supported_source($state))return null;if(!is_array($state['commercial_context']??null))$state['commercial_context']=[];
    $state=hache_sharky_meta_restore_expired($state,$now);
    $text=trim((string)($event['text']??''));$id=strtolower(trim((string)($event['interactive_id']??'')));$intent=hache_sharky_orchestrator_contextual_intent($state,$text,$id);
    if($id==='action:human'||$intent==='human')return hache_sharky_meta_human_takeover($state);if($intent==='student_claim')return hache_sharky_meta_human_takeover($state,'👤 Como indicas que ya eres alumno, te dejo directamente con una persona del equipo para revisar tu expediente por este mismo chat. 💬');
    $flow=$state['flow'];$step=(string)($flow['step']??'');$data=is_array($flow['data']??null)?$flow['data']:[];
    $side=hache_sharky_meta_safe_side_question($pdo,$state,$event,$extraContext);if(is_array($side))return $side;
    if($step==='program'){
        if(($data['entry_bootstrap']??false)===true){$data['entry_bootstrap']=false;$state=hache_sharky_meta_flow($state,'program',$data,$now);return [$state,hache_sharky_meta_welcome($pdo,true)];}
        if($id==='meta:program:learn'){$state['commercial_context']['program']='intensive';$state['commercial_context']['recommended_program']='intensive';unset($state['commercial_context']['background']);$state=hache_sharky_meta_flow($state,'venue',['program'=>'intensive'],$now);return [$state,hache_sharky_meta_intensive_info($pdo,true)];}
        if($id==='meta:program:regular'){$state=hache_sharky_meta_flow($state,'regular_background',[],$now);return [$state,hache_sharky_meta_regular_background_prompt()];}
        return [$state,hache_sharky_meta_program_retry()];
    }
    if($step==='regular_background'){
        if($id==='meta:regular:no'){$state['commercial_context']['program']='intensive';$state['commercial_context']['recommended_program']='intensive';$state['commercial_context']['background']='no_formal';$state=hache_sharky_meta_flow($state,'venue',['program'=>'intensive'],$now);return [$state,hache_sharky_meta_intensive_info($pdo,true)];}
        if($id==='meta:regular:yes'){$state['commercial_context']['program']='regular';$state['commercial_context']['recommended_program']='regular';$state['commercial_context']['background']='formal';$state=hache_sharky_meta_flow($state,'venue',['program'=>'regular'],$now);return [$state,hache_sharky_meta_regular_info($pdo,true)];}
        return [$state,hache_sharky_meta_regular_background_prompt()];
    }
    if($step==='venue'){
        $sede=$id==='meta:venue:monteverde'?'MONTEVERDE':($id==='meta:venue:palapas'?'PALAPAS':'');if($sede==='')return [$state,hache_sharky_meta_venue_retry($state)];$state['commercial_context']['sede_clave']=$sede;$state=hache_sharky_meta_flow($state,'venue_detail',['sede_clave'=>$sede],$now);return [$state,hache_sharky_meta_venue_detail($pdo,$state,$sede)];
    }
    if($step==='venue_detail'){
        $current=(string)($state['commercial_context']['sede_clave']??'');
        if($id==='meta:venue:other'){$other=$current==='MONTEVERDE'?'PALAPAS':'MONTEVERDE';$state['commercial_context']['sede_clave']=$other;$state=hache_sharky_meta_flow($state,'venue_detail',['sede_clave'=>$other],$now);return [$state,hache_sharky_meta_venue_detail($pdo,$state,$other)];}
        if($id==='meta:register:intensive'&&($state['commercial_context']['program']??'')==='intensive'){unset($state['commercial_context']['meta_step']);$state=hache_sharky_orchestrator_clear_flow($state);$context=function_exists('hache_sharky_whatsapp_context')?hache_sharky_whatsapp_context($pdo,(string)($event['from']??''),$extraContext):$extraContext;return hache_sharky_whatsapp_registration_form_from_context($state,$context,$now,'Perfecto.');}
        if($id==='meta:register:regular'&&($state['commercial_context']['program']??'')==='regular'){$extraContext['contact']=(string)($event['from']??'');return hache_sharky_meta_regular_form($pdo,$state,$now,$extraContext);}
        return [$state,hache_sharky_meta_venue_detail($pdo,$state,$current)];
    }
    return hache_sharky_meta_human_takeover($state,'⚠️ No pude recuperar este paso del proceso. 👤 Te dejo con una persona del equipo para continuar sin hacerte repetir información.');
}

function hache_sharky_meta_carousel_card(array $button,string $image,int $index): ?array
{
    $id=trim((string)($button['id']??''));
    $title=trim((string)($button['title']??''));
    $image=trim($image);
    if($id===''||$title===''||$image===''||$index<0||$index>9)return null;
    return [
        'card_index'=>$index,
        'type'=>'cta_url',
        'header'=>['type'=>'image','image'=>['link'=>$image]],
        'body'=>['text'=>mb_substr($title,0,160)],
        'action'=>['buttons'=>[[
            'type'=>'quick_reply',
            'quick_reply'=>['id'=>$id,'title'=>mb_substr($title,0,20)],
        ]]],
    ];
}

function hache_sharky_meta_render(string $to,array $decision): array
{
    $ui=is_array($decision['ui']??null)?$decision['ui']:[];
    if(($ui['type']??'')==='raw_payload'&&is_array($ui['payload']??null))return $ui['payload'];

    $images=is_array($ui['images']??null)?array_values(array_filter($ui['images'],'is_string')):[];
    $buttons=is_array($ui['buttons']??null)?array_values(array_filter($ui['buttons'],'is_array')):[];
    $message=trim((string)($decision['message']??''));
    $count=count($images);
    if($count<2||$count>10||$count!==count($buttons)||$message===''||mb_strlen($message)>1024)return hache_sharky_whatsapp_render($to,$decision);

    $cards=[];
    foreach($images as $index=>$url){
        $card=hache_sharky_meta_carousel_card($buttons[$index],$url,$index);
        if($card===null)return hache_sharky_whatsapp_render($to,$decision);
        $cards[]=$card;
    }

    return [
        'messaging_product'=>'whatsapp',
        'recipient_type'=>'individual',
        'to'=>$to,
        'type'=>'interactive',
        'interactive'=>[
            'type'=>'carousel',
            'body'=>['text'=>$message],
            'action'=>['cards'=>$cards],
        ],
    ];
}
