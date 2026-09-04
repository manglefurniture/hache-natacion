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
        raise SystemExit(f'anchor not found in {path}: {old[:160]!r}')
    write(path, text.replace(old, new, 1))


path='config/sharky-whatsapp-adapter.php'
text=read(path)

# 1. Adapter-level completeness guard. This protects every WhatsApp response,
# including answers that are transformed after the model dispatcher returns.
if 'function hache_sharky_whatsapp_answer_looks_incomplete' not in text:
    anchor="function hache_sharky_whatsapp_nado_libre_request(string $text): bool\n"
    block=r'''function hache_sharky_whatsapp_answer_looks_incomplete(string $answer): bool
{
    $answer=trim($answer);if($answer==='')return true;
    foreach(preg_split('/\R/u',$answer)?:[] as $line){
        if(preg_match('/^\s*[•·-]\s*$/u',(string)$line)===1)return true;
    }
    if(preg_match('/(?:[:,;]|[•·])\s*$/u',$answer)===1)return true;
    $t=hache_sharky_orchestrator_normalize($answer);
    $plain=trim(preg_replace('/[^\p{L}\p{N} ]+/u',' ',$t)??'');
    $words=preg_split('/\s+/u,$plain,-1,PREG_SPLIT_NO_EMPTY)?:[];
    if(count($words)<=4&&preg_match('/\b(?:hola|claro|perfecto|genial|listo|entendido|ok|oki|vale)\b/u',$plain)===1)return true;
    return preg_match('/\b(?:para\s+avanzar|antes\s+de\s+registrarte(?:\s+dime\s+por\s+favor)?|dime\s+por\s+favor|necesito\s+primero\s+ubicar\s+que\s+te\s+interesa|como\s+ya\s+mencionaste\s+que\s+quieres\s+la\s+otra\s+sede)\s*$/u',$t)===1;
}

function hache_sharky_whatsapp_incomplete_recovery(array $state): string
{
    if(function_exists('hache_sharky_whatsapp_commercial_ready')&&hache_sharky_whatsapp_commercial_ready($state)){
        return hache_sharky_whatsapp_commercial_ready_message($state,'Sigo contigo.');
    }
    $next=hache_sharky_orchestrator_next_required_step($state);
    $prompt=trim((string)($next['prompt']??''));
    if(($next['slot']??'')==='age')$prompt='';
    return $prompt!==''?'Sigo contigo. '.$prompt:'Sigo contigo. Puedo ayudarte con horarios, precio, sede o inscripción.';
}

'''
    if anchor not in text: raise SystemExit('completeness insertion anchor missing')
    text=text.replace(anchor,block+anchor,1)

# 2. Relative venue preference: "quiero la otra sede" must flip the venue already
# known in state instead of leaving the old venue active.
if 'function hache_sharky_whatsapp_detect_relative_venue_preference' not in text:
    anchor="function hache_sharky_whatsapp_apply_natural_venue_preference(array $state,string $text): array\n"
    block=r'''function hache_sharky_whatsapp_detect_relative_venue_preference(array $state,string $text): ?string
{
    $current=(string)($state['commercial_context']['sede_clave']??'');
    if(!in_array($current,['MONTEVERDE','PALAPAS'],true))return null;
    $t=hache_sharky_orchestrator_normalize($text);
    if(preg_match('/\b(?:quiero|prefiero|elijo|escojo|me\s+quedo\s+con|voy\s+con|cambio\s+a|mejor\s+quiero)\b.{0,35}\b(?:la\s+)?otra\s+sede\b/u',$t)!==1
        &&preg_match('/\bpero\s+quiero\s+(?:la\s+)?otra\s+sede\b/u',$t)!==1)return null;
    return $current==='MONTEVERDE'?'PALAPAS':'MONTEVERDE';
}

function hache_sharky_whatsapp_detect_swim_level(string $text): ?string
{
    $t=hache_sharky_orchestrator_normalize($text);
    if(preg_match('/\b(?:estoy|empiezo|empezando|voy)\s+(?:desde|en)\s*(?:cero|0)\b|\bdesde\s*(?:cero|0)\b|\bno\s+(?:se\s+)?nadar\b|\bnunca\s+he\s+nadado\b/u',$t)===1)return 'beginner';
    if(preg_match('/\b(?:ya\s+)?se\s+nadar\b|\bya\s+nado\b/u',$t)===1)return 'swims';
    return null;
}

function hache_sharky_whatsapp_apply_natural_swim_level(array $state,string $text): array
{
    if(($state['identity']['kind']??'unknown')!=='prospect')return $state;
    $level=hache_sharky_whatsapp_detect_swim_level($text);
    if($level===null)return $state;
    $state['commercial_context']['swim_level']=$level;
    if($level==='beginner'&&($state['commercial_context']['program']??null)===null)$state['commercial_context']['program']='intensive';
    return $state;
}

function hache_sharky_whatsapp_registration_help_continuation(string $text): bool
{
    $t=hache_sharky_orchestrator_normalize($text);
    return preg_match('/\b(?:no\s+)?me\s+puedes\s+ayudar(?:me)?\s+tu\b|\bme\s+ayudas\s+tu\b|\bpuedes\s+hacerlo\s+tu\b|\blo\s+puedo\s+hacer\s+contigo\b|\bquiero\s+hacerlo\s+contigo\b/u',$t)===1;
}

'''
    if anchor not in text: raise SystemExit('relative venue insertion anchor missing')
    text=text.replace(anchor,block+anchor,1)

old="""    $sede=hache_sharky_whatsapp_detect_venue_preference($text);\n    if($sede!==null)$state['commercial_context']['sede_clave']=$sede;\n"""
new="""    $sede=hache_sharky_whatsapp_detect_venue_preference($text);\n    if($sede===null)$sede=hache_sharky_whatsapp_detect_relative_venue_preference($state,$text);\n    if($sede!==null)$state['commercial_context']['sede_clave']=$sede;\n"""
if old in text:text=text.replace(old,new,1)
elif 'detect_relative_venue_preference($state,$text)' not in text:raise SystemExit('natural venue preference anchor missing')

old="$sede=hache_sharky_whatsapp_detect_venue_preference((string)($event['text']??''));\n    if($sede===null)return null;"
new="$sede=hache_sharky_whatsapp_detect_venue_preference((string)($event['text']??''));\n    if($sede===null)$sede=hache_sharky_whatsapp_detect_relative_venue_preference($state,(string)($event['text']??''));\n    if($sede===null)return null;"
if old in text:text=text.replace(old,new,1)
elif 'detect_relative_venue_preference($state,(string)($event' not in text:raise SystemExit('registration venue correction anchor missing')

# 3. Natural beginner statements become durable context even when they arrive in
# free conversation instead of precisely on the controlled swim step.
old="""        if(trim((string)($event['interactive_id']??''))===''){\n            $state=hache_sharky_whatsapp_apply_natural_venue_preference($state,(string)($event['text']??''));\n            $state=hache_sharky_whatsapp_capture_declared_age($state,(string)($event['text']??''));\n        }\n"""
new="""        if(trim((string)($event['interactive_id']??''))===''){\n            $state=hache_sharky_whatsapp_apply_natural_venue_preference($state,(string)($event['text']??''));\n            $state=hache_sharky_whatsapp_apply_natural_swim_level($state,(string)($event['text']??''));\n            $state=hache_sharky_whatsapp_capture_declared_age($state,(string)($event['text']??''));\n        }\n"""
if old in text:text=text.replace(old,new,1)
elif 'apply_natural_swim_level($state' not in text:raise SystemExit('natural context application anchor missing')

# 4. Everyday acknowledgements shown in production should advance the ready
# intensive funnel instead of falling back to free conversation.
old="return preg_match('/^(?:si(?:,)?\\s+quiero|claro\\s+que\\s+si|si(?:,)?\\s+por\\s+favor)[!. ]*$/u',$t)===1;"
new="return preg_match('/^(?:si|si(?:,)?\\s+quiero|claro|claro\\s+que\\s+si|si(?:,)?\\s+por\\s+favor|ok|oki|okay|vale|va|dale|perfecto|me\\s+parece\\s+bien|esta\\s+bien|de\\s+acuerdo|aja|anja)[!?!. ]*$/u',$t)===1;"
if old in text:text=text.replace(old,new,1)
elif 'me\\s+parece\\s+bien' not in text:raise SystemExit('offer affirmation anchor missing')

# 5. "No me puedes ayudar tú?" after discussing registration is contextual
# consent to use Sharky's own registration flow, not a request to repeat the URL.
anchor="""        if(trim((string)($event['interactive_id']??''))===''&&!is_array($state['flow']??null)&&hache_sharky_whatsapp_commercial_ready($state)&&($state['commercial_context']['program']??null)==='intensive'&&hache_sharky_whatsapp_offer_affirmation((string)($event['text']??''))){\n"""
if 'hache_sharky_whatsapp_registration_help_continuation((string)($event' not in text:
    block="""        if(trim((string)($event['interactive_id']??''))===''&&!is_array($state['flow']??null)&&hache_sharky_whatsapp_commercial_ready($state)&&($state['commercial_context']['program']??null)==='intensive'&&hache_sharky_whatsapp_registration_help_continuation((string)($event['text']??''))){\n            [$state,$decision]=hache_sharky_whatsapp_registration_offer_from_context($state,(int)$context['now'],'Sí, puedo ayudarte yo desde aquí.');\n            hache_sharky_db_state_save($pdo,$contact,$state);hache_sharky_whatsapp_complete_receipt($pdo,$messageId,$extraContext);\n            return ['skip'=>false,'state'=>$state,'decision'=>$decision,'payload'=>hache_sharky_whatsapp_render($contact,$decision),'action_result'=>null];\n        }\n\n"""
    if anchor not in text: raise SystemExit('registration assistance handler anchor missing')
    text=text.replace(anchor,block+anchor,1)

# 6. Remember the level in style context and in the repeated-question guard.
old="""        if(is_int($commercial['age']??null))$known[]='edad: '.$commercial['age'].' años';\n        if($known)$instruction.=' Contexto comercial ya confirmado por el usuario: '.implode(', ',$known).'. Trátalo como memoria vigente. No vuelvas a preguntar estos datos salvo que el usuario los cambie explícitamente.';\n"""
new="""        if(is_int($commercial['age']??null))$known[]='edad: '.$commercial['age'].' años';\n        if(($commercial['swim_level']??null)==='beginner')$known[]='experiencia: empieza desde cero';\n        elseif(($commercial['swim_level']??null)==='swims')$known[]='experiencia: ya sabe nadar';\n        if($known)$instruction.=' Contexto comercial ya confirmado por el usuario: '.implode(', ',$known).'. Trátalo como memoria vigente. No vuelvas a preguntar estos datos salvo que el usuario los cambie explícitamente.';\n"""
if old in text:text=text.replace(old,new,1)
elif "experiencia: empieza desde cero" not in text:raise SystemExit('style context anchor missing')

old="""    if(in_array(($commercial['sede_clave']??null),['MONTEVERDE','PALAPAS'],true))$confirmed[]='sede';\n    if(is_int($commercial['age']??null))$confirmed[]='age';\n"""
new="""    if(in_array(($commercial['sede_clave']??null),['MONTEVERDE','PALAPAS'],true))$confirmed[]='sede';\n    if(is_int($commercial['age']??null))$confirmed[]='age';\n    if(in_array(($commercial['swim_level']??null),['beginner','swims'],true))$confirmed[]='swim';\n"""
if old in text:text=text.replace(old,new,1)
elif "$confirmed[]='swim'" not in text:raise SystemExit('confirmed swim anchor missing')

old="""    if($slot==='identity')return preg_match('/\\b(?:ya\\s+)?eres\\s+(?:alumno|alumna|estudiante)\\b|\\b(?:alumno|alumna|estudiante)\\s+o\\s+(?:nuevo|nueva)\\b/u',$t)===1;\n    if($slot==='program'){\n"""
new="""    if($slot==='identity')return preg_match('/\\b(?:ya\\s+)?eres\\s+(?:alumno|alumna|estudiante)\\b|\\b(?:alumno|alumna|estudiante)\\s+o\\s+(?:nuevo|nueva)\\b/u',$t)===1;\n    if($slot==='swim')return preg_match('/\\b(?:ya\\s+)?sabes\\s+nadar\\b|\\bvas\\s+empezando\\b|\\bestas\\s+empezando\\b|\\bempiezas\\s+desde\\s+cero\\b|\\bdesde\\s+cero\\b/u',$t)===1;\n    if($slot==='program'){\n"""
if old in text:text=text.replace(old,new,1)
elif "if($slot==='swim')" not in text:raise SystemExit('question slot swim anchor missing')

# 7. Guard at the last adapter boundary, after all answer rewrites.
old="""            $answer=hache_sharky_whatsapp_enforce_confirmed_context($answer,$state);\n            $answer=hache_sharky_whatsapp_enforce_no_reintroduction($answer,$state,(string)($event['text']??''));\n            $answer=rtrim($answer).\"\\n\\nCuando quieras, seguimos donde lo dejamos.\";\n"""
new="""            $answer=hache_sharky_whatsapp_enforce_confirmed_context($answer,$state);\n            $answer=hache_sharky_whatsapp_enforce_no_reintroduction($answer,$state,(string)($event['text']??''));\n            if(hache_sharky_whatsapp_answer_looks_incomplete($answer))$answer=hache_sharky_whatsapp_incomplete_recovery($state);\n            $answer=rtrim($answer).\"\\n\\nCuando quieras, seguimos donde lo dejamos.\";\n"""
if old in text:text=text.replace(old,new,1)
elif 'answer_looks_incomplete($answer)' not in text:raise SystemExit('side-question completeness anchor missing')

old="""            $conversation=hache_sharky_whatsapp_enforce_confirmed_context($conversation,$state);\n            $conversation=hache_sharky_whatsapp_enforce_no_reintroduction($conversation,$state,(string)($event['text']??''));\n        }\n"""
new="""            $conversation=hache_sharky_whatsapp_enforce_confirmed_context($conversation,$state);\n            $conversation=hache_sharky_whatsapp_enforce_no_reintroduction($conversation,$state,(string)($event['text']??''));\n            if(hache_sharky_whatsapp_answer_looks_incomplete($conversation))$conversation=hache_sharky_whatsapp_incomplete_recovery($state);\n        }\n"""
if old in text:text=text.replace(old,new,1)
elif 'answer_looks_incomplete($conversation)' not in text:raise SystemExit('conversation completeness anchor missing')

write(path,text)

# 8. Durable state explicitly documents swim_level in the base schema.
path='config/sharky-orchestrator.php'
text=read(path)
old="""            'program' => null,\n            'sede_clave' => null,\n            'age' => null,\n"""
new="""            'program' => null,\n            'sede_clave' => null,\n            'age' => null,\n            'swim_level' => null,\n"""
if old in text:text=text.replace(old,new,1)
elif "'swim_level' => null" not in text:raise SystemExit('orchestrator commercial schema anchor missing')
old="""    $age=$out['commercial_context']['age'];\n    if ($age !== null && (!is_int($age) || $age < 1 || $age > 120)) $out['commercial_context']['age'] = null;\n"""
new="""    $age=$out['commercial_context']['age'];\n    if ($age !== null && (!is_int($age) || $age < 1 || $age > 120)) $out['commercial_context']['age'] = null;\n    if (!in_array($out['commercial_context']['swim_level'], [null,'beginner','swims'], true)) $out['commercial_context']['swim_level'] = null;\n"""
if old in text:text=text.replace(old,new,1)
elif "commercial_context']['swim_level']" not in text:raise SystemExit('orchestrator swim validation anchor missing')
write(path,text)

# 9. New regression suite for the exact production screenshots.
reg=r'''<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-orchestrator.php';
require_once __DIR__.'/../config/sharky-whatsapp-adapter.php';

function drift_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY CONTEXT DRIFT FAIL: {$message}\n");exit(1);}
}

$state=hache_sharky_orchestrator_state(null,1788489000);
$state['identity']=array_replace($state['identity'],['kind'=>'prospect','verified'=>true,'source'=>'self_declared']);
$state['commercial_context']['sede_clave']='MONTEVERDE';

drift_ok(
    hache_sharky_whatsapp_detect_relative_venue_preference($state,'Estoy desde 0 pero quiero la otra sede que tienen.')==='PALAPAS',
    '"quiero la otra sede" must flip Monteverde to Palapas.'
);
$state=hache_sharky_whatsapp_apply_natural_venue_preference($state,'Estoy desde 0 pero quiero la otra sede que tienen.');
drift_ok(($state['commercial_context']['sede_clave']??null)==='PALAPAS','Natural venue preference must persist Palapas.');
$state=hache_sharky_whatsapp_apply_natural_swim_level($state,'Estoy desde 0 pero quiero la otra sede que tienen.');
drift_ok(($state['commercial_context']['swim_level']??null)==='beginner','Starting from zero must be durable context.');
drift_ok(($state['commercial_context']['program']??null)==='intensive','Beginner statement must reuse the existing controlled recommendation of intensive.');

drift_ok(hache_sharky_whatsapp_offer_affirmation('Perfecto!'),'Perfecto must advance a ready intensive funnel.');
drift_ok(hache_sharky_whatsapp_offer_affirmation('Me parece bien.'),'Me parece bien must advance a ready intensive funnel.');
drift_ok(hache_sharky_whatsapp_offer_affirmation('Anja??'),'Colloquial Anja/Ajá continuation must not be treated as a person name.');
drift_ok(!hache_sharky_whatsapp_offer_affirmation('No'),'No must not be treated as consent.');

drift_ok(hache_sharky_whatsapp_registration_help_continuation('No me puedes ayudar tú??'),'Registration help continuation must stay inside Sharky.');

drift_ok(hache_sharky_whatsapp_answer_looks_incomplete("¡Claro!\n\n😊 Para darte informes correctos necesito primero ubicar qué te interesa:\n\n•"),'Dangling discovery bullet must be rejected.');
drift_ok(hache_sharky_whatsapp_answer_looks_incomplete('Como ya mencionaste que quieres “la otra sede”,'),'Dangling comma must be rejected.');
drift_ok(hache_sharky_whatsapp_answer_looks_incomplete("¡Genial!\n\n😊 Para avanzar:"),'Dangling Para avanzar must be rejected.');
drift_ok(hache_sharky_whatsapp_answer_looks_incomplete("Sí, claro 😊 Yo te guío.\n\nAntes de registrarte, dime por favor:"),'Dangling registration prompt must be rejected.');
drift_ok(hache_sharky_whatsapp_answer_looks_incomplete("¡Claro!\n\n😊\n\n¿En qué necesitas ayuda exactamente?\n\n•"),'Question followed by empty bullet must be rejected.');
drift_ok(!hache_sharky_whatsapp_answer_looks_incomplete('Perfecto. Ya tengo tu sede y el curso. ¿Quieres horarios o precio?'),'Complete answer must remain valid.');

$recovery=hache_sharky_whatsapp_incomplete_recovery($state);
$normalized=hache_sharky_orchestrator_normalize($recovery);
drift_ok(str_contains($normalized,'palapas protudec'),'Recovery must preserve the corrected venue.');
drift_ok(str_contains($normalized,'curso intensivo'),'Recovery must preserve the selected/recommended program.');
drift_ok(!str_contains($normalized,'monteverde'),'Recovery must not resurrect the previous venue.');

$repeat=hache_sharky_whatsapp_enforce_confirmed_context('¿Tú ya sabes nadar o estás empezando desde cero?',$state);
drift_ok(!str_contains(hache_sharky_orchestrator_normalize($repeat),'sabes nadar'),'Known swim level must suppress repeated swim discovery.');

$instruction=hache_sharky_whatsapp_style_instruction(['kind'=>'conversation'],$state);
drift_ok(str_contains(hache_sharky_orchestrator_normalize($instruction),'experiencia: empieza desde cero'),'Model context must include the known beginner level.');

echo "SHARKY_CONTEXT_DRIFT_REGRESSION_OK\n";
'''
write('tests/sharky-context-drift-regression.php',reg)

# Include the regression in the normal quality suite so it cannot silently return.
path='package.json'
text=read(path)
needle='php tests/sharky-commercial-next-action-regression.php && php tests/sharky-deterministic-replies-regression.php'
replacement='php tests/sharky-commercial-next-action-regression.php && php tests/sharky-context-drift-regression.php && php tests/sharky-deterministic-replies-regression.php'
if needle in text:text=text.replace(needle,replacement,1)
elif 'sharky-context-drift-regression.php' not in text:raise SystemExit('package test anchor missing')
needle='php -l tests/sharky-commercial-next-action-regression.php && php -l tests/sharky-deterministic-replies-regression.php'
replacement='php -l tests/sharky-commercial-next-action-regression.php && php -l tests/sharky-context-drift-regression.php && php -l tests/sharky-deterministic-replies-regression.php'
if needle in text:text=text.replace(needle,replacement,1)
write(path,text)

print('SHARKY_CONTEXT_DRIFT_PATCH_APPLIED')
