<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-whatsapp-adapter.php';

function hache_sharky_whatsapp_student_claim_requires_handoff(array $state,array $event): bool
{
    return hache_sharky_orchestrator_contextual_intent(
        $state,
        (string)($event['text']??''),
        (string)($event['interactive_id']??'')
    )==='student_claim';
}

function hache_sharky_whatsapp_deferred_close_request(string $text): bool
{
    if(str_contains($text,'?')||str_contains($text,'¿'))return false;
    $t=hache_sharky_orchestrator_normalize($text);
    $t=preg_replace('/\s+/u',' ',trim($t))??trim($t);
    if($t==='')return false;
    return preg_match('/^(?:(?:gracias|muchas\s+gracias|perfecto|ok|vale)[,;.!\s]+)?(?:(?:te\s+)?(?:confirmo|aviso|digo)\s+(?:mas\s+tarde|luego|despues|manana)|(?:mas\s+tarde|luego|despues|manana)\s+(?:te\s+)?(?:confirmo|aviso|digo)|dejame\s+(?:checar|revisar(?:lo)?|ver|pensar(?:lo)?)(?:\s+y\s+(?:te\s+)?(?:digo|aviso|confirmo))?|(?:lo|me\s+lo)\s+(?:pienso|checo|reviso)\s+y\s+(?:te\s+)?(?:digo|aviso|confirmo))[.!\s]*$/u',$t)===1;
}

function hache_sharky_whatsapp_deferred_close_eligible(array $state,array $event): bool
{
    if(trim((string)($event['interactive_id']??''))!=='')return false;
    if(is_array($state['flow']??null))return false;
    if(!hache_sharky_whatsapp_commercial_ready($state))return false;
    return hache_sharky_whatsapp_deferred_close_request((string)($event['text']??''));
}

function hache_sharky_whatsapp_deferred_close_message(array $state): string
{
    $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    $sede=hache_sharky_whatsapp_venue_label((string)($commercial['sede_clave']??''));
    if(($commercial['program']??null)==='regular'){
        return 'Perfecto 😊 Cuando quieras continuar, aquí estaré. Ya tengo que te interesan las clases regulares en '.$sede.'.';
    }
    return 'Perfecto 😊 Cuando quieras continuar, aquí estaré. Ya tengo que te interesa el curso intensivo en '.$sede.'.';
}

function hache_sharky_whatsapp_batch_question_like(string $text): bool
{
    $text=trim($text);if($text==='')return false;
    if(str_contains($text,'?')||str_contains($text,'¿'))return true;
    $t=hache_sharky_orchestrator_normalize($text);
    return preg_match('/^(?:cuanto|como|donde|cuando|que\b|cual\b|aceptan|puedo|tienen|hay\b|precio\b|precios\b|costo\b|costos\b|horario\b|horarios\b|ubicacion\b)/u',$t)===1;
}

/**
 * A safe discovery tap may join only when a direct-chat text question is
 * actively sleeping in the debounce queue. Standalone buttons keep their fast
 * path and transactional/action buttons never enter this coalescing path.
 */
function hache_sharky_whatsapp_batch_pending_question(string $contact): bool
{
    try{
        $dir=hache_sharky_orchestrator_runtime_dir('batch');if($dir==='')return false;
        $queue=$dir.'/'.hache_sharky_orchestrator_contact_hash($contact).'.json';
        if(!is_file($queue))return false;
        $stored=json_decode((string)@file_get_contents($queue),true);if(!is_array($stored))return false;
        $flushAtMs=(int)($stored['flush_at_ms']??0);$nowMs=(int)floor(microtime(true)*1000);
        if($flushAtMs<=0||$flushAtMs<=$nowMs)return false;
        $parts=[];
        foreach(is_array($stored['events']??null)?$stored['events']:[] as $queued){
            if(!is_array($queued))continue;
            if(trim((string)($queued['interactive_id']??''))!=='')continue;
            $text=trim((string)($queued['text']??''));if($text!=='')$parts[]=$text;
        }
        return hache_sharky_whatsapp_batch_question_like(implode("\n",$parts));
    }catch(Throwable $e){return false;}
}

function hache_sharky_whatsapp_batch_joinable_interactive(string $interactiveId): bool
{
    $id=strtolower(trim($interactiveId));
    if(in_array($id,[
        'qualify:swims','qualify:beginner','qualify:formal','qualify:self',
        'qualify:intensive','qualify:regular',
    ],true))return true;
    return str_starts_with($id,'sede:')||str_starts_with($id,'daypart:');
}

function hache_sharky_whatsapp_batch_encode_interactive(array $event): string
{
    $data=json_encode([
        'id'=>trim((string)($event['interactive_id']??'')),
        'title'=>trim((string)($event['text']??'')),
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(!is_string($data))return '';
    $token=rtrim(strtr(base64_encode($data),'+/','-_'),'=');
    return '[[SHARKY_INTERACTIVE:'.$token.']]';
}

function hache_sharky_whatsapp_batch_decode_interactive(string $line): ?array
{
    $line=trim($line);
    if(preg_match('/^\[\[SHARKY_INTERACTIVE:([A-Za-z0-9_-]+)\]\]$/',$line,$m)!==1)return null;
    $token=strtr((string)$m[1],'-_','+/');$padding=(4-(strlen($token)%4))%4;
    if($padding)$token.=str_repeat('=',$padding);
    $raw=base64_decode($token,true);if(!is_string($raw))return null;
    $data=json_decode($raw,true);if(!is_array($data))return null;
    $id=trim((string)($data['id']??''));$title=trim((string)($data['title']??''));
    if($id===''||!hache_sharky_whatsapp_batch_joinable_interactive($id))return null;
    return ['id'=>$id,'title'=>$title];
}

/** @return array{text:string,interactives:list<array{id:string,title:string}>} */
function hache_sharky_whatsapp_batch_unpack(string $text): array
{
    $parts=[];$interactives=[];
    foreach(preg_split('/\R/u',$text)?:[] as $line){
        $decoded=hache_sharky_whatsapp_batch_decode_interactive((string)$line);
        if(is_array($decoded)){$interactives[]=$decoded;continue;}
        $line=trim((string)$line);if($line!=='')$parts[]=$line;
    }
    return ['text'=>implode("\n",$parts),'interactives'=>$interactives];
}

function hache_sharky_whatsapp_process_with_delivery_lock(PDO $pdo,array $event,callable $conversationAnswer,array $extraContext=[]): array
{
    $contact=(string)($event['from']??'');
    $lock=hache_sharky_orchestrator_delivery_lock($contact);
    if(!is_resource($lock))return ['skip'=>true,'code'=>'DELIVERY_LOCK_UNAVAILABLE'];
    try{
        // A human may take the chat while a text is sleeping in the debounce window.
        // Revalidate only after acquiring the same delivery lock used by takeover/outbox.
        // A receipt already marked handoff_pending is the recovery exception: it must
        // replay the handoff decision instead of being swallowed by active takeover.
        $handoffPending=($extraContext['handoff_pending']??false)===true;
        $messageId=(string)($event['id']??'');
        if(!$handoffPending&&function_exists('hache_sharky_inbox_handoff_pending')){
            $handoffPending=hache_sharky_inbox_handoff_pending($pdo,$messageId);
        }
        if(function_exists('hache_sharky_takeover_active')&&hache_sharky_takeover_active($contact)&&!$handoffPending){
            $hash=hache_sharky_orchestrator_contact_hash($contact);
            if(!hache_sharky_orchestrator_claim_message($pdo,$messageId,$hash,(string)($event['type']??'message'))){
                hache_sharky_orchestrator_unlock($lock);
                return ['skip'=>true,'code'=>'DUPLICATE'];
            }
            $state=hache_sharky_db_state_load($pdo,$contact);
            $decision=hache_sharky_orchestrator_decision('silent_human_takeover');
            hache_sharky_whatsapp_complete_receipt($pdo,$messageId,$extraContext);
            $result=['skip'=>false,'code'=>'HUMAN_TAKEOVER','state'=>$state,'decision'=>$decision,'payload'=>null,'action_result'=>null];
        }else{
            // Temporary operating rule: existing students are handled by a person.
            // Keep identity verification intact for future reactivation, but do not
            // enter it from WhatsApp while this direct-handoff policy is active.
            $state=hache_sharky_db_state_load($pdo,$contact);
            $now=(int)($extraContext['now']??time());
            $deferredState=hache_sharky_orchestrator_expire_flow($state,$now);
            if(hache_sharky_whatsapp_deferred_close_eligible($deferredState,$event)){
                $state=$deferredState;
                $hash=hache_sharky_orchestrator_contact_hash($contact);
                if(!hache_sharky_orchestrator_claim_message($pdo,$messageId,$hash,(string)($event['type']??'message'))){
                    hache_sharky_orchestrator_unlock($lock);
                    return ['skip'=>true,'code'=>'DUPLICATE'];
                }
                $state['updated_at']=$now;
                $state['last_user_text']=trim((string)($event['text']??''));
                $ref=hache_sharky_orchestrator_referral($event,$now);
                if($ref)$state=hache_sharky_orchestrator_capture_referral($state,$ref);
                $decision=hache_sharky_orchestrator_decision(
                    'commercial_deferred_close',
                    hache_sharky_whatsapp_deferred_close_message($state)
                );
                hache_sharky_db_state_save($pdo,$contact,$state);
                hache_sharky_whatsapp_complete_receipt($pdo,$messageId,$extraContext);
                $result=[
                    'skip'=>false,
                    'code'=>'COMMERCIAL_DEFERRED_CLOSE',
                    'state'=>$state,
                    'decision'=>$decision,
                    'payload'=>hache_sharky_whatsapp_render($contact,$decision),
                    'action_result'=>null,
                ];
            }elseif(hache_sharky_whatsapp_student_claim_requires_handoff($state,$event)){
                $hash=hache_sharky_orchestrator_contact_hash($contact);
                if(!hache_sharky_orchestrator_claim_message($pdo,$messageId,$hash,(string)($event['type']??'message'))){
                    hache_sharky_orchestrator_unlock($lock);
                    return ['skip'=>true,'code'=>'DUPLICATE'];
                }
                $state=hache_sharky_orchestrator_clear_flow($state);
                $decision=hache_sharky_orchestrator_decision(
                    'student_human_takeover',
                    'Perfecto. Como ya eres alumno, te dejo directamente con una persona del equipo de Hache Natación para que continúe contigo por este mismo chat.',
                    [],
                    ['type'=>'human_takeover']
                );
                hache_sharky_db_state_save($pdo,$contact,$state);
                hache_sharky_whatsapp_complete_receipt($pdo,$messageId,$extraContext);
                $result=[
                    'skip'=>false,
                    'code'=>'STUDENT_HUMAN_TAKEOVER',
                    'state'=>$state,
                    'decision'=>$decision,
                    'payload'=>hache_sharky_whatsapp_render($contact,$decision),
                    'action_result'=>['ok'=>true,'code'=>'HANDOFF'],
                ];
            }else{
                $result=hache_sharky_whatsapp_process($pdo,$event,$conversationAnswer,$extraContext);
            }
        }
    }catch(Throwable $e){
        hache_sharky_orchestrator_unlock($lock);
        throw $e;
    }
    if(($extraContext['defer_delivery_unlock']??false)===true){
        $result['_delivery_lock']=$lock;
    }else{
        hache_sharky_orchestrator_unlock($lock);
    }
    return $result;
}

/**
 * Text turns wait for the normal debounce window. A safe discovery button may
 * join an already-open direct-chat question burst, so Sharky applies the button
 * choice before answering the customer's question. Groups and business-changing
 * actions continue to bypass batching.
 */
function hache_sharky_whatsapp_enqueue(PDO $pdo,array $event,callable $conversationAnswer,array $extraContext=[]): array
{
    $contact=(string)($event['from']??'');$id=(string)($event['id']??'');
    if($contact===''||$id==='')return ['skip'=>true,'code'=>'INVALID_EVENT'];
    $groupId=trim((string)($event['group_id']??''));$interactiveId=trim((string)($event['interactive_id']??''));
    $isInteractive=(string)($event['type']??'')==='interactive'||$interactiveId!=='';
    if($groupId!=='')return hache_sharky_whatsapp_process_with_delivery_lock($pdo,$event,$conversationAnswer,$extraContext);
    $joinInteractive=$isInteractive
        &&hache_sharky_whatsapp_batch_joinable_interactive($interactiveId)
        &&hache_sharky_whatsapp_batch_pending_question($contact);
    if($isInteractive&&!$joinInteractive)return hache_sharky_whatsapp_process_with_delivery_lock($pdo,$event,$conversationAnswer,$extraContext);

    $hash=hache_sharky_orchestrator_contact_hash($contact);
    if(!hache_sharky_orchestrator_claim_message($pdo,$id,$hash,(string)($event['type']??'text')))return ['skip'=>true,'code'=>'DUPLICATE'];
    $ref=hache_sharky_orchestrator_referral($event,(int)($extraContext['now']??time()));
    if($ref){$identity=hache_sharky_business_identity_by_whatsapp($pdo,$contact);hache_sharky_orchestrator_store_referral($pdo,$id,$hash,$ref,($identity['found']??false)?(string)$identity['student_id']:null);}

    $batchEvent=$event;
    if($joinInteractive){
        $encoded=hache_sharky_whatsapp_batch_encode_interactive($event);
        if($encoded==='')return ['skip'=>true,'code'=>'BATCH_INTERACTIVE_ENCODING_FAILED'];
        $batchEvent['text']=$encoded;
    }
    $batch=hache_sharky_orchestrator_batch_enqueue_and_wait($contact,$batchEvent,(int)($extraContext['batch_window_ms']??HACHE_SHARKY_BATCH_WINDOW_MS));
    if($batch===null)return ['skip'=>true,'code'=>'BATCH_DEFERRED'];
    $ids=is_array($batch['ids']??null)?$batch['ids']:[$id];$latestReferral=is_array($batch['referral']??null)?$batch['referral']:null;
    if($latestReferral===null&&is_array($event['referral']??null))$latestReferral=$event['referral'];
    $baseId='batch:'.hash('sha256',implode('|',$ids));$unpacked=hache_sharky_whatsapp_batch_unpack((string)($batch['text']??''));
    $plainText=trim((string)$unpacked['text']);$choices=is_array($unpacked['interactives']??null)?$unpacked['interactives']:[];

    if($choices){
        // Multiple taps on the same stale prompt can arrive in one debounce window.
        // The latest tap is the customer's final choice; never execute intermediate taps.
        $choice=$choices[array_key_last($choices)];
        $semantic=[
            'id'=>$baseId.':semantic',
            'from'=>$contact,
            'type'=>'interactive',
            'text'=>(string)($choice['title']??''),
            'interactive_id'=>(string)($choice['id']??''),
            'timestamp_ms'=>(int)($event['timestamp_ms']??floor(microtime(true)*1000)),
        ];
        if($latestReferral!==null)$semantic['referral']=$latestReferral;
        $result=hache_sharky_whatsapp_process_with_delivery_lock($pdo,$semantic,$conversationAnswer,$extraContext);
        $syntheticId=$semantic['id'];

        // The button establishes the newest commercial context first. The pending
        // text must then traverse the exact normal text pipeline so student claims,
        // age/underage rules, takeover and every other policy guard still apply.
        if($plainText!==''&&($result['skip']??false)!==true&&($result['payload']??null)!==null){
            $textSynthetic=[
                'id'=>$baseId.':text',
                'from'=>$contact,
                'type'=>'text',
                'text'=>$plainText,
                'interactive_id'=>'',
                'timestamp_ms'=>(int)($event['timestamp_ms']??floor(microtime(true)*1000)),
            ];
            if($latestReferral!==null)$textSynthetic['referral']=$latestReferral;
            $result=hache_sharky_whatsapp_process_with_delivery_lock($pdo,$textSynthetic,$conversationAnswer,$extraContext);
            $syntheticId=$textSynthetic['id'];
        }
    }else{
        $synthetic=['id'=>$baseId,'from'=>$contact,'type'=>'text','text'=>$plainText,'interactive_id'=>'','timestamp_ms'=>(int)($event['timestamp_ms']??floor(microtime(true)*1000))];
        if($latestReferral!==null)$synthetic['referral']=$latestReferral;
        $result=hache_sharky_whatsapp_process_with_delivery_lock($pdo,$synthetic,$conversationAnswer,$extraContext);
        $syntheticId=$synthetic['id'];
    }

    $deliveryPending=function_exists('hache_sharky_action_delivery_pending_for_message')&&hache_sharky_action_delivery_pending_for_message($pdo,$syntheticId);
    $deferCompletion=($extraContext['defer_receipt_completion']??false)===true||$deliveryPending;
    $result['batched_ids']=$ids;$result['synthetic_id']=$syntheticId;$result['defer_processed']=$deferCompletion;
    if(!$deferCompletion)foreach($ids as $messageId)hache_sharky_orchestrator_mark_processed($pdo,(string)$messageId);
    return $result;
}
