<?php

declare(strict_types=1);

/**
 * Onboarding determinístico inicial para prospectos nuevos.
 *
 * Este bloque recopila únicamente la identidad mínima necesaria antes de entrar
 * al catálogo comercial: nombre del contacto, quién tomará las clases, edad y
 * nivel declarado. Después entrega el control al catálogo comercial existente.
 *
 * `background=advanced` significa exclusivamente "nivel avanzado declarado".
 * No afirma ni inventa que la persona haya tomado clases formales.
 */

function hache_sharky_prospect_onboarding_active(array $state): bool
{
    $flow=$state['flow']??null;
    return is_array($flow)&&($flow['name']??'')==='prospect_onboarding';
}

function hache_sharky_prospect_onboarding_name(string $text): ?string
{
    $name=trim(preg_replace('/\s+/u',' ',preg_replace('/[\x00-\x1F\x7F]+/u',' ',trim($text))??'')??'');
    if($name==='')return null;
    $name=preg_replace('/^(?:me\s+llamo|mi\s+nombre\s+es|soy)\s+/iu','',$name)??$name;
    $name=trim($name," \t\n\r\0\x0B,;:!¡?¿");
    if($name===''||mb_strlen($name,'UTF-8')>60)return null;
    if(preg_match('/(?:https?:\/\/|www\.|\b\S+@\S+\b|\.(?:com|mx|net|org)\b)/iu',$name)===1)return null;
    if(preg_match('/\d/u',$name)===1)return null;
    if(preg_match('/^[\p{L}\p{M}][\p{L}\p{M}.\'’\- ]*$/u',$name)!==1)return null;
    $normalized=hache_sharky_orchestrator_normalize($name);
    // No persistir como nombre frases conversacionales comunes. El usuario puede
    // responderlas al no entender la pregunta; en ese caso debemos reintentar.
    if(preg_match('/^(?:hola|buen\s+dia|buenos\s+dias|buenas\s+tardes|buenas\s+noches|buenas?|gracias|no\s+entendi|no\s+entiendo|no\s+se|quiero|quisiera|necesito|busco|me\s+interesa|dame|mandame|informacion)\b/u',$normalized)===1)return null;
    if(function_exists('hache_sharky_whatsapp_venue_help_request')&&hache_sharky_whatsapp_venue_help_request($name))return null;
    if(function_exists('hache_sharky_whatsapp_batch_question_like')&&hache_sharky_whatsapp_batch_question_like($name))return null;
    $parts=array_values(array_filter(preg_split('/\s+/u',$name)?:[],static fn(string $part):bool=>$part!==''));
    if(count($parts)<1||count($parts)>6)return null;
    return mb_convert_case($name,MB_CASE_TITLE,'UTF-8');
}

function hache_sharky_prospect_onboarding_yes_no(string $text,string $interactiveId='',string $scope=''): ?bool
{
    $id=strtolower(trim($interactiveId));
    $expectedYes=$scope==='background'?'onboarding:background:yes':'onboarding:self:yes';
    $expectedNo=$scope==='background'?'onboarding:background:no':'onboarding:self:no';
    if($id===$expectedYes)return true;
    if($id===$expectedNo)return false;
    // Un botón de un paso anterior no puede responder otro paso.
    if($id!=='')return null;
    $t=hache_sharky_orchestrator_normalize($text);
    if(preg_match('/^(?:si|sí|claro|correcto|asi\s+es|así\s+es|para\s+mi|son\s+para\s+mi|yo)[.! ]*$/u',$t)===1)return true;
    if(preg_match('/^(?:no|no\s+son\s+para\s+mi|es\s+para\s+otra\s+persona|para\s+otra\s+persona)[.! ]*$/u',$t)===1)return false;
    return null;
}

function hache_sharky_prospect_onboarding_level(string $text,string $interactiveId=''): ?string
{
    $id=strtolower(trim($interactiveId));
    $map=[
        'onboarding:level:beginner'=>'beginner',
        'onboarding:level:intermediate'=>'intermediate',
        'onboarding:level:advanced'=>'advanced',
    ];
    if(isset($map[$id]))return $map[$id];
    if($id!=='')return null;
    $t=hache_sharky_orchestrator_normalize($text);
    if(preg_match('/^(?:principiante|basico|basica|desde\s+cero|de\s+ceros?|nada\s+de\s+nada|no\s+(?:se\s+)?nadar|quiero\s+aprender\s+a\s+nadar)[.! ]*$/u',$t)===1)return 'beginner';
    if(function_exists('hache_sharky_whatsapp_detect_swim_level')&&hache_sharky_whatsapp_detect_swim_level($text)==='beginner')return 'beginner';
    if(preg_match('/^(?:intermedio|intermedia)[.! ]*$/u',$t)===1)return 'intermediate';
    if(preg_match('/^(?:avanzado|avanzada)[.! ]*$/u',$t)===1)return 'advanced';
    return null;
}

function hache_sharky_prospect_onboarding_contact_name(array $state): string
{
    $name=trim((string)($state['commercial_context']['prospect_name']??''));
    return $name!==''?$name:'Perfecto';
}

function hache_sharky_prospect_onboarding_input_matches_step(array $state,array $event): bool
{
    if(!hache_sharky_prospect_onboarding_active($state))return false;
    $flow=$state['flow'];
    $step=(string)($flow['step']??'');
    $data=is_array($flow['data']??null)?$flow['data']:[];
    $text=trim((string)($event['text']??''));
    $id=strtolower(trim((string)($event['interactive_id']??'')));
    if($step==='name'||$step==='student_name')return hache_sharky_prospect_onboarding_name($text)!==null;
    if($step==='participant')return hache_sharky_prospect_onboarding_yes_no($text,$id,'participant')!==null;
    if($step==='age')return function_exists('hache_sharky_whatsapp_declared_age')&&hache_sharky_whatsapp_declared_age($text)!==null;
    if($step==='level')return hache_sharky_prospect_onboarding_level($text,$id)!==null;
    if($step==='intermediate_background')return hache_sharky_prospect_onboarding_yes_no($text,$id,'background')!==null;
    if($step==='product_info'){
        $program=(string)($data['program']??($state['commercial_context']['program']??''));
        return $id===($program==='regular'?'onboarding:info:regular':'onboarding:info:intensive');
    }
    if($step==='sede'){
        if(hache_sharky_prospect_onboarding_both_venues($text,$id))return true;
        $sede=function_exists('hache_sharky_whatsapp_detect_venue_preference')
            ?hache_sharky_whatsapp_detect_venue_preference($text,$id):null;
        return in_array($sede,['MONTEVERDE','PALAPAS'],true);
    }
    return false;
}

function hache_sharky_prospect_onboarding_side_question(array $state,array $event): bool
{
    if(!hache_sharky_prospect_onboarding_active($state))return false;
    if(trim((string)($event['interactive_id']??''))!=='')return false;
    $text=trim((string)($event['text']??''));
    if($text===''||hache_sharky_prospect_onboarding_input_matches_step($state,$event))return false;
    $intent=hache_sharky_orchestrator_contextual_intent($state,$text,'');
    if(in_array($intent,['human','student_claim','cancel'],true))return false;

    $probe=hache_sharky_orchestrator_normalize($text);
    $probe=preg_replace('/^(?:(?:hola|buen\s+dia|buenos\s+dias|buenas\s+tardes|buenas\s+noches|buenas)\b[\s,;:!.-]*)+/u','',$probe)??$probe;
    if(function_exists('hache_sharky_whatsapp_venue_help_request')&&hache_sharky_whatsapp_venue_help_request($probe))return true;
    if(function_exists('hache_sharky_whatsapp_batch_question_like')&&hache_sharky_whatsapp_batch_question_like($probe))return true;
    return str_contains($text,'?')||str_contains($text,'¿');
}

function hache_sharky_prospect_onboarding_resume_decision(array $state): array
{
    $flow=is_array($state['flow']??null)?$state['flow']:[];
    $step=(string)($flow['step']??'');
    $data=is_array($flow['data']??null)?$flow['data']:[];
    if($step==='name')return hache_sharky_orchestrator_decision('prospect_name_prompt','Antes de seguir, ¿me puedes decir tu nombre, por favor?');
    if($step==='participant')return hache_sharky_orchestrator_decision('prospect_participant_prompt','Para seguir, ¿las clases son para ti?',['type'=>'buttons','buttons'=>[
        hache_sharky_orchestrator_button('onboarding:self:yes','Sí'),
        hache_sharky_orchestrator_button('onboarding:self:no','No'),
    ]]);
    if($step==='student_name')return hache_sharky_orchestrator_decision('prospect_student_name_prompt','Para seguir, ¿cómo se llama la persona que tomaría las clases?');
    if($step==='age'){
        $self=($state['commercial_context']['participant_relation']??'self')==='self';
        $participant=trim((string)($state['commercial_context']['participant_name']??''));
        $question=$self?'¿Qué edad tienes?':($participant!==''?'¿Qué edad tiene '.$participant.'?':'¿Qué edad tiene la persona que tomaría las clases?');
        return hache_sharky_orchestrator_decision('prospect_age_prompt','Para seguir, '.$question);
    }
    if($step==='level')return hache_sharky_orchestrator_decision('prospect_level_prompt','Para seguir, escoge el nivel que mejor te representa:',hache_sharky_prospect_onboarding_level_ui());
    if($step==='intermediate_background')return hache_sharky_orchestrator_decision('prospect_intermediate_background_prompt','Para seguir, ¿ya has tomado clases de natación antes?',['type'=>'buttons','buttons'=>[
        hache_sharky_orchestrator_button('onboarding:background:yes','Sí'),
        hache_sharky_orchestrator_button('onboarding:background:no','No'),
    ]]);
    if($step==='product_info'){
        $program=(string)($data['program']??($state['commercial_context']['program']??''));
        $expected=$program==='regular'?'onboarding:info:regular':'onboarding:info:intensive';
        return hache_sharky_orchestrator_decision('prospect_product_info_prompt','Para continuar, toca el botón para ver la información.',['type'=>'buttons','buttons'=>[
            hache_sharky_orchestrator_button($expected,'Ver información'),
        ]]);
    }
    if($step==='sede'){
        $includeBoth=($data['both_shown']??false)!==true;
        return hache_sharky_orchestrator_decision('prospect_sede_prompt','Para seguir, elige la sede con la que prefieres continuar:',hache_sharky_prospect_onboarding_venue_ui($includeBoth));
    }
    return hache_sharky_orchestrator_decision('prospect_onboarding_prompt','Cuando quieras, seguimos con el paso que tienes pendiente.');
}

function hache_sharky_prospect_onboarding_side_question_response(PDO $pdo,array $state,array $event,?callable $conversationAnswer=null,array $extraContext=[]): ?array
{
    if(!hache_sharky_prospect_onboarding_side_question($state,$event))return null;
    $text=trim((string)($event['text']??''));
    $answer='';
    if(function_exists('hache_sharky_whatsapp_nado_libre_request')&&hache_sharky_whatsapp_nado_libre_request($text)){
        $answer=hache_sharky_whatsapp_nado_libre_message();
    }elseif(function_exists('hache_sharky_whatsapp_weather_cancellation_request')&&hache_sharky_whatsapp_weather_cancellation_request($text)){
        $answer=hache_sharky_whatsapp_weather_cancellation_message();
    }elseif(function_exists('hache_sharky_whatsapp_venue_help_request')&&hache_sharky_whatsapp_venue_help_request($text)){
        $answer=hache_sharky_whatsapp_venue_help_message($pdo);
        $answer=preg_replace('/\n\nRevisa cuál te queda mejor y elige abajo con cuál prefieres seguir\.\s*$/u','',$answer)??$answer;
    }else{
        if($conversationAnswer===null&&function_exists('hache_sharky_lab_answer'))$conversationAnswer='hache_sharky_lab_answer';
        if(is_callable($conversationAnswer)){
            $contact=preg_replace('/\D+/','',(string)($event['from']??''))?:'';
            $context=$extraContext;
            if(function_exists('hache_sharky_whatsapp_context')){
                try{$context=hache_sharky_whatsapp_context($pdo,$contact,$extraContext);}
                catch(Throwable $ignored){}
            }
            $instruction=function_exists('hache_sharky_whatsapp_style_instruction')
                ?hache_sharky_whatsapp_style_instruction(['kind'=>'side_question'],$state):'';
            $instruction.=' El usuario está dentro del onboarding inicial de prospectos. Interpreta este turno como una duda lateral informativa, no como respuesta al dato pendiente. Responde solo la duda con información verificada disponible. Si depende de nivel, producto o sede aún no confirmados, dilo brevemente sin inferirlos ni inventar. No cambies el paso ni hagas otra pregunta: el sistema volverá a mostrar la pregunta pendiente.';
            $answer=(string)$conversationAnswer($text,$instruction,$state,$context);
            if(function_exists('hache_sharky_whatsapp_clean_answer'))$answer=hache_sharky_whatsapp_clean_answer($answer);
            if(function_exists('hache_sharky_whatsapp_enforce_confirmed_context'))$answer=hache_sharky_whatsapp_enforce_confirmed_context($answer,$state);
            if(function_exists('hache_sharky_whatsapp_enforce_no_reintroduction'))$answer=hache_sharky_whatsapp_enforce_no_reintroduction($answer,$state,$text);
        }
        if($answer===''||(function_exists('hache_sharky_whatsapp_answer_looks_incomplete')&&hache_sharky_whatsapp_answer_looks_incomplete($answer))){
            $answer='Te ayudo con esa duda. Si depende del nivel, producto o sede, te mostraré la información correcta en cuanto terminemos estos datos.';
        }
    }
    $resume=hache_sharky_prospect_onboarding_resume_decision($state);
    $message=rtrim($answer);
    $prompt=trim((string)($resume['message']??''));
    if($prompt!=='')$message.="\n\n".$prompt;
    return [$state,hache_sharky_orchestrator_decision(
        'prospect_onboarding_side_question',
        $message,
        is_array($resume['ui']??null)?$resume['ui']:[]
    )];
}

function hache_sharky_prospect_onboarding_refresh_contact(PDO $pdo,array $state,string $contact): void
{
    $name=trim((string)($state['commercial_context']['prospect_name']??''));
    $digits=preg_replace('/\D+/','',$contact)?:'';
    if($name===''||$digits===''||!function_exists('hache_sharky_contact_book_capture_event'))return;
    try{
        hache_sharky_contact_book_capture_event($pdo,[
            'id'=>'confirmed-name:'.hash('sha256',$digits.'|'.$name),
            'from'=>$digits,
            'kind'=>'confirmed_prospect_name',
            'data'=>['full_name'=>$name],
        ]);
    }catch(Throwable $ignored){}
}

function hache_sharky_prospect_onboarding_level_ui(): array
{
    return ['type'=>'buttons','buttons'=>[
        hache_sharky_orchestrator_button('onboarding:level:beginner','Principiante'),
        hache_sharky_orchestrator_button('onboarding:level:intermediate','Intermedio'),
        hache_sharky_orchestrator_button('onboarding:level:advanced','Avanzado'),
    ]];
}

function hache_sharky_prospect_onboarding_venue_ui(bool $includeBoth=true): array
{
    $buttons=[
        hache_sharky_orchestrator_button('sede:monteverde','Ubicación Monteverde'),
        hache_sharky_orchestrator_button('sede:palapas','Ubicación Palapas'),
    ];
    if($includeBoth)$buttons[]=hache_sharky_orchestrator_button('sede:both','Ambas ubicaciones');
    return ['type'=>'buttons','buttons'=>$buttons];
}

function hache_sharky_prospect_onboarding_config_int(PDO $pdo,string $key,int $fallback): int
{
    if(!function_exists('hache_sharky_business_values')||!function_exists('hache_sharky_config_int'))return $fallback;
    try{
        $values=hache_sharky_business_values($pdo);
        return hache_sharky_config_int($values,$key,$fallback,0,100000);
    }catch(Throwable $ignored){return $fallback;}
}

function hache_sharky_prospect_onboarding_product_offer(array $state,string $program,int $now): array
{
    if(!is_array($state['commercial_context']??null))$state['commercial_context']=[];
    $state['commercial_context']['program']=$program;
    $state['commercial_context']['recommended_program']=$program;
    $name=hache_sharky_prospect_onboarding_contact_name($state);
    $state=hache_sharky_orchestrator_flow($state,'prospect_onboarding','product_info',['program'=>$program],$now);
    if($program==='regular'){
        return [$state,hache_sharky_orchestrator_decision(
            'prospect_regular_offer',
            $name.', para tu nivel tenemos clases regulares:',
            ['type'=>'buttons','buttons'=>[
                hache_sharky_orchestrator_button('onboarding:info:regular','Ver información'),
            ]]
        )];
    }
    return [$state,hache_sharky_orchestrator_decision(
        'prospect_intensive_offer',
        $name.', para tu nivel tenemos un curso básico e intensivo:',
        ['type'=>'buttons','buttons'=>[
            hache_sharky_orchestrator_button('onboarding:info:intensive','Ver información'),
        ]]
    )];
}

function hache_sharky_prospect_onboarding_product_information(PDO $pdo,array $state,string $program,int $now): array
{
    $state=hache_sharky_orchestrator_flow($state,'prospect_onboarding','sede',['program'=>$program,'both_shown'=>false],$now);
    if($program==='regular'){
        $regular3=hache_sharky_prospect_onboarding_config_int($pdo,'sharky_precio_regular_3',1000);
        $regular5=hache_sharky_prospect_onboarding_config_int($pdo,'sharky_precio_regular_5',1200);
        $monteverde=hache_sharky_prospect_onboarding_config_int($pdo,'sharky_inscripcion_monteverde',500);
        $palapas=hache_sharky_prospect_onboarding_config_int($pdo,'sharky_inscripcion_palapas',400);
        $message="Clases regulares\n"
            .'3 clases por semana: $'.number_format($regular3,0,'.',',')."/mes\n"
            .'5 clases por semana: $'.number_format($regular5,0,'.',',')."/mes\n"
            .'Inscripción Monteverde: $'.number_format($monteverde,0,'.',',')."\n"
            .'Inscripción Palapas: $'.number_format($palapas,0,'.',',');
        return [$state,hache_sharky_orchestrator_decision('prospect_regular_info',$message,hache_sharky_prospect_onboarding_venue_ui(true))];
    }
    $price=hache_sharky_prospect_onboarding_config_int($pdo,'sharky_precio_intensivo',1200);
    $message="Curso básico para aprender a nadar\n"
        .'Costo: $'.number_format($price,0,'.',',')."\n"
        .'Duración: 3 semanas (clases de lunes a viernes)';
    return [$state,hache_sharky_orchestrator_decision('prospect_intensive_info',$message,hache_sharky_prospect_onboarding_venue_ui(true))];
}

function hache_sharky_prospect_onboarding_both_venues(string $text,string $interactiveId=''): bool
{
    $id=strtolower(trim($interactiveId));
    if($id==='sede:both')return true;
    if($id!=='')return false;
    $t=hache_sharky_orchestrator_normalize($text);
    return preg_match('/^(?:ambas|ambas\s+ubicaciones|las\s+dos|las\s+dos\s+ubicaciones|ambas\s+sedes|las\s+dos\s+sedes|dos\s+sedes)[.! ]*$/u',$t)===1;
}

function hache_sharky_prospect_onboarding_continue_after_venue(PDO $pdo,array $state,array $event,array $extraContext=[]): array
{
    $contact=preg_replace('/\D+/','',(string)($event['from']??''))?:'';
    $state=hache_sharky_orchestrator_clear_flow($state);
    $context=function_exists('hache_sharky_whatsapp_context')
        ?hache_sharky_whatsapp_context($pdo,$contact,$extraContext)
        :array_replace(['today'=>(new DateTimeImmutable('today',new DateTimeZone('America/Cancun')))->format('Y-m-d'),'intensive_options'=>[]],$extraContext);
    $catalog=hache_sharky_commercial_catalog($pdo,$state,$context);
    $sede=(string)($state['commercial_context']['sede_clave']??'');
    $label=function_exists('hache_sharky_whatsapp_venue_label')?hache_sharky_whatsapp_venue_label($sede):$sede;
    $decision=hache_sharky_commercial_reply($state,'Perfecto, seguimos con '.$label.'.',$catalog);
    if(function_exists('hache_sharky_whatsapp_empty_options_guard')){
        [$state,$decision]=hache_sharky_whatsapp_empty_options_guard($state,$decision);
    }
    return [$state,$decision];
}

function hache_sharky_prospect_onboarding_handle(PDO $pdo,array $state,array $event,int $now,int $minAge=12,array $extraContext=[]): ?array
{
    if(!hache_sharky_prospect_onboarding_active($state))return null;
    if(($state['identity']['kind']??'unknown')!=='prospect')return null;
    if(!is_array($state['commercial_context']??null))$state['commercial_context']=[];

    $flow=$state['flow'];
    $step=(string)($flow['step']??'');
    $data=is_array($flow['data']??null)?$flow['data']:[];
    $text=trim((string)($event['text']??''));
    $id=strtolower(trim((string)($event['interactive_id']??'')));
    $intent=hache_sharky_orchestrator_contextual_intent($state,$text,$id);
    if($intent==='human'){
        $state=hache_sharky_orchestrator_clear_flow($state);
        return [$state,hache_sharky_orchestrator_decision('human_takeover','Voy a dejar la conversación al equipo para que continúe contigo.',[],['type'=>'human_takeover'])];
    }
    if($intent==='student_claim'){
        $state=hache_sharky_orchestrator_clear_flow($state);
        return [$state,hache_sharky_orchestrator_decision(
            'student_human_takeover',
            'Perfecto. Como ya eres alumno, te dejo directamente con una persona del equipo de Hache Natación para que continúe contigo por este mismo chat.',
            [],
            ['type'=>'human_takeover']
        )];
    }
    if($intent==='cancel'){
        $state=hache_sharky_orchestrator_clear_flow($state);
        return [$state,hache_sharky_orchestrator_decision('flow_cancelled','Listo, cancelé este proceso. Podemos seguir conversando normalmente.')];
    }

    if($step!=='name'||($data['entry_bootstrap']??false)!==true){
        $sideQuestion=hache_sharky_prospect_onboarding_side_question_response($pdo,$state,$event,null,$extraContext);
        if(is_array($sideQuestion))return $sideQuestion;
    }

    if($step==='name'){
        if(($data['entry_bootstrap']??false)===true){
            $data['entry_bootstrap']=false;
            $state=hache_sharky_orchestrator_flow($state,'prospect_onboarding','name',$data,$now);
            return [$state,hache_sharky_orchestrator_decision(
                'prospect_name_prompt',
                'Necesito un par de datos tuyos para conocernos mejor. Por favor, ¿me puedes decir tu nombre?'
            )];
        }
        $name=hache_sharky_prospect_onboarding_name($text);
        if($name===null){
            return [$state,hache_sharky_orchestrator_decision(
                'prospect_name_prompt',
                'Una disculpa, no entendí bien tu respuesta. ¿Me puedes decir tu nombre, por favor?'
            )];
        }
        $state['commercial_context']['prospect_name']=$name;
        $state['identity']['name']=$name;
        $state=hache_sharky_orchestrator_flow($state,'prospect_onboarding','participant',[], $now);
        hache_sharky_prospect_onboarding_refresh_contact($pdo,$state,(string)($event['from']??''));
        return [$state,hache_sharky_orchestrator_decision(
            'prospect_participant_prompt',
            'Bueno, '.$name.', ¿las clases son para ti?',
            ['type'=>'buttons','buttons'=>[
                hache_sharky_orchestrator_button('onboarding:self:yes','Sí'),
                hache_sharky_orchestrator_button('onboarding:self:no','No'),
            ]]
        )];
    }

    if($step==='participant'){
        $self=hache_sharky_prospect_onboarding_yes_no($text,$id,'participant');
        if($self===null){
            return [$state,hache_sharky_orchestrator_decision(
                'prospect_participant_prompt',
                'Una disculpa, no entendí tu respuesta. ¿Las clases son para ti?',
                ['type'=>'buttons','buttons'=>[
                    hache_sharky_orchestrator_button('onboarding:self:yes','Sí'),
                    hache_sharky_orchestrator_button('onboarding:self:no','No'),
                ]]
            )];
        }
        $state['commercial_context']['participant_relation']=$self?'self':'other';
        if($self){
            $state['commercial_context']['participant_name']=(string)($state['commercial_context']['prospect_name']??'');
            $state=hache_sharky_orchestrator_flow($state,'prospect_onboarding','age',[], $now);
            return [$state,hache_sharky_orchestrator_decision('prospect_age_prompt','¿Qué edad tienes?')];
        }
        $state=hache_sharky_orchestrator_flow($state,'prospect_onboarding','student_name',[], $now);
        return [$state,hache_sharky_orchestrator_decision('prospect_student_name_prompt','Entiendo. ¿Cómo se llama la persona que tomaría las clases?')];
    }

    if($step==='student_name'){
        $studentName=hache_sharky_prospect_onboarding_name($text);
        if($studentName===null){
            return [$state,hache_sharky_orchestrator_decision(
                'prospect_student_name_prompt',
                'Una disculpa, no entendí bien el nombre. ¿Cómo se llama la persona que tomaría las clases?'
            )];
        }
        $state['commercial_context']['participant_name']=$studentName;
        $state=hache_sharky_orchestrator_flow($state,'prospect_onboarding','age',[], $now);
        return [$state,hache_sharky_orchestrator_decision('prospect_age_prompt','¿Qué edad tiene '.$studentName.'?')];
    }

    if($step==='age'){
        $age=function_exists('hache_sharky_whatsapp_declared_age')?hache_sharky_whatsapp_declared_age($text):null;
        if($age===null){
            $self=($state['commercial_context']['participant_relation']??'self')==='self';
            $participant=trim((string)($state['commercial_context']['participant_name']??''));
            $question=$self?'¿Qué edad tienes?':($participant!==''?'¿Qué edad tiene '.$participant.'?':'¿Qué edad tiene la persona que tomaría las clases?');
            return [$state,hache_sharky_orchestrator_decision('prospect_age_prompt','Una disculpa, no entendí tu respuesta. '.$question)];
        }
        $state['commercial_context']['age']=$age;
        if($age<max(1,$minAge)&&function_exists('hache_sharky_whatsapp_underage_rejection')){
            return hache_sharky_whatsapp_underage_rejection($state,max(1,$minAge));
        }
        $state=hache_sharky_orchestrator_flow($state,'prospect_onboarding','level',[], $now);
        $self=($state['commercial_context']['participant_relation']??'self')==='self';
        $participant=trim((string)($state['commercial_context']['participant_name']??''));
        $message=$self?'Escoge el nivel que mejor te representa:':($participant!==''?'Escoge el nivel que mejor representa a '.$participant.':':'Escoge el nivel que mejor representa a la persona que tomará las clases:');
        return [$state,hache_sharky_orchestrator_decision('prospect_level_prompt',$message,hache_sharky_prospect_onboarding_level_ui())];
    }

    if($step==='level'){
        $level=hache_sharky_prospect_onboarding_level($text,$id);
        if($level===null){
            return [$state,hache_sharky_orchestrator_decision(
                'prospect_level_prompt',
                'Una disculpa, no entendí tu respuesta. Escoge el nivel que mejor te representa:',
                hache_sharky_prospect_onboarding_level_ui()
            )];
        }
        $state['commercial_context']['declared_level']=$level;
        foreach(['background','program','recommended_program','plan_id','plan_name','sessions_per_week','plan_price','schedule_id','schedule_label','course_id','fecha_inicio','course_price'] as $key)unset($state['commercial_context'][$key]);
        if($level==='beginner'){
            $state['commercial_context']['swim_level']='beginner';
            return hache_sharky_prospect_onboarding_product_offer($state,'intensive',$now);
        }
        $state['commercial_context']['swim_level']='swims';
        if($level==='advanced'){
            $state['commercial_context']['background']='advanced';
            return hache_sharky_prospect_onboarding_product_offer($state,'regular',$now);
        }
        $state=hache_sharky_orchestrator_flow($state,'prospect_onboarding','intermediate_background',[], $now);
        $name=hache_sharky_prospect_onboarding_contact_name($state);
        return [$state,hache_sharky_orchestrator_decision(
            'prospect_intermediate_background_prompt',
            $name.', ¿ya has tomado clases de natación antes?',
            ['type'=>'buttons','buttons'=>[
                hache_sharky_orchestrator_button('onboarding:background:yes','Sí'),
                hache_sharky_orchestrator_button('onboarding:background:no','No'),
            ]]
        )];
    }

    if($step==='intermediate_background'){
        $formal=hache_sharky_prospect_onboarding_yes_no($text,$id,'background');
        if($formal===null){
            return [$state,hache_sharky_orchestrator_decision(
                'prospect_intermediate_background_prompt',
                'Una disculpa, no entendí tu respuesta. ¿Ya has tomado clases de natación antes?',
                ['type'=>'buttons','buttons'=>[
                    hache_sharky_orchestrator_button('onboarding:background:yes','Sí'),
                    hache_sharky_orchestrator_button('onboarding:background:no','No'),
                ]]
            )];
        }
        $state['commercial_context']['background']=$formal?'formal':'no_formal';
        return hache_sharky_prospect_onboarding_product_offer($state,$formal?'regular':'intensive',$now);
    }

    if($step==='product_info'){
        $program=(string)($data['program']??($state['commercial_context']['program']??''));
        $expected=$program==='regular'?'onboarding:info:regular':'onboarding:info:intensive';
        if($id!==$expected){
            return [$state,hache_sharky_orchestrator_decision(
                'prospect_product_info_prompt',
                'Para continuar, toca el botón para ver la información.',
                ['type'=>'buttons','buttons'=>[hache_sharky_orchestrator_button($expected,'Ver información')]]
            )];
        }
        return hache_sharky_prospect_onboarding_product_information($pdo,$state,$program,$now);
    }

    if($step==='sede'){
        if(hache_sharky_prospect_onboarding_both_venues($text,$id)){
            $data['both_shown']=true;
            $state=hache_sharky_orchestrator_flow($state,'prospect_onboarding','sede',$data,$now);
            $message=function_exists('hache_sharky_whatsapp_venue_help_message')
                ?hache_sharky_whatsapp_venue_help_message($pdo)
                :'Tenemos dos sedes: Colegio Monteverde y Palapas Protudec. Elige con cuál quieres continuar.';
            return [$state,hache_sharky_orchestrator_decision('prospect_both_venues',$message,hache_sharky_prospect_onboarding_venue_ui(false))];
        }
        $sede=function_exists('hache_sharky_whatsapp_detect_venue_preference')
            ?hache_sharky_whatsapp_detect_venue_preference($text,$id)
            :null;
        if(!in_array($sede,['MONTEVERDE','PALAPAS'],true)){
            $includeBoth=($data['both_shown']??false)!==true;
            return [$state,hache_sharky_orchestrator_decision(
                'prospect_sede_prompt',
                'Una disculpa, no entendí qué sede prefieres. Elige una opción:',
                hache_sharky_prospect_onboarding_venue_ui($includeBoth)
            )];
        }
        $state['commercial_context']['sede_clave']=$sede;
        return hache_sharky_prospect_onboarding_continue_after_venue($pdo,$state,$event,$extraContext);
    }

    return null;
}
