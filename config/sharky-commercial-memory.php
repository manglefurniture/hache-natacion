<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-orchestrator.php';
require_once __DIR__.'/sharky-start-authority.php';

/** Confirmed selections only. Pending preferences never masquerade as catalog ids. */
function hache_sharky_commercial_snapshot(array $state): array
{
    $keys=['program','sede_clave','age','swim_level','plan_id','plan_name','sessions_per_week','plan_price','schedule_id','schedule_label','course_id','fecha_inicio','course_price','kit','date_preference'];
    return array_intersect_key(is_array($state['commercial_context']??null)?$state['commercial_context']:[],array_flip($keys));
}

function hache_sharky_commercial_invalidate(array $state,array $before): array
{
    $c=&$state['commercial_context'];
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

/** Pure reducer; catalog must come from the same current backend as controlled registration. */
function hache_sharky_commercial_capture(array $state,string $text,array $catalog,string $today): array
{
    if(($state['identity']['kind']??'')!=='prospect')return $state;
    if(is_array($state['flow']??null)&&($state['flow']['name']??'')!=='qualify_prospect')return $state;
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
    if(($c['program']??'')==='intensive'&&!str_contains($flat,'?')&&!str_contains($flat,'¿')){
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
    if(empty($c['program']))return ['slot'=>'program','prompt'=>'¿Buscas un curso intensivo o clases regulares?'];
    if(empty($c['sede_clave']))return ['slot'=>'sede','prompt'=>'¿Prefieres Colegio Monteverde o Palapas Protudec?'];
    if($c['program']==='regular'&&empty($c['plan_id']))return ['slot'=>'plan','prompt'=>isset($c['sessions_per_week'])?'¿Qué plan de '.$c['sessions_per_week'].' sesiones prefieres?':'¿Qué plan de clases regulares prefieres?'];
    if(empty($c['schedule_id']))return ['slot'=>'schedule','prompt'=>'¿Qué horario prefieres?'];
    if($c['program']==='intensive'&&empty($c['course_id']))return ['slot'=>'course','prompt'=>'¿Qué fecha de inicio prefieres?'];
    return ['slot'=>'enroll','prompt'=>$c['program']==='regular'?'Puedes continuar tu inscripción con el equipo por este chat.':'Puedes iniciar tu inscripción ahora.'];
}

/** One commercial continuation, never a business mutation or implicit consent. */
function hache_sharky_commercial_reply(array $state,string $answer,array $catalog=[]): array
{
    $next=hache_sharky_commercial_next($state);
    $answer=preg_replace('/¿[^?]*\?/u','',$answer)??$answer;
    $message=trim($answer);$message.=($message!==''?"\n\n":'').$next['prompt'];
    if($next['slot']==='plan'){
        $plans=array_values(array_filter($catalog['plans']??[],static fn(array $p):bool=>!isset($state['commercial_context']['sessions_per_week'])||(int)$p['sesiones_semana']===(int)$state['commercial_context']['sessions_per_week']));
        if($plans)$message.="\n".implode("\n",array_map(static fn(array $p):string=>'• '.$p['nombre'].' ('.$p['sesiones_semana'].' sesiones): $'.number_format((float)$p['precio'],2,'.',','),$plans));
    }
    $ui=[];
    if($next['slot']==='enroll')$ui=['type'=>'buttons','buttons'=>[hache_sharky_orchestrator_button(($state['commercial_context']['program']??'')==='regular'?'action:human':'action:register_intensive','Inscribirme')]];
    return hache_sharky_orchestrator_decision('commercial_progress',$message,$ui);
}
