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

function hache_sharky_commercial_invalidate(array $state,array $before): array
{
    $c=&$state['commercial_context'];
    if(($before['swim_level']??null)!==($c['swim_level']??null)){
        unset($c['background'],$c['recommended_program']);
    }
    if(($before['program']??null)!==($c['program']??null)||($before['sede_clave']??null)!==($c['sede_clave']??null)){
        foreach(['plan_id','plan_name','sessions_per_week','plan_price','schedule_id','schedule_label','course_id','fecha_inicio','course_price','date_preference'] as $key)unset($c[$key]);
    }
    return $state;
}

function hache_sharky_commercial_catalog(PDO $pdo,array $state,array $context): array
{
    $c=$state['commercial_context']??[];$sede=(string)($c['sede_clave']??'');$program=(string)($c['program']??'');
    $out=['plans'=>[],'schedules'=>[],'courses'=>[]];
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
    return null;
}

function hache_sharky_commercial_recommendation_affirmation(string $text): bool
{
    if(str_contains($text,'?')||str_contains($text,'¿'))return false;
    $t=hache_sharky_orchestrator_normalize($text);
    return preg_match('/^(?:si|si\s+quiero|claro|claro\s+que\s+si|ok|oki|okay|vale|va|dale|de\s+acuerdo|esta\s+bien|me\s+parece\s+bien)[!. ]*$/u',$t)===1;
}

/**
 * Keeps recommendation memory distinct from a confirmed program and makes sure
 * an unresolved recommendation always has a live guided step. This prevents a
 * model-written recommendation from becoming conversational memory only.
 */
function hache_sharky_commercial_reconcile_guidance(array $state,string $text): array
{
    $c=&$state['commercial_context'];
    $flow=is_array($state['flow']??null)?$state['flow']:null;
    $flowName=(string)($flow['name']??'');$flowStep=(string)($flow['step']??'');
    $backgroundEligible=$flowName==='qualify_prospect'&&$flowStep==='background';
    if(!$backgroundEligible&&!is_array($flow)&&($c['swim_level']??null)==='swims'&&empty($c['program']))$backgroundEligible=true;

    if($backgroundEligible){
        $background=hache_sharky_commercial_background_choice($text);
        if($background!==null){
            $c['background']=$background;
            if(in_array($background,['self_taught','no_formal'],true))$c['recommended_program']='intensive';
            elseif($background==='formal'&&in_array(($c['entry_interest']??null),['intensive','regular'],true))$c['recommended_program']=$c['entry_interest'];
            if(empty($c['program'])){
                $state=hache_sharky_orchestrator_flow($state,'qualify_prospect','program',[
                    'recommended_program'=>$c['recommended_program']??null,
                    'background'=>$background,
                    'preferred_program'=>$c['entry_interest']??null,
                ],(int)($state['updated_at']??time()));
                $flow=$state['flow'];$flowName='qualify_prospect';$flowStep='program';
            }
        }
    }

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
    return $state;
}

function hache_sharky_commercial_relative_date_question(string $text): bool
{
    $t=hache_sharky_orchestrator_normalize($text);
    if(preg_match('/\b(?:proximo\s+lunes|lunes\s+que\s+viene)\b/u',$t)!==1)return false;
    return str_contains($text,'?')||str_contains($text,'¿')
        ||preg_match('/\b(?:no\s+se|dime|decirme|saber|cual|que)\b.{0,36}\b(?:fecha|dia|cuando)\b/u',$t)===1
        ||preg_match('/\b(?:que\s+dia|que\s+fecha|cuando)\s+(?:es|cae)\b/u',$t)===1;
}

function hache_sharky_commercial_human_date(string $iso): string
{
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$iso,new DateTimeZone('America/Cancun'));
    if(!$d||$d->format('Y-m-d')!==$iso)return $iso;
    $months=[1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'];
    return (int)$d->format('j').' de '.$months[(int)$d->format('n')].' de '.$d->format('Y');
}

/** Pure reducer; catalog must come from the same current backend as controlled registration. */
function hache_sharky_commercial_capture(array $state,string $text,array $catalog,string $today): array
{
    if(($state['identity']['kind']??'')!=='prospect')return $state;
    if(is_array($state['flow']??null)&&($state['flow']['name']??'')!=='qualify_prospect')return $state;
    $state=hache_sharky_commercial_reconcile_guidance($state,$text);
    $c=&$state['commercial_context'];
    foreach(hache_sharky_orchestrator_text_segments($text) as $line){
        $t=hache_sharky_orchestrator_normalize($line);
        if(str_contains($line,'?')||str_contains($line,'¿'))continue;
        if(preg_match('/\b(?:ya\s+)?tengo\s+(?:mi\s+)?gorro\s+y\s+goggles\b/u',$t))$c['kit']=['gorro'=>true,'goggles'=>true];
        if(($c['program']??'')==='regular'){
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
            $matches=array_values(array_filter($catalog['schedules']??[],static fn(array $s):bool=>substr((string)$s['label'],0,5)===$start&&($end===null||substr((string)$s['label'],-5)===$end)));
            if(count($matches)===1){$c['schedule_id']=(string)$matches[0]['id'];$c['schedule_label']=(string)$matches[0]['label'];}
            else {unset($c['schedule_id'],$c['schedule_label']);}
        }
    }

    // Join a date split across messages without treating a month/year as a day.
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
            }
        }elseif(preg_match('/^(?:de\s+)?(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre)(?:\s+(?:de\s+)?(\d{4}))?[.! ]*$/u',$flat,$m)){
            unset($c['course_id'],$c['fecha_inicio'],$c['course_price']);
            $c['date_preference']=['month'=>$m[1],'year'=>isset($m[2])?(int)$m[2]:null];
        }elseif(preg_match('/^\d{4}$/',$flat)&&is_array($c['date_preference']??null)){
            unset($c['course_id'],$c['fecha_inicio'],$c['course_price']);
            $c['date_preference']['year']=(int)$flat;
        }
    }
    return $state;
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
    $next=hache_sharky_commercial_next($state);
    $answer=preg_replace('/¿[^?]*\?/u','',$answer)??$answer;
    $message=trim($answer);

    if($next['slot']==='plan'){
        $message.=($message!==''?"\n\n":'').$next['prompt'];
        $plans=array_values(array_filter($catalog['plans']??[],static fn(array $p):bool=>!isset($state['commercial_context']['sessions_per_week'])||(int)$p['sesiones_semana']===(int)$state['commercial_context']['sessions_per_week']));
        if($plans)$message.="\n".implode("\n",array_map(static fn(array $p):string=>'• '.$p['nombre'].' ('.$p['sesiones_semana'].' sesiones): $'.number_format((float)$p['precio'],2,'.',','),$plans));
    }elseif($next['slot']==='schedule'){
        $message.=($message!==''?"\n\n":'').'Horarios disponibles:';
        $schedules=array_slice(array_values($catalog['schedules']??[]),0,10);
        if($schedules)$message.="\n".implode("\n",array_map(static fn(array $s):string=>'• '.(string)($s['label']??''),$schedules));
        $message.="\n\n".'Puedes escribir el horario tal como aparece, por ejemplo “8 a 9”.';
    }elseif($next['slot']==='course'){
        $courses=array_slice(array_values($catalog['courses']??[]),0,10);
        $message.=($message!==''?"\n\n":'').'Los cursos intensivos comienzan los lunes. Inicios disponibles:';
        if($courses)$message.="\n".implode("\n",array_map(static fn(array $o):string=>'• Lunes '.hache_sharky_commercial_human_date((string)($o['fecha_inicio']??'')),$courses));
        $message.="\n\n".'Puedes escribir “el próximo lunes”, “el 14” o una de las fechas mostradas.';
    }else{
        $message.=($message!==''?"\n\n":'').$next['prompt'];
    }

    $ui=[];
    if($next['slot']==='enroll')$ui=['type'=>'buttons','buttons'=>[hache_sharky_orchestrator_button(($state['commercial_context']['program']??'')==='regular'?'action:human':'action:register_intensive','Inscribirme')]];
    return hache_sharky_orchestrator_decision('commercial_progress',$message,$ui);
}
