<?php
declare(strict_types=1);

require_once __DIR__.'/profesor-actividad.php';

/** @return array<string,mixed> */
function hache_profesor_actividad_evidence_from_context(array $ctx): array
{
    $single=[];$shared=[];$substitutions=[];$activeSubs=[];$annulledSubs=[];$incidents=[];$unattributed=[];
    $inactiveWithHistory=0;$plannedWithSource=0;$confirmedWithSource=0;
    $period=$ctx['periodo']??null;

    foreach(($ctx['profesores']??[]) as $profesor){
        $sessions=is_array($profesor['sesiones']??null)?$profesor['sesiones']:[];
        if((int)($profesor['activo']??1)===0&&count($sessions)>0)$inactiveWithHistory++;

        $planned=$profesor['carga_prevista']??[];
        $realized=$profesor['carga_realizada']??[];
        $hasPeriod=is_array($profesor['periodo']??null)&&($profesor['periodo']??null)===$period;
        if($hasPeriod&&(int)($planned['sesiones']??0)>0&&trim((string)($planned['fuente']??''))!=='')$plannedWithSource++;
        if($hasPeriod&&(int)($realized['sesiones_confirmadas']??0)>0&&trim((string)($realized['fuente_confirmada']??''))!=='')$confirmedWithSource++;

        foreach($sessions as $session){
            $sid=trim((string)($session['sesion_id']??''));
            if($sid==='')continue;
            if(($session['asignacion_prevista']??false)===true){
                if(($session['docencia_compartida']??false)===true)$shared[$sid]=true;
                else $single[$sid]=true;
            }
            if(
                (string)($session['estado']??'')==='REALIZADA'
                &&($session['asignacion_prevista']??false)===true
                &&array_key_exists('imparticion_confirmada',$session)
                &&$session['imparticion_confirmada']===null
            )$unattributed[$sid]=true;

            $incident=$session['incidencia']??null;
            if(is_array($incident)){
                $iid=trim((string)($incident['id']??''));
                if($iid!=='')$incidents[$iid]=true;
            }

            foreach(($session['sustituciones']??[]) as $sub){
                if(!is_array($sub))continue;
                $id=trim((string)($sub['id']??''));
                if($id==='')continue;
                $substitutions[$id]=true;
                $state=strtoupper(trim((string)($sub['estado']??'')));
                if($state==='ACTIVA')$activeSubs[$id]=true;
                elseif($state==='ANULADA')$annulledSubs[$id]=true;
            }
        }
    }

    $requirements=[
        'single_teacher_class_observed'=>count($single)>0,
        'co_teaching_observed'=>count($shared)>0,
        'substitution_observed'=>count($substitutions)>0,
        'individual_incident_observed'=>count($incidents)>0,
        'inactive_professor_history_observed'=>$inactiveWithHistory>0,
        'load_with_source_and_period_observed'=>$plannedWithSource>0||$confirmedWithSource>0,
        'insufficient_evidence_without_reconstruction_observed'=>count($unattributed)>0,
        'history_precoverage_reconstructed'=>($ctx['cobertura']['historia_previa_reconstruida']??null)!==false,
    ];

    return [
        'period'=>$period,
        'coverage'=>$ctx['cobertura']??null,
        'professor_count'=>count($ctx['profesores']??[]),
        'counts'=>[
            'single_teacher_sessions'=>count($single),
            'co_teaching_sessions'=>count($shared),
            'substitution_records'=>count($substitutions),
            'active_substitution_records'=>count($activeSubs),
            'annulled_substitution_records'=>count($annulledSubs),
            'individual_incidents'=>count($incidents),
            'inactive_professors_with_history'=>$inactiveWithHistory,
            'professors_with_planned_load_source'=>$plannedWithSource,
            'professors_with_confirmed_load_source'=>$confirmedWithSource,
            'realized_sessions_without_attribution'=>count($unattributed),
        ],
        'requirements'=>$requirements,
        'decision'=>'HUMAN_REVIEW_REQUIRED',
    ];
}

/** @return array<string,mixed> */
function hache_profesor_actividad_operational_evidence(PDO $pdo,?DateTimeImmutable $now=null): array
{
    try{
        if(!hache_profesor_actividad_schema_ready($pdo)){
            return ['present'=>false,'evidence_status'=>'SCHEMA_NOT_READY','decision'=>'HUMAN_REVIEW_REQUIRED'];
        }
        $tz=new DateTimeZone('America/Cancun');
        $utc=new DateTimeZone('UTC');
        $now=($now??new DateTimeImmutable('now',$tz))->setTimezone($tz);
        $coverage=(string)$pdo->query("SELECT valor FROM configuracion WHERE clave='profesores_asignaciones_cobertura_desde' LIMIT 1")->fetchColumn();
        $coverageUtc=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$coverage,$utc);
        if(!$coverageUtc||$coverageUtc->format('Y-m-d H:i:s')!==$coverage){
            return ['present'=>false,'evidence_status'=>'COVERAGE_UNAVAILABLE','decision'=>'HUMAN_REVIEW_REQUIRED'];
        }

        $floor=$now->modify('-62 days')->format('Y-m-d');
        $coverageDay=$coverageUtc->setTimezone($tz)->format('Y-m-d');
        $today=$now->format('Y-m-d');
        $desde=max($floor,$coverageDay);
        if($desde>$today)$desde=$today;

        $ctx=hache_profesor_actividad_contexto($pdo,$desde,$today);
        return ['present'=>true,'evidence_status'=>'AVAILABLE']+hache_profesor_actividad_evidence_from_context($ctx);
    }catch(Throwable $e){
        return ['present'=>false,'evidence_status'=>'COLLECTION_FAILED','decision'=>'HUMAN_REVIEW_REQUIRED'];
    }
}
