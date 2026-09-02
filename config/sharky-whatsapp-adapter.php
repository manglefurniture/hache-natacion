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
        $result=hache_sharky_orchestrate($state,$event,$context);$state=$result['state'];$decision=$result['decision'];
        $ref=hache_sharky_orchestrator_referral($event,(int)$context['now']);
        if($ref)hache_sharky_orchestrator_store_referral($pdo,$messageId,$contactHash,$ref,($context['identity']['found']??false)?(string)$context['identity']['student_id']:null);

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
