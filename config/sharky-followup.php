<?php

declare(strict_types=1);

const HACHE_SHARKY_FOLLOWUP_FIRST_DELAY_SECONDS = 900;
const HACHE_SHARKY_FOLLOWUP_SECOND_DELAY_SECONDS = 5400;
const HACHE_SHARKY_FOLLOWUP_SESSION_SECONDS = 86400;
const HACHE_SHARKY_FOLLOWUP_TIMEZONE = 'America/Cancun';
const HACHE_SHARKY_FOLLOWUP_START_HOUR = 8;
const HACHE_SHARKY_FOLLOWUP_END_HOUR = 22;

function hache_sharky_followup_state(array $state): array
{
    $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    $followup=is_array($commercial['_idle_followup']??null)?$commercial['_idle_followup']:[];
    return array_replace([
        'status'=>'idle','token'=>null,'user_turn_at'=>0,'sent_count'=>0,'next_stage'=>null,
        'first_due_at'=>null,'first_sent_at'=>null,'second_due_at'=>null,'completed_at'=>null,
    ],$followup);
}

function hache_sharky_followup_set_state(array $state,array $followup): array
{
    if(!is_array($state['commercial_context']??null))$state['commercial_context']=[];
    $state['commercial_context']['_idle_followup']=$followup;
    return $state;
}

function hache_sharky_followup_next_allowed_at(int $timestamp): int
{
    $tz=new DateTimeZone(HACHE_SHARKY_FOLLOWUP_TIMEZONE);
    $local=(new DateTimeImmutable('@'.$timestamp))->setTimezone($tz);
    $hour=(int)$local->format('G');
    if($hour<HACHE_SHARKY_FOLLOWUP_START_HOUR)return $local->setTime(HACHE_SHARKY_FOLLOWUP_START_HOUR,0,0)->getTimestamp();
    if($hour>=HACHE_SHARKY_FOLLOWUP_END_HOUR)return $local->modify('+1 day')->setTime(HACHE_SHARKY_FOLLOWUP_START_HOUR,0,0)->getTimestamp();
    return $timestamp;
}

function hache_sharky_followup_send_allowed_now(int $timestamp): bool
{
    $local=(new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone(HACHE_SHARKY_FOLLOWUP_TIMEZONE));
    $hour=(int)$local->format('G');
    return $hour>=HACHE_SHARKY_FOLLOWUP_START_HOUR&&$hour<HACHE_SHARKY_FOLLOWUP_END_HOUR;
}

function hache_sharky_followup_user_deferred(string $text): bool
{
    if(str_contains($text,'?')||str_contains($text,'¿'))return false;
    $t=hache_sharky_orchestrator_normalize($text);
    $t=preg_replace('/\s+/u',' ',trim($t))??trim($t);
    if($t==='')return false;
    return preg_match('/^(?:(?:gracias|muchas\s+gracias|perfecto|ok|vale)[,;.!\s]+)?(?:(?:te\s+)?(?:confirmo|aviso|digo)\s+(?:mas\s+tarde|luego|despues|manana)|(?:mas\s+tarde|luego|despues|manana)\s+(?:te\s+)?(?:confirmo|aviso|digo)|dejame\s+(?:checar|revisar(?:lo)?|ver|pensar(?:lo)?)(?:\s+y\s+(?:te\s+)?(?:digo|aviso|confirmo))?|(?:lo|me\s+lo)\s+(?:pienso|checo|reviso)\s+y\s+(?:te\s+)?(?:digo|aviso|confirmo))[.!\s]*$/u',$t)===1;
}

function hache_sharky_followup_user_opted_out(string $text): bool
{
    $t=hache_sharky_orchestrator_normalize($text);
    if($t==='')return false;
    if(hache_sharky_followup_user_deferred($text))return true;
    if(preg_match('/^(?:gracias|muchas gracias|listo gracias|gracias eso es todo|eso es todo|con eso gracias)[.! ]*$/u',$t)===1)return true;
    if(preg_match('/\b(?:no\s+gracias|no\s+me\s+interesa|ya\s+no\s+me\s+interesa|dejalo|dejala|yo\s+te\s+aviso|luego\s+te\s+escribo|despues\s+te\s+escribo|solo\s+estaba\s+preguntando|solo\s+queria\s+informacion)\b/u',$t)===1)return true;
    if(preg_match('/(?:^|\b)(?:por\s+favor[,.]?\s*)?(?:no|nunca)\s+me\s+(?:escribas|contactes|mandes|envies)(?:\s+mas(?:\s+mensajes)?)?(?:\s*(?:por\s+favor|gracias))?[.! ]*$/u',$t)===1)return true;
    if(preg_match('/\b(?:deja|dejen)\s+de\s+(?:escribirme|contactarme|mandarme|enviarme)(?:\s+mensajes)?\b/u',$t)===1)return true;
    if(preg_match('/\bno\s+quiero\s+(?:recibir(?:\s+mas)?\s+mensajes|que\s+me\s+(?:escriban|contacten|manden|envien)(?:\s+mas)?(?:\s+mensajes)?)\b/u',$t)===1)return true;
    return preg_match('/\b(?:borra|borren|elimina|eliminen|quita|quiten)\s+(?:mi\s+numero|este\s+numero|mis\s+datos)\b/u',$t)===1;
}

function hache_sharky_followup_commercial_ready(array $state): bool
{
    if(($state['identity']['kind']??'')!=='prospect')return false;
    if(is_array($state['flow']??null))return false;
    $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    if(!in_array(($commercial['program']??null),['intensive','regular'],true))return false;
    if(!in_array(($commercial['sede_clave']??null),['MONTEVERDE','PALAPAS'],true))return false;
    if(hache_sharky_followup_user_opted_out((string)($state['last_user_text']??'')))return false;
    return true;
}

function hache_sharky_followup_payload_body(array $payload): string
{
    if(($payload['type']??'')==='text')return trim((string)($payload['text']['body']??''));
    if(($payload['type']??'')==='interactive')return trim((string)($payload['interactive']['body']['text']??''));
    return '';
}

function hache_sharky_followup_payload_armable(array $payload): bool
{
    if(is_array($payload['_sharky_followup']??null)||is_array($payload['_sharky_followup_arm']??null)||($payload['_sharky_allow_takeover']??false)===true)return false;
    $body=hache_sharky_followup_payload_body($payload);
    if(mb_strlen($body)<20)return false;
    $t=hache_sharky_orchestrator_normalize($body);
    foreach(['te dejo con el equipo','una persona continuara','no pude procesar','no puedo continuar con esta orientacion'] as $blocked){
        if(str_contains($t,$blocked))return false;
    }
    return true;
}

function hache_sharky_followup_label(array $state): array
{
    $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    $program=($commercial['program']??null)==='regular'?'regular':'intensive';
    $sede=($commercial['sede_clave']??null)==='MONTEVERDE'?'Monteverde':'Palapas Protudec';
    return [$program,$sede];
}

function hache_sharky_followup_button(string $id,string $title): array
{
    return ['type'=>'reply','reply'=>['id'=>$id,'title'=>$title]];
}

function hache_sharky_followup_payload(string $contact,array $state,int $stage,string $token,int $userTurnAt): array
{
    [$program,$sede]=hache_sharky_followup_label($state);
    if($stage===1&&$program==='intensive'){
        $message='¿Te gustaría que te ayude a iniciar la inscripción al curso intensivo en '.$sede.'?';
        $buttons=[hache_sharky_followup_button('action:register_intensive','Inscribirme'),hache_sharky_followup_button('action:commercial_schedules','Horarios'),hache_sharky_followup_button('action:commercial_price','Precio')];
    }elseif($stage===1){
        $message='¿Quieres que te muestre horarios y precio de las clases regulares en '.$sede.'?';
        $buttons=[hache_sharky_followup_button('action:commercial_schedules','Horarios'),hache_sharky_followup_button('action:commercial_price','Precio')];
    }elseif($program==='intensive'){
        $message='Si prefieres decidirlo con calma, también puedo mostrarte horarios y precio del curso intensivo en '.$sede.'. ¿Te los comparto?';
        $buttons=[hache_sharky_followup_button('action:commercial_schedules','Horarios'),hache_sharky_followup_button('action:commercial_price','Precio')];
    }else{
        $message='Si quieres, puedo dejarte aquí horarios y precio de las clases regulares en '.$sede.' para que los revises con calma. ¿Te los comparto?';
        $buttons=[hache_sharky_followup_button('action:commercial_schedules','Horarios'),hache_sharky_followup_button('action:commercial_price','Precio')];
    }
    return [
        'messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$contact,'type'=>'interactive',
        'interactive'=>['type'=>'button','body'=>['text'=>$message],'action'=>['buttons'=>$buttons]],
        '_sharky_followup'=>['token'=>$token,'stage'=>$stage,'user_turn_at'=>$userTurnAt,'program'=>$program,'sede_clave'=>(string)($state['commercial_context']['sede_clave']??'')],
    ];
}

function hache_sharky_followup_completed_recently(array $followup,int $now): bool
{
    if(!str_starts_with((string)($followup['status']??''),'completed'))return false;
    $completed=(int)($followup['completed_at']??0);
    return $completed>0&&$completed>$now-HACHE_SHARKY_FOLLOWUP_SESSION_SECONDS;
}

function hache_sharky_followup_arm_meta(array $state,string $contact,string $dedupeSeed): ?array
{
    $userTurnAt=(int)($state['updated_at']??0);if($userTurnAt<=0)return null;
    [$program,]=hache_sharky_followup_label($state);
    $token=substr(hash('sha256','idle-followup|'.hache_sharky_orchestrator_contact_hash($contact).'|'.$userTurnAt.'|'.$dedupeSeed),0,40);
    return ['token'=>$token,'user_turn_at'=>$userTurnAt,'program'=>$program,'sede_clave'=>(string)($state['commercial_context']['sede_clave']??'')];
}

function hache_sharky_followup_prepare_normal_outbound(PDO $pdo,string $contact,array $payload,string $dedupeSeed,?int $now=null): array
{
    $now??=time();
    try{
        $state=hache_sharky_db_state_load($pdo,$contact);$followup=hache_sharky_followup_state($state);
        if(hache_sharky_followup_user_opted_out((string)($state['last_user_text']??''))){
            $followup['status']='completed_optout';$followup['next_stage']=null;$followup['completed_at']=$now;$followup['token']=null;
            hache_sharky_db_state_save_now($pdo,$contact,hache_sharky_followup_set_state($state,$followup));
            return $payload;
        }
        if((int)$followup['sent_count']>=1&&!str_starts_with((string)$followup['status'],'completed')){
            $followup['status']='completed_after_reply';$followup['next_stage']=null;$followup['completed_at']=$now;$followup['token']=null;
            hache_sharky_db_state_save_now($pdo,$contact,hache_sharky_followup_set_state($state,$followup));
            return $payload;
        }
        if(hache_sharky_followup_completed_recently($followup,$now))return $payload;
        if(!hache_sharky_followup_commercial_ready($state)||!hache_sharky_followup_payload_armable($payload)){
            if(in_array((string)($followup['status']??''),['armed','pending_delivery'],true)){
                $followup['status']='idle';$followup['token']=null;$followup['next_stage']=null;
                hache_sharky_db_state_save_now($pdo,$contact,hache_sharky_followup_set_state($state,$followup));
            }
            return $payload;
        }
        $meta=hache_sharky_followup_arm_meta($state,$contact,$dedupeSeed);if(!is_array($meta))return $payload;
        $followup=['status'=>'pending_delivery','token'=>$meta['token'],'user_turn_at'=>$meta['user_turn_at'],'sent_count'=>0,'next_stage'=>1,'first_due_at'=>null,'first_sent_at'=>null,'second_due_at'=>null,'completed_at'=>null];
        hache_sharky_db_state_save_now($pdo,$contact,hache_sharky_followup_set_state($state,$followup));
        $payload['_sharky_followup_arm']=$meta;
    }catch(Throwable $e){error_log('[sharky-followup] outbound preparation failed');}
    return $payload;
}

function hache_sharky_followup_newer_inbound_pending(PDO $pdo,string $contact,int $userTurnAt): bool
{
    if($userTurnAt<=0)return true;
    try{
        $st=$pdo->prepare("SELECT 1 FROM sharky_message_receipts WHERE contact_hash=:c AND processed_at IS NULL AND payload_ciphertext IS NOT NULL AND received_at>FROM_UNIXTIME(:u) LIMIT 1");
        $st->execute([':c'=>hache_sharky_orchestrator_contact_hash($contact),':u'=>$userTurnAt]);
        return (bool)$st->fetchColumn();
    }catch(Throwable $e){return true;}
}

function hache_sharky_followup_context_matches(array $state,array $meta): bool
{
    [$program,]=hache_sharky_followup_label($state);
    return ($meta['program']??'')===$program&&($meta['sede_clave']??'')===($state['commercial_context']['sede_clave']??'');
}

function hache_sharky_followup_after_normal_sent(PDO $pdo,string $contact,array $meta,?int $now=null): void
{
    $now??=time();$token=trim((string)($meta['token']??''));$userTurnAt=(int)($meta['user_turn_at']??0);
    if($token===''||$userTurnAt<=0)return;
    try{
        $state=hache_sharky_db_state_load($pdo,$contact);$followup=hache_sharky_followup_state($state);
        if(($followup['status']??'')!=='pending_delivery'||!hash_equals((string)($followup['token']??''),$token)||(int)($state['updated_at']??0)!==$userTurnAt)return;
        if($now>=$userTurnAt+HACHE_SHARKY_FOLLOWUP_SESSION_SECONDS||!hache_sharky_followup_commercial_ready($state)||!hache_sharky_followup_context_matches($state,$meta))return;
        $due=hache_sharky_followup_next_allowed_at($now+HACHE_SHARKY_FOLLOWUP_FIRST_DELAY_SECONDS);
        $followup['status']='armed';$followup['first_due_at']=$due;
        $state=hache_sharky_followup_set_state($state,$followup);hache_sharky_db_state_save_now($pdo,$contact,$state);
        $payload=hache_sharky_followup_payload($contact,$state,1,$token,$userTurnAt);
        if(!hache_sharky_outbox_enqueue_raw($pdo,$contact,$payload,'idle-followup|'.$token.'|1',$due)){
            $followup['status']='completed_first_schedule_failed';$followup['next_stage']=null;$followup['token']=null;$followup['completed_at']=$now;
            hache_sharky_db_state_save_now($pdo,$contact,hache_sharky_followup_set_state($state,$followup));
        }
    }catch(Throwable $e){error_log('[sharky-followup] first schedule failed');}
}

function hache_sharky_followup_validate_before_send(PDO $pdo,string $contact,array $meta,?int $now=null): array
{
    $now??=time();$token=trim((string)($meta['token']??''));$stage=(int)($meta['stage']??0);$userTurnAt=(int)($meta['user_turn_at']??0);
    if($token===''||!in_array($stage,[1,2],true)||$userTurnAt<=0)return ['ok'=>false,'reason'=>'INVALID_FOLLOWUP_META'];
    try{$state=hache_sharky_db_state_load($pdo,$contact);}catch(Throwable $e){return ['ok'=>false,'reason'=>'STATE_UNAVAILABLE'];}
    $followup=hache_sharky_followup_state($state);
    if(!hash_equals((string)($followup['token']??''),$token)||((int)($followup['next_stage']??0))!==$stage)return ['ok'=>false,'reason'=>'STALE_FOLLOWUP'];
    if((int)($state['updated_at']??0)!==$userTurnAt)return ['ok'=>false,'reason'=>'USER_REPLIED'];
    if(hache_sharky_followup_newer_inbound_pending($pdo,$contact,$userTurnAt))return ['ok'=>false,'reason'=>'PENDING_INBOUND'];
    if($now>=$userTurnAt+HACHE_SHARKY_FOLLOWUP_SESSION_SECONDS)return ['ok'=>false,'reason'=>'SESSION_EXPIRED'];
    if(!hache_sharky_followup_commercial_ready($state))return ['ok'=>false,'reason'=>'CONTEXT_NOT_ELIGIBLE'];
    if(!hache_sharky_followup_context_matches($state,$meta))return ['ok'=>false,'reason'=>'CONTEXT_CHANGED'];
    if(!hache_sharky_followup_send_allowed_now($now))return ['ok'=>false,'reason'=>'QUIET_HOURS','reschedule_at'=>hache_sharky_followup_next_allowed_at($now)];
    return ['ok'=>true,'state'=>$state];
}

function hache_sharky_followup_note_cancelled(PDO $pdo,string $contact,array $meta,string $reason,?int $now=null): void
{
    $now??=time();$token=trim((string)($meta['token']??''));if($token==='')return;
    try{
        $state=hache_sharky_db_state_load($pdo,$contact);$followup=hache_sharky_followup_state($state);
        if(!hash_equals((string)($followup['token']??''),$token))return;
        if(in_array($reason,['USER_REPLIED','PENDING_INBOUND','SESSION_EXPIRED','CONTEXT_NOT_ELIGIBLE','CONTEXT_CHANGED'],true)){
            $followup['status']='completed_'.strtolower($reason);$followup['next_stage']=null;$followup['token']=null;$followup['completed_at']=$now;
            hache_sharky_db_state_save_now($pdo,$contact,hache_sharky_followup_set_state($state,$followup));
        }
    }catch(Throwable $e){error_log('[sharky-followup] cancellation state update failed');}
}

function hache_sharky_followup_after_sent(PDO $pdo,string $contact,array $meta,?int $now=null): void
{
    $now??=time();$token=trim((string)($meta['token']??''));$stage=(int)($meta['stage']??0);$userTurnAt=(int)($meta['user_turn_at']??0);
    if($token===''||!in_array($stage,[1,2],true))return;
    try{
        $state=hache_sharky_db_state_load($pdo,$contact);$followup=hache_sharky_followup_state($state);
        if(!hash_equals((string)($followup['token']??''),$token)||(int)($state['updated_at']??0)!==$userTurnAt)return;
        if($stage===1){
            $due=hache_sharky_followup_next_allowed_at($now+HACHE_SHARKY_FOLLOWUP_SECOND_DELAY_SECONDS);
            $followup['status']='first_sent';$followup['sent_count']=1;$followup['next_stage']=2;$followup['first_sent_at']=$now;$followup['second_due_at']=$due;
            $state=hache_sharky_followup_set_state($state,$followup);hache_sharky_db_state_save_now($pdo,$contact,$state);
            $payload=hache_sharky_followup_payload($contact,$state,2,$token,$userTurnAt);
            if(!hache_sharky_outbox_enqueue_raw($pdo,$contact,$payload,'idle-followup|'.$token.'|2',$due)){
                $followup['status']='completed_second_schedule_failed';$followup['next_stage']=null;$followup['token']=null;$followup['completed_at']=$now;
                hache_sharky_db_state_save_now($pdo,$contact,hache_sharky_followup_set_state($state,$followup));
            }
            return;
        }
        $followup['status']='completed_two_sent';$followup['sent_count']=2;$followup['next_stage']=null;$followup['token']=null;$followup['completed_at']=$now;
        hache_sharky_db_state_save_now($pdo,$contact,hache_sharky_followup_set_state($state,$followup));
    }catch(Throwable $e){error_log('[sharky-followup] post-send state update failed');}
}
