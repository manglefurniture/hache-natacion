<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-orchestrator-db.php';

function hache_sharky_whatsapp_extract(array $payload): array
{
    $out=[];
    foreach(($payload['entry']??[]) as $entry){
        if(!is_array($entry))continue;
        foreach(($entry['changes']??[]) as $change){
            if(!is_array($change))continue;
            $value=$change['value']??null;if(!is_array($value))continue;
            $phoneId=trim((string)($value['metadata']['phone_number_id']??''));
            foreach(($value['messages']??[]) as $m){
                if(!is_array($m))continue;
                $id=trim((string)($m['id']??''));$from=preg_replace('/\D+/','',(string)($m['from']??''))?:'';$type=(string)($m['type']??'');
                if($id===''||$from==='')continue;
                $event=['id'=>$id,'from'=>$from,'type'=>$type,'text'=>'','interactive_id'=>'','phone_number_id'=>$phoneId,'timestamp_ms'=>((int)($m['timestamp']??time()))*1000];
                if($type==='text')$event['text']=mb_substr(trim((string)($m['text']['body']??'')),0,700);
                elseif($type==='interactive'){
                    $kind=(string)($m['interactive']['type']??'');
                    if($kind==='button_reply'){$event['interactive_id']=trim((string)($m['interactive']['button_reply']['id']??''));$event['text']=trim((string)($m['interactive']['button_reply']['title']??''));}
                    elseif($kind==='list_reply'){$event['interactive_id']=trim((string)($m['interactive']['list_reply']['id']??''));$event['text']=trim((string)($m['interactive']['list_reply']['title']??''));}
                    else continue;
                } else continue;
                if(is_array($m['referral']??null))$event['referral']=$m['referral'];
                $out[]=$event;
            }
        }
    }
    return $out;
}

function hache_sharky_whatsapp_clean_answer(string $answer): string
{
    $answer=str_replace(["\r\n","\r"],"\n",trim($answer));
    $lines=preg_split('/\n+/u',$answer)?:[];$clean=[];$last='';
    foreach($lines as $line){$line=preg_replace('/\s+/u',' ',trim((string)$line))??'';if($line==='')continue;$norm=mb_strtolower($line,'UTF-8');if($norm===$last)continue;$clean[]=$line;$last=$norm;}
    $answer=implode("\n\n",$clean);
    if(mb_strlen($answer)>1400){$slice=mb_substr($answer,0,1360);$cut=max(mb_strrpos($slice,'.')?:0,mb_strrpos($slice,'?')?:0,mb_strrpos($slice,'!')?:0);$answer=trim($cut>700?mb_substr($slice,0,$cut+1):$slice).'…';}
    return $answer;
}

function hache_sharky_whatsapp_style_instruction(array $decision,array $state): string
{
    $instruction='Responde para WhatsApp en español natural. Máximo 3 párrafos cortos o 5 viñetas. No repitas tu presentación ni información ya dada. Responde primero a la pregunta actual y termina con una sola pregunta útil si hace falta avanzar.';
    $latest=$state['referral']['latest']??null;
    if(is_array($latest)&&!empty($latest['headline']))$instruction.=' El usuario llegó desde un anuncio cuyo contexto es: '.mb_substr((string)$latest['headline'],0,180).'. Úsalo como contexto, pero no asumas que sigue siendo su intención actual.';
    if(($decision['kind']??'')==='conversation_identity_prompt')$instruction.=' Después de responder brevemente, pregunta si ya es alumno de Hache Natación.';
    return $instruction;
}

function hache_sharky_whatsapp_text_payload(string $to,string $body): array
{
    return ['messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$to,'type'=>'text','text'=>['preview_url'=>false,'body'=>mb_substr(trim($body),0,4000)]];
}

function hache_sharky_whatsapp_render(string $to,array $decision,?string $conversationAnswer=null,?string $verificationUrl=null): array
{
    $ui=is_array($decision['ui']??null)?$decision['ui']:[];$message=trim((string)($conversationAnswer??$decision['message']??''));
    if(($ui['type']??'')==='verification_link'){
        $body=$message!==''?$message:'Para proteger tus datos necesito verificar tu identidad.';
        if($verificationUrl)$body.="\n\nVerificar identidad: ".$verificationUrl;
        return hache_sharky_whatsapp_text_payload($to,$body);
    }
    if(($ui['type']??'')==='buttons'){
        $buttons=[];
        foreach(array_slice(is_array($ui['buttons']??null)?$ui['buttons']:[],0,3) as $b){if(!is_array($b))continue;$id=trim((string)($b['id']??''));$title=trim((string)($b['title']??''));if($id===''||$title==='')continue;$buttons[]=['type'=>'reply','reply'=>['id'=>mb_substr($id,0,256),'title'=>mb_substr($title,0,20)]];}
        if($buttons)return ['messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$to,'type'=>'interactive','interactive'=>['type'=>'button','body'=>['text'=>mb_substr($message!==''?$message:'Elige una opción.',0,1024)],'action'=>['buttons'=>$buttons]]];
    }
    if(($ui['type']??'')==='list'){
        $rows=[];foreach(array_slice(is_array($ui['options']??null)?$ui['options']:[],0,10) as $o){if(!is_array($o))continue;$id=trim((string)($o['id']??''));$title=trim((string)($o['title']??''));if($id===''||$title==='')continue;$row=['id'=>mb_substr($id,0,200),'title'=>mb_substr($title,0,24)];$desc=trim((string)($o['description']??''));if($desc!=='')$row['description']=mb_substr($desc,0,72);$rows[]=$row;}
        if($rows)return ['messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$to,'type'=>'interactive','interactive'=>['type'=>'list','body'=>['text'=>mb_substr($message!==''?$message:'Elige una opción.',0,1024)],'action'=>['button'=>'Ver opciones','sections'=>[['title'=>'Opciones','rows'=>$rows]]]]];
    }
    return hache_sharky_whatsapp_text_payload($to,$message!==''?$message:'¿En qué te puedo ayudar?');
}

function hache_sharky_whatsapp_context(PDO $pdo,string $contact,array $extra=[]): array
{
    $identity=hache_sharky_business_identity_by_whatsapp($pdo,$contact);
    $verification=hache_sharky_verification_status($pdo,$contact);
    return array_replace($extra,[
        'identity'=>$identity,
        'verification'=>$verification,
        'intensive_options'=>hache_sharky_business_intensive_options($pdo),
        'today'=>(string)($extra['today']??date('Y-m-d')),
        'now'=>(int)($extra['now']??time()),
        'min_age'=>(int)($extra['min_age']??12),
    ]);
}

function hache_sharky_whatsapp_resume_verified_state(array $state,array $context,int $now): array
{
    $verification=$context['verification']??null;
    if(!is_array($verification)||($verification['verified']??false)!==true)return $state;
    if(($state['identity']['verified']??false)!==true){
        $state['identity']=array_replace($state['identity'],[
            'kind'=>'student','verified'=>true,'source'=>'verification','student_id'=>$verification['student_id']??null,'name'=>$verification['name']??null,'sede_clave'=>$verification['sede_clave']??null,'status'=>$verification['status']??null,
        ]);
    }
    $flow=$state['flow']??null;
    if(!is_array($flow)||($flow['name']??'')!=='identify_student')return $state;
    $returnTo=(string)($flow['data']['return_to']??'');
    if($returnTo==='absence')return hache_sharky_orchestrator_flow($state,'absence','offer',[],$now);
    return hache_sharky_orchestrator_clear_flow($state);
}

function hache_sharky_whatsapp_interactive_is_current(array $state,array $event): bool
{
    $id=strtolower(trim((string)($event['interactive_id']??'')));
    if($id==='')return true;
    $flow=$state['flow']??null;
    if(!is_array($flow)){
        return str_starts_with($id,'identity:')||str_starts_with($id,'action:');
    }
    if(in_array($id,['flow:cancel','flow:no','action:human'],true))return true;
    $name=(string)($flow['name']??'');$step=(string)($flow['step']??'');
    if($name==='absence'){
        if($step==='offer')return $id==='flow:yes';
        if($step==='date')return $id==='date:tomorrow';
        if($step==='confirm')return $id==='flow:confirm';
        return false;
    }
    if($name==='register_intensive'){
        if($step==='offer')return $id==='flow:yes';
        if($step==='sede')return str_starts_with($id,'sede:');
        if($step==='course')return str_starts_with($id,'course:');
        if($step==='schedule')return str_starts_with($id,'schedule:');
        if($step==='confirm')return $id==='flow:confirm';
        return false;
    }
    return false;
}

function hache_sharky_whatsapp_empty_options_guard(array $state,array $decision): array
{
    $ui=is_array($decision['ui']??null)?$decision['ui']:[];
    if(($ui['type']??'')!=='list')return [$state,$decision];
    $options=is_array($ui['options']??null)?$ui['options']:[];
    if($options)return [$state,$decision];
    $state=hache_sharky_orchestrator_clear_flow($state);
    $decision=hache_sharky_orchestrator_decision(
        'options_unavailable_handoff',
        'No encuentro opciones activas para continuar este proceso de forma segura. Te dejo con el equipo para revisarlo contigo.',
        [],
        ['type'=>'human_takeover']
    );
    return [$state,$decision];
}

function hache_sharky_whatsapp_is_side_question(array $state,array $event): bool
{
    $flow=$state['flow']??null;if(!is_array($flow))return false;
    $step=(string)($flow['step']??'');
    if(!in_array($step,['offer','sede','course','schedule','confirm'],true))return false;
    if(trim((string)($event['interactive_id']??''))!=='')return false;
    $text=trim((string)($event['text']??''));if($text==='')return false;
    $intent=hache_sharky_orchestrator_intent($text,'');
    if(in_array($intent,['yes','no','cancel','human','absence','register_intensive'],true))return false;
    $t=hache_sharky_orchestrator_normalize($text);
    return str_contains($text,'?')||preg_match('/^(cuanto|como|donde|cuando|que |aceptan|puedo|tienen|hay |cual)/u',$t)===1;
}

function hache_sharky_whatsapp_process(PDO $pdo,array $event,callable $conversationAnswer,array $extraContext=[]): array
{
    $contact=(string)($event['from']??'');$messageId=(string)($event['id']??'');
    if($contact===''||$messageId==='')return ['skip'=>true,'code'=>'INVALID_EVENT'];
    $contactHash=hache_sharky_orchestrator_contact_hash($contact);
    if(!hache_sharky_orchestrator_claim_message($pdo,$messageId,$contactHash,(string)($event['type']??'text')))return ['skip'=>true,'code'=>'DUPLICATE'];

    $lock=hache_sharky_orchestrator_lock($contact);
    try{
        $state=hache_sharky_db_state_load($pdo,$contact);
        $context=hache_sharky_whatsapp_context($pdo,$contact,$extraContext);
        $state=hache_sharky_whatsapp_resume_verified_state($state,$context,(int)$context['now']);
        $ref=hache_sharky_orchestrator_referral($event,(int)$context['now']);
        if($ref)hache_sharky_orchestrator_store_referral($pdo,$messageId,$contactHash,$ref,($context['identity']['found']??false)?(string)$context['identity']['student_id']:null);

        if(!hache_sharky_whatsapp_interactive_is_current($state,$event)){
            $decision=hache_sharky_orchestrator_decision('stale_interactive','Esa opción pertenece a un paso anterior. No hice ningún cambio; continuemos desde la opción que tienes activa ahora.');
            hache_sharky_db_state_save($pdo,$contact,$state);hache_sharky_orchestrator_mark_processed($pdo,$messageId);
            return ['skip'=>false,'state'=>$state,'decision'=>$decision,'payload'=>hache_sharky_whatsapp_render($contact,$decision),'action_result'=>null];
        }

        $preIntent=hache_sharky_orchestrator_intent((string)($event['text']??''),(string)($event['interactive_id']??''));
        if(!is_array($state['flow']??null)&&$preIntent==='register_intensive'&&(($context['identity']['found']??false)===true||(($state['identity']['kind']??'')==='student'&&($state['identity']['verified']??false)===true))){
            $state=hache_sharky_orchestrator_clear_flow($state);
            $decision=hache_sharky_orchestrator_decision('existing_student_intensive_handoff','Veo que este número ya está vinculado a un alumno. Para evitar duplicar tu expediente, el equipo continuará contigo la inscripción al intensivo por este mismo chat.',[],['type'=>'human_takeover']);
            hache_sharky_db_state_save($pdo,$contact,$state);hache_sharky_orchestrator_mark_processed($pdo,$messageId);
            return ['skip'=>false,'state'=>$state,'decision'=>$decision,'payload'=>hache_sharky_whatsapp_render($contact,$decision),'action_result'=>['ok'=>true,'code'=>'HANDOFF']];
        }

        if(hache_sharky_whatsapp_is_side_question($state,$event)){
            $instruction='Responde solo la duda actual de forma breve. El usuario está dentro de un proceso controlado; no pierdas ni cambies ese proceso. No vuelvas a pedir datos ya capturados.';
            $answer=hache_sharky_whatsapp_clean_answer((string)$conversationAnswer((string)($event['text']??''),$instruction,$state,$context));
            $answer=rtrim($answer)."\n\nCuando quieras, seguimos donde lo dejamos.";
            hache_sharky_db_state_save($pdo,$contact,$state);hache_sharky_orchestrator_mark_processed($pdo,$messageId);
            return ['skip'=>false,'state'=>$state,'decision'=>['kind'=>'side_question','message'=>$answer,'ui'=>[],'action'=>null],'payload'=>hache_sharky_whatsapp_text_payload($contact,$answer),'action_result'=>null];
        }

        $result=hache_sharky_orchestrate($state,$event,$context);$state=$result['state'];$decision=$result['decision'];
        [$state,$decision]=hache_sharky_whatsapp_empty_options_guard($state,$decision);
        $verificationUrl=null;
        if(($decision['ui']['type']??'')==='verification_link'){
            $challenge=hache_sharky_verification_issue($pdo,$contact,(string)($extraContext['verification_base_url']??'https://hnatacion.com/sharky-verificar.php'));
            $verificationUrl=$challenge['url'];
        }

        $conversation=null;
        if(in_array((string)($decision['kind']??''),['conversation','conversation_identity_prompt'],true)){
            $instruction=hache_sharky_whatsapp_style_instruction($decision,$state);
            $conversation=hache_sharky_whatsapp_clean_answer((string)$conversationAnswer((string)($event['text']??''),$instruction,$state,$context));
            if(($decision['kind']??'')==='conversation_identity_prompt')$conversation=rtrim($conversation)."\n\nAntes de seguir, ¿ya eres alumno de Hache Natación?";
        }

        $actionResult=null;
        if(is_array($decision['action']??null)){
            $action=$decision['action'];
            if(($action['type']??'')==='human_takeover')$actionResult=['ok'=>true,'code'=>'HANDOFF','message'=>(string)$decision['message']];
            else{
                $key=$messageId.'|'.(string)($action['type']??'').'|'.(string)($action['student_id']??$action['course_id']??'');
                $actionResult=hache_sharky_execute_action($pdo,$contact,$action,$key,$context);
                $decision['message']=(string)($actionResult['message']??$decision['message']);
                $decision['ui']=[];
            }
        }

        hache_sharky_db_state_save($pdo,$contact,$state);
        hache_sharky_orchestrator_mark_processed($pdo,$messageId);
        return ['skip'=>false,'state'=>$state,'decision'=>$decision,'payload'=>hache_sharky_whatsapp_render($contact,$decision,$conversation,$verificationUrl),'action_result'=>$actionResult];
    }finally{hache_sharky_orchestrator_unlock($lock);}
}
