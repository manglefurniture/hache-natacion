from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    p = Path(path)
    text = p.read_text()
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{path}: expected one exact match, found {count}")
    p.write_text(text.replace(old, new, 1))


adapter = "config/sharky-whatsapp-adapter.php"

old = """function hache_sharky_whatsapp_apply_natural_swim_level(array $state,string $text): array
{
    if(($state['identity']['kind']??'unknown')!=='prospect')return $state;
    $level=null;
    foreach(hache_sharky_orchestrator_text_segments($text) as $line){
        $choice=hache_sharky_whatsapp_detect_swim_level($line);if($choice!==null)$level=$choice;
    }
    if($level===null)return $state;
    return hache_sharky_whatsapp_apply_swim_level_choice($state,$level);
}
"""
new = old + """
function hache_sharky_whatsapp_swim_level_conflict(array $state,array $event,int $now): ?array
{
    if(($state['identity']['kind']??'unknown')!=='prospect')return null;
    if(trim((string)($event['interactive_id']??''))!=='')return null;
    $current=(string)($state['commercial_context']['swim_level']??'');
    if(!in_array($current,['beginner','swims'],true))return null;
    $incoming=hache_sharky_whatsapp_detect_swim_level((string)($event['text']??''));
    if(!in_array($incoming,['beginner','swims'],true)||$incoming===$current)return null;
    $flow=is_array($state['flow']??null)?$state['flow']:null;
    $flowName=(string)($flow['name']??'');
    if($flowName!==''&&!in_array($flowName,['qualify_prospect','register_intensive'],true))return null;

    if(!is_array($state['commercial_context']??null))$state['commercial_context']=[];
    $c=&$state['commercial_context'];
    unset($c['swim_level'],$c['program'],$c['recommended_program'],$c['background']);
    foreach(['plan_id','plan_name','sessions_per_week','plan_price','schedule_id','schedule_label','course_id','fecha_inicio','course_price','date_preference','payment_choice_pct','kit','_requested_slot'] as $key)unset($c[$key]);
    $data=['previous_level'=>$current,'conflicting_level'=>$incoming];
    if(in_array(($c['sede_clave']??null),['MONTEVERDE','PALAPAS'],true))$data['sede_clave']=$c['sede_clave'];
    $state=hache_sharky_orchestrator_flow($state,'qualify_prospect','swim_conflict',$data,$now);
    $decision=hache_sharky_orchestrator_decision(
        'prospect_swim_conflict',
        'Antes me indicaste un nivel diferente. Para no orientarte al producto equivocado necesito aclarar una sola cosa: ¿estás empezando desde cero o ya sabes nadar?',
        ['type'=>'buttons','buttons'=>[
            hache_sharky_orchestrator_button('qualify:swims','Ya sé nadar'),
            hache_sharky_orchestrator_button('qualify:beginner','Desde cero'),
        ]]
    );
    return [$state,$decision];
}

function hache_sharky_whatsapp_monteverde_rejection(string $text): bool
{
    if(str_contains($text,'?')||str_contains($text,'¿'))return false;
    $t=hache_sharky_orchestrator_normalize($text);
    if(preg_match('/^(?:no|no\\s+me\\s+(?:sirve|funciona|conviene)|prefiero\\s+(?:la\\s+)?otra(?:\\s+sede)?|mejor\\s+(?:la\\s+)?otra(?:\\s+sede)?|la\\s+otra|otra\\s+sede)[.! ]*$/u',$t)===1)return true;
    return preg_match('/\\b(?:monteverde)\\b.{0,28}\\b(?:no\\s+me\\s+(?:sirve|funciona|conviene|queda)|no\\s+quiero)\\b|\\b(?:no\\s+quiero|no\\s+me\\s+(?:sirve|funciona|conviene|queda))\\b.{0,28}\\bmonteverde\\b/u',$t)===1;
}
"""
replace_once(adapter, old, new)

old = """    $sede=hache_sharky_whatsapp_detect_venue_preference($text);
    if($sede===null)$sede=hache_sharky_whatsapp_detect_relative_venue_preference($state,$text);
"""
new = """    if(is_array($flow)&&($flow['name']??'')==='qualify_prospect'&&($flow['step']??'')==='sede'&&($flow['data']['venue_proposal']??null)==='MONTEVERDE'&&hache_sharky_whatsapp_monteverde_rejection($text))return $state;
    $sede=hache_sharky_whatsapp_detect_venue_preference($text);
    if($sede===null)$sede=hache_sharky_whatsapp_detect_relative_venue_preference($state,$text);
"""
replace_once(adapter, old, new)

old = """function hache_sharky_whatsapp_qualification_start(array $state,int $now): array
{
    $data=[];
    if(in_array(($state['commercial_context']['swim_level']??null),['beginner','swims'],true)){
        return hache_sharky_whatsapp_qualification_sede_step($state,$data,$now,'Continuamos con tu elección.');
    }
    $preferred=(string)($state['commercial_context']['program']??'');
    if(in_array($preferred,['intensive','regular'],true))$data['preferred_program']=$preferred;
    $state=hache_sharky_orchestrator_flow($state,'qualify_prospect','swim',$data,$now);
    $decision=hache_sharky_orchestrator_decision('prospect_swim_prompt','Para orientarte por lo que necesitas: ¿ya sabes nadar o estás empezando desde cero?',['type'=>'buttons','buttons'=>[
        hache_sharky_orchestrator_button('qualify:swims','Ya sé nadar'),
        hache_sharky_orchestrator_button('qualify:beginner','Desde cero'),
    ]]);
    return [$state,$decision];
}
"""
new = """function hache_sharky_whatsapp_qualification_start(array $state,int $now): array
{
    $data=[];
    $level=(string)($state['commercial_context']['swim_level']??'');
    $background=(string)($state['commercial_context']['background']??'');
    if($level==='beginner'){
        $state['commercial_context']['program']='intensive';
        $state['commercial_context']['recommended_program']='intensive';
        return hache_sharky_whatsapp_qualification_sede_step($state,$data,$now,'Si estás empezando desde cero, seguimos con el curso intensivo básico.');
    }
    if($level==='swims'){
        if(in_array($background,['self_taught','no_formal'],true)){
            $state['commercial_context']['program']='intensive';
            $state['commercial_context']['recommended_program']='intensive';
            return hache_sharky_whatsapp_qualification_sede_step($state,$data,$now,'Como aprendiste sin clases formales, seguimos con el curso intensivo básico.');
        }
        if($background==='formal'){
            $state['commercial_context']['program']='regular';
            $state['commercial_context']['recommended_program']='regular';
            return hache_sharky_whatsapp_qualification_sede_step($state,$data,$now,'Como ya sabes nadar y has tomado clases, seguimos con clases regulares.');
        }
        $state=hache_sharky_orchestrator_flow($state,'qualify_prospect','background',$data,$now);
        return [$state,hache_sharky_orchestrator_decision('prospect_background_prompt','Perfecto. ¿Has tomado clases de natación antes o aprendiste por tu cuenta?',['type'=>'buttons','buttons'=>[
            hache_sharky_orchestrator_button('qualify:formal','He tomado clases'),hache_sharky_orchestrator_button('qualify:self','Por mi cuenta')]])];
    }
    $state=hache_sharky_orchestrator_flow($state,'qualify_prospect','swim',$data,$now);
    $decision=hache_sharky_orchestrator_decision('prospect_swim_prompt','Para orientarte por lo que necesitas: ¿ya sabes nadar o estás empezando desde cero?',['type'=>'buttons','buttons'=>[
        hache_sharky_orchestrator_button('qualify:swims','Ya sé nadar'),
        hache_sharky_orchestrator_button('qualify:beginner','Desde cero'),
    ]]);
    return [$state,$decision];
}
"""
replace_once(adapter, old, new)

old = """function hache_sharky_whatsapp_qualification_sede_step(array $state,array $data,int $now,string $message): array
{
    $known=(string)($state['commercial_context']['sede_clave']??'');
    if(in_array($known,['MONTEVERDE','PALAPAS'],true)){
        $label=hache_sharky_whatsapp_venue_label($known);
        $state=hache_sharky_orchestrator_clear_flow($state);
        return [$state,hache_sharky_whatsapp_commercial_next_action($state,$message.' Ya tengo tu sede: '.$label.'.')];
    }
    $state=hache_sharky_orchestrator_flow($state,'qualify_prospect','sede',$data,$now);
    $prompt=$message.' Tenemos dos sedes en Cancún: Colegio Monteverde y Palapas Protudec. ¿Cuál de las dos te queda mejor?';
    return [$state,hache_sharky_orchestrator_decision('prospect_program_recommendation',$prompt,['type'=>'buttons','buttons'=>[
        hache_sharky_orchestrator_button('sede:monteverde','Colegio Monteverde'),
        hache_sharky_orchestrator_button('sede:palapas','Palapas Protudec'),
    ]])];
}
"""
new = """function hache_sharky_whatsapp_qualification_sede_step(array $state,array $data,int $now,string $message): array
{
    $known=(string)($state['commercial_context']['sede_clave']??'');
    if(in_array($known,['MONTEVERDE','PALAPAS'],true)){
        $label=hache_sharky_whatsapp_venue_label($known);
        $state=hache_sharky_orchestrator_clear_flow($state);
        return [$state,hache_sharky_whatsapp_commercial_next_action($state,$message.' Ya tengo tu sede: '.$label.'.')];
    }
    $data['venue_proposal']='MONTEVERDE';
    $state=hache_sharky_orchestrator_flow($state,'qualify_prospect','sede',$data,$now);
    $prompt=$message.' Te propongo primero Colegio Monteverde. ¿Te funciona esta sede?';
    return [$state,hache_sharky_orchestrator_decision('prospect_program_recommendation',$prompt,['type'=>'buttons','buttons'=>[
        hache_sharky_orchestrator_button('sede:monteverde','Sí, Monteverde'),
        hache_sharky_orchestrator_button('sede:palapas','Prefiero Palapas'),
    ]])];
}
"""
replace_once(adapter, old, new)

old = """    if($step==='swim'){
        $level=hache_sharky_whatsapp_swim_level_from_input($text,$id);
        if($level===null)return [$state,hache_sharky_orchestrator_decision('prospect_swim_prompt','¿Ya sabes nadar o estás empezando desde cero?',['type'=>'buttons','buttons'=>[
            hache_sharky_orchestrator_button('qualify:swims','Ya sé nadar'),hache_sharky_orchestrator_button('qualify:beginner','Desde cero')]])];
"""
new = """    if(in_array($step,['swim','swim_conflict'],true)){
        $level=hache_sharky_whatsapp_swim_level_from_input($text,$id);
        if($level===null){
            $prompt=$step==='swim_conflict'
                ?'Necesito aclarar tu nivel antes de continuar: ¿estás empezando desde cero o ya sabes nadar?'
                :'¿Ya sabes nadar o estás empezando desde cero?';
            return [$state,hache_sharky_orchestrator_decision('prospect_swim_prompt',$prompt,['type'=>'buttons','buttons'=>[
                hache_sharky_orchestrator_button('qualify:swims','Ya sé nadar'),hache_sharky_orchestrator_button('qualify:beginner','Desde cero')]])];
        }
"""
replace_once(adapter, old, new)

old = """    if($step==='background'){
        $formal=$id==='qualify:formal'||preg_match('/^(?:si|he\\s+tomado\\s+clases|ya\\s+tome\\s+clases|con\\s+profesor|con\\s+entrenador|formal(?:mente)?)[.! ]*$/u',$t)===1;
        $self=$id==='qualify:self'||preg_match('/^(?:no|por\\s+mi\\s+cuenta|aprendi\\s+solo|aprendi\\s+sola|nunca\\s+he\\s+tomado\\s+clases|autodidacta)[.! ]*$/u',$t)===1;
        if(!$formal&&!$self)return [$state,hache_sharky_orchestrator_decision('prospect_background_prompt','¿Has tomado clases antes o aprendiste por tu cuenta?',['type'=>'buttons','buttons'=>[
            hache_sharky_orchestrator_button('qualify:formal','He tomado clases'),hache_sharky_orchestrator_button('qualify:self','Por mi cuenta')]])];
        if($self){
            $state['commercial_context']['program']='intensive';
            return hache_sharky_whatsapp_qualification_sede_step($state,$data,$now,'Aunque ya nades, si aprendiste por tu cuenta te recomendamos comenzar por el intensivo para ordenar fundamentos y técnica.');
        }
        $preferred=(string)($data['preferred_program']??'');
        if(in_array($preferred,['intensive','regular'],true)){
            $state['commercial_context']['program']=$preferred;
            $why=$preferred==='intensive'?'Como ya tomaste clases, también puedes elegir el intensivo si buscas un bloque temporal o trabajar técnica.':'Como ya has tomado clases, las clases regulares son una buena opción para seguir técnica, resistencia y estilos.';
            return hache_sharky_whatsapp_qualification_sede_step($state,$data,$now,$why);
        }
        $state=hache_sharky_orchestrator_flow($state,'qualify_prospect','program',$data,$now);
        return [$state,hache_sharky_orchestrator_decision('prospect_program_prompt','Como ya sabes nadar y has tomado clases, ¿qué buscas ahora: un curso intensivo temporal o clases regulares mensuales?',['type'=>'buttons','buttons'=>[
            hache_sharky_orchestrator_button('qualify:intensive','Intensivo'),
            hache_sharky_orchestrator_button('qualify:regular','Regulares'),
        ]])];
    }
"""
new = """    if($step==='background'){
        $formal=$id==='qualify:formal'||preg_match('/^(?:si|he\\s+tomado\\s+clases|ya\\s+tome\\s+clases|con\\s+profesor|con\\s+entrenador|formal(?:mente)?)[.! ]*$/u',$t)===1;
        $self=$id==='qualify:self'||preg_match('/^(?:no|por\\s+mi\\s+cuenta|aprendi\\s+solo|aprendi\\s+sola|nunca\\s+he\\s+tomado\\s+clases|autodidacta)[.! ]*$/u',$t)===1;
        if(!$formal&&!$self)return [$state,hache_sharky_orchestrator_decision('prospect_background_prompt','¿Has tomado clases antes o aprendiste por tu cuenta?',['type'=>'buttons','buttons'=>[
            hache_sharky_orchestrator_button('qualify:formal','He tomado clases'),hache_sharky_orchestrator_button('qualify:self','Por mi cuenta')]])];
        if($self){
            $state['commercial_context']['background']='self_taught';
            $state['commercial_context']['recommended_program']='intensive';
            $state['commercial_context']['program']='intensive';
            return hache_sharky_whatsapp_qualification_sede_step($state,$data,$now,'Aunque ya nades, si aprendiste por tu cuenta seguimos con el curso intensivo básico para ordenar fundamentos y técnica.');
        }
        $state['commercial_context']['background']='formal';
        $state['commercial_context']['recommended_program']='regular';
        $state['commercial_context']['program']='regular';
        return hache_sharky_whatsapp_qualification_sede_step($state,$data,$now,'Como ya sabes nadar y has tomado clases, seguimos con clases regulares.');
    }
"""
replace_once(adapter, old, new)

old = """    if($step==='program'){
        $program='';
        if($id==='qualify:intensive')$program='intensive';
        elseif($id==='qualify:regular')$program='regular';
        else $program=(string)(hache_sharky_orchestrator_program_choice($text)??'');
        if(!in_array($program,['intensive','regular'],true))return [$state,hache_sharky_orchestrator_decision('prospect_program_prompt','¿Prefieres un curso intensivo temporal o clases regulares mensuales?',['type'=>'buttons','buttons'=>[
            hache_sharky_orchestrator_button('qualify:intensive','Intensivo'),hache_sharky_orchestrator_button('qualify:regular','Regulares')]])];
        $state['commercial_context']['program']=$program;
        $why=$program==='intensive'?'Perfecto, podemos orientarte por el intensivo.':'Perfecto, seguimos por clases regulares.';
        return hache_sharky_whatsapp_qualification_sede_step($state,$data,$now,$why);
    }
"""
new = """    if($step==='program'){
        $level=(string)($state['commercial_context']['swim_level']??'');
        $background=(string)($state['commercial_context']['background']??($data['background']??''));
        $program='';
        if($level==='beginner'||in_array($background,['self_taught','no_formal'],true))$program='intensive';
        elseif($level==='swims'&&$background==='formal')$program='regular';
        elseif($id==='qualify:intensive')$program='intensive';
        elseif($id==='qualify:regular')$program='regular';
        else $program=(string)(hache_sharky_orchestrator_program_choice($text)??'');
        if(!in_array($program,['intensive','regular'],true))return [$state,hache_sharky_orchestrator_decision('prospect_program_prompt','¿Prefieres un curso intensivo temporal o clases regulares mensuales?',['type'=>'buttons','buttons'=>[
            hache_sharky_orchestrator_button('qualify:intensive','Intensivo'),hache_sharky_orchestrator_button('qualify:regular','Regulares')]])];
        $state['commercial_context']['program']=$program;
        $state['commercial_context']['recommended_program']=$program;
        $why=$program==='intensive'?'Perfecto, seguimos por el intensivo.':'Perfecto, seguimos por clases regulares.';
        return hache_sharky_whatsapp_qualification_sede_step($state,$data,$now,$why);
    }
"""
replace_once(adapter, old, new)

old = """    if($step==='sede'){
        $sede=hache_sharky_whatsapp_detect_venue_preference($text,$id);
        if($sede===null){
            $prompt=hache_sharky_whatsapp_venue_help_request($text)
                ?hache_sharky_whatsapp_venue_help_message($pdo)
                :'Tenemos dos sedes en Cancún: Colegio Monteverde y Palapas Protudec. ¿Cuál de las dos te queda mejor?';
            return [$state,hache_sharky_orchestrator_decision('prospect_sede_prompt',$prompt,['type'=>'buttons','buttons'=>[
                hache_sharky_orchestrator_button('sede:monteverde','Colegio Monteverde'),hache_sharky_orchestrator_button('sede:palapas','Palapas Protudec')]])];
        }
        $state['commercial_context']['sede_clave']=$sede;
        $state=hache_sharky_orchestrator_clear_flow($state);
        return [$state,hache_sharky_whatsapp_commercial_next_action($state)];
    }
"""
new = """    if($step==='sede'){
        $help=hache_sharky_whatsapp_venue_help_request($text);
        $sede=null;
        if(!$help&&($data['venue_proposal']??null)==='MONTEVERDE'&&hache_sharky_whatsapp_monteverde_rejection($text))$sede='PALAPAS';
        if($sede===null)$sede=hache_sharky_whatsapp_detect_venue_preference($text,$id);
        if($sede===null){
            $prompt=$help
                ?hache_sharky_whatsapp_venue_help_message($pdo)
                :'Te propongo primero Colegio Monteverde. ¿Te funciona esta sede?';
            return [$state,hache_sharky_orchestrator_decision('prospect_sede_prompt',$prompt,['type'=>'buttons','buttons'=>[
                hache_sharky_orchestrator_button('sede:monteverde','Sí, Monteverde'),hache_sharky_orchestrator_button('sede:palapas','Prefiero Palapas')]])];
        }
        $state['commercial_context']['sede_clave']=$sede;
        $state=hache_sharky_orchestrator_clear_flow($state);
        return [$state,hache_sharky_whatsapp_commercial_next_action($state)];
    }
"""
replace_once(adapter, old, new)

replace_once(
    adapter,
    "        if($step==='swim')return in_array($id,['qualify:swims','qualify:beginner'],true);\n",
    "        if(in_array($step,['swim','swim_conflict'],true))return in_array($id,['qualify:swims','qualify:beginner'],true);\n",
)
replace_once(
    adapter,
    "        if(!in_array($step,['swim','background','program','sede','daypart'],true))return false;\n",
    "        if(!in_array($step,['swim','swim_conflict','background','program','sede','daypart'],true))return false;\n",
)

replace_once(
    adapter,
    "        $commercialBefore=$state['commercial_context']??[];\n",
    """        $levelConflict=hache_sharky_whatsapp_swim_level_conflict($state,$event,$now);
        if(is_array($levelConflict)){
            [$state,$decision]=$levelConflict;
            hache_sharky_db_state_save($pdo,$contact,$state);hache_sharky_whatsapp_complete_receipt($pdo,$messageId,$extraContext);
            return ['skip'=>false,'state'=>$state,'decision'=>$decision,'payload'=>hache_sharky_whatsapp_render($contact,$decision),'action_result'=>null];
        }

        $commercialBefore=$state['commercial_context']??[];
""",
)

memory = "config/sharky-commercial-memory.php"
replace_once(
    memory,
    "                    $state=hache_sharky_orchestrator_flow($state,'qualify_prospect','sede',['recommended_program'=>'intensive','background'=>$background],(int)($state['updated_at']??time()));\n",
    "                    $state=hache_sharky_orchestrator_flow($state,'qualify_prospect','sede',['recommended_program'=>'intensive','background'=>$background,'venue_proposal'=>'MONTEVERDE'],(int)($state['updated_at']??time()));\n",
)
old = """            }else{
                if($background==='formal'&&in_array(($c['entry_interest']??null),['intensive','regular'],true))$c['recommended_program']=$c['entry_interest'];
                if(empty($c['program'])){
                    $state=hache_sharky_orchestrator_flow($state,'qualify_prospect','program',[
                        'recommended_program'=>$c['recommended_program']??null,
                        'background'=>$background,
                        'preferred_program'=>$c['entry_interest']??null,
                    ],(int)($state['updated_at']??time()));
                    $c=&$state['commercial_context'];
                    $flow=$state['flow'];$flowName='qualify_prospect';$flowStep='program';
                }
            }
"""
new = """            }else{
                $c['recommended_program']='regular';
                $c['program']='regular';
                foreach(['course_id','fecha_inicio','course_price','date_preference'] as $key)unset($c[$key]);
                if(in_array(($c['sede_clave']??null),['MONTEVERDE','PALAPAS'],true)){
                    $state=hache_sharky_orchestrator_clear_flow($state);
                }else{
                    $state=hache_sharky_orchestrator_flow($state,'qualify_prospect','sede',[
                        'recommended_program'=>'regular','background'=>'formal','venue_proposal'=>'MONTEVERDE',
                    ],(int)($state['updated_at']??time()));
                }
                $c=&$state['commercial_context'];
                $flow=$state['flow']??null;$flowName=(string)($flow['name']??'');$flowStep=(string)($flow['step']??'');
            }
"""
replace_once(memory, old, new)

boundary = "config/sharky-product-boundary-guard.php"
old = """function hache_sharky_product_boundary_sanitize_state(array $state,string $userText=''): array
{
    if(!hache_sharky_product_boundary_regular_restricted($state,$userText))return $state;
    if(!is_array($state['commercial_context']??null))$state['commercial_context']=[];
    $c=&$state['commercial_context'];
    if($userText!==''&&hache_sharky_product_boundary_no_formal_signal($userText))$c['background']='no_formal';
    $c['recommended_program']='intensive';
    $c['program']='intensive';
    foreach(['plan_id','plan_name','sessions_per_week','plan_price'] as $key)unset($c[$key]);
    return $state;
}
"""
new = """function hache_sharky_product_boundary_sanitize_state(array $state,string $userText=''): array
{
    if(!is_array($state['commercial_context']??null))$state['commercial_context']=[];
    $c=&$state['commercial_context'];
    if(($c['swim_level']??null)==='swims'&&($c['background']??null)==='formal'){
        $c['recommended_program']='regular';
        $c['program']='regular';
        foreach(['course_id','fecha_inicio','course_price','date_preference'] as $key)unset($c[$key]);
        return $state;
    }
    if(!hache_sharky_product_boundary_regular_restricted($state,$userText))return $state;
    if($userText!==''&&hache_sharky_product_boundary_no_formal_signal($userText))$c['background']='no_formal';
    $c['recommended_program']='intensive';
    $c['program']='intensive';
    foreach(['plan_id','plan_name','sessions_per_week','plan_price'] as $key)unset($c[$key]);
    return $state;
}
"""
replace_once(boundary, old, new)

policy = "config/sharky-post-pr72.php"
replace_once(
    policy,
    "        '- Solo cuando background sea formal puede Sharky ofrecer o vender automáticamente clases regulares. Aun así, intensivo y regulares siguen siendo productos distintos y una frecuencia semanal no cambia el producto por sí sola.',\n",
    """        '- REGLA DE PRODUCTO: cuando commercial_context.swim_level sea swims y background sea formal, el producto automático es clases regulares. No ofrezcas una elección entre intensivo y regulares en ese perfil. Intensivo y regulares siguen siendo productos distintos y una frecuencia semanal no cambia el producto por sí sola.',
        '- Si un swim_level ya confirmado entra en contradicción con una declaración posterior, no lo sobrescribas silenciosamente ni avances la venta: detén el flujo y pide una aclaración explícita del nivel antes de volver a determinar producto.',
        '- Una vez determinado el producto y si todavía no hay sede confirmada, propone primero Colegio Monteverde. Si la persona rechaza Monteverde o pide Palapas Protudec, acepta Palapas y continúa sin volver a insistir con Monteverde.',
""",
)
replace_once(
    policy,
    "        '- Una consulta lateral sobre regulares no cambia por sí sola el programa activo. Si el perfil no es elegible para regulares, explica que requiere valoración humana; si tiene formación formal, puede responderse la consulta sin cambiar automáticamente el producto.',\n",
    "        '- Una consulta lateral sobre regulares no cambia por sí sola el programa activo. Si el perfil no es elegible para regulares, explica que requiere valoración humana; si swim_level es swims y la formación formal ya está confirmada, las clases regulares son el producto automático.',\n",
)

docs = "docs/SHARKY-POSITIVE-PATTERNS.md"
old = """- Solo un prospecto con formación formal confirmada puede cambiar automáticamente a clases regulares mediante una preferencia explícita. Si no es elegible, Sharky no muestra planes, precios ni horarios regulares y deriva la excepción a una persona.
- La preferencia posterior del cliente se respeta dentro de las reglas de elegibilidad; una preferencia no autoriza a Sharky a saltarse una valoración humana requerida.
- La sede elegida se conserva en contexto y no se vuelve a preguntar sin motivo.
"""
new = """- Si el prospecto ya sabe nadar y confirma que ha tomado clases formales, el producto automático es clases regulares; Sharky no abre una elección entre intensivo y regulares. Si aprendió por su cuenta o no ha tomado clases formales, el producto automático sigue siendo el curso intensivo.
- Si una declaración nueva contradice el swim_level ya confirmado, Sharky no reemplaza silenciosamente ese dato: congela el avance comercial y pide una aclaración explícita antes de volver a determinar producto.
- La preferencia posterior del cliente se respeta dentro de las reglas de elegibilidad; una preferencia no autoriza a Sharky a saltarse una valoración humana requerida.
- La sede elegida se conserva en contexto y no se vuelve a preguntar sin motivo. Si aún no hay sede, Sharky propone primero Colegio Monteverde; si el prospecto la rechaza o pide Palapas Protudec, acepta Palapas y continúa sin insistir de nuevo con Monteverde.
"""
replace_once(docs, old, new)

pr162 = "tests/sharky-pr162-review-regression.php"
replace_once(
    pr162,
    """// Regression: arriving from the intensive ad is not the same as choosing intensive.
// A swimmer who has taken classes must still choose between intensive and regular.
""",
    """// Regression: arriving from the intensive ad is not the same as choosing intensive.
// A swimmer with formal lessons is routed to regular classes by the current product rule.
""",
)
replace_once(
    pr162,
    """pr162_review_ok(empty($metaFormal['commercial_context']['program']),'Formal experience must not auto-confirm the intensive ad program.');
pr162_review_ok(($metaFormal['flow']['step']??null)==='program','A formal swimmer must explicitly choose intensive or regular before venue selection.');
pr162_review_ok(array_column($metaFormalDecision['ui']['buttons']??[],'id')===['qualify:intensive','qualify:regular'],'Formal swimmer must receive Intensivo and Regulares buttons, not venue buttons.');
""",
    """pr162_review_ok(($metaFormal['commercial_context']['program']??null)==='regular','Formal experience must route to regular classes, never inherit the intensive ad program.');
pr162_review_ok(($metaFormal['commercial_context']['background']??null)==='formal','Formal training history must persist as durable commercial context.');
pr162_review_ok(($metaFormal['flow']['step']??null)==='sede','A formal swimmer must go directly to venue selection after product resolution.');
pr162_review_ok(array_column($metaFormalDecision['ui']['buttons']??[],'id')===['sede:monteverde','sede:palapas'],'Formal swimmer must receive venue buttons, not a product-choice screen.');
""",
)

product_test = "tests/sharky-product-boundary-regression.php"
replace_once(
    product_test,
    "product_boundary_ok(hache_sharky_product_boundary_reply('Prefiero clases regulares',$formal)===null,'Only a prospect with formal training may explicitly switch to regular classes automatically.');\n",
    """product_boundary_ok(hache_sharky_product_boundary_reply('Prefiero clases regulares',$formal)===null,'A prospect with formal training may continue with regular classes without a human exception.');
$formalClean=hache_sharky_product_boundary_sanitize_state($formal);
product_boundary_ok(($formalClean['commercial_context']['program']??null)==='regular','Formal swimmer state must canonicalize to regular classes automatically.');
product_boundary_ok(($formalClean['commercial_context']['recommended_program']??null)==='regular','Formal swimmer recommendation must canonicalize to regular classes.');
""",
)

package = "package.json"
replace_once(
    package,
    "php tests/sharky-guided-onboarding-regression.php && php tests/sharky-guided-first-prospect-regression.php",
    "php tests/sharky-guided-onboarding-regression.php && php tests/sharky-qualification-priority-regression.php && php tests/sharky-guided-first-prospect-regression.php",
)
