<?php

declare(strict_types=1);

/**
 * Sharky 2.0 conversation orchestrator.
 *
 * Pure/deterministic core: it never calls OpenAI, Meta or MySQL. The WhatsApp
 * adapter supplies identity/options and executes only actions explicitly
 * returned after a controlled confirmation.
 */

const HACHE_SHARKY_FLOW_TTL = 1800;
const HACHE_SHARKY_BATCH_WINDOW_MS = 2800;
const HACHE_SHARKY_MAX_SEEN_IDS = 64;

function hache_sharky_orchestrator_state(?array $state = null, ?int $now = null): array
{
    $now ??= time();
    $base = [
        'version' => 1,
        'updated_at' => $now,
        'mode' => 'conversation',
        'identity' => [
            'kind' => 'unknown',
            'verified' => false,
            'source' => 'none',
            'student_id' => null,
            'name' => null,
            'sede_clave' => null,
            'status' => null,
        ],
        'flow' => null,
        'referral' => ['first' => null, 'latest' => null],
        'seen_message_ids' => [],
        'assistant_presentation_queued' => false,
        'last_user_text' => '',
    ];
    if (!is_array($state)) return $base;

    $out = $base;
    foreach (['version','updated_at','mode','flow','last_user_text'] as $key) {
        if (array_key_exists($key, $state)) $out[$key] = $state[$key];
    }
    if (array_key_exists('assistant_presentation_queued', $state)) {
        $out['assistant_presentation_queued'] = $state['assistant_presentation_queued'] === true;
    }
    if (is_array($state['identity'] ?? null)) $out['identity'] = array_replace($base['identity'], $state['identity']);
    if (is_array($state['referral'] ?? null)) $out['referral'] = array_replace($base['referral'], $state['referral']);
    if (is_array($state['seen_message_ids'] ?? null)) {
        $out['seen_message_ids'] = array_values(array_slice(array_filter(array_map('strval', $state['seen_message_ids'])), -HACHE_SHARKY_MAX_SEEN_IDS));
    }
    if ($out['flow'] !== null && !is_array($out['flow'])) $out['flow'] = null;
    if (!in_array($out['mode'], ['conversation','controlled'], true)) $out['mode'] = 'conversation';
    return $out;
}

function hache_sharky_orchestrator_normalize(string $text): string
{
    $text = mb_strtolower(trim($text), 'UTF-8');
    return strtr($text, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
}

function hache_sharky_orchestrator_referral(array $message, ?int $now = null): ?array
{
    $ref = $message['referral'] ?? null;
    if (!is_array($ref)) return null;
    $now ??= time();
    $allowed = ['source_type','source_id','source_url','headline','body','media_type','image_url','video_url','thumbnail_url','ctwa_clid'];
    $out = ['captured_at' => $now];
    foreach ($allowed as $key) {
        $value = trim((string) ($ref[$key] ?? ''));
        if ($value !== '') $out[$key] = mb_substr($value, 0, $key === 'body' ? 1000 : 500);
    }
    if (count($out) === 1) return null;
    return $out;
}

function hache_sharky_orchestrator_capture_referral(array $state, ?array $referral): array
{
    if ($referral === null) return $state;
    $state['referral']['latest'] = $referral;
    if (!is_array($state['referral']['first'] ?? null)) $state['referral']['first'] = $referral;
    return $state;
}

function hache_sharky_orchestrator_apply_identity(array $state, ?array $identity): array
{
    if (!is_array($identity)) return $state;
    if (($identity['found'] ?? false) !== true) return $state;
    $state['identity'] = array_replace($state['identity'], [
        'kind' => 'student',
        'verified' => true,
        'source' => 'whatsapp_number',
        'student_id' => $identity['student_id'] ?? null,
        'name' => $identity['name'] ?? null,
        'sede_clave' => $identity['sede_clave'] ?? null,
        'status' => $identity['status'] ?? null,
    ]);
    return $state;
}

function hache_sharky_orchestrator_intent(string $text, string $interactiveId = ''): string
{
    $id = strtolower(trim($interactiveId));
    if ($id !== '') {
        foreach ([
            'cancel' => ['cancel','flow:cancel'],
            'yes' => ['yes','confirm','flow:yes','flow:confirm'],
            'no' => ['no','flow:no'],
            'student_claim' => ['identity:student'],
            'new_claim' => ['identity:new'],
            'absence' => ['action:absence'],
            'register_intensive' => ['action:register_intensive'],
            'human' => ['action:human'],
        ] as $intent => $ids) if (in_array($id, $ids, true)) return $intent;
        if (str_starts_with($id, 'sede:') || str_starts_with($id, 'course:') || str_starts_with($id, 'schedule:') || str_starts_with($id, 'date:')) return 'selection';
    }

    $t = hache_sharky_orchestrator_normalize($text);
    if ($t === '') return 'empty';
    if (preg_match('/\b(cancelar|cancela|dejalo|dejala|olvidalo|ya no|mejor no|salir)\b/u', $t)) return 'cancel';
    if (preg_match('/\b(hablar|asesor|persona|humano|operador|atencion humana)\b/u', $t)) return 'human';
    if (preg_match('/\b(no voy|no podre ir|no puedo ir|faltare|voy a faltar|reportar (una )?ausencia|avisar (una )?ausencia)\b/u', $t)) return 'absence';
    if (preg_match('/\b(inscribirme|registrarme|anotarme|apuntarme|quiero entrar)\b.{0,35}\b(intensivo|curso)\b/u', $t)
        || preg_match('/\b(intensivo|curso)\b.{0,35}\b(inscribirme|registrarme|anotarme|apuntarme)\b/u', $t)) return 'register_intensive';
    if (preg_match('/\b(ya soy|soy)\s+(alumno|alumna|estudiante)\b/u', $t)) return 'student_claim';
    if (preg_match('/\b(soy nuevo|soy nueva|no soy alumno|no soy alumna|quiero informacion|solo informacion)\b/u', $t)) return 'new_claim';
    if (preg_match('/^(si|sí|sip|sipi|claro|va|vale|ok|okay|dale|de acuerdo|correcto|confirmo|confirmar)[!. ]*$/u', trim($text))) return 'yes';
    if (preg_match('/^(no|nop|nel|negativo)[!. ]*$/u', $t)) return 'no';
    return 'conversation';
}

function hache_sharky_orchestrator_parse_date(string $text, string $today): ?string
{
    $base = DateTimeImmutable::createFromFormat('!Y-m-d', $today);
    if (!$base || $base->format('Y-m-d') !== $today) return null;
    $t = hache_sharky_orchestrator_normalize($text);
    if (preg_match('/\bmanana\b/u', $t)) return $base->modify('+1 day')->format('Y-m-d');
    if (preg_match('/\bhoy\b/u', $t)) return $base->format('Y-m-d');
    if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/u', $t, $m)) {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $m[0]);
        return $d && $d->format('Y-m-d') === $m[0] ? $m[0] : null;
    }
    if (preg_match('/\b(\d{1,2})[\/-](\d{1,2})(?:[\/-](\d{4}))?\b/u', $t, $m)) {
        $year = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (int) $base->format('Y');
        $date = sprintf('%04d-%02d-%02d', $year, (int)$m[2], (int)$m[1]);
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date ? $date : null;
    }
    return null;
}

function hache_sharky_orchestrator_expire_flow(array $state, int $now): array
{
    $flow = $state['flow'] ?? null;
    if (!is_array($flow)) return $state;
    $updated = (int) ($flow['updated_at'] ?? $state['updated_at'] ?? 0);
    if ($updated > 0 && $updated < $now - HACHE_SHARKY_FLOW_TTL) {
        $state['flow'] = null;
        $state['mode'] = 'conversation';
    }
    return $state;
}

function hache_sharky_orchestrator_flow(array $state, string $name, string $step, array $data, int $now): array
{
    $state['mode'] = 'controlled';
    $state['flow'] = ['name'=>$name,'step'=>$step,'data'=>$data,'updated_at'=>$now];
    return $state;
}

function hache_sharky_orchestrator_clear_flow(array $state): array
{
    $state['mode'] = 'conversation';
    $state['flow'] = null;
    return $state;
}

function hache_sharky_orchestrator_button(string $id, string $title): array
{
    return ['id'=>$id, 'title'=>mb_substr($title, 0, 20)];
}

function hache_sharky_orchestrator_decision(string $kind, string $message = '', array $ui = [], ?array $action = null): array
{
    return ['kind'=>$kind, 'message'=>$message, 'ui'=>$ui, 'action'=>$action];
}

function hache_sharky_orchestrator_identity_prompt(): array
{
    return hache_sharky_orchestrator_decision(
        'conversation_identity_prompt',
        'Responde primero la consulta de forma breve y después pregunta: “Antes de seguir, ¿ya eres alumno de Hache Natación?”',
        ['type'=>'buttons','buttons'=>[
            hache_sharky_orchestrator_button('identity:student','Ya soy alumno'),
            hache_sharky_orchestrator_button('identity:new','Soy nuevo'),
        ]]
    );
}

function hache_sharky_orchestrator_yes_no(string $kind, string $message, string $yesId = 'flow:yes'): array
{
    return hache_sharky_orchestrator_decision($kind, $message, ['type'=>'buttons','buttons'=>[
        hache_sharky_orchestrator_button($yesId,'Sí'),
        hache_sharky_orchestrator_button('flow:no','No'),
        hache_sharky_orchestrator_button('flow:cancel','Cancelar'),
    ]]);
}

function hache_sharky_orchestrator_handle_flow(array $state, array $event, array $context, string $intent, int $now): array
{
    $flow = $state['flow'];
    $name = (string) ($flow['name'] ?? '');
    $step = (string) ($flow['step'] ?? '');
    $data = is_array($flow['data'] ?? null) ? $flow['data'] : [];
    $text = trim((string) ($event['text'] ?? ''));
    $interactive = strtolower(trim((string) ($event['interactive_id'] ?? '')));

    if ($intent === 'cancel' || $intent === 'no') {
        $state = hache_sharky_orchestrator_clear_flow($state);
        return [$state, hache_sharky_orchestrator_decision('flow_cancelled','Listo, cancelé este proceso. Podemos seguir conversando normalmente.')];
    }
    if ($intent === 'human') {
        $state = hache_sharky_orchestrator_clear_flow($state);
        return [$state, hache_sharky_orchestrator_decision('human_takeover','Voy a dejar la conversación al equipo para que continúe contigo.', [], ['type'=>'human_takeover'])];
    }

    if ($name === 'identify_student' && $step === 'verify') {
        if (($context['verification']['verified'] ?? false) === true) {
            $verified = $context['verification'];
            $state['identity'] = array_replace($state['identity'], [
                'kind'=>'student','verified'=>true,'source'=>'verification',
                'student_id'=>$verified['student_id'] ?? null,
                'name'=>$verified['name'] ?? null,
                'sede_clave'=>$verified['sede_clave'] ?? null,
                'status'=>$verified['status'] ?? null,
            ]);
            $state = hache_sharky_orchestrator_clear_flow($state);
            return [$state, hache_sharky_orchestrator_decision('identity_verified','Identidad verificada. Ya puedo ayudarte como alumno.')];
        }
        return [$state, hache_sharky_orchestrator_decision('verification_required','Para proteger tus datos necesito verificar que eres alumno antes de hacer operaciones.', ['type'=>'verification_link'])];
    }

    if ($name === 'absence') {
        if (($state['identity']['kind'] ?? '') !== 'student' || ($state['identity']['verified'] ?? false) !== true) {
            $state = hache_sharky_orchestrator_flow($state,'identify_student','verify',['return_to'=>'absence'],$now);
            return [$state, hache_sharky_orchestrator_decision('verification_required','Antes de registrar una ausencia necesito verificar tu identidad.', ['type'=>'verification_link'])];
        }
        if ($step === 'offer') {
            if ($intent !== 'yes') return [$state, hache_sharky_orchestrator_yes_no('absence_offer','¿Quieres que registre tu ausencia?')];
            $state = hache_sharky_orchestrator_flow($state,'absence','date',$data,$now);
            return [$state, hache_sharky_orchestrator_decision('absence_date','¿Para qué fecha será la ausencia?', ['type'=>'buttons','buttons'=>[
                hache_sharky_orchestrator_button('date:tomorrow','Mañana'),
                hache_sharky_orchestrator_button('flow:cancel','Cancelar'),
            ]])];
        }
        if ($step === 'date') {
            $today = (string) ($context['today'] ?? date('Y-m-d'));
            $date = $interactive === 'date:tomorrow'
                ? (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d')
                : hache_sharky_orchestrator_parse_date($text, $today);
            if ($date === null) return [$state, hache_sharky_orchestrator_decision('absence_date_invalid','Necesito una fecha concreta. Puedes escribir “mañana” o una fecha como 05/09.')];
            $data['date_from'] = $date;
            $data['date_to'] = $date;
            $state = hache_sharky_orchestrator_flow($state,'absence','confirm',$data,$now);
            return [$state, hache_sharky_orchestrator_yes_no('absence_confirm','Voy a registrar tu ausencia para '.$date.'. ¿Confirmas?','flow:confirm')];
        }
        if ($step === 'confirm') {
            if ($intent !== 'yes') return [$state, hache_sharky_orchestrator_yes_no('absence_confirm','¿Confirmas registrar la ausencia para '.($data['date_from'] ?? '').'?','flow:confirm')];
            $action = [
                'type'=>'create_absence',
                'student_id'=>$state['identity']['student_id'],
                'date_from'=>$data['date_from'] ?? null,
                'date_to'=>$data['date_to'] ?? null,
                'reason'=>$data['reason'] ?? null,
                'requires_revalidation'=>true,
            ];
            $state = hache_sharky_orchestrator_clear_flow($state);
            return [$state, hache_sharky_orchestrator_decision('absence_execute','Voy a registrar la ausencia ahora.', [], $action)];
        }
    }

    if ($name === 'register_intensive') {
        if ($step === 'offer') {
            if ($intent !== 'yes') return [$state, hache_sharky_orchestrator_yes_no('registration_offer','¿Quieres que te ayude a registrarte al curso intensivo?')];
            $state = hache_sharky_orchestrator_flow($state,'register_intensive','sede',$data,$now);
            $buttons = [
                hache_sharky_orchestrator_button('sede:monteverde','Monteverde'),
                hache_sharky_orchestrator_button('sede:palapas','Palapas'),
                hache_sharky_orchestrator_button('flow:cancel','Cancelar'),
            ];
            return [$state, hache_sharky_orchestrator_decision('registration_sede','¿En qué sede quieres tomar el intensivo?', ['type'=>'buttons','buttons'=>$buttons])];
        }
        if ($step === 'sede') {
            $sede = '';
            if (str_starts_with($interactive,'sede:')) $sede = strtoupper(substr($interactive,5));
            else {
                $t = hache_sharky_orchestrator_normalize($text);
                if (str_contains($t,'monteverde')) $sede='MONTEVERDE';
                if (str_contains($t,'palapas')) $sede='PALAPAS';
            }
            if (!in_array($sede,['MONTEVERDE','PALAPAS'],true)) return [$state, hache_sharky_orchestrator_decision('registration_sede_invalid','Elige Monteverde o Palapas.')];
            $data['sede_clave']=$sede;
            $options = array_values(array_filter($context['intensive_options'] ?? [], static fn($o): bool => is_array($o) && strtoupper((string)($o['sede_clave']??'')) === $sede));
            $state = hache_sharky_orchestrator_flow($state,'register_intensive','course',$data,$now);
            return [$state, hache_sharky_orchestrator_decision('registration_course','Elige una fecha de inicio disponible.', ['type'=>'list','list_id'=>'courses','options'=>array_map(static fn($o): array => [
                'id'=>'course:'.(string)($o['id']??''),
                'title'=>(string)($o['fecha_inicio']??''),
                'description'=>(string)($o['label']??''),
            ], array_slice($options,0,10))])];
        }
        if ($step === 'course') {
            $courseId = str_starts_with($interactive,'course:') ? substr($interactive,7) : '';
            $course = null;
            foreach ($context['intensive_options'] ?? [] as $option) if (is_array($option) && (string)($option['id']??'') === $courseId) $course=$option;
            if (!$course || strtoupper((string)($course['sede_clave']??'')) !== ($data['sede_clave']??'')) return [$state, hache_sharky_orchestrator_decision('registration_course_invalid','Esa opción ya no está disponible. Actualizaré las fechas antes de continuar.', [], ['type'=>'refresh_intensive_options'])];
            $data['course_id']=$courseId;
            $data['fecha_inicio']=$course['fecha_inicio']??null;
            $schedules = is_array($course['schedules']??null)?$course['schedules']:[];
            $state = hache_sharky_orchestrator_flow($state,'register_intensive','schedule',$data,$now);
            return [$state, hache_sharky_orchestrator_decision('registration_schedule','Elige el horario que prefieres.', ['type'=>'list','list_id'=>'schedules','options'=>array_map(static fn($s): array => [
                'id'=>'schedule:'.(string)($s['id']??''),
                'title'=>(string)($s['label']??''),
                'description'=>'',
            ], array_slice($schedules,0,10))])];
        }
        if ($step === 'schedule') {
            $scheduleId = str_starts_with($interactive,'schedule:') ? substr($interactive,9) : '';
            if ($scheduleId === '') return [$state, hache_sharky_orchestrator_decision('registration_schedule_invalid','Selecciona uno de los horarios disponibles.')];
            $data['schedule_id']=$scheduleId;
            $state = hache_sharky_orchestrator_flow($state,'register_intensive','name',$data,$now);
            return [$state, hache_sharky_orchestrator_decision('registration_name','Escribe el nombre completo de la persona que tomará el curso.')];
        }
        if ($step === 'name') {
            $nameText = preg_replace('/\s+/u',' ',trim($text)) ?? '';
            if (mb_strlen($nameText)<4 || mb_strlen($nameText)>180) return [$state, hache_sharky_orchestrator_decision('registration_name_invalid','Necesito el nombre completo para continuar.')];
            $data['name']=$nameText;
            $state = hache_sharky_orchestrator_flow($state,'register_intensive','birthdate',$data,$now);
            return [$state, hache_sharky_orchestrator_decision('registration_birthdate','Por seguridad y para validar la edad mínima, escribe la fecha de nacimiento (DD/MM/AAAA).')];
        }
        if ($step === 'birthdate') {
            if (!preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/',trim($text),$m)) return [$state, hache_sharky_orchestrator_decision('registration_birthdate_invalid','Usa el formato DD/MM/AAAA.')];
            $birth = sprintf('%04d-%02d-%02d',(int)$m[3],(int)$m[2],(int)$m[1]);
            $d=DateTimeImmutable::createFromFormat('!Y-m-d',$birth);
            if(!$d||$d->format('Y-m-d')!==$birth) return [$state,hache_sharky_orchestrator_decision('registration_birthdate_invalid','La fecha de nacimiento no es válida.')];
            $today=new DateTimeImmutable((string)($context['today']??date('Y-m-d')));
            $age=$d->diff($today)->y;
            $minAge=max(1,(int)($context['min_age']??12));
            if($d>$today||$age<$minAge) {
                $state=hache_sharky_orchestrator_clear_flow($state);
                return [$state,hache_sharky_orchestrator_decision('registration_age_rejected','Hache Natación atiende a partir de '.$minAge.' años; no puedo continuar con este registro.')];
            }
            $data['birthdate']=$birth;
            $data['age']=$age;
            $state=hache_sharky_orchestrator_flow($state,'register_intensive','confirm',$data,$now);
            $summary=sprintf('Voy a registrar a %s en %s, inicio %s. ¿Confirmas?', $data['name'], ucfirst(strtolower((string)$data['sede_clave'])), (string)$data['fecha_inicio']);
            return [$state,hache_sharky_orchestrator_yes_no('registration_confirm',$summary,'flow:confirm')];
        }
        if ($step === 'confirm') {
            if ($intent !== 'yes') return [$state,hache_sharky_orchestrator_yes_no('registration_confirm','¿Confirmas el registro con los datos mostrados?','flow:confirm')];
            $action=[
                'type'=>'register_intensive',
                'sede_clave'=>$data['sede_clave']??null,
                'course_id'=>$data['course_id']??null,
                'fecha_inicio'=>$data['fecha_inicio']??null,
                'schedule_id'=>$data['schedule_id']??null,
                'name'=>$data['name']??null,
                'birthdate'=>$data['birthdate']??null,
                'contact_phone'=>$event['from']??null,
                'requires_revalidation'=>true,
            ];
            $state=hache_sharky_orchestrator_clear_flow($state);
            return [$state,hache_sharky_orchestrator_decision('registration_execute','Voy a validar una vez más los datos y realizar el registro.',[],$action)];
        }
    }

    $state = hache_sharky_orchestrator_clear_flow($state);
    return [$state, hache_sharky_orchestrator_decision('flow_reset','El proceso anterior ya no es válido. Volvamos a empezar desde la conversación.')];
}

function hache_sharky_orchestrate(?array $previousState, array $event, array $context = []): array
{
    $now = (int) ($context['now'] ?? time());
    $state = hache_sharky_orchestrator_state($previousState, $now);
    $state = hache_sharky_orchestrator_expire_flow($state, $now);

    $messageId = trim((string) ($event['id'] ?? ''));
    if ($messageId !== '' && in_array($messageId, $state['seen_message_ids'], true)) {
        return ['state'=>$state, 'decision'=>hache_sharky_orchestrator_decision('duplicate')];
    }
    if ($messageId !== '') {
        $state['seen_message_ids'][] = $messageId;
        $state['seen_message_ids'] = array_slice(array_values(array_unique($state['seen_message_ids'])), -HACHE_SHARKY_MAX_SEEN_IDS);
    }

    $state = hache_sharky_orchestrator_capture_referral($state, hache_sharky_orchestrator_referral($event, $now));
    $state = hache_sharky_orchestrator_apply_identity($state, $context['identity'] ?? null);
    $state['updated_at'] = $now;
    $state['last_user_text'] = trim((string) ($event['text'] ?? ''));

    if (($context['human_takeover'] ?? false) === true) {
        return ['state'=>$state, 'decision'=>hache_sharky_orchestrator_decision('silent_human_takeover')];
    }

    $intent = hache_sharky_orchestrator_intent((string)($event['text']??''),(string)($event['interactive_id']??''));

    if (is_array($state['flow'])) {
        [$state,$decision]=hache_sharky_orchestrator_handle_flow($state,$event,$context,$intent,$now);
        $state['updated_at']=$now;
        return ['state'=>$state,'decision'=>$decision];
    }

    if ($intent === 'human') {
        return ['state'=>$state,'decision'=>hache_sharky_orchestrator_decision('human_takeover','Voy a dejar la conversación al equipo para que continúe contigo.',[],['type'=>'human_takeover'])];
    }
    if ($intent === 'student_claim' && ($state['identity']['verified'] ?? false) !== true) {
        $state=hache_sharky_orchestrator_flow($state,'identify_student','verify',[],$now);
        return ['state'=>$state,'decision'=>hache_sharky_orchestrator_decision('verification_required','Perfecto. Como escribes desde un número que no reconozco, necesito verificar que eres alumno antes de hacer operaciones.', ['type'=>'verification_link'])];
    }
    if ($intent === 'new_claim') {
        $state['identity']=array_replace($state['identity'],['kind'=>'prospect','verified'=>true,'source'=>'self_declared']);
        return ['state'=>$state,'decision'=>hache_sharky_orchestrator_decision('conversation','Continúa en modo conversacional comercial; no actives un flujo hasta que el usuario acepte expresamente una acción.')];
    }
    if ($intent === 'absence') {
        if (($state['identity']['kind'] ?? '') !== 'student' || ($state['identity']['verified'] ?? false) !== true) {
            $state=hache_sharky_orchestrator_flow($state,'identify_student','verify',['return_to'=>'absence'],$now);
            return ['state'=>$state,'decision'=>hache_sharky_orchestrator_decision('verification_required','Puedo ayudarte a reportar la ausencia, pero primero necesito verificar que eres alumno.', ['type'=>'verification_link'])];
        }
        $state=hache_sharky_orchestrator_flow($state,'absence','offer',[],$now);
        return ['state'=>$state,'decision'=>hache_sharky_orchestrator_yes_no('absence_offer','Entendido. ¿Quieres que registre tu ausencia?')];
    }
    if ($intent === 'register_intensive') {
        $state=hache_sharky_orchestrator_flow($state,'register_intensive','offer',[],$now);
        return ['state'=>$state,'decision'=>hache_sharky_orchestrator_yes_no('registration_offer','Claro. ¿Quieres que te ayude a registrarte al curso intensivo?')];
    }
    if (($state['identity']['kind'] ?? '') === 'unknown') {
        return ['state'=>$state,'decision'=>hache_sharky_orchestrator_identity_prompt()];
    }
    return ['state'=>$state,'decision'=>hache_sharky_orchestrator_decision('conversation','Responde de forma natural, breve y contextual. No ejecutes acciones sin consentimiento explícito.')];
}

function hache_sharky_orchestrator_batch(array $events): array
{
    $clean=[];
    foreach($events as $event){
        if(!is_array($event)) continue;
        $text=trim((string)($event['text']??''));
        if($text==='') continue;
        $clean[]=$event;
    }
    if(!$clean) return ['ids'=>[],'text'=>'','referral'=>null];
    usort($clean,static fn(array $a,array $b):int=>(int)($a['timestamp_ms']??0)<=>(int)($b['timestamp_ms']??0));
    $ids=[];$parts=[];$ref=null;
    foreach($clean as $event){
        $id=trim((string)($event['id']??''));if($id!=='')$ids[]=$id;
        $parts[]=trim((string)$event['text']);
        if($ref===null&&is_array($event['referral']??null))$ref=$event['referral'];
    }
    return ['ids'=>array_values(array_unique($ids)),'text'=>implode("\n",$parts),'referral'=>$ref];
}
