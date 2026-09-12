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

    return !in_array((string)($decision['kind']??''),[
        'conversation','conversation_identity_prompt','side_question',
        'commercial_progress','commercial_next_action',
    ],true);
}

function hache_sharky_brain_conversational_explicit_pause(string $text): bool
{
    if(str_contains($text,'?')||str_contains($text,'¿'))return false;
    $t=hache_sharky_orchestrator_normalize($text);
    $t=preg_replace('/\s+/u',' ',trim($t))??trim($t);
    if($t==='')return false;
    if(preg_match('/\b(?:dejame|deje|permiteme)\s+(?:analizar|pensar|revisar|checar)\b/u',$t)===1)return true;
    if(preg_match('/\b(?:lo|esto|eso|me\s+lo)\s+voy\s+a\s+(?:analizar|pensar|revisar|checar)\b/u',$t)===1)return true;
    return preg_match('/^(?:voy\s+a\s+pensarlo|lo\s+pienso\s+y\s+te\s+(?:digo|aviso|confirmo)|dejame\s+pensarlo)(?:\s+por\s+favor)?[.! ]*$/u',$t)===1;
}

function hache_sharky_brain_conversational_ambiguous_numeric_age_drift(array $beforeState,array $state,string $message): bool
{
    $t=hache_sharky_orchestrator_normalize($message);
    $t=preg_replace('/\s+/u',' ',trim($t))??trim($t);
    if(preg_match('/^\d{1,3}[.! ]*$/u',$t)!==1)return false;

    $beforeAge=$beforeState['commercial_context']['age']??null;
    $afterAge=$state['commercial_context']['age']??null;
    if($beforeAge!==null||!is_int($afterAge))return false;

    $beforeFlow=is_array($beforeState['flow']??null)?$beforeState['flow']:null;
    if(is_array($beforeFlow)&&($beforeFlow['name']??'')!=='qualify_prospect')return false;
    return true;
}

function hache_sharky_brain_conversational_strip_opening_filler(string $answer): string
{
    $answer=trim($answer);if($answer==='')return '';
    $lines=preg_split('/\R/u',$answer)?:[];
    $removed=0;
    while($lines&&$removed<3){
        $line=trim((string)$lines[0]);
        if($line===''){array_shift($lines);continue;}
        $plain=preg_replace('/[^\p{L}\p{N}\s]/u',' ',$line)??$line;
        $plain=hache_sharky_orchestrator_normalize($plain);
        $plain=preg_replace('/\s+/u',' ',trim($plain))??trim($plain);
        if(!in_array($plain,['hola','claro','con gusto','por supuesto','perfecto','genial'],true))break;
        array_shift($lines);$removed++;
    }
    $clean=trim(implode("\n",$lines));
    return $clean!==''?$clean:$answer;
}

function hache_sharky_brain_conversational_intensive_offer_guard(string $answer,array $state,array $business,string $qualificationStep): string
{
    if($qualificationStep!=='sede'||($state['commercial_context']['program']??null)!=='intensive')return $answer;
    $raw=$business['sharky_precio_intensivo']??1200;
    $price=is_numeric($raw)?max(0,min(100000,(int)$raw)):1200;
    $priceText=number_format($price,0,'.',',');
    $canonical='curso intensivo (3 semanas, lunes a viernes, $'.$priceText.' MXN)';
    $replaced=preg_replace('/\bcurso\s+intensivo(?:\s+b[aá]sico)?(?:\s*\([^)]*\))?/iu',$canonical,$answer,1,$count);
    if(is_string($replaced)&&$count>0)return $replaced;
    return '🏊 Curso intensivo: 3 semanas, lunes a viernes, $'.$priceText." MXN.\n\n".$answer;
}

function hache_sharky_brain_conversational_pause_result(array $state,array $result,string $contact,?int $now=null): array
{
    $now??=time();
    if(function_exists('hache_sharky_whatsapp_mark_followup_paused')){
        $state=hache_sharky_whatsapp_mark_followup_paused($state,$now,'brain_explicit_pause');
    }else{
        if(!is_array($state['commercial_context']??null))$state['commercial_context']=[];
        $state['commercial_context']['_idle_followup']=['status'=>'completed_optout','user_turn_at'=>$now,'completed_at'=>$now,'pause_reason'=>'brain_explicit_pause'];
    }
    $state['brain_conversational_experiment']=true;
    $state['updated_at']=$now;
    $decision=hache_sharky_orchestrator_decision('flow_paused','Claro 😊 Tómate tu tiempo. Cuando quieras retomar, seguimos desde aquí.');
    $result['state']=$state;
    $result['decision']=$decision;
    $result['payload']=hache_sharky_whatsapp_render($contact,$decision);
    $result['action_result']=null;
    $result['_brain_2ba']=['applied'=>true,'action'=>'brain_conversational_pause'];
    $result['_brain_conversational']=['applied'=>true,'mode'=>'paused'];
    hache_sharky_brain_2ba_metric('brain_conversational_pause');
    return $result;
}

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

    $message=trim((string)($state['last_user_text']??''));
    if($message==='')$message=trim((string)($event['text']??''));
    $ambiguousAgeDrift=hache_sharky_brain_conversational_ambiguous_numeric_age_drift($beforeState,$state,$message);
    if($ambiguousAgeDrift){
        $state['commercial_context']['age']=$beforeState['commercial_context']['age']??null;
        $decisionBeforeRepair=is_array($result['decision']??null)?$result['decision']:[];
        if(($decisionBeforeRepair['kind']??'')==='prospect_age_rejected'){
            $state['flow']=$beforeState['flow']??null;
        }
        $result['state']=$state;
        hache_sharky_brain_2ba_metric('brain_conversational_ambiguous_age_reverted');
    }

    if(is_array($result['action_result']??null))return $result;

    $decision=is_array($result['decision']??null)?$result['decision']:[];
    $action=is_array($decision['action']??null)?$decision['action']:[];
    if(($action['type']??'')==='human_takeover')return $result;

    $flow=is_array($state['flow']??null)?$state['flow']:null;
    $qualificationFlow=is_array($flow)&&($flow['name']??'')==='qualify_prospect';
    if(is_array($flow)&&!$qualificationFlow)return $result;

    // A semantic qualification cursor is not a protected business operation.
    // Explicit deliberation must still pause the sales push while preserving the
    // cursor for a later resume.
    if(hache_sharky_brain_conversational_explicit_pause($message)){
        return hache_sharky_brain_conversational_pause_result($state,$result,$contact,$now);
    }

    $kind=(string)($decision['kind']??'');
    $softKinds=['conversation','conversation_identity_prompt','side_question','commercial_progress'];

    if(!$qualificationFlow&&!$ambiguousAgeDrift){
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

    if($message===''||!function_exists('hache_sharky_lab_answer')){
        hache_sharky_brain_2ba_metric('brain_conversational_fallback');
        return $result;
    }

    $openState=$state;
    $qualificationStep=$qualificationFlow?(string)($flow['step']??''):'';
    $now??=time();
    $openState['brain_conversational_experiment']=true;
    $openState['updated_at']=$now;

    $seed=hache_sharky_orchestrator_decision('conversation','');
    $instruction=hache_sharky_whatsapp_style_instruction($seed,$openState);
    $instruction.="\n\nMODO BRAIN CONVERSACIONAL EXPERIMENTAL: responde primero a lo que realmente preguntó la persona y conduce la conversación con naturalidad. Haz como máximo una pregunta útil por turno cuando haga falta avanzar. En el primer turno no saludes ni añadas muletillas como ‘¡Claro!’ o ‘¡Con gusto!’: la presentación de Sharky se agrega de forma determinística aparte. Si commercial_context.entry_source es meta_ad y entry_interest es intensive, considera el curso intensivo como el tema actual y no preguntes intensivo vs. clases regulares salvo que la persona cambie explícitamente de interés. Mantén la respuesta breve y móvil: no vuelques todos los horarios, fechas o variantes cuando basta un resumen. Un número aislado es ambiguo: no lo conviertas en edad por tu cuenta; resuélvelo con el contexto conversacional anterior y, si sigue siendo ambiguo, pregunta brevemente qué significa. Solo trata una edad como confirmada cuando la persona la expresa inequívocamente (por ejemplo, ‘tengo 62 años’). Puedes explicar, comparar y cambiar de tema usando únicamente datos confirmados por el contexto de Hache Natación. No inventes precios, horarios, cupos, políticas ni datos del alumno. No afirmes haber ejecutado pagos, inscripciones, cancelaciones, reposiciones, cambios de datos ni ninguna operación sensible: esas acciones pertenecen exclusivamente a los flujos y ejecutores determinísticos del backend. Si una operación requiere un flujo protegido, deja que el backend tome el control.";
    if($qualificationStep==='swim')$instruction.="\nPaso pendiente de calificación: NIVEL. Si hace falta avanzar, pregunta únicamente si ya sabe nadar o empieza desde cero; no le pidas elegir producto todavía.";
    elseif($qualificationStep==='background')$instruction.="\nPaso pendiente de calificación: FORMACIÓN PREVIA. Si hace falta avanzar, pregunta únicamente si ha tomado clases formales de natación o aprendió por su cuenta. No repitas esta pregunta si commercial_context.background ya está confirmado.";
    elseif($qualificationStep==='program')$instruction.="\nPaso pendiente de calificación: PROGRAMA. Solo una persona con formación formal confirmada puede elegir regulares automáticamente.";
    elseif($qualificationStep==='sede')$instruction.="\nPaso pendiente de calificación: SEDE. Conserva el programa ya confirmado y pregunta únicamente qué sede prefiere si todavía no la indicó.";
    elseif($qualificationStep==='daypart')$instruction.="\nPaso pendiente de calificación: TURNO. Conserva programa y sede; pregunta únicamente matutino o vespertino si aún falta esa preferencia.";

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
        $answer=hache_sharky_brain_conversational_strip_opening_filler($answer);
        $answer=hache_sharky_brain_conversational_intensive_offer_guard($answer,$openState,$business,$qualificationStep);
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
    if(is_array($result['action_result']??null))return $result;

    $flow=is_array($state['flow']??null)?$state['flow']:null;
    if(is_array($flow)&&($flow['name']??'')!=='qualify_prospect')return $result;

    $decision=is_array($result['decision']??null)?$result['decision']:[];
    $action=is_array($decision['action']??null)?$decision['action']:[];
    if(($action['type']??'')==='human_takeover')return $result;

    $now??=time();
    unset($state['brain_conversational_experiment']);

    if(is_array($flow)&&($flow['name']??'')==='qualify_prospect'){
        $state['updated_at']=$now;
        $result['state']=$state;
        $result['_brain_2ba']=['applied'=>true,'action'=>'brain_conversational_restore'];
        $result['_brain_conversational']=['applied'=>false,'fallback'=>'deterministic'];
        hache_sharky_brain_2ba_metric('brain_conversational_restored');
        return $result;
    }

    if(!in_array((string)($decision['kind']??''),[
        'conversation','conversation_identity_prompt','side_question','commercial_progress',
    ],true))return $result;

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

    if(hache_sharky_brain_2ba_result_protected($result))return ['status'=>'blocked_protected','action'=>null,'evaluation'=>null];

    $evaluation=hache_sharky_brain_shadow_evaluate($beforeState,$state,$event,$result,true);
    $brain=is_array($evaluation['brain']??null)?$evaluation['brain']:[];
    $action=(string)($brain['action']??'');

    if(($evaluation['match']??false)===true)return ['status'=>'aligned','action'=>$action,'evaluation'=>$evaluation];

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
