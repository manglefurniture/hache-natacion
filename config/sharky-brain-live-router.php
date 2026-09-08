<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-brain-shadow-runtime.php';

const HACHE_SHARKY_BRAIN_2BA_ENABLED_KEY = 'sharky_brain_2ba_habilitado';
const HACHE_SHARKY_BRAIN_2BA_CANARY_KEY = 'sharky_brain_2ba_canary_pct';

/** @return array<string,string> */
function hache_sharky_brain_2ba_config_defaults(): array
{
    // The candidate gate has already been satisfied; start with a deliberately
    // small cohort. Both values remain kill-switchable from Sharky admin.
    return [
        HACHE_SHARKY_BRAIN_2BA_ENABLED_KEY=>'1',
        HACHE_SHARKY_BRAIN_2BA_CANARY_KEY=>'10',
    ];
}

function hache_sharky_brain_2ba_config_value_valid(string $key,string $value): bool
{
    if($key===HACHE_SHARKY_BRAIN_2BA_ENABLED_KEY)return in_array($value,['0','1'],true);
    if($key===HACHE_SHARKY_BRAIN_2BA_CANARY_KEY)return ctype_digit($value)&&(int)$value>=0&&(int)$value<=100;
    return false;
}

/** @return list<array{clave:string,valor:string,descripcion:string,tipo:string,etiqueta:string}> */
function hache_sharky_brain_2ba_config_rows(array $values): array
{
    return [
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
            'descripcion'=>'Porcentaje determinista de prospectos nuevos elegibles para Fase 2B-A (0–100).',
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
        $st=$pdo->prepare('SELECT clave,valor FROM configuracion WHERE clave IN (?,?)');
        $st->execute([HACHE_SHARKY_BRAIN_2BA_ENABLED_KEY,HACHE_SHARKY_BRAIN_2BA_CANARY_KEY]);
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
            $key=(string)($row['clave']??'');$value=trim((string)($row['valor']??''));
            if(isset($values[$key])&&hache_sharky_brain_2ba_config_value_valid($key,$value))$values[$key]=$value;
        }
        return $values;
    }catch(Throwable $e){
        // Fase live is optional. If its config authority is unavailable, the only
        // safe behavior is shadow-only, never "default enabled" through an error.
        error_log('[sharky-brain-2ba] configuration unavailable; live routing disabled');
        return [HACHE_SHARKY_BRAIN_2BA_ENABLED_KEY=>'0',HACHE_SHARKY_BRAIN_2BA_CANARY_KEY=>'0'];
    }
}

/** @return list<string> */
function hache_sharky_brain_2ba_live_actions(): array
{
    // Phase 2B-A may only push a prospect toward existing deterministic UI.
    // Open conversation/discovery deliberately remain shadow-only.
    return ['start_guided_qualification','show_commercial_menu'];
}

function hache_sharky_brain_2ba_enabled(array $business): bool
{
    return trim((string)($business[HACHE_SHARKY_BRAIN_2BA_ENABLED_KEY]??'0'))==='1';
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
 * Apply only a pre-approved deterministic executor. The model never authors a
 * message here; existing WhatsApp decisions/buttons remain the presentation layer.
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
