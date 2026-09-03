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

function hache_sharky_whatsapp_nado_libre_request(string $text): bool
{
    $t=hache_sharky_orchestrator_normalize($text);
    if($t==='')return false;
    if(preg_match('/\bnado\s+libre\b|\bnadar\s+libre(?:mente)?\b/u',$t)===1)return true;
    $request='(?:puedo|podria|se\s+puede|dejan|permiten|hay|tienen|ofrecen|quiero|quisiera)';
    $activity='(?:nadar|entrenar|practicar|usar\s+(?:la\s+)?alberca)';
    $without='(?:sin\s+(?:tomar\s+)?clases?|sin\s+(?:un\s+)?entrenador|por\s+mi\s+cuenta|(?:ir|nadar|entrenar)\s+solo|(?:ir|nadar|entrenar)\s+sola)';
    return preg_match('/\b'.$request.'\b.{0,55}\b'.$activity.'\b.{0,45}\b'.$without.'\b/u',$t)===1
        || preg_match('/\b'.$request.'\b.{0,55}\b'.$without.'\b.{0,45}\b'.$activity.'\b/u',$t)===1;
}

function hache_sharky_whatsapp_nado_libre_message(): string
{
    return 'No. Hache Natación no ofrece nado libre ni acceso a la alberca sin clase. Las actividades se realizan dentro de nuestros cursos o clases.';
}

function hache_sharky_whatsapp_commercial_ready(array $state): bool
{
    if(($state['identity']['kind']??'')!=='prospect')return false;
    $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    if(!in_array(($commercial['program']??null),['intensive','regular'],true))return false;
    if(!in_array(($commercial['sede_clave']??null),['MONTEVERDE','PALAPAS'],true))return false;
    return is_int($commercial['age']??null);
}

function hache_sharky_whatsapp_commercial_ready_message(array $state,string $prefix='Perfecto.'): string
{
    $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    $program=($commercial['program']??null)==='regular'?'clases regulares':'curso intensivo';
    $sede=($commercial['sede_clave']??null)==='MONTEVERDE'?'Monteverde':'Palapas Protudec';
    $age=is_int($commercial['age']??null)?(int)$commercial['age']:null;
    $summary=$program.' en '.$sede.($age!==null?' para una persona de '.$age.' años':'');
    if(($commercial['program']??null)==='intensive')return rtrim($prefix).' Ya tengo: '.$summary.'. ¿Quieres que te muestre horarios disponibles o prefieres que te ayude a inscribirte?';
    return rtrim($prefix).' Ya tengo: '.$summary.'. ¿Quieres que te muestre horarios o precios?';
}

function hache_sharky_whatsapp_low_information_reengagement(string $text): bool
{
    $t=hache_sharky_orchestrator_normalize($text);
    if($t==='')return true;
    if(preg_match('/[\p{L}\p{N}]/u',$t)!==1)return true;
    $greeting=preg_replace('/^[.!?¿¡\s]+|[.!?¿¡\s]+$/u','',$t)??$t;
    return preg_match('/^(?:hola|holi|buenas|hey|ey|que\s+tal|ola)$/u',$greeting)===1;
}

function hache_sharky_whatsapp_user_asks_assistant_identity(string $text): bool
{
    $t=hache_sharky_orchestrator_normalize($text);
    return preg_match('/\b(?:quien\s+eres|quien\s+es\s+sharky|como\s+te\s+llamas|eres\s+sharky|que\s+eres)\b/u',$t)===1;
}

function hache_sharky_whatsapp_enforce_no_reintroduction(string $answer,array $state,string $userText=''): string
{
    if(hache_sharky_whatsapp_user_asks_assistant_identity($userText))return $answer;
    $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    $conversationUnderway=(($state['assistant_presentation_queued']??false)===true)
        || (($state['identity']['kind']??'unknown')!=='unknown')
        || in_array(($commercial['program']??null),['intensive','regular'],true)
        || in_array(($commercial['sede_clave']??null),['MONTEVERDE','PALAPAS'],true)
        || is_int($commercial['age']??null);
    if(!$conversationUnderway)return $answer;

    $parts=preg_split('/\n+|(?<=[.!?;])\s+|(?=¿)/u',$answer)?:[];$kept=[];$removed=false;
    foreach($parts as $part){
        $part=trim((string)$part);if($part==='')continue;
        $t=hache_sharky_orchestrator_normalize($part);
        $introduces=(str_contains($t,'sharky')&&str_contains($t,'hache natacion')&&preg_match('/\b(?:soy|me\s+llamo|asistente(?:\s+ia)?|soy\s+el\s+asistente)\b/u',$t)===1);
        if($introduces){$removed=true;continue;}
        $kept[]=$part;
    }
    if(!$removed)return $answer;
    $safe=hache_sharky_whatsapp_clean_answer(implode("\n\n",$kept));
    if($safe!==''&&!hache_sharky_whatsapp_low_information_reengagement($safe))return $safe;
    if(hache_sharky_whatsapp_commercial_ready($state))return hache_sharky_whatsapp_commercial_ready_message($state,'Sigo contigo.');
    $next=hache_sharky_orchestrator_next_required_step($state);$prompt=trim((string)($next['prompt']??''));
    return $prompt!==''?'Sigo contigo. '.$prompt:'Sigo contigo. ¿En qué te ayudo?';
}

function hache_sharky_whatsapp_question_targets_slot(string $text,string $slot): bool
{
    $t=hache_sharky_orchestrator_normalize($text);
    if($t==='')return false;
    $looksLikeQuestion=str_contains($text,'?')||str_contains($text,'¿')
        || preg_match('/^(?:antes de seguir,?\s*)?(?:que|cual|cuales|en que|donde|eres|ya eres|tienes|cuantos|cuantas|prefieres|buscas)\b/u',$t)===1;
    if(!$looksLikeQuestion)return false;

    if($slot==='identity')return preg_match('/\b(?:ya\s+)?eres\s+(?:alumno|alumna|estudiante)\b|\b(?:alumno|alumna|estudiante)\s+o\s+(?:nuevo|nueva)\b/u',$t)===1;
    if($slot==='program'){
        $intensive='(?:curso\s+intensivo|intensivo)';
        $regular='(?:clases?\s+regulares|curso\s+regular|regular(?:es)?)';
        $alternatives=preg_match('/\b'.$intensive.'\b.{0,60}\b'.$regular.'\b|\b'.$regular.'\b.{0,60}\b'.$intensive.'\b/u',$t)===1;
        $generic=preg_match('/\b(?:que|cual)\s+(?:tipo\s+de\s+)?(?:curso|programa)\b/u',$t)===1;
        $single=preg_match('/\b(?:prefieres|eliges|escoges|te\s+quedas\s+con|vas\s+con|quieres|buscas)\s+(?:(?:tomar|hacer|llevar)\s+)?(?:(?:el|un|las?|unas?)\s+)?(?:'.$intensive.'|'.$regular.')\b/u',$t)===1;
        return $alternatives||$generic||$single;
    }
    if($slot==='sede'){
        $venue='(?:palapas(?:\s+protudec)?|monteverde)';
        $alternatives=preg_match('/\bpalapas(?:\s+protudec)?\b.{0,60}\bmonteverde\b|\bmonteverde\b.{0,60}\bpalapas(?:\s+protudec)?\b/u',$t)===1;
        $generic=preg_match('/\b(?:en\s+que|cual|que)\s+sede\b|\bdonde\s+(?:quieres|prefieres|tomarias|serian)\b/u',$t)===1;
        $single=preg_match('/\b(?:prefieres|quieres|eliges|escoges|te\s+queda\s+mejor|vas\s+a|te\s+interesa)\s+(?:(?:la\s+)?sede\s+)?(?:en\s+)?'.$venue.'\b/u',$t)===1
            || preg_match('/\b(?:prefieres|quieres)\s+(?:tomar|hacer|llevar)\s+(?:las?\s+)?clases\s+en\s+'.$venue.'\b/u',$t)===1;
        return $alternatives||$generic||$single;
    }
    if($slot==='age')return preg_match('/\b(?:que\s+edad|cuantos?\s+anos|edad\s+tiene|tienes\s+cuantos?)\b/u',$t)===1;
    return false;
}

function hache_sharky_whatsapp_answer_asks_slot(string $answer,string $slot): bool
{
    foreach(preg_split('/\n+|(?<=[.!?;])\s+|(?=¿)/u',$answer)?:[] as $part){
        if(hache_sharky_whatsapp_question_targets_slot(trim((string)$part),$slot))return true;
    }
    return false;
}

/**
 * Last line of defence for model output: confirmed facts cannot be requested
 * again even when the model ignores its prompt. Useful content is preserved;
 * only contradictory discovery questions are removed.
 */
function hache_sharky_whatsapp_enforce_confirmed_context(string $answer,array $state): string
{
    $confirmed=[];
    if(($state['identity']['kind']??'unknown')!=='unknown')$confirmed[]='identity';
    $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    if(in_array(($commercial['program']??null),['intensive','regular'],true))$confirmed[]='program';
    if(in_array(($commercial['sede_clave']??null),['MONTEVERDE','PALAPAS'],true))$confirmed[]='sede';
    if(is_int($commercial['age']??null))$confirmed[]='age';
    if(!$confirmed)return $answer;

    $kept=[];$removed=false;
    foreach(preg_split('/\n+|(?<=[.!?;])\s+|(?=¿)/u',$answer)?:[] as $part){
        $part=trim((string)$part);if($part==='')continue;
        $repeats=false;
        foreach($confirmed as $slot){
            if(hache_sharky_whatsapp_question_targets_slot($part,$slot)){$repeats=true;break;}
        }
        if($repeats){$removed=true;continue;}
        $kept[]=$part;
    }
    if(!$removed)return $answer;

    $safe=hache_sharky_whatsapp_clean_answer(implode("\n\n",$kept));
    if($safe==='')$safe='Perfecto, ya tengo esos datos.';
    $next=hache_sharky_orchestrator_next_required_step($state);
    $slot=(string)($next['slot']??'');$prompt=trim((string)($next['prompt']??''));
    if($slot!==''&&$prompt!==''&&!hache_sharky_whatsapp_answer_asks_slot($safe,$slot))$safe=rtrim($safe)."\n\n".$prompt;
    return $safe;
}

function hache_sharky_whatsapp_style_instruction(array $decision,array $state): string
{
    $instruction='Responde para WhatsApp en español natural. Sé breve y fácil de escanear en móvil. No repitas tu presentación ni información ya dada. Responde primero a la pregunta actual y termina con una sola pregunta útil si hace falta avanzar. Si muestras horarios, precios, formas de pago o información estructurada, sepárala por sede o categoría con encabezados cortos y saltos de línea; cuando haya varios horarios, pon cada horario en una viñeta breve y nunca una tira larga de horas en una sola línea. Separa precios de horarios. Puedes usar un emoji funcional en un encabezado (por ejemplo 📍, 🕐, 💰 o ✅), pero no en cada línea ni como infografía. No inventes horarios, precios ni disponibilidad: usa solo datos actuales del backend/contexto. Hache Natación no ofrece nado libre ni acceso a la alberca sin clase.';
    $latest=$state['referral']['latest']??null;
    if(is_array($latest)&&!empty($latest['headline']))$instruction.=' El usuario llegó desde un anuncio cuyo contexto es: '.mb_substr((string)$latest['headline'],0,180).'. Úsalo como contexto, pero no asumas que sigue siendo su intención actual.';
    $commercial=$state['commercial_context']??null;
    if(is_array($commercial)){
        $known=[];
        if(($commercial['program']??null)==='intensive')$known[]='programa: curso intensivo';
        elseif(($commercial['program']??null)==='regular')$known[]='programa: clases regulares';
        if(($commercial['sede_clave']??null)==='PALAPAS')$known[]='sede: Palapas Protudec';
        elseif(($commercial['sede_clave']??null)==='MONTEVERDE')$known[]='sede: Monteverde';
        if(is_int($commercial['age']??null))$known[]='edad: '.$commercial['age'].' años';
        if($known)$instruction.=' Contexto comercial ya confirmado por el usuario: '.implode(', ',$known).'. Trátalo como memoria vigente. No vuelvas a preguntar estos datos salvo que el usuario los cambie explícitamente.';
    }
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
    $operationalToday=(new DateTimeImmutable('today',new DateTimeZone('America/Cancun')))->format('Y-m-d');
    $today=(string)($extra['today']??$operationalToday);
    $intensiveOptions=array_values(array_filter(
        hache_sharky_business_intensive_options($pdo),
        static fn(array $option):bool=>hache_sharky_start_authority_intensive_date_allowed((string)($option['fecha_inicio']??''),$today)
    ));
    return array_replace($extra,[
        'identity'=>$identity,
        'verification'=>$verification,
        'intensive_options'=>$intensiveOptions,
        'today'=>$today,
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
    $intent=hache_sharky_orchestrator_contextual_intent($state,$text,'');
    if(in_array($intent,['yes','no','cancel','human','absence','register_intensive'],true))return false;
    $t=hache_sharky_orchestrator_normalize($text);
    return str_contains($text,'?')||preg_match('/^(cuanto|como|donde|cuando|que |aceptan|puedo|tienen|hay |cual)/u',$t)===1;
}

function hache_sharky_whatsapp_complete_receipt(PDO $pdo,string $messageId,array $extraContext): void
{
    if(($extraContext['defer_receipt_completion']??false)===true)return;
    hache_sharky_orchestrator_mark_processed($pdo,$messageId);
}

function hache_sharky_whatsapp_process(PDO $pdo,array $event,callable $conversationAnswer,array $extraContext=[]): array
{
    $contact=(string)($event['from']??'');$messageId=(string)($event['id']??'');
    if($contact===''||$messageId==='')return ['skip'=>true,'code'=>'INVALID_EVENT'];
    $contactHash=hache_sharky_orchestrator_contact_hash($contact);
    if(!hache_sharky_orchestrator_claim_message($pdo,$messageId,$contactHash,(string)($event['type']??'text')))return ['skip'=>true,'code'=>'DUPLICATE'];

    $lock=hache_sharky_orchestrator_lock($contact);
    if(!is_resource($lock))return ['skip'=>true,'code'=>'CONTACT_LOCK_UNAVAILABLE'];
    try{
        $state=hache_sharky_db_state_load($pdo,$contact);
        $context=hache_sharky_whatsapp_context($pdo,$contact,$extraContext);
        $state=hache_sharky_whatsapp_resume_verified_state($state,$context,(int)$context['now']);
        $ref=hache_sharky_orchestrator_referral($event,(int)$context['now']);
        if($ref)hache_sharky_orchestrator_store_referral($pdo,$messageId,$contactHash,$ref,($context['identity']['found']??false)?(string)$context['identity']['student_id']:null);

        if(!hache_sharky_whatsapp_interactive_is_current($state,$event)){
            $decision=hache_sharky_orchestrator_decision('stale_interactive','Esa opción pertenece a un paso anterior. No hice ningún cambio; continuemos desde la opción que tienes activa ahora.');
            hache_sharky_db_state_save($pdo,$contact,$state);hache_sharky_whatsapp_complete_receipt($pdo,$messageId,$extraContext);
            return ['skip'=>false,'state'=>$state,'decision'=>$decision,'payload'=>hache_sharky_whatsapp_render($contact,$decision),'action_result'=>null];
        }

        if(trim((string)($event['interactive_id']??''))===''&&hache_sharky_whatsapp_nado_libre_request((string)($event['text']??''))){
            $message=hache_sharky_whatsapp_nado_libre_message();
            if(is_array($state['flow']??null))$message.="\n\nCuando quieras, seguimos donde lo dejamos.";
            $decision=hache_sharky_orchestrator_decision('nado_libre_unavailable',$message);
            hache_sharky_db_state_save($pdo,$contact,$state);hache_sharky_whatsapp_complete_receipt($pdo,$messageId,$extraContext);
            return ['skip'=>false,'state'=>$state,'decision'=>$decision,'payload'=>hache_sharky_whatsapp_render($contact,$decision),'action_result'=>null];
        }

        if(trim((string)($event['interactive_id']??''))===''&&!is_array($state['flow']??null)&&hache_sharky_whatsapp_commercial_ready($state)&&hache_sharky_whatsapp_low_information_reengagement((string)($event['text']??''))){
            $decision=hache_sharky_orchestrator_decision('commercial_reengagement',hache_sharky_whatsapp_commercial_ready_message($state,'Sigo contigo.'));
            hache_sharky_db_state_save($pdo,$contact,$state);hache_sharky_whatsapp_complete_receipt($pdo,$messageId,$extraContext);
            return ['skip'=>false,'state'=>$state,'decision'=>$decision,'payload'=>hache_sharky_whatsapp_render($contact,$decision),'action_result'=>null];
        }

        $preIntent=hache_sharky_orchestrator_contextual_intent($state,(string)($event['text']??''),(string)($event['interactive_id']??''));
        if(!is_array($state['flow']??null)&&$preIntent==='register_intensive'&&(($context['identity']['found']??false)===true||(($state['identity']['kind']??'')==='student'&&($state['identity']['verified']??false)===true))){
            $state=hache_sharky_orchestrator_clear_flow($state);
            $decision=hache_sharky_orchestrator_decision('existing_student_intensive_handoff','Veo que este número ya está vinculado a un alumno. Para evitar duplicar tu expediente, el equipo continuará contigo la inscripción al intensivo por este mismo chat.',[],['type'=>'human_takeover']);
            hache_sharky_db_state_save($pdo,$contact,$state);hache_sharky_whatsapp_complete_receipt($pdo,$messageId,$extraContext);
            return ['skip'=>false,'state'=>$state,'decision'=>$decision,'payload'=>hache_sharky_whatsapp_render($contact,$decision),'action_result'=>['ok'=>true,'code'=>'HANDOFF']];
        }

        if(hache_sharky_whatsapp_is_side_question($state,$event)){
            $instruction=hache_sharky_whatsapp_style_instruction(['kind'=>'side_question'],$state).' El usuario está dentro de un proceso controlado: responde solo la duda actual, no pierdas ni cambies ese proceso y no vuelvas a pedir datos ya capturados.';
            $answer=hache_sharky_whatsapp_clean_answer((string)$conversationAnswer((string)($event['text']??''),$instruction,$state,$context));
            $answer=hache_sharky_whatsapp_enforce_confirmed_context($answer,$state);
            $answer=hache_sharky_whatsapp_enforce_no_reintroduction($answer,$state,(string)($event['text']??''));
            $answer=rtrim($answer)."\n\nCuando quieras, seguimos donde lo dejamos.";
            hache_sharky_db_state_save($pdo,$contact,$state);hache_sharky_whatsapp_complete_receipt($pdo,$messageId,$extraContext);
            return ['skip'=>false,'state'=>$state,'decision'=>['kind'=>'side_question','message'=>$answer,'ui'=>[],'action'=>null],'payload'=>hache_sharky_whatsapp_text_payload($contact,$answer),'action_result'=>null];
        }

        $stateBeforeOrchestrate=$state;
        $result=hache_sharky_orchestrate($state,$event,$context);$state=$result['state'];$decision=$result['decision'];
        if(($decision['kind']??'')==='conversation'&&!hache_sharky_whatsapp_commercial_ready($stateBeforeOrchestrate)&&hache_sharky_whatsapp_commercial_ready($state)){
            $decision=hache_sharky_orchestrator_decision('commercial_ready',hache_sharky_whatsapp_commercial_ready_message($state));
        }
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
            $conversation=hache_sharky_whatsapp_enforce_confirmed_context($conversation,$state);
            $conversation=hache_sharky_whatsapp_enforce_no_reintroduction($conversation,$state,(string)($event['text']??''));
            if(($decision['kind']??'')==='conversation_identity_prompt'&&!hache_sharky_whatsapp_answer_asks_slot($conversation,'identity'))$conversation=rtrim($conversation)."\n\nAntes de seguir, ¿ya eres alumno de Hache Natación?";
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
        hache_sharky_whatsapp_complete_receipt($pdo,$messageId,$extraContext);
        return ['skip'=>false,'state'=>$state,'decision'=>$decision,'payload'=>hache_sharky_whatsapp_render($contact,$decision,$conversation,$verificationUrl),'action_result'=>$actionResult];
    }finally{hache_sharky_orchestrator_unlock($lock);}
}
