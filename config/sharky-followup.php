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

function hache_sharky_followup_user_opted_out(string $text): bool
{
    $t=hache_sharky_orchestrator_normalize($text);
    if($t==='')return false;
    return preg_match('/\b(no\s+gracias|no\s+me\s+interesa|ya\s+no\s+me\s+interesa|dejalo|dejala|yo\s+te\s+aviso|luego\s+te\s+escribo|despues\s+te\s+escribo|solo\s+estaba\s+preguntando|solo\s+queria\s+informacion)\b/u',$t)===1;
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
    if(is_array($payload['_sharky_followup']??null)||($payload['_sharky_allow_takeover']??false)===true)return false;
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

function hache_sharky_followup_on_normal_outbound(PDO $pdo,string $contact,array $payload,string $dedupeSeed,?int $now=null): void
{
    $now??=time();
    try{
        $state=hache_sharky_db_state_load($pdo,$contact);
        $followup=hache_sharky_followup_state($state);
        if((int)$followup['sent_count']>=1&&!str_starts_with((string)$followup['status'],'completed')){
            $followup['status']='completed_after_reply';$followup['next_stage']=null;$followup['completed_at']=$now;$followup['token']=null;
            hache_sharky_db_state_save_now($pdo,$contact,hache_sharky_followup_set_state($state,$followup));
            return;
        }
        if(hache_sharky_followup_completed_recently($followup,$now))return;
        if(!hache_sharky_followup_commercial_ready($state)||!hache_sharky_followup_payload_armable($payload)){
            if(($followup['status']??'')==='armed'){
                $followup['status']='idle';$followup['token']=null;$followup['next_stage']=null;
                hache_sharky_db_state_save_now($pdo,$contact,hache_sharky_followup_set_state($state,$followup));
            }
            return;
        }
        $userTurnAt=(int)($state['updated_at']??0);if($userTurnAt<=0)return;
        $token=substr(hash('sha256','idle-followup|'.hache_sharky_orchestrator_contact_hash($contact).'|'.$userTurnAt.'|'.$dedupeSeed),0,40);
        $due=hache_sharky_followup_next_allowed_at($now+HACHE_SHARKY_FOLLOWUP_FIRST_DELAY_SECONDS);
        $followup=['status'=>'armed','token'=>$token,'user_turn_at'=>$userTurnAt,'sent_count'=>0,'next_stage'=>1,'first_due_at'=>$due,'first_sent_at'=>null,'second_due_at'=>null,'completed_at'=>null];
        $state=hache_sharky_followup_set_state($state,$followup);
        hache_sharky_db_state_save_now($pdo,$contact,$state);
        $followPayload=hache_sharky_followup_payload($contact,$state,1,$token,$userTurnAt);
        if(!hache_sharky_outbox_enqueue_raw($pdo,$contact,$followPayload,'idle-followup|'.$token.'|1',$due))error_log('[sharky-followup] unable to schedule first follow-up');
    }catch(Throwable $e){error_log('[sharky-followup] arm failed');}
}

function hache_sharky_followup_validate_before_send(PDO $pdo,string $contact,array $meta,?int $now=null): array
{
    $now??=time();$token=trim((string)($meta['token']??''));$stage=(int)($meta['stage']??0);$userTurnAt=(int)($meta['user_turn_at']??0);
    if($token===''||!in_array($stage,[1,2],true)||$userTurnAt<=0)return ['ok'=>false,'reason'=>'INVALID_FOLLOWUP_META'];
    try{$state=hache_sharky_db_state_load($pdo,$contact);}catch(Throwable $e){return ['ok'=>false,'reason'=>'STATE_UNAVAILABLE'];}
    $followup=hache_sharky_followup_state($state);
    if(!hash_equals((string)($followup['token']??''),$token)||((int)($followup['next_stage']??0))!==$stage)return ['ok'=>false,'reason'=>'STALE_FOLLOWUP'];
    if((int)($state['updated_at']??0)!==$userTurnAt){return ['ok'=>false,'reason'=>'USER_REPLIED'];}
    if($now>=$userTurnAt+HACHE_SHARKY_FOLLOWUP_SESSION_SECONDS)return ['ok'=>false,'reason'=>'SESSION_EXPIRED'];
    if(!hache_sharky_followup_commercial_ready($state))return ['ok'=>false,'reason'=>'CONTEXT_NOT_ELIGIBLE'];
    [$program,]=$labels=hache_sharky_followup_label($state);
    if(($meta['program']??'')!==$program||($meta['sede_clave']??'')!==($state['commercial_context']['sede_clave']??''))return ['ok'=>false,'reason'=>'CONTEXT_CHANGED'];
    if(!hache_sharky_followup_send_allowed_now($now))return ['ok'=>false,'reason'=>'QUIET_HOURS','reschedule_at'=>hache_sharky_followup_next_allowed_at($now)];
    return ['ok'=>true,'state'=>$state];
}

function hache_sharky_followup_note_cancelled(PDO $pdo,string $contact,array $meta,string $reason,?int $now=null): void
{
    $now??=time();$token=trim((string)($meta['token']??''));if($token==='')return;
    try{
        $state=hache_sharky_db_state_load($pdo,$contact);$followup=hache_sharky_followup_state($state);
        if(!hash_equals((string)($followup['token']??''),$token))return;
        if($reason==='USER_REPLIED'||$reason==='SESSION_EXPIRED'||$reason==='CONTEXT_NOT_ELIGIBLE'||$reason==='CONTEXT_CHANGED'){
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
