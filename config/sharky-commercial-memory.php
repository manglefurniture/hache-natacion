<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-orchestrator.php';
require_once __DIR__.'/sharky-start-authority.php';

/** Confirmed selections plus durable discovery/recommendation/entry memory. */
function hache_sharky_commercial_snapshot(array $state): array
{
    $keys=['program','recommended_program','background','entry_source','entry_interest','sede_clave','age','swim_level','plan_id','plan_name','sessions_per_week','plan_price','schedule_id','schedule_label','course_id','fecha_inicio','course_price','kit','date_preference'];
    return array_intersect_key(is_array($state['commercial_context']??null)?$state['commercial_context']:[],array_flip($keys));
}

function hache_sharky_commercial_regular_restricted(array $state): bool
{
    $c=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    return ($c['swim_level']??null)==='beginner'
        || in_array(($c['background']??null),['self_taught','no_formal'],true);
}

function hache_sharky_commercial_force_intensive_eligibility(array $state): array
{
    if(!hache_sharky_commercial_regular_restricted($state))return $state;
    $c=&$state['commercial_context'];
    $program=(string)($c['program']??'');
    $recommended=(string)($c['recommended_program']??'');
    if($program!=='intensive'||$recommended==='regular')$c['recommended_program']='intensive';
    $c['program']='intensive';
    foreach(['plan_id','plan_name','sessions_per_week','plan_price'] as $key)unset($c[$key]);
    return $state;
}

function hache_sharky_commercial_invalidate(array $state,array $before): array
{
    $c=&$state['commercial_context'];
    if(($before['swim_level']??null)!==($c['swim_level']??null)){
        unset($c['background'],$c['recommended_program']);
    }
    $state=hache_sharky_commercial_force_intensive_eligibility($state);
    $c=&$state['commercial_context'];
    if(($before['program']??null)!==($c['program']??null)||($before['sede_clave']??null)!==($c['sede_clave']??null)){
        foreach(['plan_id','plan_name','sessions_per_week','plan_price','schedule_id','schedule_label','course_id','fecha_inicio','course_price','date_preference'] as $key)unset($c[$key]);
    }
    return $state;
}

function hache_sharky_commercial_catalog(PDO $pdo,array $state,array $context): array
{
    $c=$state['commercial_context']??[];$sede=(string)($c['sede_clave']??'');$program=(string)($c['program']??'');
    $out=['plans'=>[],'schedules'=>[],'courses'=>[],'_sede'=>$sede,'_program'=>$program];
    if(!in_array($sede,['MONTEVERDE','PALAPAS'],true))return $out;
    if($program==='intensive'){
        foreach($context['intensive_options']??[] as $course){
            if(($course['sede_clave']??'')!==$sede)continue;
            $out['courses'][]=$course;
            foreach($course['schedules']??[] as $schedule)$out['schedules'][(string)$schedule['id']]=$schedule;
        }
        $out['schedules']=array_values($out['schedules']);
    }elseif($program==='regular'){
        $st=$pdo->prepare('SELECT p.id,p.nombre,p.sesiones_semana,p.precio FROM planes p JOIN sedes s ON s.id=p.sede_id WHERE p.activo=1 AND s.activo=1 AND s.clave=:s ORDER BY p.sesiones_semana,p.nombre');
        $st->execute([':s'=>$sede]);$out['plans']=$st->fetchAll(PDO::FETCH_ASSOC);
        $st=$pdo->prepare('SELECT h.id,h.hora_inicio,h.hora_fin FROM horarios h JOIN sedes s ON s.id=h.sede_id WHERE h.activo=1 AND h.regular=1 AND s.activo=1 AND s.clave=:s ORDER BY h.hora_inicio');
        $st->execute([':s'=>$sede]);
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row)$out['schedules'][]=['id'=>(string)$row['id'],'label'=>substr((string)$row['hora_inicio'],0,5).'–'.substr((string)$row['hora_fin'],0,5)];
    }
    return $out;
}

function hache_sharky_commercial_background_choice(string $text): ?string
{
    if(str_contains($text,'?')||str_contains($text,'¿'))return null;
    $t=hache_sharky_orchestrator_normalize($text);
    if(preg_match('/^(?:si|si\s+he\s+tomado\s+clases|he\s+tomado\s+clases|ya\s+tome\s+clases|con\s+profesor|con\s+entrenador|formal(?:mente)?)[.! ]*$/u',$t)===1)return 'formal';
    if(preg_match('/^(?:por\s+mi\s+cuenta|aprendi\s+solo|aprendi\s+sola|autodidacta)[.! ]*$/u',$t)===1)return 'self_taught';
    if(preg_match('/^(?:no(?:\s+nunca)?|nunca|no\s+he\s+tomado\s+clases|nunca\s+he\s+tomado\s+clases|jamas\s+he\s+tomado\s+clases)[.! ]*$/u',$t)===1)return 'no_formal';
    if(preg_match('/\b(?:nunca|jamas|no)\s+(?:he\s+)?(?:tomado|recibido|tenido)\s+clases?(?:\s+formales?)?\s+de\s+natacion\b/u',$t)===1)return 'no_formal';
    if(preg_match('/\b(?:sin|ninguna?)\s+clases?(?:\s+formales?)?\s+(?:de\s+)?natacion\b/u',$t)===1)return 'no_formal';
    if(preg_match('/\b(?:nunca|jamas|no)\s+(?:he\s+)?(?:tomado|recibido|tenido)\s+clases?(?:\s+formales?)?(?:\s+antes)?[.!¡!\s]*$/u',$t)===1)return 'no_formal';
    if(preg_match('/\b(?:aprendi|nado|he\s+nadado)\b.{0,30}\b(?:solo|sola|por\s+mi\s+cuenta|autodidacta)\b/u',$t)===1)return 'self_taught';
    return null;
}

function hache_sharky_commercial_venue_browse_choice(string $text): ?string
{
    $t=hache_sharky_orchestrator_normalize($text);
    if($t==='')return null;
    $pal='palapas(?:\s+protudec)?';$mv='(?:colegio\s+)?monteverde';
    $hasPal=preg_match('/\b'.$pal.'\b/u',$t)===1;$hasMv=preg_match('/\b'.$mv.'\b/u',$t)===1;
    if($hasPal===$hasMv)return null;
    $name=$hasPal?$pal:$mv;$venue=$hasPal?'PALAPAS':'MONTEVERDE';
    $simple=preg_match('/^(?:y\s+)?(?:en\s+)?'.$name.'[?!. ]*$/u',$t)===1;
    $preference=preg_match('/\b'.$name.'\b.{0,28}\b(?:me\s+gusta|me\s+interesa|me\s+queda\s+mejor|me\s+conviene|me\s+queda\s+mas\s+cerca)\b/u',$t)===1
        ||preg_match('/\b(?:me\s+gusta|me\s+interesa|me\s+queda\s+mejor|me\s+conviene|me\s+queda\s+mas\s+cerca)\b.{0,28}\b'.$name.'\b/u',$t)===1;
    $browse=preg_match('/^(?:y\s+)?(?:quiero|quisiera|me\s+gustaria)?\s*(?:ver|conocer|revisar|mostrar|muestrame)?\s*(?:(?:los|las|opciones|horarios)\s+)?(?:de\s+|en\s+)?'.$name.'[?!. ]*$/u',$t)===1;
    return ($simple||$preference||$browse)?$venue:null;
}

function hache_sharky_commercial_recommendation_affirmation(string $text): bool
{
    if(str_contains($text,'?')||str_contains($text,'¿'))return false;
    $t=hache_sharky_orchestrator_normalize($text);
    return preg_match('/^(?:si|si\s+quiero|claro|claro\s+que\s+si|ok|oki|okay|vale|va|dale|de\s+acuerdo|esta\s+bien|me\s+parece\s+bien)[!. ]*$/u',$t)===1;
}

function hache_sharky_commercial_reconcile_guidance(array $state,string $text): array
{
    $c=&$state['commercial_context'];
    $flow=is_array($state['flow']??null)?$state['flow']:null;
    $flowName=(string)($flow['name']??'');$flowStep=(string)($flow['step']??'');
    $backgroundEligible=$flowName==='qualify_prospect'&&$flowStep==='background';
    if(!$backgroundEligible&&!is_array($flow)&&($c['swim_level']??null)==='swims'&&empty($c['background']))$backgroundEligible=true;

    if($backgroundEligible){
        $background=hache_sharky_commercial_background_choice($text);
        if($background!==null){
            $c['background']=$background;
            if(in_array($background,['self_taught','no_formal'],true)){
                $c['recommended_program']='intensive';
                $c['program']='intensive';
                foreach(['plan_id','plan_name','sessions_per_week','plan_price'] as $key)unset($c[$key]);
                if(in_array(($c['sede_clave']??null),['MONTEVERDE','PALAPAS'],true)){
                    $state=hache_sharky_orchestrator_clear_flow($state);
                }else{
                    $state=hache_sharky_orchestrator_flow($state,'qualify_prospect','sede',['recommended_program'=>'intensive','background'=>$background],(int)($state['updated_at']??time()));
                }
                $c=&$state['commercial_context'];
                $flow=$state['flow']??null;$flowName=(string)($flow['name']??'');$flowStep=(string)($flow['step']??'');
            }else{
                if($background==='formal'&&in_array(($c['entry_interest']??null),['intensive','regular'],true))$c['recommended_program']=$c['entry_interest'];
                if(empty($c['program'])){
                    $state=hache_sharky_orchestrator_flow($state,'qualify_prospect','program',[
                        'recommended_program'=>$c['recommended_program']??null,
                        'background'=>$background,
                        'preferred_program'=>$c['entry_interest']??null,
                    ],(int)($state['updated_at']??time()));
                    $c=&$state['commercial_context'];
                    $flow=$state['flow'];$flowName='qualify_prospect';$flowStep='program';
                }
            }
        }
    }

    $state=hache_sharky_commercial_force_intensive_eligibility($state);
    $c=&$state['commercial_context'];
    $recommended=(string)($c['recommended_program']??'');
    if(empty($c['program'])&&in_array($recommended,['intensive','regular'],true)){
        if(!is_array($state['flow']??null)){
            $state=hache_sharky_orchestrator_flow($state,'qualify_prospect','program',['recommended_program'=>$recommended],(int)($state['updated_at']??time()));
            $flowStep='program';
        }elseif(($state['flow']['name']??'')==='qualify_prospect'){
            $flowStep=(string)($state['flow']['step']??'');
        }
        if($flowStep==='program'&&hache_sharky_commercial_recommendation_affirmation($text)){
            $c=&$state['commercial_context'];
            $c['program']=$recommended;
            if(in_array(($c['sede_clave']??null),['MONTEVERDE','PALAPAS'],true)){
                $state=hache_sharky_orchestrator_clear_flow($state);
            }else{
                $state=hache_sharky_orchestrator_flow($state,'qualify_prospect','sede',['recommended_program'=>$recommended],(int)($state['updated_at']??time()));
            }
        }
    }
    return hache_sharky_commercial_force_intensive_eligibility($state);
}

function hache_sharky_commercial_relative_date_question(string $text): bool
{
    $t=hache_sharky_orchestrator_normalize($text);
    if(preg_match('/\b(?:proximo\s+lunes|lunes\s+que\s+viene)\b/u',$t)!==1)return false;
    return str_contains($text,'?')||str_contains($text,'¿')
        ||preg_match('/\b(?:no\s+se|dime|decirme|saber|cual|que)\b.{0,36}\b(?:fecha|dia|cuando)\b/u',$t)===1
        ||preg_match('/\b(?:que\s+dia|que\s+fecha|cuando)\s+(?:es|cae)\b/u',$t)===1;
}

function hache_sharky_commercial_course_options_question(string $text): bool
{
    $t=hache_sharky_orchestrator_normalize($text);
    if($t==='')return false;
    $question=str_contains($text,'?')||str_contains($text,'¿')||preg_match('/^(?:cuando|cual|que\s+fecha)\b/u',$t)===1;
    if(!$question)return false;
    return preg_match('/\b(?:cuando|cual|que\s+fecha)\b.{0,50}\b(?:inicia|inicio|empieza|comienza|arranca|proximo\s+curso|curso\s+intensivo)\b/u',$t)===1
        ||preg_match('/\b(?:proximo\s+curso|curso\s+intensivo)\b.{0,45}\b(?:inicia|inicio|empieza|comienza|fecha)\b/u',$t)===1;
}

function hache_sharky_commercial_human_date(string $iso): string
{
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$iso,new DateTimeZone('America/Cancun'));
    if(!$d||$d->format('Y-m-d')!==$iso)return $iso;
    $months=[1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'];
    return (int)$d->format('j').' de '.$months[(int)$d->format('n')].' de '.$d->format('Y');
}

function hache_sharky_commercial_short_date(string $iso): string
{
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$iso,new DateTimeZone('America/Cancun'));
    if(!$d||$d->format('Y-m-d')!==$iso)return $iso;
    $months=[1=>'ene',2=>'feb',3=>'mar',4=>'abr',5=>'may',6=>'jun',7=>'jul',8=>'ago',9=>'sep',10=>'oct',11=>'nov',12=>'dic'];
    return 'Lun '.(int)$d->format('j').' '.$months[(int)$d->format('n')];
}

function hache_sharky_commercial_visible_plans(array $state,array $catalog): array
{
    if(hache_sharky_commercial_regular_restricted($state))return [];
    $sessions=$state['commercial_context']['sessions_per_week']??null;
    return array_values(array_filter($catalog['plans']??[],static fn(array $p):bool=>$sessions===null||(int)($p['sesiones_semana']??0)===(int)$sessions));
}

function hache_sharky_commercial_visible_schedules(array $state,array $catalog): array
{
    $c=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    if(($c['program']??null)==='intensive'&&!empty($c['course_id'])){
        foreach($catalog['courses']??[] as $course){
            if((string)($course['id']??'')!==(string)$c['course_id'])continue;
            return array_values(is_array($course['schedules']??null)?$course['schedules']:[]);
        }
        return [];
    }
    return array_values($catalog['schedules']??[]);
}

function hache_sharky_commercial_visible_courses(array $state,array $catalog): array
{
    $scheduleId=(string)($state['commercial_context']['schedule_id']??'');
    $courses=array_values($catalog['courses']??[]);
    if($scheduleId==='')return $courses;
    return array_values(array_filter($courses,static function(array $course) use($scheduleId):bool {
        foreach($course['schedules']??[] as $schedule)if((string)($schedule['id']??'')===$scheduleId)return true;
        return false;
    }));
}

function hache_sharky_commercial_schedule_daypart(string $text): ?string
{
    $t=hache_sharky_orchestrator_normalize($text);
    if(preg_match('/\b(?:manana|matutino|matutina)\b/u',$t)===1)return 'morning';
    if(preg_match('/\b(?:tarde|noche|vespertino|vespertina|nocturno|nocturna)\b/u',$t)===1)return 'evening';
    return null;
}

function hache_sharky_commercial_filter_catalog_daypart(array $catalog,?string $daypart): array
{
    if(!in_array($daypart,['morning','evening'],true))return $catalog;
    $keep=static function(array $schedule) use($daypart): bool {
        $label=(string)($schedule['label']??'');
        if(preg_match('/^(\d{2}):/u',$label,$m)!==1)return false;
        $hour=(int)$m[1];
        return $daypart==='morning'?$hour<12:$hour>=12;
    };
    $catalog['schedules']=array_values(array_filter($catalog['schedules']??[],$keep));
    if(is_array($catalog['courses']??null)){
        foreach($catalog['courses'] as &$course){
            if(is_array($course))$course['schedules']=array_values(array_filter($course['schedules']??[],$keep));
        }
        unset($course);
    }
    return $catalog;
}

/** Buttons when WhatsApp can show every choice safely; otherwise a native list. */
function hache_sharky_commercial_choice_ui(string $slot,array $options): array
{
    $options=array_values(array_filter($options,static fn(array $o):bool=>trim((string)($o['id']??''))!==''&&trim((string)($o['title']??''))!==''));
    if(!$options)return ['type'=>'list','list_id'=>'commercial_'.$slot,'options'=>[]];
    $buttonSafe=count($options)<=3;
    $titles=[];
    foreach($options as $option){
        $title=trim((string)$option['title']);
        if(mb_strlen($title)>20||isset($titles[$title]))$buttonSafe=false;
        $titles[$title]=true;
    }
    if($buttonSafe){
        return ['type'=>'buttons','buttons'=>array_map(static fn(array $o):array=>hache_sharky_orchestrator_button((string)$o['id'],(string)$o['title']),$options)];
    }
    return ['type'=>'list','list_id'=>'commercial_'.$slot,'options'=>array_map(static fn(array $o):array=>[
        'id'=>(string)$o['id'],'title'=>(string)$o['title'],'description'=>(string)($o['description']??''),
    ],array_slice($options,0,10))];
}

function hache_sharky_commercial_control_ui(array $state,array $catalog,array $next): array
{
    $slot=(string)($next['slot']??'');$c=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    if($slot==='program'){
        $recommended=(string)($c['recommended_program']??'');
        if(hache_sharky_commercial_regular_restricted($state))return ['type'=>'buttons','buttons'=>[
            hache_sharky_orchestrator_button('qualify:intensive','Seguir intensivo'),
        ]];
        if($recommended==='intensive')return ['type'=>'buttons','buttons'=>[
            hache_sharky_orchestrator_button('qualify:intensive','Seguir intensivo'),hache_sharky_orchestrator_button('qualify:regular','Ver regulares'),
        ]];
        if($recommended==='regular')return ['type'=>'buttons','buttons'=>[
            hache_sharky_orchestrator_button('qualify:regular','Seguir regulares'),hache_sharky_orchestrator_button('qualify:intensive','Ver intensivo'),
        ]];
        return ['type'=>'buttons','buttons'=>[
            hache_sharky_orchestrator_button('qualify:intensive','Intensivo'),hache_sharky_orchestrator_button('qualify:regular','Regulares'),
        ]];
    }
    if($slot==='sede')return ['type'=>'buttons','buttons'=>[
        hache_sharky_orchestrator_button('sede:monteverde','Colegio Monteverde'),hache_sharky_orchestrator_button('sede:palapas','Palapas Protudec'),
    ]];
    if($slot==='plan'){
        $options=[];
        foreach(hache_sharky_commercial_visible_plans($state,$catalog) as $plan){
            $price=is_numeric($plan['precio']??null)?'$'.rtrim(rtrim(number_format((float)$plan['precio'],2,'.',''),'0'),'.'):'';
            $options[]=['id'=>'action:commercial:plan:'.(string)($plan['id']??''),'title'=>(string)($plan['nombre']??''),'description'=>(int)($plan['sesiones_semana']??0).' sesiones'.($price!==''?' · '.$price:'')];
        }
        return hache_sharky_commercial_choice_ui('plan',$options);
    }
    if($slot==='schedule'){
        $options=[];
        foreach(hache_sharky_commercial_visible_schedules($state,$catalog) as $schedule)$options[]=[
            'id'=>'action:commercial:schedule:'.(string)($schedule['id']??''),'title'=>(string)($schedule['label']??''),'description'=>'',
        ];
        return hache_sharky_commercial_choice_ui('schedule',$options);
    }
    if($slot==='course'){
        $options=[];
        foreach(hache_sharky_commercial_visible_courses($state,$catalog) as $course){
            $iso=(string)($course['fecha_inicio']??'');
            $options[]=['id'=>'action:commercial:course:'.(string)($course['id']??''),'title'=>hache_sharky_commercial_short_date($iso),'description'=>hache_sharky_commercial_human_date($iso)];
        }
        return hache_sharky_commercial_choice_ui('course',$options);
    }
    if($slot==='enroll')return ['type'=>'buttons','buttons'=>[
        hache_sharky_orchestrator_button(($c['program']??'')==='regular'?'action:human':'action:register_intensive','Inscribirme'),
    ]];
    return [];
}

function hache_sharky_commercial_interactive_input(PDO $pdo,array $state,array $event,array $context): ?array
{
    $id=strtolower(trim((string)($event['interactive_id']??'')));
    if(!str_starts_with($id,'action:commercial:'))return null;
    if(($state['identity']['kind']??'')!=='prospect'||is_array($state['flow']??null))return null;

    $minAge=max(1,(int)($context['min_age']??12));$age=$state['commercial_context']['age']??null;
    if(is_int($age)&&$age<$minAge){
        if(function_exists('hache_sharky_whatsapp_underage_rejection'))return hache_sharky_whatsapp_underage_rejection($state,$minAge);
        $state=hache_sharky_orchestrator_clear_flow($state);
        return [$state,hache_sharky_orchestrator_decision('prospect_age_rejected','Hache Natación atiende a partir de '.$minAge.' años; no puedo continuar con esta orientación para una persona de '.$age.' años.')];
    }

    $state=hache_sharky_commercial_force_intensive_eligibility($state);
    $catalog=hache_sharky_commercial_catalog($pdo,$state,$context);
    $next=hache_sharky_commercial_next($state);$slot=(string)($next['slot']??'');
    $requestedSlot=(string)($state['commercial_context']['_requested_slot']??'');
    if($requestedSlot==='course'&&($state['commercial_context']['program']??'')==='intensive'&&in_array(($state['commercial_context']['sede_clave']??null),['MONTEVERDE','PALAPAS'],true))$slot='course';
    $prefix='action:commercial:'.$slot.':';
    if(!in_array($slot,['plan','schedule','course'],true)||!str_starts_with($id,$prefix)){
        return [$state,hache_sharky_commercial_reply($state,'Esa opción corresponde a un paso anterior. Seguimos con la opción que toca ahora.',$catalog)];
    }
    $selectedId=substr($id,strlen($prefix));
    if($selectedId==='')return [$state,hache_sharky_commercial_reply($state,'Esa opción ya no está disponible.',$catalog)];

    $c=&$state['commercial_context'];$label='';$matched=null;
    if($slot==='plan'){
        foreach(hache_sharky_commercial_visible_plans($state,$catalog) as $plan)if(strtolower((string)($plan['id']??''))===$selectedId){$matched=$plan;break;}
        if(is_array($matched)){
            $c['plan_id']=(string)$matched['id'];$c['plan_name']=(string)$matched['nombre'];$c['sessions_per_week']=(int)$matched['sesiones_semana'];$c['plan_price']=(float)$matched['precio'];$label=(string)$matched['nombre'];
        }
    }elseif($slot==='schedule'){
        foreach(hache_sharky_commercial_visible_schedules($state,$catalog) as $schedule)if(strtolower((string)($schedule['id']??''))===$selectedId){$matched=$schedule;break;}
        if(is_array($matched)){
            $c['schedule_id']=(string)$matched['id'];$c['schedule_label']=(string)$matched['label'];$label=(string)$matched['label'];
        }
    }else{
        foreach(hache_sharky_commercial_visible_courses($state,$catalog) as $course)if(strtolower((string)($course['id']??''))===$selectedId){$matched=$course;break;}
        if(is_array($matched)){
            $c['course_id']=(string)$matched['id'];$c['fecha_inicio']=(string)$matched['fecha_inicio'];$c['course_price']=is_numeric($matched['precio']??null)?(float)$matched['precio']:null;unset($c['date_preference']);
            $label=hache_sharky_commercial_human_date((string)$matched['fecha_inicio']);
            if(!empty($c['schedule_id'])){
                $valid=false;foreach($matched['schedules']??[] as $schedule)if((string)($schedule['id']??'')===(string)$c['schedule_id']){$valid=true;break;}
                if(!$valid)unset($c['schedule_id'],$c['schedule_label']);
            }
        }
    }

    if(!is_array($matched)){
        if($slot==='course'&&$requestedSlot==='course'){
            unset($c['course_id'],$c['fecha_inicio'],$c['course_price']);
            $c['_requested_slot']='course';
        }
        return [$state,hache_sharky_commercial_reply($state,'Esa opción ya no está disponible. Elige una de las opciones actuales.',$catalog)];
    }
    unset($c['_requested_slot']);
    return [$state,hache_sharky_commercial_reply($state,'Listo, elegiste '.$label.'.',$catalog)];
}

/** Pure reducer; catalog must come from the same current backend as controlled registration. */
function hache_sharky_commercial_capture(array $state,string $text,array $catalog,string $today): array
{
    if(($state['identity']['kind']??'')!=='prospect')return $state;
    if(is_array($state['flow']??null)&&($state['flow']['name']??'')!=='qualify_prospect')return $state;
    $flow=is_array($state['flow']??null)?$state['flow']:null;
    $entryBootstrap=is_array($flow)&&($flow['name']??'')==='qualify_prospect'&&($flow['step']??'')==='swim'&&($flow['data']['entry_bootstrap']??false)===true;
    if($entryBootstrap&&in_array(($state['commercial_context']['program']??null),['intensive','regular'],true))$state['commercial_context']['program']=null;

    $venueChoice=hache_sharky_commercial_venue_browse_choice($text);
    if($venueChoice!==null&&($state['commercial_context']['sede_clave']??null)!==$venueChoice){
        $state['commercial_context']['sede_clave']=$venueChoice;
        foreach(['plan_id','plan_name','sessions_per_week','plan_price','schedule_id','schedule_label','course_id','fecha_inicio','course_price','date_preference'] as $key)unset($state['commercial_context'][$key]);
    }

    $state=hache_sharky_commercial_reconcile_guidance($state,$text);
    $state=hache_sharky_commercial_force_intensive_eligibility($state);
    $c=&$state['commercial_context'];
    unset($c['_requested_slot']);
    if(($c['program']??'')==='intensive'&&in_array(($c['sede_clave']??null),['MONTEVERDE','PALAPAS'],true)&&hache_sharky_commercial_course_options_question($text))$c['_requested_slot']='course';
    foreach(hache_sharky_orchestrator_text_segments($text) as $line){
        $t=hache_sharky_orchestrator_normalize($line);
        if(str_contains($line,'?')||str_contains($line,'¿'))continue;
        if(preg_match('/\b(?:ya\s+)?tengo\s+(?:mi\s+)?gorro\s+y\s+goggles\b/u',$t))$c['kit']=['gorro'=>true,'goggles'=>true];
        if(($c['program']??'')==='regular'&&!hache_sharky_commercial_regular_restricted($state)){
            $sessions=null;
            if(preg_match('/^(?:(?:quiero|prefiero|mejor|cambio\s+a)\s+)?(\d{1,2})\s*(?:clases|sesiones|dias)(?:\s+(?:por|a\s+la)\s+semana)?[.! ]*$/u',$t,$m))$sessions=(int)$m[1];
            $matches=[];
            foreach($catalog['plans']??[] as $plan){
                $name=hache_sharky_orchestrator_normalize((string)($plan['nombre']??''));
                if(($sessions!==null&&(int)$plan['sesiones_semana']===$sessions)||($name!==''&&preg_match('/^(?:(?:quiero|prefiero|elijo|mejor)\s+)?(?:el\s+plan\s+)?'.preg_quote($name,'/').'[.! ]*$/u',$t)))$matches[]=$plan;
            }
            if($sessions!==null){
                if(($c['sessions_per_week']??null)!==$sessions)foreach(['plan_id','plan_name','plan_price'] as $key)unset($c[$key]);
                $c['sessions_per_week']=$sessions;
            }
            if(count($matches)===1){
                $p=$matches[0];$c['plan_id']=(string)$p['id'];$c['plan_name']=(string)$p['nombre'];$c['sessions_per_week']=(int)$p['sesiones_semana'];$c['plan_price']=(float)$p['precio'];
            }
        }
        $start=null;$end=null;
        if(preg_match('/^(?:(?:quiero|prefiero|mejor|el\s+horario|de)\s+)*(\d{1,2})(?::(\d{2}))?\s*(?:a|[-–])\s*(\d{1,2})(?::(\d{2}))?[.! ]*$/u',$t,$m)){
            $start=sprintf('%02d:%02d',(int)$m[1],(int)($m[2]??0));$end=sprintf('%02d:%02d',(int)$m[3],(int)($m[4]??0));
        }elseif(preg_match('/^(?:(?:quiero|prefiero|mejor|a\s+las|las|el\s+de)\s+)*(\d{1,2})(?::(\d{2}))?\s*(?:de\s+la\s+)?(noche|tarde|manana)[.! ]*$/u',$t,$m)){
            $hour=(int)$m[1];if($hour>=1&&$hour<=12){if($m[3]!=='manana'&&$hour<12)$hour+=12;$start=sprintf('%02d:%02d',$hour,(int)($m[2]??0));}
        }
        if($start!==null){
            $matches=array_values(array_filter(hache_sharky_commercial_visible_schedules($state,$catalog),static fn(array $s):bool=>substr((string)$s['label'],0,5)===$start&&($end===null||substr((string)$s['label'],-5)===$end)));
            if(count($matches)===1){$c['schedule_id']=(string)$matches[0]['id'];$c['schedule_label']=(string)$matches[0]['label'];}
            else {unset($c['schedule_id'],$c['schedule_label']);}
        }
    }

    $declarations=array_filter(hache_sharky_orchestrator_text_segments($text),static fn(string $line):bool=>!str_contains($line,'?')&&!str_contains($line,'¿'));
    $flat=preg_replace('/\s+/u',' ',hache_sharky_orchestrator_normalize(implode(' ',$declarations)))??'';
    if(($c['program']??'')==='intensive'&&!str_contains($flat,'?')&&!str_contains($flat,'¿')&&!hache_sharky_commercial_relative_date_question($text)){
        $date=hache_sharky_start_authority_parse_date($flat,new DateTimeImmutable($today,new DateTimeZone('America/Cancun')));
        $explicitNumericDate=preg_match('/\b\d{1,2}[\/.-]\d{1,2}(?:[\/.-]\d{2,4})?\b/u',$flat)===1;
        if($date!==null&&($explicitNumericDate||preg_match('/\b(?:iniciar|inicio|empezar|comenzar|lunes|\d{1,2}\s+de)\b/u',$flat))){
            $iso=$date->format('Y-m-d');
            $matches=array_values(array_filter($catalog['courses']??[],static fn(array $o):bool=>($o['fecha_inicio']??'')===$iso&&hache_sharky_start_authority_intensive_date_allowed($iso,$today)));
            if(count($matches)!==1){unset($c['course_id'],$c['fecha_inicio'],$c['course_price']);}
            if(count($matches)===1){
                $o=$matches[0];$c['course_id']=(string)$o['id'];$c['fecha_inicio']=$iso;$c['course_price']=is_numeric($o['precio']??null)?(float)$o['precio']:null;unset($c['date_preference']);
                $valid=false;foreach($o['schedules']??[] as $s)if((string)$s['id']===($c['schedule_id']??null))$valid=true;
                if(!$valid){unset($c['schedule_id'],$c['schedule_label']);}
            }
        }elseif(!empty($c['schedule_id'])&&preg_match('/^(?:(?:el|lunes)\s+)?(\d{1,2})[.! ]*$/u',$flat,$m)){
            $day=(int)$m[1];
            $matches=array_values(array_filter($catalog['courses']??[],static function(array $o) use($day,$today):bool {
                $iso=(string)($o['fecha_inicio']??'');
                $d=DateTimeImmutable::createFromFormat('!Y-m-d',$iso,new DateTimeZone('America/Cancun'));
                return $d&&$d->format('Y-m-d')===$iso&&(int)$d->format('j')===$day&&hache_sharky_start_authority_intensive_date_allowed($iso,$today);
            }));
            if(count($matches)===1){
                $o=$matches[0];$c['course_id']=(string)$o['id'];$c['fecha_inicio']=(string)$o['fecha_inicio'];$c['course_price']=is_numeric($o['precio']??null)?(float)$o['precio']:null;unset($c['date_preference']);
            }else{
                unset($c['course_id'],$c['fecha_inicio'],$c['course_price']);
            }
        }elseif(preg_match('/^(?:de\s+)?(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre)(?:\s+(?:de\s+)?(\d{4}))?[.! ]*$/u',$flat,$m)){
            unset($c['course_id'],$c['fecha_inicio'],$c['course_price']);
            $c['date_preference']=['month'=>$m[1],'year'=>isset($m[2])?(int)$m[2]:null];
        }elseif(preg_match('/^\d{4}$/',$flat)&&is_array($c['date_preference']??null)){
            unset($c['course_id'],$c['fecha_inicio'],$c['course_price']);
            $c['date_preference']['year']=(int)$flat;
        }
    }
    return hache_sharky_commercial_force_intensive_eligibility($state);
}

function hache_sharky_commercial_next(array $state): array
{
    $c=$state['commercial_context']??[];
    if(empty($c['program'])){
        if(($c['recommended_program']??null)==='intensive')return ['slot'=>'program','prompt'=>'Por lo que me contaste, te recomiendo el curso intensivo. ¿Seguimos con esa opción?'];
        if(($c['recommended_program']??null)==='regular')return ['slot'=>'program','prompt'=>'Por lo que me contaste, te recomiendo las clases regulares. ¿Seguimos con esa opción?'];
        return ['slot'=>'program','prompt'=>'¿Buscas un curso intensivo o clases regulares?'];
    }
    if(empty($c['sede_clave']))return ['slot'=>'sede','prompt'=>'¿Prefieres Colegio Monteverde o Palapas Protudec?'];
    if($c['program']==='regular'&&empty($c['plan_id']))return ['slot'=>'plan','prompt'=>isset($c['sessions_per_week'])?'¿Qué plan de '.$c['sessions_per_week'].' sesiones prefieres?':'¿Qué plan de clases regulares prefieres?'];
    if(empty($c['schedule_id']))return ['slot'=>'schedule','prompt'=>'Elige uno de los horarios disponibles.'];
    if($c['program']==='intensive'&&empty($c['course_id']))return ['slot'=>'course','prompt'=>'Elige uno de los inicios disponibles.'];
    return ['slot'=>'enroll','prompt'=>$c['program']==='regular'?'Puedes continuar tu inscripción con el equipo por este chat.':'Puedes iniciar tu inscripción ahora.'];
}

/** One commercial continuation, never a business mutation or implicit consent. */
function hache_sharky_commercial_reply(array $state,string $answer,array $catalog=[]): array
{
    $state=hache_sharky_commercial_force_intensive_eligibility($state);
    $c=is_array($state['commercial_context']??null)?$state['commercial_context']:[];

    if(($c['program']??null)==='regular'&&($c['swim_level']??null)!=='swims'){
        return hache_sharky_orchestrator_decision('commercial_level_required','Antes de continuar con clases regulares necesito confirmar algo: ¿ya sabes nadar o estás empezando desde cero?');
    }
    if(($c['program']??null)==='regular'&&($c['background']??null)!=='formal'){
        return hache_sharky_orchestrator_decision('commercial_background_required','Antes de ofrecerte clases regulares necesito confirmar algo: ¿has tomado clases formales de natación con un profesor o entrenador?');
    }

    $catalogSede=(string)($catalog['_sede']??'');$catalogProgram=(string)($catalog['_program']??'');
    $catalogStale=($catalogSede!==''&&$catalogSede!==(string)($c['sede_clave']??''))
        ||($catalogProgram!==''&&$catalogProgram!==(string)($c['program']??''));
    if($catalogStale){
        $safe=trim(preg_replace('/¿[^?]*\?/u','',$answer)??$answer);
        if($safe===''){
            $venue=($c['sede_clave']??'')==='PALAPAS'?'Palapas Protudec':'Colegio Monteverde';
            $safe='Ya actualicé el contexto a '.$venue.'. Puedo mostrarte los horarios vigentes de esta sede.';
        }
        return hache_sharky_orchestrator_decision('commercial_scope_refreshed',$safe);
    }

    $requestedCourse=($c['_requested_slot']??null)==='course'&&($c['program']??null)==='intensive'&&in_array(($c['sede_clave']??null),['MONTEVERDE','PALAPAS'],true);
    $next=$requestedCourse?['slot'=>'course','prompt'=>'Elige uno de los inicios disponibles.']:hache_sharky_commercial_next($state);
    $daypart=hache_sharky_commercial_schedule_daypart($answer);
    $uiCatalog=hache_sharky_commercial_filter_catalog_daypart($catalog,$daypart);
    $answer=preg_replace('/¿[^?]*\?/u','',$answer)??$answer;
    $message=$requestedCourse?'Estos son los inicios disponibles actualmente en '.(($c['sede_clave']??'')==='MONTEVERDE'?'Colegio Monteverde':'Palapas Protudec').'.':trim($answer);
    $slot=(string)$next['slot'];

    if($slot==='plan'){
        $message.=($message!==''?"\n\n":'').$next['prompt'].' Toca una opción o escribe el nombre del plan.';
    }elseif($slot==='schedule'){
        $message.=($message!==''?"\n\n":'').'Elige uno de los horarios disponibles. Puedes tocarlo o escribirlo, por ejemplo “8 a 9”.';
    }elseif($slot==='course'){
        $message.=($message!==''?"\n\n":'').'Los cursos intensivos comienzan los lunes. Elige un inicio disponible; también puedes escribir “el próximo lunes” o una fecha mostrada.';
    }else{
        $message.=($message!==''?"\n\n":'').$next['prompt'];
    }

    $ui=hache_sharky_commercial_control_ui($state,$uiCatalog,$next);
    return hache_sharky_orchestrator_decision('commercial_progress',$message,$ui);
}
