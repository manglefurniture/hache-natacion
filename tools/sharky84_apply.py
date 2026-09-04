from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path):
    return (ROOT / path).read_text(encoding='utf-8')


def write(path, text):
    (ROOT / path).write_text(text, encoding='utf-8')


def replace_once(path, old, new, marker=None):
    text = read(path)
    if marker and marker in text:
        return
    if old not in text:
        raise SystemExit(f'anchor not found in {path}: {old[:120]!r}')
    text = text.replace(old, new, 1)
    write(path, text)


def append_before(path, anchor, block, marker):
    text = read(path)
    if marker in text:
        return
    if anchor not in text:
        raise SystemExit(f'append anchor not found in {path}: {anchor[:120]!r}')
    text = text.replace(anchor, block + '\n' + anchor, 1)
    write(path, text)


# 1) Fix the current static route regression and enrich dispatcher context.
path = 'public/api/sharky-whatsapp-dispatch.php'
text = read(path)
text = text.replace("require __DIR__.'/sharky-v2.php';", "require __DIR__.DIRECTORY_SEPARATOR.'sharky-v2.php';")
if "'previous_user_text'=>''" not in text:
    text = text.replace(
        "        'assistant_presentation_queued'=>false,\n",
        "        'assistant_presentation_queued'=>false,\n        'previous_user_text'=>'',\n        'selected_course_price'=>null,\n",
        1,
    )
if "$state['previous_user_text']=$content;" not in text:
    text = text.replace(
        "        if($role==='assistant'&&$content!=='')$state['assistant_presentation_queued']=true;\n        if($role!=='system'||$content==='')continue;\n",
        "        if($role==='assistant'&&$content!=='')$state['assistant_presentation_queued']=true;\n        if($role==='user'&&$content!==''){$state['previous_user_text']=$content;continue;}\n        if($role!=='system'||$content==='')continue;\n        if(preg_match('/Precio del curso intensivo seleccionado en backend:\\s*\\$([0-9]+(?:\\.[0-9]+)?)/u',$content,$pm)===1)$state['selected_course_price']=(float)$pm[1];\n",
        1,
    )
if "$responseIncomplete" not in text:
    text = text.replace(
        "    $answer=hache_sharky_dispatcher_clean_model_answer((string)$body['answer'],$underway);\n    if(hache_sharky_reply_looks_incomplete($answer)){\n",
        "    $answer=hache_sharky_dispatcher_clean_model_answer((string)$body['answer'],$underway);\n    $responseIncomplete=(string)($body['response_status']??'completed')==='incomplete';\n    if($responseIncomplete||hache_sharky_reply_looks_incomplete($answer)){\n",
        1,
    )
write(path, text)

# 2) Deterministic follow-up location + selected backend course price.
path = 'config/sharky-deterministic-replies.php'
text = read(path)
if 'function hache_sharky_deterministic_location_followup_request' not in text:
    anchor = "function hache_sharky_deterministic_route_followup(string $text): bool\n"
    block = r'''function hache_sharky_deterministic_location_followup_request(string $text,array $state): bool
{
    if(hache_sharky_deterministic_detect_explicit_sede($text)===null)return false;
    $t=hache_sharky_deterministic_normalize($text);
    if(preg_match('/^(?:y\s+)?(?:la\s+)?(?:de\s+)?(?:monteverde|palapas(?:\s+protudec)?)[?!. ]*$/u',$t)!==1)return false;
    $previous=trim((string)($state['previous_user_text']??''));
    return $previous!==''&&hache_sharky_deterministic_location_request($previous);
}

'''
    if anchor not in text:
        raise SystemExit('deterministic route anchor missing')
    text = text.replace(anchor, block + anchor, 1)
old = """    if($commercial['program']==='intensive'){\n        $price=hache_sharky_config_int($business,'sharky_precio_intensivo',1200,0,100000);\n        return '💰 Curso intensivo'.\"\\n\\n\".'• Precio general: $'.number_format($price,0,'.',',').' MXN'.\"\\n\".'• Duración: 3 semanas, lunes a viernes'.\"\\n\".'• No cobra inscripción.';\n    }\n"""
new = """    if($commercial['program']==='intensive'){\n        $selected=$state['selected_course_price']??null;\n        $price=is_numeric($selected)?(float)$selected:(float)hache_sharky_config_int($business,'sharky_precio_intensivo',1200,0,100000);\n        $priceText=rtrim(rtrim(number_format($price,2,'.',','),'0'),'.');\n        $label=is_numeric($selected)?'Precio del curso seleccionado':'Precio general';\n        return '💰 Curso intensivo'.\"\\n\\n\".'• '.$label.': $'.$priceText.' MXN'.\"\\n\".'• Duración: 3 semanas, lunes a viernes'.\"\\n\".'• No cobra inscripción.';\n    }\n"""
if old in text:
    text = text.replace(old, new, 1)
elif "selected_course_price" not in text:
    raise SystemExit('intensive price anchor missing')
text = text.replace(
    "    if(hache_sharky_deterministic_location_request($text))return hache_sharky_deterministic_location_message($text,$state);\n",
    "    if(hache_sharky_deterministic_location_request($text)||hache_sharky_deterministic_location_followup_request($text,$state))return hache_sharky_deterministic_location_message($text,$state);\n",
    1,
)
write(path, text)

# 3) Carry backend course price into the controlled registration state.
replace_once(
    'config/sharky-business-actions.php',
    "            $st = $pdo->prepare('SELECT id,estado FROM cursos_intensivos WHERE sede_id=:s AND fecha_inicio=:f LIMIT 1');",
    "            $st = $pdo->prepare('SELECT id,estado,precio FROM cursos_intensivos WHERE sede_id=:s AND fecha_inicio=:f LIMIT 1');",
    marker="SELECT id,estado,precio FROM cursos_intensivos",
)
replace_once(
    'config/sharky-business-actions.php',
    "                'label'=>'Inicio '.date('d/m/Y', strtotime($date)),\n                'schedules'=>$schedules,",
    "                'label'=>'Inicio '.date('d/m/Y', strtotime($date)),\n                'precio'=>$course&&is_numeric($course['precio']??null)?(float)$course['precio']:null,\n                'schedules'=>$schedules,",
    marker="'precio'=>$course&&is_numeric",
)
replace_once(
    'config/sharky-orchestrator.php',
    "            $data['course_id']=$courseId;\n            $data['fecha_inicio']=$course['fecha_inicio']??null;",
    "            $data['course_id']=$courseId;\n            $data['fecha_inicio']=$course['fecha_inicio']??null;\n            $data['course_price']=is_numeric($course['precio']??null)?(float)$course['precio']:null;",
    marker="['course_price']",
)

# 4) Durable conversation guards in the WhatsApp adapter.
path = 'config/sharky-whatsapp-adapter.php'
text = read(path)
if 'function hache_sharky_whatsapp_reconcile_qualification_context' not in text:
    anchor = "function hache_sharky_whatsapp_commercial_ready_message(array $state,string $prefix='Perfecto.'): string\n"
    helpers = r'''function hache_sharky_whatsapp_reconcile_qualification_context(array $state): array
{
    $flow=$state['flow']??null;
    if(is_array($flow)&&($flow['name']??'')==='qualify_prospect'&&hache_sharky_whatsapp_commercial_ready($state))return hache_sharky_orchestrator_clear_flow($state);
    return $state;
}

function hache_sharky_whatsapp_recover_safe_qualification_interactive(array $state,array $event,int $now): array
{
    if(is_array($state['flow']??null))return $state;
    if(($state['identity']['kind']??'unknown')!=='prospect')return $state;
    $id=strtolower(trim((string)($event['interactive_id']??'')));
    if($id==='')return $state;
    $updated=(int)($state['updated_at']??0);
    if($updated>0&&$updated<$now-21600)return $state;
    $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    $program=$commercial['program']??null;$sede=$commercial['sede_clave']??null;$step=null;
    if(in_array($id,['qualify:swims','qualify:beginner'],true)&&$program===null&&$sede===null)$step='swim';
    elseif(in_array($id,['qualify:formal','qualify:self'],true)&&$program===null)$step='background';
    elseif(in_array($id,['qualify:intensive','qualify:regular'],true)&&$program===null)$step='program';
    elseif(str_starts_with($id,'sede:')&&in_array($program,['intensive','regular'],true)&&$sede===null)$step='sede';
    elseif(str_starts_with($id,'daypart:')&&$program==='regular'&&in_array($sede,['MONTEVERDE','PALAPAS'],true))$step='daypart';
    return $step===null?$state:hache_sharky_orchestrator_flow($state,'qualify_prospect',$step,[],$now);
}

function hache_sharky_whatsapp_payment_choice(string $text): ?int
{
    $t=hache_sharky_orchestrator_normalize($text);
    if(preg_match('/^(?:quiero\s+pagar\s+(?:el\s+)?|prefiero\s+(?:el\s+)?|elijo\s+(?:el\s+)?|voy\s+con\s+(?:el\s+)?|pago\s+(?:el\s+)?|el\s+)?(50|100)\s*(?:%|por\s+ciento)?[.! ]*$/u',$t,$m)!==1)return null;
    return (int)$m[1];
}

function hache_sharky_whatsapp_payment_confirmation(string $text): bool
{
    $t=hache_sharky_orchestrator_normalize($text);
    return preg_match('/^(?:si|ok|oki|okay|claro|confirmo|confirmado|dale|va|correcto|de\s+acuerdo)[!. ]*$/u',$t)===1;
}

function hache_sharky_whatsapp_payment_flow_active(array $state): bool
{
    $flow=$state['flow']??null;
    return is_array($flow)&&($flow['name']??'')==='commercial_payment'&&($flow['step']??'')==='confirm';
}

function hache_sharky_whatsapp_payment_confirmation_start(array $state,int $percentage,int $now): array
{
    $percentage=in_array($percentage,[50,100],true)?$percentage:100;
    $state=hache_sharky_orchestrator_flow($state,'commercial_payment','confirm',['percentage'=>$percentage],$now);
    $message='¿Confirmas que quieres pagar el '.$percentage.'% por anticipado para reservar tu lugar?';
    return [$state,hache_sharky_orchestrator_yes_no('commercial_payment_confirm',$message)];
}

function hache_sharky_whatsapp_payment_flow_input(array $state,array $event,int $now): ?array
{
    if(!hache_sharky_whatsapp_payment_flow_active($state))return null;
    $flow=$state['flow'];$data=is_array($flow['data']??null)?$flow['data']:[];$percentage=(int)($data['percentage']??0);
    $id=strtolower(trim((string)($event['interactive_id']??'')));
    if($id==='flow:no'||$id==='flow:cancel'){
        $state=hache_sharky_orchestrator_clear_flow($state);
        return [$state,hache_sharky_whatsapp_commercial_next_action($state,'Entendido.')];
    }
    if($id!=='flow:yes')return [$state,hache_sharky_orchestrator_yes_no('commercial_payment_confirm','¿Confirmas la opción de pago seleccionada?')];
    if(!in_array($percentage,[50,100],true)){
        $state=hache_sharky_orchestrator_clear_flow($state);
        return [$state,hache_sharky_whatsapp_commercial_next_action($state,'No pude recuperar esa opción de pago.')];
    }
    $state['commercial_context']['payment_choice_pct']=$percentage;
    $state=hache_sharky_orchestrator_clear_flow($state);
    $prefix=$percentage===100?'Perfecto, elegiste pagar el 100% por anticipado.':'Perfecto, elegiste reservar con el 50% por anticipado.';
    return hache_sharky_whatsapp_registration_offer_from_context($state,$now,$prefix);
}

'''
    if anchor not in text:
        raise SystemExit('adapter commercial ready message anchor missing')
    text = text.replace(anchor, helpers + anchor, 1)

# commercial_payment buttons are only valid inside their exact durable flow.
if "$name==='commercial_payment'" not in text:
    text = text.replace(
        "    if($name==='absence'){\n",
        "    if($name==='commercial_payment'){\n        if($step==='confirm')return in_array($id,['flow:yes','flow:no','flow:cancel'],true);\n        return false;\n    }\n    if($name==='absence'){\n",
        1,
    )

# Append selected backend course price to trusted style context.
if 'Precio del curso intensivo seleccionado en backend:' not in text:
    old_tail = """    if(is_array($commercial)){\n        $known=[];\n        if(($commercial['program']??null)==='intensive')$known[]='programa: curso intensivo';\n        elseif(($commercial['program']??null)==='regular')$known[]='programa: clases regulares';\n        if(($commercial['sede_clave']??null)==='PALAPAS')$known[]='sede: Palapas Protudec';\n        elseif(($commercial['sede_clave']??null)==='MONTEVERDE')$known[]='sede: Monteverde';\n        if(is_int($commercial['age']??null))$known[]='edad: '.$commercial['age'].' años';\n        if($known)$instruction.=' Contexto comercial ya confirmado por el usuario: '.implode(', ',$known).'. Trátalo como memoria vigente. No vuelvas a preguntar estos datos salvo que el usuario los cambie explícitamente.';\n    }\n    return $instruction;\n"""
    new_tail = """    if(is_array($commercial)){\n        $known=[];\n        if(($commercial['program']??null)==='intensive')$known[]='programa: curso intensivo';\n        elseif(($commercial['program']??null)==='regular')$known[]='programa: clases regulares';\n        if(($commercial['sede_clave']??null)==='PALAPAS')$known[]='sede: Palapas Protudec';\n        elseif(($commercial['sede_clave']??null)==='MONTEVERDE')$known[]='sede: Monteverde';\n        if(is_int($commercial['age']??null))$known[]='edad: '.$commercial['age'].' años';\n        if($known)$instruction.=' Contexto comercial ya confirmado por el usuario: '.implode(', ',$known).'. Trátalo como memoria vigente. No vuelvas a preguntar estos datos salvo que el usuario los cambie explícitamente.';\n    }\n    $flow=$state['flow']??null;$flowData=is_array($flow)&&is_array($flow['data']??null)?$flow['data']:[];\n    if(($flow['name']??'')==='register_intensive'&&is_numeric($flowData['course_price']??null)){\n        $coursePrice=rtrim(rtrim(number_format((float)$flowData['course_price'],2,'.',''),'0'),'.');\n        $instruction.=' Precio del curso intensivo seleccionado en backend: $'.$coursePrice.' MXN. Este precio prevalece sobre el precio general.';\n    }\n    return $instruction;\n"""
    if old_tail not in text:
        raise SystemExit('adapter style tail anchor missing')
    text = text.replace(old_tail, new_tail, 1)

# Preserve previous user turn/contact in trusted runtime context before any branch can mutate state.
old = """        $state=hache_sharky_db_state_load($pdo,$contact);\n        $context=hache_sharky_whatsapp_context($pdo,$contact,$extraContext);\n        $state=hache_sharky_whatsapp_resume_verified_state($state,$context,(int)$context['now']);\n        $state=hache_sharky_orchestrator_expire_flow($state,(int)$context['now']);\n        $ref=hache_sharky_orchestrator_referral($event,(int)$context['now']);\n"""
new = """        $state=hache_sharky_db_state_load($pdo,$contact);\n        $context=hache_sharky_whatsapp_context($pdo,$contact,$extraContext);\n        $context['contact']=$contact;\n        $context['previous_user_text']=trim((string)($state['last_user_text']??''));\n        $state=hache_sharky_whatsapp_resume_verified_state($state,$context,(int)$context['now']);\n        $state=hache_sharky_orchestrator_expire_flow($state,(int)$context['now']);\n        $state=hache_sharky_whatsapp_reconcile_qualification_context($state);\n        $state=hache_sharky_whatsapp_recover_safe_qualification_interactive($state,$event,(int)$context['now']);\n        $ref=hache_sharky_orchestrator_referral($event,(int)$context['now']);\n"""
if old in text:
    text = text.replace(old, new, 1)
elif "$context['previous_user_text']" not in text:
    raise SystemExit('adapter state/context anchor missing')

# Natural short confirmation belongs to the durable payment confirmation when active.
anchor = """        if(trim((string)($event['interactive_id']??''))===''&&hache_sharky_whatsapp_registration_offer_active($state)&&hache_sharky_whatsapp_offer_affirmation((string)($event['text']??''))){\n            $event['interactive_id']='flow:yes';\n        }\n"""
if 'hache_sharky_whatsapp_payment_flow_active($state)' not in text[text.find('function hache_sharky_whatsapp_process'):]:
    replacement = """        if(trim((string)($event['interactive_id']??''))===''&&hache_sharky_whatsapp_payment_flow_active($state)&&hache_sharky_whatsapp_payment_confirmation((string)($event['text']??''))){\n            $event['interactive_id']='flow:yes';\n        }\n\n""" + anchor
    if anchor not in text:
        raise SystemExit('offer affirmation anchor missing')
    text = text.replace(anchor, replacement, 1)

# Handle durable payment flow immediately after stale-interactive validation.
stale_block = """        if(!hache_sharky_whatsapp_interactive_is_current($state,$event)){\n            $decision=hache_sharky_orchestrator_decision('stale_interactive','Esa opción pertenece a un paso anterior. No hice ningún cambio; continuemos desde la opción que tienes activa ahora.');\n            hache_sharky_db_state_save($pdo,$contact,$state);hache_sharky_whatsapp_complete_receipt($pdo,$messageId,$extraContext);\n            return ['skip'=>false,'state'=>$state,'decision'=>$decision,'payload'=>hache_sharky_whatsapp_render($contact,$decision),'action_result'=>null];\n        }\n\n"""
if '$paymentFlow=hache_sharky_whatsapp_payment_flow_input' not in text:
    addition = stale_block + """        $paymentFlow=hache_sharky_whatsapp_payment_flow_input($state,$event,(int)$context['now']);\n        if(is_array($paymentFlow)){\n            [$state,$decision]=$paymentFlow;\n            hache_sharky_db_state_save($pdo,$contact,$state);hache_sharky_whatsapp_complete_receipt($pdo,$messageId,$extraContext);\n            return ['skip'=>false,'state'=>$state,'decision'=>$decision,'payload'=>hache_sharky_whatsapp_render($contact,$decision),'action_result'=>null];\n        }\n\n"""
    if stale_block not in text:
        raise SystemExit('stale block anchor missing')
    text = text.replace(stale_block, addition, 1)

# Start a durable payment confirmation from 50/100 text after policy interceptors.
regular_anchor = """        if(!is_array($state['flow']??null)&&($state['commercial_context']['program']??null)==='regular'&&in_array(($state['commercial_context']['sede_clave']??null),['MONTEVERDE','PALAPAS'],true)){\n"""
if '$paymentChoice=hache_sharky_whatsapp_payment_choice' not in text:
    block = """        if(trim((string)($event['interactive_id']??''))===''&&!is_array($state['flow']??null)&&hache_sharky_whatsapp_commercial_ready($state)&&($state['commercial_context']['program']??null)==='intensive'){\n            $paymentChoice=hache_sharky_whatsapp_payment_choice((string)($event['text']??''));\n            if($paymentChoice!==null){\n                [$state,$decision]=hache_sharky_whatsapp_payment_confirmation_start($state,$paymentChoice,(int)$context['now']);\n                hache_sharky_db_state_save($pdo,$contact,$state);hache_sharky_whatsapp_complete_receipt($pdo,$messageId,$extraContext);\n                return ['skip'=>false,'state'=>$state,'decision'=>$decision,'payload'=>hache_sharky_whatsapp_render($contact,$decision),'action_result'=>null];\n            }\n        }\n\n"""
    if regular_anchor not in text:
        raise SystemExit('regular schedule anchor missing')
    text = text.replace(regular_anchor, block + regular_anchor, 1)
write(path, text)

# 5) Add previous user turn to LLM history without elevating it to system instructions,
#    and persist real token usage per hashed WhatsApp conversation.
path = 'config/sharky-lab-worker.php'
text = read(path)
if "$context['previous_user_text']" not in text[text.find('function hache_sharky_lab_answer'):text.find('function hache_sharky_lab_claim_early')]:
    text = text.replace(
        "    $history=[];$ref=$state['referral']['latest']??null;\n",
        "    $history=[];$ref=$state['referral']['latest']??null;\n    $previous=trim((string)($context['previous_user_text']??''));\n    if($previous!=='')$history[]=['role'=>'user','content'=>mb_substr($previous,0,700)];\n",
        1,
    )
old = """    $data=json_decode((string)$response,true);return is_array($data)&&($data['ok']??false)===true?trim((string)($data['answer']??'')):'';\n"""
new = """    $data=json_decode((string)$response,true);\n    if(!is_array($data)||($data['ok']??false)!==true)return '';\n    if(is_array($data['usage']??null)&&trim((string)($context['contact']??''))!=='')hache_sharky_usage_record((string)$context['contact'],$data['usage']);\n    return trim((string)($data['answer']??''));\n"""
if old in text:
    text = text.replace(old, new, 1)
elif 'hache_sharky_usage_record' not in text:
    raise SystemExit('lab answer return anchor missing')
write(path, text)

# 6) Record Responses API status/usage without adding another model call.
path = 'public/api/sharky-v2.php'
text = read(path)
status_anchor = """if ($status < 200 || $status >= 300 || !is_array($result)) {\n    hache_sharky_metric_increment('errors_openai');\n    error_log('[sharky-v2] OpenAI HTTP '.$status);\n    sharky_v2_out(['ok' => false, 'error' => 'Sharky no pudo responder ahora mismo. Intenta de nuevo.'], 502);\n}\n\n$answer = '';\n"""
if '$responseStatus = trim((string)($result[' not in text:
    insert = """if ($status < 200 || $status >= 300 || !is_array($result)) {\n    hache_sharky_metric_increment('errors_openai');\n    error_log('[sharky-v2] OpenAI HTTP '.$status);\n    sharky_v2_out(['ok' => false, 'error' => 'Sharky no pudo responder ahora mismo. Intenta de nuevo.'], 502);\n}\n\n$responseStatus = trim((string)($result['status'] ?? 'completed'));\n$incompleteReason = trim((string)($result['incomplete_details']['reason'] ?? ''));\n$rawUsage = is_array($result['usage'] ?? null) ? $result['usage'] : [];\n$usage = [\n    'input_tokens'=>max(0,(int)($rawUsage['input_tokens']??0)),\n    'output_tokens'=>max(0,(int)($rawUsage['output_tokens']??0)),\n    'total_tokens'=>max(0,(int)($rawUsage['total_tokens']??0)),\n];\nif($usage['total_tokens']===0)$usage['total_tokens']=$usage['input_tokens']+$usage['output_tokens'];\nif($usage['input_tokens']>0)hache_sharky_metric_increment('openai_input_tokens',$usage['input_tokens']);\nif($usage['output_tokens']>0)hache_sharky_metric_increment('openai_output_tokens',$usage['output_tokens']);\nif($usage['total_tokens']>0)hache_sharky_metric_increment('openai_total_tokens',$usage['total_tokens']);\nif($responseStatus==='incomplete'){\n    hache_sharky_metric_increment('openai_incomplete');\n    error_log('[sharky-v2] incomplete response reason='.($incompleteReason!==''?$incompleteReason:'unknown'));\n}\n\n$answer = '';\n"""
    if status_anchor not in text:
        raise SystemExit('v2 status anchor missing')
    text = text.replace(status_anchor, insert, 1)
old_final = "sharky_v2_out(['ok' => true, 'answer' => $answer, 'channel' => $channel]);"
new_final = """$out=['ok'=>true,'answer'=>$answer,'channel'=>$channel,'response_status'=>$responseStatus];\nif($incompleteReason!=='')$out['incomplete_reason']=$incompleteReason;\nif($channel==='whatsapp')$out['usage']=$usage;\nsharky_v2_out($out);"""
if old_final in text:
    text = text.replace(old_final, new_final, 1)
elif "'response_status'=>$responseStatus" not in text:
    raise SystemExit('v2 final output anchor missing')
write(path, text)

# 7) Per-conversation token counters are hashed on disk, separate from daily global counters.
path = 'config/sharky-runtime.php'
text = read(path)
if 'function hache_sharky_usage_record' not in text:
    text += r'''

function hache_sharky_usage_record(string $contact,array $usage): void
{
    $contact=preg_replace('/\D+/','',$contact)?:'';if($contact==='')return;
    $input=max(0,(int)($usage['input_tokens']??0));$output=max(0,(int)($usage['output_tokens']??0));$total=max(0,(int)($usage['total_tokens']??0));
    if($total===0)$total=$input+$output;if($input===0&&$output===0&&$total===0)return;
    $dir=hache_sharky_writable_dir('usage');if($dir==='')return;
    $hash=hache_sharky_contact_hash($contact);$path=$dir.'/'.hache_sharky_local_date().'-'.$hash.'.json';
    $handle=@fopen($path,'c+');if(!$handle||!flock($handle,LOCK_EX)){if(is_resource($handle))fclose($handle);return;}
    rewind($handle);$raw=stream_get_contents($handle);$data=json_decode(is_string($raw)?$raw:'',true);if(!is_array($data))$data=[];
    $data['calls']=max(0,(int)($data['calls']??0)+1);$data['input_tokens']=max(0,(int)($data['input_tokens']??0)+$input);$data['output_tokens']=max(0,(int)($data['output_tokens']??0)+$output);$data['total_tokens']=max(0,(int)($data['total_tokens']??0)+$total);$data['updated_at']=gmdate('c');
    $encoded=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($encoded!==false){ftruncate($handle,0);rewind($handle);fwrite($handle,$encoded);}flock($handle,LOCK_UN);fclose($handle);
}

function hache_sharky_usage_for_contact(string $contact,int $days=7): array
{
    $contact=preg_replace('/\D+/','',$contact)?:'';$days=max(1,min(31,$days));$sum=['calls'=>0,'input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0];if($contact==='')return $sum;
    $hash=hache_sharky_contact_hash($contact);
    for($i=0;$i<$days;$i++){
        $date=hache_sharky_local_date(-$i);
        foreach(hache_sharky_state_dirs('usage') as $dir){$path=$dir.'/'.$date.'-'.$hash.'.json';if(!is_file($path))continue;$data=json_decode((string)@file_get_contents($path),true);if(!is_array($data))continue;foreach(array_keys($sum) as $key)$sum[$key]+=(int)($data[$key]??0);break;}
    }
    return $sum;
}
'''
write(path, text)

# 8) Regression coverage for every production screenshot family.
path = 'tests/sharky-deterministic-replies-regression.php'
text = read(path)
if 'Elliptical Monteverde location follow-up' not in text:
    anchor = 'echo "SHARKY_DETERMINISTIC_REPLIES_OK\\n";'
    block = r'''
$followupState=$state;
$followupState['previous_user_text']='Me envías la ubicación';
deterministic_ok(hache_sharky_deterministic_location_followup_request('¿Y la de Monteverde?',$followupState),'Elliptical Monteverde location follow-up must inherit the previous location topic.');
deterministic_ok(!hache_sharky_deterministic_location_followup_request('¿Y la de Monteverde?',array_merge($followupState,['previous_user_text'=>'¿Qué horarios hay?'])),'Venue-only follow-up must not become location without a location topic.');
$selectedPriceState=$state;$selectedPriceState['selected_course_price']=1350.0;
$selectedPrice=hache_sharky_deterministic_price_message($selectedPriceState)??'';
deterministic_ok(str_contains($selectedPrice,'$1,350')&&str_contains($selectedPrice,'curso seleccionado'),'Selected backend intensive price must override the general price.');
$dispatcher=file_get_contents(__DIR__.'/../public/api/sharky-whatsapp-dispatch.php')?:'';
deterministic_ok(str_contains($dispatcher,"response_status']??'completed')==='incomplete'"),'Dispatcher must reject Responses API incomplete output before WhatsApp delivery.');
deterministic_ok(!str_contains($dispatcher,"__DIR__.'/sharky-v2.php'"),'Dispatcher must avoid the false local-route literal caught by the global static regression.');
'''
    if anchor not in text:
        raise SystemExit('deterministic test echo anchor missing')
    text = text.replace(anchor, block + '\n' + anchor, 1)
write(path, text)

path = 'tests/sharky-whatsapp-adapter-regression.php'
text = read(path)
if 'Payment choice 100% must become a durable confirmation flow' not in text:
    anchor = 'echo "SHARKY_WHATSAPP_ADAPTER_REGRESSION_OK\\n";'
    block = r'''
// Captura productiva 20:45–20:46: un cierre comercial nunca puede caer de nuevo a swim discovery.
$closing=hache_sharky_orchestrator_state(null,1788486300);
$closing['identity']=array_replace($closing['identity'],['kind'=>'prospect','verified'=>true,'source'=>'self_declared']);
$closing['commercial_context']=array_replace($closing['commercial_context'],['program'=>'intensive','sede_clave'=>'MONTEVERDE']);
$closing=hache_sharky_orchestrator_flow($closing,'qualify_prospect','swim',[],1788486300);
$closing=hache_sharky_whatsapp_reconcile_qualification_context($closing);
expect_adapter(!is_array($closing['flow']??null),'Complete commercial context must invalidate an obsolete qualification flow before a short reply is processed.');
expect_adapter(hache_sharky_whatsapp_payment_choice('El 100')===100,'Payment choice 100% must become a durable confirmation flow.');
expect_adapter(hache_sharky_whatsapp_payment_choice('50%')===50,'Payment choice 50% must be recognized.');
[$payState,$payDecision]=hache_sharky_whatsapp_payment_confirmation_start($closing,100,1788486301);
expect_adapter(($payState['flow']['name']??'')==='commercial_payment'&&($payState['flow']['step']??'')==='confirm','Payment choice must persist as a controlled confirmation flow.');
expect_adapter(hache_sharky_whatsapp_payment_confirmation('Ok'),'Short Ok must confirm only the active durable payment question.');
expect_adapter(hache_sharky_whatsapp_interactive_is_current($payState,['interactive_id'=>'flow:yes']),'Payment confirmation button must be current only while commercial_payment is active.');
$payResult=hache_sharky_whatsapp_payment_flow_input($payState,['interactive_id'=>'flow:yes','text'=>'Ok'],1788486302);
expect_adapter(is_array($payResult),'Payment confirmation must produce a controlled next step.');
expect_adapter(($payResult[0]['commercial_context']['payment_choice_pct']??null)===100,'Confirmed payment percentage must survive in durable commercial context.');
expect_adapter(($payResult[0]['flow']['name']??'')==='register_intensive'&&($payResult[0]['flow']['step']??'')==='offer','Ok after 100% must advance to registration offer, never restart swim discovery.');

// Visible non-mutating qualification buttons may recover after the 30-minute flow TTL, but only for a bounded window.
$expired=hache_sharky_orchestrator_state(null,1788480000);
$expired['identity']=array_replace($expired['identity'],['kind'=>'prospect','verified'=>true,'source'=>'self_declared']);
$expired['updated_at']=1788480000;
$recovered=hache_sharky_whatsapp_recover_safe_qualification_interactive($expired,['interactive_id'=>'qualify:swims','text'=>'Ya sé nadar'],1788485160);
expect_adapter(($recovered['flow']['name']??'')==='qualify_prospect'&&($recovered['flow']['step']??'')==='swim','A still-visible Ya sé nadar button must recover safely after TTL expiration.');
$tooOld=hache_sharky_whatsapp_recover_safe_qualification_interactive($expired,['interactive_id'=>'qualify:swims','text'=>'Ya sé nadar'],1788502001);
expect_adapter(!is_array($tooOld['flow']??null),'Ancient qualification buttons must not be revived indefinitely.');
$mutating=hache_sharky_whatsapp_recover_safe_qualification_interactive($expired,['interactive_id'=>'flow:confirm','text'=>'Confirmar'],1788485160);
expect_adapter(!is_array($mutating['flow']??null),'Mutation/confirmation buttons must never be reconstructed after their flow expires.');

$runtimeSource=file_get_contents(__DIR__.'/../config/sharky-runtime.php')?:'';
expect_adapter(str_contains($runtimeSource,'function hache_sharky_usage_record')&&str_contains($runtimeSource,'function hache_sharky_usage_for_contact'),'Real OpenAI token usage must be persistable per hashed conversation.');
expect_adapter(str_contains($v2Source,"'input_tokens'=>")&&str_contains($v2Source,"'response_status'=>$responseStatus"),'Responses API status and real token counters must be surfaced to the internal WhatsApp caller.');
'''
    if anchor not in text:
        raise SystemExit('adapter test echo anchor missing')
    text = text.replace(anchor, block + '\n' + anchor, 1)
write(path, text)

print('SHARKY84_PATCH_APPLIED')
