<?php

declare(strict_types=1);

const HACHE_SHARKY_BRAIN_DIAG_MIN_OBS = 50;
const HACHE_SHARKY_BRAIN_DIAG_MIN_AGREEMENT = 90.0;
const HACHE_SHARKY_BRAIN_DIAG_COHORT = 'v3';

/** @return array<string,array{code:string,label:string,blocking:bool}> */
function hache_sharky_brain_diag_actions(): array
{
    return [
        'wait_for_human'=>['code'=>'01','label'=>'Esperar atención humana','blocking'=>true],
        'handoff_policy_exception'=>['code'=>'02','label'=>'Derivar excepción de política','blocking'=>true],
        'serve_teacher'=>['code'=>'03','label'=>'Atender profesor','blocking'=>true],
        'serve_pending_student'=>['code'=>'04','label'=>'Atender inscripción pendiente','blocking'=>true],
        'serve_palapas_restricted'=>['code'=>'05','label'=>'Atender Palapas restringido','blocking'=>true],
        'serve_known_student'=>['code'=>'06','label'=>'Atender alumno conocido','blocking'=>true],
        'handoff_known_student'=>['code'=>'07','label'=>'Derivar alumno conocido','blocking'=>true],
        'close_age_scope'=>['code'=>'08','label'=>'Cerrar por alcance de edad','blocking'=>true],
        'pause_commercial_intent'=>['code'=>'09','label'=>'Pausar intención comercial','blocking'=>true],
        'answer_side_question'=>['code'=>'10','label'=>'Responder duda lateral','blocking'=>false],
        'continue_controlled_flow'=>['code'=>'11','label'=>'Continuar flujo controlado','blocking'=>true],
        'preserve_deterministic_decision'=>['code'=>'12','label'=>'Conservar decisión determinista','blocking'=>true],
        'start_guided_qualification'=>['code'=>'13','label'=>'Iniciar calificación guiada','blocking'=>false],
        'show_commercial_menu'=>['code'=>'14','label'=>'Mostrar menú comercial','blocking'=>false],
        'ask_identity'=>['code'=>'15','label'=>'Preguntar identidad','blocking'=>false],
        'continue_discovery'=>['code'=>'16','label'=>'Continuar descubrimiento','blocking'=>false],
        'answer_user'=>['code'=>'17','label'=>'Responder al usuario','blocking'=>false],
        'unknown'=>['code'=>'00','label'=>'Acción desconocida','blocking'=>true],
    ];
}

/** @return array{action:string,code:string,label:string,blocking:bool} */
function hache_sharky_brain_diag_action(string $action): array
{
    $action=trim($action);
    $all=hache_sharky_brain_diag_actions();
    if(!isset($all[$action]))$action='unknown';
    return ['action'=>$action]+$all[$action];
}

/** @return array{action:string,code:string,label:string,blocking:bool} */
function hache_sharky_brain_diag_action_by_code(string $code): array
{
    foreach(hache_sharky_brain_diag_actions() as $action=>$row){
        if($row['code']===$code)return ['action'=>$action]+$row;
    }
    return hache_sharky_brain_diag_action('unknown');
}

function hache_sharky_brain_diag_observed_metric_key(): string
{
    return 'brain_diag_'.HACHE_SHARKY_BRAIN_DIAG_COHORT.'_observed';
}

function hache_sharky_brain_diag_metric_key(string $liveAction,string $brainAction): string
{
    $live=hache_sharky_brain_diag_action($liveAction);
    $brain=hache_sharky_brain_diag_action($brainAction);
    return 'brain_'.HACHE_SHARKY_BRAIN_DIAG_COHORT.'_mm_'.$live['code'].'_'.$brain['code'];
}

/**
 * Build an aggregate report from privacy-safe daily metric counters.
 *
 * v3 starts when Brain learns the current member-service lanes (Monteverde
 * self-service, Palapas red light, pending records and teacher ownership). Older
 * v1/v2 observations remain historical evidence but cannot satisfy this new
 * activation gate. Observer/pre-state errors remain conservative and continue
 * using the shared brain_diag_error counter, so instrumentation failures still
 * block Fase 2B.
 *
 * @param list<array{date?:string,counters?:array<string,int>}> $metrics
 * @return array<string,mixed>
 */
function hache_sharky_brain_diag_report(array $metrics,int $minObservations=HACHE_SHARKY_BRAIN_DIAG_MIN_OBS,float $minAgreement=HACHE_SHARKY_BRAIN_DIAG_MIN_AGREEMENT): array
{
    $minObservations=max(1,$minObservations);
    $minAgreement=max(0.0,min(100.0,$minAgreement));
    $observed=0;$errors=0;$pairCounts=[];
    $observedKey=hache_sharky_brain_diag_observed_metric_key();
    $pairPattern='/^brain_'.preg_quote(HACHE_SHARKY_BRAIN_DIAG_COHORT,'/').'_mm_(\d{2})_(\d{2})$/';

    foreach($metrics as $day){
        $counters=is_array($day['counters']??null)?$day['counters']:[];
        $observed+=(int)($counters[$observedKey]??0);
        $errors+=(int)($counters['brain_diag_error']??0);
        foreach($counters as $key=>$value){
            if(!is_string($key)||preg_match($pairPattern,$key,$m)!==1)continue;
            $pairCounts[$m[1].'_'.$m[2]]=(int)($pairCounts[$m[1].'_'.$m[2]]??0)+(int)$value;
        }
    }

    $pairs=[];$mismatches=0;$blockingMismatches=0;
    foreach($pairCounts as $pair=>$count){
        if($count<=0)continue;
        [$liveCode,$brainCode]=explode('_',$pair,2);
        $live=hache_sharky_brain_diag_action_by_code($liveCode);
        $brain=hache_sharky_brain_diag_action_by_code($brainCode);
        $blocking=$live['blocking']||$brain['blocking'];
        $mismatches+=$count;
        if($blocking)$blockingMismatches+=$count;
        $pairs[]=[
            'live'=>$live['action'],'live_label'=>$live['label'],
            'brain'=>$brain['action'],'brain_label'=>$brain['label'],
            'count'=>$count,'blocking'=>$blocking,
        ];
    }
    usort($pairs,static fn(array $a,array $b):int=>($b['count']<=>$a['count'])?:strcmp((string)$a['live'],(string)$b['live']));

    $matches=max(0,$observed-$mismatches);
    $agreement=$observed>0?round(($matches/$observed)*100,1):0.0;

    if($errors>0){$status='review_errors';$label='Revisar errores del shadow';}
    elseif($blockingMismatches>0){$status='review_blocking';$label='Revisar divergencias protegidas';}
    elseif($observed<$minObservations){$status='collecting';$label='Acumulando evidencia';}
    elseif($agreement<$minAgreement){$status='review_consistency';$label='Revisar consistencia';}
    else{$status='candidate';$label='Candidato a Fase 2B';}

    return [
        'status'=>$status,'status_label'=>$label,
        'cohort'=>HACHE_SHARKY_BRAIN_DIAG_COHORT,
        'observed'=>$observed,'matches'=>$matches,'mismatches'=>$mismatches,'errors'=>$errors,
        'agreement_pct'=>$agreement,'blocking_mismatches'=>$blockingMismatches,
        'minimum_observations'=>$minObservations,'minimum_agreement_pct'=>$minAgreement,
        'remaining_observations'=>max(0,$minObservations-$observed),
        'pairs'=>$pairs,
        'routing_live'=>false,
    ];
}
