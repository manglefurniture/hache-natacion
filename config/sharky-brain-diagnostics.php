<?php

declare(strict_types=1);

const HACHE_SHARKY_BRAIN_DIAG_MIN_OBS = 50;
const HACHE_SHARKY_BRAIN_DIAG_MIN_AGREEMENT = 90.0;

/** @return array<string,array{code:string,label:string,blocking:bool}> */
function hache_sharky_brain_diag_actions(): array
{
    return [
        'wait_for_human'=>['code'=>'01','label'=>'Esperar atención humana','blocking'=>true],
        'handoff_known_student'=>['code'=>'02','label'=>'Derivar alumno conocido','blocking'=>true],
        'close_age_scope'=>['code'=>'03','label'=>'Cerrar por alcance de edad','blocking'=>true],
        'pause_commercial_intent'=>['code'=>'04','label'=>'Pausar intención comercial','blocking'=>true],
        'handoff_policy_exception'=>['code'=>'05','label'=>'Derivar excepción de política','blocking'=>true],
        'answer_side_question'=>['code'=>'06','label'=>'Responder duda lateral','blocking'=>false],
        'continue_controlled_flow'=>['code'=>'07','label'=>'Continuar flujo controlado','blocking'=>true],
        'preserve_deterministic_decision'=>['code'=>'08','label'=>'Conservar decisión determinista','blocking'=>true],
        'start_guided_qualification'=>['code'=>'09','label'=>'Iniciar calificación guiada','blocking'=>false],
        'show_commercial_menu'=>['code'=>'10','label'=>'Mostrar menú comercial','blocking'=>false],
        'ask_identity'=>['code'=>'11','label'=>'Preguntar identidad','blocking'=>false],
        'continue_discovery'=>['code'=>'12','label'=>'Continuar descubrimiento','blocking'=>false],
        'answer_user'=>['code'=>'13','label'=>'Responder al usuario','blocking'=>false],
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

function hache_sharky_brain_diag_metric_key(string $liveAction,string $brainAction): string
{
    $live=hache_sharky_brain_diag_action($liveAction);
    $brain=hache_sharky_brain_diag_action($brainAction);
    return 'brain_mm_'.$live['code'].'_'.$brain['code'];
}

/**
 * Build an aggregate report from the same privacy-safe daily metric counters
 * already used by the Sharky admin panel. No conversation content is needed.
 *
 * @param list<array{date?:string,counters?:array<string,int>}> $metrics
 * @return array<string,mixed>
 */
function hache_sharky_brain_diag_report(array $metrics,int $minObservations=HACHE_SHARKY_BRAIN_DIAG_MIN_OBS,float $minAgreement=HACHE_SHARKY_BRAIN_DIAG_MIN_AGREEMENT): array
{
    $minObservations=max(1,$minObservations);
    $minAgreement=max(0.0,min(100.0,$minAgreement));
    $observed=0;$errors=0;$pairCounts=[];

    foreach($metrics as $day){
        $counters=is_array($day['counters']??null)?$day['counters']:[];
        $observed+=(int)($counters['brain_diag_observed']??0);
        $errors+=(int)($counters['brain_diag_error']??0);
        foreach($counters as $key=>$value){
            if(!is_string($key)||preg_match('/^brain_mm_(\d{2})_(\d{2})$/',$key,$m)!==1)continue;
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

    // Each successful observation contributes exactly one match OR one mismatch.
    $matches=max(0,$observed-$mismatches);
    $agreement=$observed>0?round(($matches/$observed)*100,1):0.0;

    if($errors>0){$status='review_errors';$label='Revisar errores del shadow';}
    elseif($blockingMismatches>0){$status='review_blocking';$label='Revisar divergencias protegidas';}
    elseif($observed<$minObservations){$status='collecting';$label='Acumulando evidencia';}
    elseif($agreement<$minAgreement){$status='review_consistency';$label='Revisar consistencia';}
    else{$status='candidate';$label='Candidato a Fase 2B';}

    return [
        'status'=>$status,'status_label'=>$label,
        'observed'=>$observed,'matches'=>$matches,'mismatches'=>$mismatches,'errors'=>$errors,
        'agreement_pct'=>$agreement,'blocking_mismatches'=>$blockingMismatches,
        'minimum_observations'=>$minObservations,'minimum_agreement_pct'=>$minAgreement,
        'remaining_observations'=>max(0,$minObservations-$observed),
        'pairs'=>$pairs,
        'routing_live'=>false,
    ];
}
