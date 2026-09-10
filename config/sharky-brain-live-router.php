<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-brain-shadow-runtime.php';

const HACHE_SHARKY_BRAIN_2BA_ENABLED_KEY = 'sharky_brain_2ba_habilitado';
const HACHE_SHARKY_BRAIN_2BA_CANARY_KEY = 'sharky_brain_2ba_canary_pct';
const HACHE_SHARKY_BRAIN_CONVERSATIONAL_ENABLED_KEY = 'sharky_brain_conversacional_habilitado';

/** @return array<string,string> */
function hache_sharky_brain_2ba_config_defaults(): array
{
    // 2B-A remains the proven fallback. The conversational experiment is a
    // separate kill-switchable layer and may be disabled without a rollback.
    return [
        HACHE_SHARKY_BRAIN_2BA_ENABLED_KEY=>'1',
        HACHE_SHARKY_BRAIN_2BA_CANARY_KEY=>'10',
        HACHE_SHARKY_BRAIN_CONVERSATIONAL_ENABLED_KEY=>'1',
    ];
}

function hache_sharky_brain_2ba_config_value_valid(string $key,string $value): bool
{
    if(in_array($key,[HACHE_SHARKY_BRAIN_2BA_ENABLED_KEY,HACHE_SHARKY_BRAIN_CONVERSATIONAL_ENABLED_KEY],true)){
        return in_array($value,['0','1'],true);
    }
    if($key===HACHE_SHARKY_BRAIN_2BA_CANARY_KEY)return ctype_digit($value)&&(int)$value>=0&&(int)$value<=100;
    return false;
}

/** @return list<array{clave:string,valor:string,descripcion:string,tipo:string,etiqueta:string}> */
function hache_sharky_brain_2ba_config_rows(array $values): array
{
    return [
        [
            'clave'=>HACHE_SHARKY_BRAIN_CONVERSATIONAL_ENABLED_KEY,
            'valor'=>(string)($values[HACHE_SHARKY_BRAIN_CONVERSATIONAL_ENABLED_KEY]??'0'),
            'descripcion'=>'ON: abre la conversación para el 100 % de prospectos nuevos. Brain puede conversar libremente, pero registro, pagos, cancelaciones, cambios de datos y demás operaciones sensibles siguen bajo ejecutores determinísticos. OFF: vuelve al comportamiento actual de Sharky/2B-A sin rollback.',
            'tipo'=>'checkbox',
            'etiqueta'=>'Brain conversacional experimental — 100 % prospectos nuevos',
        ],
        [
            'clave'=>HACHE_SHARKY_BRAIN_2BA_ENABLED_KEY,
            'valor'=>(string)($values[HACHE_SHARKY_BRAIN_2BA_ENABLED_KEY]??'0'),
            'descripcion'=>'Kill switch de Fase 2B-A. Solo permite routing guiado de bajo riesgo para prospectos nuevos; nunca conversación abierta ni rutas protegidas.',
            'tipo'=>'checkbox',
            'etiqueta'=>'Brain Fase 2B-A — canary restringido',
        ],
        [
            'clave'=>HACHE_SHARKY_BRAIN_2BA_CANARY_KEY,
            'valor'=>(string)($values[HACHE_SHARKY_BRAIN_2BA_CANARY_KEY]??'0'),
            'descripcion'=>'Porcentaje determinista de prospectos nuevos elegibles para Fase 2B-A (0–100). Solo se usa cuando el Brain conversacional experimental está apagado.',
            'tipo'=>'text',
            'etiqueta'=>'Brain Fase 2B-A — porcentaje canary',
        ],
    ];
}

/** @return array<string,string> */
function hache_sharky_brain_2ba_config(PDO $pdo): array
{
    $values=hache_sharky_brain_2ba_config_defaults();
    try{
        $st=$pdo->prepare('SELECT clave,valor FROM configuracion WHERE clave IN (?,?,?)');
        $st->execute([
            HACHE_SHARKY_BRAIN_2BA_ENABLED_KEY,
            HACHE_SHARKY_BRAIN_2BA_CANARY_KEY,
            HACHE_SHARKY_BRAIN_CONVERSATIONAL_ENABLED_KEY,
        ]);
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
            $key=(string)($row['clave']??'');$value=trim((string)($row['valor']??''));
            if(isset($values[$key])&&hache_sharky_brain_2ba_config_value_valid($key,$value))$values[$key]=$value;
        }
        return $values;
    }catch(Throwable $e){
        // Live Brain is optional. If its config authority is unavailable, fail
        // closed to deterministic/shadow behavior, never to open conversation.
        error_log('[sharky-brain] configuration unavailable; live routing disabled');
        return [
            HACHE_SHARKY_BRAIN_2BA_ENABLED_KEY=>'0',
            HACHE_SHARKY_BRAIN_2BA_CANARY_KEY=>'0',
            HACHE_SHARKY_BRAIN_CONVERSATIONAL_ENABLED_KEY=>'0',
        ];
    }
}

/** @return list<string> */
function hache_sharky_brain_2ba_live_actions(): array
{
    // Phase 2B-A remains unchanged. The conversational experiment is deliberately
    // implemented as a separate layer so OFF restores this exact behavior.
    return ['start_guided_qualification','show_commercial_menu'];
}

function hache_sharky_brain_2ba_enabled(array $business): bool
{
    return trim((string)($business[HACHE_SHARKY_BRAIN_2BA_ENABLED_KEY]??'0'))==='1';
}

function hache_sharky_brain_conversational_enabled(array $business): bool
{
    return trim((string)($business[HACHE_SHARKY_BRAIN_CONVERSATIONAL_ENABLED_KEY]??'0'))==='1';
}

function hache_sharky_brain_2ba_canary_percent(array $business): int
{
    $raw=(string)($business[HACHE_SHARKY_BRAIN_2BA_CANARY_KEY]??'0');
    if(!ctype_digit($raw))return 0;
    return max(0,min(100,(int)$raw));
}

function hache_sharky_brain_2ba_contact_in_canary(string $contact,int $percent): bool
{
    $percent=max(0,min(100,$percent));
    if($percent<=0)return false;
    if($percent>=100)return true;
    $hash=hache_sharky_orchestrator_contact_hash($contact);
    $bucket=(int)(hexdec(substr($hash,0,8))%100);
    return $bucket<$percent;
}

function hache_sharky_brain_2ba_unmatched_prospect(array $state): bool
{
    $identity=is_array($state['identity']??null)?$state['identity']:[];
    return ($identity['kind']??'')==='prospect'
        &&($identity['source']??'')==='whatsapp_unmatched';
}

function hache_sharky_brain_2ba_result_protected(array $result): bool
{
    $state=is_array($result['state']??null)?$result['state']:[];
    if(is_array($state['flow']??null))return true;
    if(is_array($result['action_result']??null))return true;

    $decision=is_array($result['decision']??null)?$result['decision']:[];
    $action=is_array($decision['action']??null)?$decision['action']:[];
    if(($action['type']??'')==='human_takeover')return true;

    // Only generic prospect presentation decisions are eligible for a low-risk
    // Brain correction. Everything concrete stays owned by the current backend.
    return !in_array((string)($decision['kind']??''),[
        'conversation','conversation_identity_prompt','side_question',
        'commercial_progress','commercial_next_action',
    ],true);
}

/**
 * Conversational experiment: remove only the guided qualification rail.
 *
 * Protected flows remain untouched. If the model is unavailable or returns an
 * empty answer, the original deterministic result is returned as an automatic
 * fallback for the same turn.
 */
function hache_sharky_brain_conversational_apply(
    array $beforeState,
    array $result,
    array $event,
    array $business,
    string $contact,
    bool $directChat=true,
    ?int $now=null
): array {
    if(!hache_sharky_brain_conversational_enabled($business))return $result;
    if(!$directChat)return $result;

    $state=is_array($result['state']??null)?$result['state']:[];
    if(!hache_sharky_brain_2ba_unmatched_prospect($state))return $result;
    if(is_array($result['action_result']??null))return $result;

    $decision=is_array($result['decision']??null)?$result['decision']:[];
    $action=is_array($decision['action']??null)?$decision['action']:[];
    if(($action['type']??'')==='human_takeover')return $result;

    $flow=is_array($state['flow']??null)?$state['flow']:null;
    $qualificationFlow=is_array($flow)&&($flow['name']??'')==='qualify_prospect';
    if(is_array($flow)&&!$qualificationFlow)return $result;

    $kind=(string)($decision['kind']??'');
    $softKinds=['conversation','conversation_identity_prompt','side_question','commercial_progress'];

    // Once the guided rail has been removed, keep the existing natural response
    // path. Tagging the state makes OFF able to return this conversation to the
    // exact deterministic qualification flow on the next safe turn.
    if(!$qualificationFlow){
        if(!in_array($kind,$softKinds,true))return $result;
        $now??=time();
        $state['brain_conversational_experiment']=true;
        $state['updated_at']=$now;
        $result['state']=$state;
        $result['_brain_2ba']=['applied'=>true,'action'=>'brain_conversational_passthrough'];
        $result['_brain_conversational']=['applied'=>true,'mode'=>'passthrough'];
        hache_sharky_brain_2ba_metric('brain_conversational_passthrough');
        return $result;
    }

    // The adapter may have processed a synthetic debounce event containing the
    // complete customer burst. Prefer its durable last_user_text over the worker's
    // original fragment so Brain answers the same coalesced turn as deterministic Sharky.
    $message=trim((string)($state['last_user_text']??''));
    if($message==='')$message=trim((string)($event['text']??''));
    if($message===''||!function_exists('hache_sharky_lab_answer')){
        hache_sharky_brain_2ba_metric('brain_conversational_fallback');
        return $result;
    }

    $openState=function_exists('hache_sharky_orchestrator_clear_flow')
        ?hache_sharky_orchestrator_clear_flow($state)
        :array_replace($state,['flow'=>null]);
    $now??=time();
    $openState['brain_conversational_experiment']=true;
    $openState['updated_at']=$now;

    $seed=hache_sharky_orchestrator_decision('conversation','');
    $instruction=hache_sharky_whatsapp_style_instruction($seed,$openState);
    $instruction.="\n\nMODO BRAIN CONVERSACIONAL EXPERIMENTAL: responde primero a lo que realmente preguntó la persona y conduce la conversación con naturalidad. Haz como máximo una pregunta útil por turno cuando haga falta avanzar. Puedes explicar, comparar y cambiar de tema usando únicamente datos confirmados por el contexto de Hache Natación. No inventes precios, horarios, cupos, políticas ni datos del alumno. No afirmes haber ejecutado pagos, inscripciones, cancelaciones, reposiciones, cambios de datos ni ninguna operación sensible: esas acciones pertenecen exclusivamente a los flujos y ejecutores determinísticos del backend. Si una operación requiere un flujo protegido, deja que el backend tome el control.";

    $context=[
        'today'=>function_exists('hache_sharky_lab_today')?hache_sharky_lab_today():date('Y-m-d'),
        'contact'=>$contact,
        'previous_user_text'=>(string)($beforeState['last_user_text']??''),
    ];

    try{
        $answer=hache_sharky_whatsapp_clean_answer((string)hache_sharky_lab_answer($message,$instruction,$openState,$context));
        $answer=hache_sharky_whatsapp_enforce_confirmed_context($answer,$openState);
        $answer=hache_sharky_whatsapp_enforce_no_reintroduction($answer,$openState,$message);
        if(hache_sharky_whatsapp_answer_looks_incomplete($answer))$answer=hache_sharky_whatsapp_incomplete_recovery($openState);
        $answer=trim($answer);
    }catch(Throwable $e){
        error_log('[sharky-brain-conversational] model/guard pipeline failed; deterministic fallback used');
        $answer='';
    }
    if($answer===''){
        hache_sharky_brain_2ba_metric('brain_conversational_fallback');
        return $result;
    }

    $decision=hache_sharky_orchestrator_decision('conversation',$answer);
    $result['state']=$openState;
    $result['decision']=$decision;
    $result['payload']=hache_sharky_whatsapp_render($contact,$decision,$answer);
    $result['action_result']=null;
    $result['_brain_2ba']=['applied'=>true,'action'=>'brain_conversational_open'];
    $result['_brain_conversational']=['applied'=>true,'mode'=>'open'];
    hache_sharky_brain_2ba_metric('brain_conversational_open');
    return $result;
}

/**
 * If the experiment is switched OFF while a prospect is already in open mode,
 * restore the deterministic qualification flow on the next safe conversational
 * turn. Protected operations are never interrupted.
 */
function hache_sharky_brain_conversational_restore(
    array $result,
    string $contact,
    bool $directChat=true,
    ?int $now=null
): array {
    if(!$directChat)return $result;
    $state=is_array($result['state']??null)?$result['state']:[];
    if(($state['brain_conversational_experiment']??false)!==true)return $result;
    if(!hache_sharky_brain_2ba_unmatched_prospect($state))return $result;
    if(is_array($state['flow']??null)||is_array($result['action_result']??null))return $result;

    $decision=is_array($result['decision']??null)?$result['decision']:[];
    $action=is_array($decision['action']??null)?$decision['action']:[];
    if(($action['type']??'')==='human_takeover')return $result;
    if(!in_array((string)($decision['kind']??''),[
        'conversation','conversation_identity_prompt','side_question','commercial_progress',
    ],true))return $result;

    $now??=time();
    unset($state['brain_conversational_experiment']);
    [$state,$decision]=hache_sharky_whatsapp_qualification_start($state,$now);
    $state['updated_at']=$now;
    $result['state']=$state;
    $result['decision']=$decision;
    $result['payload']=hache_sharky_whatsapp_render($contact,$decision);
    $result['action_result']=null;
    $result['_brain_2ba']=['applied'=>true,'action'=>'brain_conversational_restore'];
    $result['_brain_conversational']=['applied'=>false,'fallback'=>'deterministic'];
    hache_sharky_brain_2ba_metric('brain_conversational_restored');
    return $result;
}

/**
 * Pure Phase 2B-A gate. It never sends, persists or mutates business state.
 *
 * @return array{status:string,action:?string,evaluation:?array}
 */
function hache_sharky_brain_2ba_plan(
    array $beforeState,
    array $result,
    array $event,
    array $business,
    string $contact,
    bool $directChat=true
): array {
    if(!hache_sharky_brain_2ba_enabled($business))return ['status'=>'disabled','action'=>null,'evaluation'=>null];
    if(!$directChat)return ['status'=>'excluded_channel','action'=>null,'evaluation'=>null];

    $state=is_array($result['state']??null)?$result['state']:[];
    if(!hache_sharky_brain_2ba_unmatched_prospect($state))return ['status'=>'excluded_identity','action'=>null,'evaluation'=>null];

    $pct=hache_sharky_brain_2ba_canary_percent($business);
    if(!hache_sharky_brain_2ba_contact_in_canary($contact,$pct))return ['status'=>'outside_canary','action'=>null,'evaluation'=>null];

    // Protected live results are an absolute barrier. They never enter the live
    // Brain comparator, even when shadow already agrees with the current route.
    if(hache_sharky_brain_2ba_result_protected($result))return ['status'=>'blocked_protected','action'=>null,'evaluation'=>null];

    $evaluation=hache_sharky_brain_shadow_evaluate($beforeState,$state,$event,$result,true);
    $brain=is_array($evaluation['brain']??null)?$evaluation['brain']:[];
    $action=(string)($brain['action']??'');

    // If current live already made the same eligible routing choice, leave its
    // exact payload/state untouched instead of rewriting an aligned result.
    if(($evaluation['match']??false)===true)return ['status'=>'aligned','action'=>$action,'evaluation'=>$evaluation];

    // These are intentionally explicit so a future Brain change cannot silently
    // turn Phase 2B-A into an open conversational router.
    if(in_array($action,['answer_user','continue_discovery'],true)){
        return ['status'=>'blocked_open_conversation','action'=>$action,'evaluation'=>$evaluation];
    }
    if(!in_array($action,hache_sharky_brain_2ba_live_actions(),true)){
        return ['status'=>'blocked_action','action'=>$action,'evaluation'=>$evaluation];
    }

    if($action==='start_guided_qualification'){
        if(is_array($state['flow']??null)||($state['identity']['kind']??'')!=='prospect'){
            return ['status'=>'blocked_invariant','action'=>$action,'evaluation'=>$evaluation];
        }
        return ['status'=>'apply','action'=>$action,'evaluation'=>$evaluation];
    }

    if($action==='show_commercial_menu'){
        if(is_array($state['flow']??null)||!hache_sharky_whatsapp_commercial_ready($state)){
            return ['status'=>'blocked_invariant','action'=>$action,'evaluation'=>$evaluation];
        }
        return ['status'=>'apply','action'=>$action,'evaluation'=>$evaluation];
    }

    return ['status'=>'blocked_action','action'=>$action,'evaluation'=>$evaluation];
}

function hache_sharky_brain_2ba_metric(string $key): void
{
    if(function_exists('hache_sharky_metric_increment'))hache_sharky_metric_increment($key);
}

/**
 * Apply the conversational experiment first. When OFF, restore any previously
 * open prospect and then execute the original Phase 2B-A router unchanged.
 */
function hache_sharky_brain_2ba_apply(
    array $beforeState,
    array $result,
    array $event,
    array $business,
    string $contact,
    bool $directChat=true,
    ?int $now=null
): array {
    if(hache_sharky_brain_conversational_enabled($business)){
        return hache_sharky_brain_conversational_apply(
            $beforeState,$result,$event,$business,$contact,$directChat,$now
        );
    }

    $restored=hache_sharky_brain_conversational_restore($result,$contact,$directChat,$now);
    if(($restored['_brain_conversational']['fallback']??'')==='deterministic')return $restored;

    $plan=hache_sharky_brain_2ba_plan($beforeState,$result,$event,$business,$contact,$directChat);
    $status=(string)($plan['status']??'blocked_action');

    if(!in_array($status,['disabled','excluded_channel','excluded_identity','outside_canary'],true)){
        hache_sharky_brain_2ba_metric('brain_live_2ba_eligible');
    }
    if($status==='aligned'){
        hache_sharky_brain_2ba_metric('brain_live_2ba_aligned');
        return $result;
    }
    if($status!=='apply'){
        if(str_starts_with($status,'blocked_'))hache_sharky_brain_2ba_metric('brain_live_2ba_blocked');
        if($status==='blocked_open_conversation')hache_sharky_brain_2ba_metric('brain_live_2ba_open_conversation_blocked');
        return $result;
    }

    $state=is_array($result['state']??null)?$result['state']:[];
    $action=(string)($plan['action']??'');
    $now??=time();

    if($action==='start_guided_qualification'){
        [$state,$decision]=hache_sharky_whatsapp_qualification_start($state,$now);
    }elseif($action==='show_commercial_menu'){
        $decision=hache_sharky_whatsapp_commercial_next_action($state,'Perfecto. Continuemos con lo que ya elegiste.');
    }else{
        hache_sharky_brain_2ba_metric('brain_live_2ba_blocked');
        return $result;
    }

    $state['updated_at']=$now;
    $result['state']=$state;
    $result['decision']=$decision;
    $result['payload']=hache_sharky_whatsapp_render($contact,$decision);
    $result['action_result']=null;
    $result['_brain_2ba']=['applied'=>true,'action'=>$action];
    hache_sharky_brain_2ba_metric('brain_live_2ba_applied');
    hache_sharky_brain_2ba_metric('brain_live_2ba_applied_'.$action);
    return $result;
}