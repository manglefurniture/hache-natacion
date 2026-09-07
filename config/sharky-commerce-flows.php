<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-whatsapp-flow-runtime.php';
require_once __DIR__.'/sharky-mercadopago.php';

const HACHE_SHARKY_COMMERCE_FLOW_KIND = 'commerce_flow';

function hache_sharky_commerce_flow_specs(): array
{
    return [
        'enrollment'=>[
            'name'=>'Hache_Sharky_Enrollment_v1',
            'asset'=>'enrollment-v1.json',
            'screen'=>'ENROLLMENT',
            'cta'=>'Completar inscripción',
            'env'=>'WHATSAPP_ENROLLMENT_FLOW_ID',
        ],
        'payment_method'=>[
            'name'=>'Hache_Sharky_Payment_Method_v1',
            'asset'=>'payment-method-v1.json',
            'screen'=>'PAYMENT_METHOD',
            'cta'=>'Elegir forma de pago',
            'env'=>'WHATSAPP_PAYMENT_METHOD_FLOW_ID',
        ],
        'payment_transfer'=>[
            'name'=>'Hache_Sharky_Payment_Transfer_v1',
            'asset'=>'payment-transfer-v1.json',
            'screen'=>'TRANSFER_PROOF',
            'cta'=>'Ver datos SPEI',
            'env'=>'WHATSAPP_PAYMENT_TRANSFER_FLOW_ID',
        ],
        'payment_card'=>[
            'name'=>'Hache_Sharky_Payment_Card_v1',
            'asset'=>'payment-card-v1.json',
            'screen'=>'CARD_PROOF',
            'cta'=>'Pagar con tarjeta',
            'env'=>'WHATSAPP_PAYMENT_CARD_FLOW_ID',
        ],
    ];
}

function hache_sharky_commerce_flow_spec(string $key): ?array
{
    $specs = hache_sharky_commerce_flow_specs();
    return is_array($specs[$key] ?? null) ? $specs[$key] : null;
}

function hache_sharky_commerce_flow_cache_path(string $key): string
{
    $dir = hache_sharky_whatsapp_birthdate_flow_cache_dir();
    return $dir === '' ? '' : $dir.'/commerce-'.preg_replace('/[^a-z0-9_-]+/i','-', $key).'.id';
}

function hache_sharky_commerce_flow_cached_id(string $key): ?string
{
    $spec = hache_sharky_commerce_flow_spec($key); if (!$spec) return null;
    $configured = preg_replace('/\D+/', '', hache_sharky_whatsapp_flow_secret((string)$spec['env'])) ?: '';
    if ($configured !== '') return $configured;
    $path = hache_sharky_commerce_flow_cache_path($key);
    if ($path === '' || !is_file($path)) return null;
    $id = preg_replace('/\D+/', '', trim((string)@file_get_contents($path))) ?: '';
    return $id !== '' ? $id : null;
}

function hache_sharky_commerce_flow_cache_id(string $key, string $flowId): bool
{
    $flowId = preg_replace('/\D+/', '', $flowId) ?: '';
    $path = hache_sharky_commerce_flow_cache_path($key);
    if ($flowId === '' || $path === '') return false;
    $ok = @file_put_contents($path, $flowId, LOCK_EX) !== false;
    if ($ok) @chmod($path, 0600);
    return $ok;
}

function hache_sharky_commerce_flow_existing(array $data, string $name): ?array
{
    foreach (($data['data'] ?? []) as $flow) {
        if (!is_array($flow) || trim((string)($flow['name'] ?? '')) !== $name) continue;
        $id = preg_replace('/\D+/', '', (string)($flow['id'] ?? '')) ?: '';
        if ($id === '') continue;
        return ['id'=>$id, 'status'=>strtoupper(trim((string)($flow['status'] ?? '')))];
    }
    return null;
}

function hache_sharky_commerce_flow_upload(string $base, string $flowId, string $token, string $asset): bool
{
    $path = __DIR__.'/whatsapp-flows/'.$asset;
    if (!is_file($path)) return false;
    $ch = curl_init($base.'/'.rawurlencode($flowId).'/assets'); if ($ch === false) return false;
    $file = new CURLFile($path, 'application/json', 'flow.json');
    curl_setopt_array($ch, [
        CURLOPT_POST=>true,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>30,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token, 'Accept: application/json'],
        CURLOPT_POSTFIELDS=>['name'=>'flow.json', 'asset_type'=>'FLOW_JSON', 'file'=>$file],
    ]);
    $raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_error($ch); curl_close($ch);
    return is_string($raw) && $error === '' && $status >= 200 && $status < 300;
}

function hache_sharky_commerce_flow_ensure(string $key, string $wabaId, callable $secretResolver): ?string
{
    $spec = hache_sharky_commerce_flow_spec($key); if (!$spec) return null;
    $configured = preg_replace('/\D+/', '', (string)$secretResolver((string)$spec['env'])) ?: '';
    if ($configured !== '') return $configured;
    $cached = hache_sharky_commerce_flow_cached_id($key); if ($cached !== null) return $cached;
    $wabaId = preg_replace('/\D+/', '', $wabaId) ?: '';
    $token = trim((string)$secretResolver('WHATSAPP_ACCESS_TOKEN'));
    if ($wabaId === '' || $token === '') return null;
    $version = trim((string)$secretResolver('WHATSAPP_GRAPH_VERSION'));
    if (preg_match('/^v\d+\.\d+$/', $version) !== 1) $version = 'v26.0';
    $base = 'https://graph.facebook.com/'.rawurlencode($version);

    $list = hache_sharky_whatsapp_flow_graph_json('GET', $base.'/'.rawurlencode($wabaId).'/flows?fields=id%2Cname%2Cstatus&limit=100', $token);
    $existing = is_array($list) ? hache_sharky_commerce_flow_existing($list, (string)$spec['name']) : null;
    if (is_array($existing) && $existing['status'] === 'PUBLISHED') {
        hache_sharky_commerce_flow_cache_id($key, (string)$existing['id']);
        return (string)$existing['id'];
    }

    $flowId = is_array($existing) ? (string)$existing['id'] : '';
    if ($flowId === '') {
        $created = hache_sharky_whatsapp_flow_graph_json('POST', $base.'/'.rawurlencode($wabaId).'/flows', $token, [
            'name'=>(string)$spec['name'],
            'categories'=>'["SIGN_UP"]',
        ]);
        $flowId = preg_replace('/\D+/', '', (string)($created['id'] ?? '')) ?: '';
        if ($flowId === '') return null;
    }

    $uploaded = hache_sharky_commerce_flow_upload($base, $flowId, $token, (string)$spec['asset']);
    if (!$uploaded) return null;
    $published = hache_sharky_whatsapp_flow_graph_json('POST', $base.'/'.rawurlencode($flowId).'/publish', $token, []);
    if (!is_array($published)) {
        $check = hache_sharky_whatsapp_flow_graph_json('GET', $base.'/'.rawurlencode($wabaId).'/flows?fields=id%2Cname%2Cstatus&limit=100', $token);
        $after = is_array($check) ? hache_sharky_commerce_flow_existing($check, (string)$spec['name']) : null;
        if (!is_array($after) || $after['status'] !== 'PUBLISHED') return null;
    }
    hache_sharky_commerce_flow_cache_id($key, $flowId);
    return $flowId;
}

/** Provisioning remains outside the webhook critical ACK path. */
function hache_sharky_commerce_flows_prime(array $payload, ?callable $secretResolver = null): array
{
    $waba = hache_sharky_whatsapp_birthdate_flow_first_waba($payload);
    if ($waba === '') return [];
    $secretResolver ??= static fn(string $name):string => hache_sharky_whatsapp_flow_secret($name);
    $ready = [];
    foreach (array_keys(hache_sharky_commerce_flow_specs()) as $key) {
        try {
            $id = hache_sharky_commerce_flow_ensure($key, $waba, $secretResolver);
            if ($id !== null) $ready[$key] = $id;
        } catch (Throwable $e) {
            error_log('[sharky-commerce-flow] provisioning unavailable key='.$key);
        }
    }
    return $ready;
}

function hache_sharky_commerce_flow_payload(
    string $to,
    string $body,
    string $flowId,
    string $screen,
    string $cta,
    array $data = [],
    ?string $flowToken = null
): array {
    $to = preg_replace('/\D+/', '', $to) ?: '';
    $flowId = preg_replace('/\D+/', '', $flowId) ?: '';
    if ($flowToken === null || trim($flowToken) === '') {
        try { $flowToken = 'hc_'.bin2hex(random_bytes(12)); }
        catch (Throwable $e) { $flowToken = 'hc_'.substr(hash('sha256', microtime(true).'|'.$to.'|'.$screen),0,24); }
    }
    $actionPayload = ['screen'=>$screen];
    if ($data) $actionPayload['data'] = $data;
    return [
        'messaging_product'=>'whatsapp',
        'recipient_type'=>'individual',
        'to'=>$to,
        'type'=>'interactive',
        'interactive'=>[
            'type'=>'flow',
            'body'=>['text'=>mb_substr(trim($body),0,1024)],
            'action'=>[
                'name'=>'flow',
                'parameters'=>[
                    'flow_message_version'=>'3',
                    'flow_token'=>$flowToken,
                    'flow_id'=>$flowId,
                    'flow_cta'=>mb_substr($cta,0,30),
                    'flow_action'=>'navigate',
                    'flow_action_payload'=>$actionPayload,
                ],
            ],
        ],
    ];
}

function hache_sharky_commerce_flow_response(array $message): ?array
{
    if (($message['type'] ?? '') !== 'interactive') return null;
    $interactive = $message['interactive'] ?? null;
    if (!is_array($interactive) || ($interactive['type'] ?? '') !== 'nfm_reply') return null;
    $raw = (string)($interactive['nfm_reply']['response_json'] ?? '');
    $data = json_decode($raw, true);
    if (!is_array($data)) return null;
    $flowKind = strtolower(trim((string)($data['flow_kind'] ?? '')));
    if (!in_array($flowKind, ['enrollment','payment_method','payment_proof'], true)) return null;
    return $data;
}

/**
 * Commerce nfm_reply payloads are normalized separately from the single-field
 * birthdate Flow. Media references are encrypted by the existing inbox; bytes
 * are not downloaded and never reach OpenAI.
 */
function hache_sharky_commerce_flow_extract_events(array $payload): array
{
    $out = [];
    foreach (($payload['entry'] ?? []) as $entry) {
        if (!is_array($entry)) continue;
        $waba = preg_replace('/\D+/', '', (string)($entry['id'] ?? '')) ?: '';
        foreach (($entry['changes'] ?? []) as $change) {
            if (!is_array($change)) continue;
            $value = $change['value'] ?? null; if (!is_array($value)) continue;
            $phoneId = trim((string)($value['metadata']['phone_number_id'] ?? ''));
            foreach (($value['messages'] ?? []) as $message) {
                if (!is_array($message)) continue;
                $commerce = hache_sharky_commerce_flow_response($message); if (!is_array($commerce)) continue;
                $id = trim((string)($message['id'] ?? ''));
                $from = preg_replace('/\D+/', '', (string)($message['from'] ?? '')) ?: '';
                if ($id === '' || $from === '') continue;
                $flowKind = strtolower(trim((string)($commerce['flow_kind'] ?? '')));
                if ($flowKind === 'payment_method') {
                    $method = strtolower(trim((string)($commerce['method'] ?? '')));
                    if (!in_array($method, ['transfer','card','cash'], true)) continue;
                }
                if ($flowKind === 'enrollment') {
                    $action = strtolower(trim((string)($commerce['user_action'] ?? '')));
                    if (!in_array($action, ['submit','cancel'], true)) continue;
                }
                if ($flowKind === 'payment_proof') {
                    $method = strtolower(trim((string)($commerce['method'] ?? '')));
                    $media = $commerce['media'] ?? null;
                    if (!in_array($method, ['transfer','card'], true) || !is_array($media) || !$media) continue;
                }
                $event = [
                    'id'=>$id,
                    'from'=>$from,
                    'type'=>'interactive',
                    'kind'=>$flowKind === 'payment_proof' ? HACHE_SHARKY_PAYMENT_PROOF_KIND : HACHE_SHARKY_COMMERCE_FLOW_KIND,
                    'text'=>'',
                    'interactive_id'=>'',
                    'commerce'=>$commerce,
                    'phone_number_id'=>$phoneId,
                    'timestamp_ms'=>((int)($message['timestamp'] ?? time()))*1000,
                ];
                if ($waba !== '') $event['waba_id'] = $waba;
                if (is_array($message['referral'] ?? null)) $event['referral'] = $message['referral'];
                $out[] = $event;
            }
        }
    }
    return $out;
}

function hache_sharky_commerce_phone(string $contact): string
{
    $digits = preg_replace('/\D+/', '', $contact) ?: '';
    if (strlen($digits) === 13 && str_starts_with($digits, '521')) $digits = '52'.substr($digits, 3);
    return $digits === '' ? '' : '+'.$digits;
}

/** Returns exactly one pending intensive registration or null on ambiguity. */
function hache_sharky_commerce_pending_registration(PDO $pdo, string $contact): ?array
{
    $phone = hache_sharky_commerce_phone($contact); if ($phone === '') return null;
    try {
        $st = $pdo->prepare(
            "SELECT a.id AS student_id,a.nombre,ci.id AS course_id,ci.fecha_inicio,ci.precio,
                    s.clave AS sede_clave,s.nombre AS sede_nombre,h.id AS schedule_id,h.hora_inicio,h.hora_fin
             FROM alumnos a
             JOIN sedes s ON s.id=a.sede_id
             JOIN curso_intensivo_alumnos cia ON cia.alumno_id=a.id
             JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id
             JOIN horarios h ON h.id=cia.horario_id
             WHERE a.whatsapp=:w AND a.estado_administrativo='PENDIENTE'
               AND ci.estado NOT IN ('FINALIZADO','CANCELADO')
             ORDER BY ci.fecha_inicio DESC
             LIMIT 2"
        );
        $st->execute([':w'=>$phone]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) return null;
        $row = $rows[0];
        return [
            'student_id'=>(string)$row['student_id'],
            'course_id'=>(string)$row['course_id'],
            'name'=>(string)$row['nombre'],
            'sede_clave'=>(string)$row['sede_clave'],
            'sede_nombre'=>(string)$row['sede_nombre'],
            'fecha_inicio'=>(string)$row['fecha_inicio'],
            'schedule_id'=>(string)$row['schedule_id'],
            'schedule_label'=>substr((string)$row['hora_inicio'],0,5).'–'.substr((string)$row['hora_fin'],0,5),
            'price'=>(float)$row['precio'],
        ];
    } catch (Throwable $e) {
        error_log('[sharky-commerce] pending registration lookup failed');
        return null;
    }
}

function hache_sharky_commerce_payment_methods(bool $recovery, float $surchargePct): array
{
    $methods = [[
        'id'=>'transfer',
        'title'=>'Transferencia SPEI',
        'description'=>'Recomendada · sin recargo',
    ]];
    if (!$recovery) {
        $pct = rtrim(rtrim(number_format($surchargePct,2,'.',''),'0'),'.');
        $methods[] = ['id'=>'card','title'=>'Tarjeta','description'=>'+'.$pct.'% mediante Mercado Pago'];
    }
    $methods[] = ['id'=>'cash','title'=>'Efectivo','description'=>'Sujeto a disponibilidad'];
    return $methods;
}

function hache_sharky_commerce_payment_method_payload(string $contact, array $registration, array $business, bool $recovery = false): array
{
    $pct = is_numeric($business['sharky_recargo_tarjeta_pct'] ?? null) ? (float)$business['sharky_recargo_tarjeta_pct'] : 5.0;
    $pct = max(0.0,min(30.0,$pct));
    $flowId = hache_sharky_commerce_flow_cached_id('payment_method');
    $intro = $recovery
        ? 'Tu pago con tarjeta todavía no se ha confirmado. Puedes cambiar a transferencia SPEI o coordinar efectivo con el profesor.'
        : 'Te recomendamos transferencia SPEI porque no genera cargos adicionales. Tarjeta es una opción adicional con recargo.';
    if ($flowId !== null) {
        $payload = hache_sharky_commerce_flow_payload(
            $contact,
            $recovery ? '¿Tuviste algún problema con el pago o prefieres cambiar de método?' : 'Ya tengo tu inscripción. Ahora elige cómo prefieres realizar el pago.',
            $flowId,
            'PAYMENT_METHOD',
            'Elegir forma de pago',
            ['intro'=>$intro,'methods'=>hache_sharky_commerce_payment_methods($recovery,$pct)]
        );
    } else {
        $buttons = [[
            'type'=>'reply','reply'=>['id'=>'commerce:pay:transfer','title'=>'Transferencia SPEI'],
        ]];
        if (!$recovery) $buttons[] = ['type'=>'reply','reply'=>['id'=>'commerce:pay:card','title'=>'Tarjeta']];
        $buttons[] = ['type'=>'reply','reply'=>['id'=>'commerce:pay:cash','title'=>'Efectivo']];
        $payload = [
            'messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$contact,'type'=>'interactive',
            'interactive'=>[
                'type'=>'button',
                'body'=>['text'=>mb_substr($intro,0,1024)],
                'action'=>['buttons'=>$buttons],
            ],
        ];
    }
    $payload['_sharky_payment_session'] = [
        'student_id'=>(string)($registration['student_id'] ?? ''),
        'course_id'=>(string)($registration['course_id'] ?? ''),
        'stage'=>$recovery?'recovery':'method',
    ];
    return $payload;
}

function hache_sharky_commerce_transfer_payload(string $contact, array $registration, array $business, ?int $now = null): array
{
    $now ??= time();
    $institution = trim((string)($business['sharky_pago_institucion'] ?? ''));
    $beneficiary = trim((string)($business['sharky_pago_beneficiario'] ?? ''));
    $clabe = preg_replace('/\D+/', '', (string)($business['sharky_pago_clabe'] ?? '')) ?: '';
    $amount = (float)($registration['price'] ?? 0);
    $amountLabel = '$'.hache_sharky_mp_money($amount).' MXN';
    $flowId = hache_sharky_commerce_flow_cached_id('payment_transfer');
    if ($flowId !== null && $institution !== '' && $beneficiary !== '' && strlen($clabe) === 18) {
        $payload = hache_sharky_commerce_flow_payload(
            $contact,
            'Transferencia SPEI · recomendada y sin recargo. Revisa los datos y sube tu comprobante en el mismo formulario.',
            $flowId,
            'TRANSFER_PROOF',
            'Ver datos SPEI',
            [
                'institution'=>$institution,
                'beneficiary'=>$beneficiary,
                'clabe'=>$clabe,
                'amount_label'=>$amountLabel,
                'student_id'=>(string)$registration['student_id'],
                'course_id'=>(string)$registration['course_id'],
            ]
        );
    } else {
        $lines = [
            '🏦 Transferencia SPEI — recomendada',
            'Importe: '.$amountLabel,
        ];
        if ($institution !== '') $lines[] = 'Institución: '.$institution;
        if ($beneficiary !== '') $lines[] = 'Beneficiario: '.$beneficiary;
        if (strlen($clabe) === 18) { $lines[] = 'CLABE:'; $lines[] = $clabe; }
        $lines[] = '';
        $lines[] = 'Cuando la realices, comparte la captura o comprobante en este chat.';
        $payload = hache_sharky_whatsapp_text_payload($contact, implode("\n",$lines));
    }
    $token = substr(hash('sha256','payment-transfer-v1|'.hache_sharky_orchestrator_contact_hash($contact).'|'.$registration['student_id'].'|'.$registration['course_id']),0,40);
    $payload['_sharky_payment_reminder_arm'] = [
        'token'=>$token,
        'student_id'=>(string)$registration['student_id'],
        'course_id'=>(string)$registration['course_id'],
        'watch_from'=>$now,
    ];
    return $payload;
}

function hache_sharky_commerce_card_payload(
    string $contact,
    array $registration,
    array $business,
    ?callable $credentialResolver = null,
    ?callable $requester = null,
    ?int $now = null
): array {
    $now ??= time();
    $mp = hache_sharky_mp_create_preference($registration,$business,$credentialResolver,$requester);
    if (($mp['ok'] ?? false) !== true) {
        $payload = hache_sharky_commerce_payment_method_payload($contact,$registration,$business,true);
        $payload['_sharky_payment_session']['stage'] = 'card_unavailable';
        return $payload;
    }
    $flowId = hache_sharky_commerce_flow_cached_id('payment_card');
    $pct = (float)($mp['surcharge_pct'] ?? 5);
    $pctLabel = rtrim(rtrim(number_format($pct,2,'.',''),'0'),'.');
    $amountLabel = '$'.hache_sharky_mp_money((float)$mp['total']).' MXN';
    if ($flowId !== null) {
        $payload = hache_sharky_commerce_flow_payload(
            $contact,
            'Pago con tarjeta mediante Mercado Pago. El total incluye el recargo de procesamiento.',
            $flowId,
            'CARD_PROOF',
            'Pagar con tarjeta',
            [
                'payment_url'=>(string)$mp['url'],
                'amount_label'=>$amountLabel,
                'surcharge_label'=>'+'.$pctLabel.'% de procesamiento',
                'student_id'=>(string)$registration['student_id'],
                'course_id'=>(string)$registration['course_id'],
                'external_reference'=>(string)$mp['external_reference'],
                'preference_id'=>(string)$mp['preference_id'],
            ]
        );
    } else {
        $payload = hache_sharky_whatsapp_text_payload($contact,
            "💳 Pago con tarjeta · Mercado Pago\n"
            .'Total: '.$amountLabel."\n"
            .'Incluye +'.$pctLabel."% de procesamiento.\n\n"
            .'Paga aquí: '.(string)$mp['url']."\n\n"
            .'Después comparte la captura o comprobante en este chat.'
        );
    }
    $token = substr(hash('sha256','mp-followup-v1|'.hache_sharky_orchestrator_contact_hash($contact).'|'.$registration['student_id'].'|'.$registration['course_id'].'|'.$mp['preference_id']),0,40);
    $payload['_sharky_mp_followup_arm'] = [
        'token'=>$token,
        'student_id'=>(string)$registration['student_id'],
        'course_id'=>(string)$registration['course_id'],
        'external_reference'=>(string)$mp['external_reference'],
        'watch_from'=>$now,
    ];
    return $payload;
}

function hache_sharky_commerce_enrollment_launch_data(PDO $pdo, array $state, int $minAge = 12): ?array
{
    $flow = $state['flow'] ?? null;
    if (!is_array($flow) || ($flow['name'] ?? '') !== 'register_intensive' || ($flow['step'] ?? '') !== 'course') return null;
    $sede = strtoupper(trim((string)($flow['data']['sede_clave'] ?? '')));
    if (!in_array($sede,['MONTEVERDE','PALAPAS'],true)) return null;
    $today = (new DateTimeImmutable('today',new DateTimeZone('America/Cancun')))->format('Y-m-d');
    $options = [];
    foreach (hache_sharky_business_intensive_options($pdo) as $option) {
        if (!is_array($option) || strtoupper((string)($option['sede_clave'] ?? '')) !== $sede) continue;
        if (function_exists('hache_sharky_start_authority_intensive_date_allowed')
            && !hache_sharky_start_authority_intensive_date_allowed((string)($option['fecha_inicio'] ?? ''),$today)) continue;
        $options[] = $option;
    }
    if (!$options) return null;
    $dates=[];$schedules=[];$seenSchedules=[];
    foreach ($options as $option) {
        $date=(string)($option['fecha_inicio']??'');
        $dateObj=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        $dates[]=[
            'id'=>(string)($option['id']??''),
            'title'=>$dateObj?$dateObj->format('d/m/Y'):$date,
            'description'=>'Inicio disponible',
        ];
        foreach (($option['schedules']??[]) as $schedule) {
            if (!is_array($schedule)) continue;
            $id=(string)($schedule['id']??''); if ($id===''||isset($seenSchedules[$id]))continue;
            $seenSchedules[$id]=true;
            $schedules[]=['id'=>$id,'title'=>(string)($schedule['label']??'Horario')];
        }
    }
    if (!$schedules) return null;
    $minAge=max(1,$minAge);
    $todayObj=new DateTimeImmutable($today);
    return [
        'venue_key'=>$sede,
        'venue_label'=>$sede==='MONTEVERDE'?'Colegio Monteverde':'Palapas Protudec',
        'min_birthdate'=>$todayObj->modify('-120 years')->format('Y-m-d'),
        'max_birthdate'=>$todayObj->modify('-'.$minAge.' years')->format('Y-m-d'),
        'start_dates'=>$dates,
        'schedules'=>$schedules,
    ];
}

function hache_sharky_commerce_upgrade_direct_payload(array $payload): array
{
    $contact = preg_replace('/\D+/', '', (string)($payload['to'] ?? '')) ?: '';
    if ($contact === '' || !function_exists('hache_sharky_pdo')) return $payload;
    $pdo = hache_sharky_pdo(); if (!$pdo instanceof PDO) return $payload;

    // Registration success becomes the payment-method Flow. The legacy text with
    // CLABE remains the fallback if commerce Flows are unavailable.
    $body = '';
    if (($payload['type']??'')==='text') $body=trim((string)($payload['text']['body']??''));
    elseif (($payload['type']??'')==='interactive') $body=trim((string)($payload['interactive']['body']['text']??''));
    if (str_contains($body,'✅ Registro recibido') && str_contains($body,'pendiente de confirmación/pago')) {
        $registration=hache_sharky_commerce_pending_registration($pdo,$contact);
        if (is_array($registration)) {
            $business=function_exists('hache_sharky_business_values')?hache_sharky_business_values($pdo):[];
            return hache_sharky_commerce_payment_method_payload($contact,$registration,$business,false);
        }
    }

    // The first course/date list is replaced by one enrollment form when possible.
    $flowId = hache_sharky_commerce_flow_cached_id('enrollment');
    if ($flowId === null || ($payload['type']??'') !== 'interactive' || ($payload['interactive']['type']??'') !== 'list') return $payload;
    try{$state=hache_sharky_db_state_load($pdo,$contact);}catch(Throwable $e){return $payload;}
    $business=function_exists('hache_sharky_business_values')?hache_sharky_business_values($pdo):[];
    $minAge=is_numeric($business['sharky_edad_minima']??null)?(int)$business['sharky_edad_minima']:12;
    $data=hache_sharky_commerce_enrollment_launch_data($pdo,$state,$minAge);
    if (!is_array($data)) return $payload;
    return hache_sharky_commerce_flow_payload(
        $contact,
        'Completa en un solo formulario los datos de tu inscripción. La sede queda fija en '.(string)$data['venue_label'].'.',
        $flowId,
        'ENROLLMENT',
        'Completar inscripción',
        $data
    );
}

function hache_sharky_commerce_enrollment_submit(array $state,array $event,array $context): array
{
    $commerce=is_array($event['commerce']??null)?$event['commerce']:[];
    $flow=$state['flow']??null;
    if (!is_array($flow)||($flow['name']??'')!=='register_intensive'||($flow['step']??'')!=='course') {
        $decision=hache_sharky_orchestrator_decision('commerce_enrollment_stale','Ese formulario pertenece a una inscripción anterior. No hice cambios; vuelve a elegir “Inscribirme” para abrir uno actualizado.');
        return [$state,$decision];
    }
    $expectedSede=strtoupper((string)($flow['data']['sede_clave']??''));
    $submittedSede=strtoupper(trim((string)($commerce['venue_key']??'')));
    if ($expectedSede===''||$submittedSede!==$expectedSede) {
        $decision=hache_sharky_orchestrator_decision('commerce_enrollment_stale','La sede de este formulario ya no coincide con tu inscripción. No hice cambios; regresemos al menú para elegir nuevamente.');
        $state=hache_sharky_orchestrator_clear_flow($state);
        return [$state,$decision];
    }
    $name=preg_replace('/\s+/u',' ',trim((string)($commerce['full_name']??'')))??'';
    if (mb_strlen($name)<4||mb_strlen($name)>180) {
        return [$state,hache_sharky_orchestrator_decision('registration_name_invalid','No pude validar el nombre completo del formulario. Vuelve a abrirlo o escríbeme el nombre por aquí.')];
    }
    $today=(string)($context['today']??date('Y-m-d'));
    $birth=hache_sharky_orchestrator_parse_birthdate((string)($commerce['birthdate']??''),$today);
    if ($birth===null) return [$state,hache_sharky_orchestrator_decision('registration_birthdate_invalid','No pude validar la fecha de nacimiento del formulario.')];
    $birthObj=DateTimeImmutable::createFromFormat('!Y-m-d',$birth);$todayObj=new DateTimeImmutable($today);
    $minAge=max(1,(int)($context['min_age']??12));
    if (!$birthObj||$birthObj->format('Y-m-d')!==$birth||$birthObj>$todayObj||$birthObj->diff($todayObj)->y<$minAge) {
        $state=hache_sharky_orchestrator_clear_flow($state);
        return [$state,hache_sharky_orchestrator_decision('registration_age_rejected','Hache Natación atiende a partir de '.$minAge.' años; no puedo continuar con este registro.')];
    }
    $courseId=trim((string)($commerce['course_id']??''));$scheduleId=trim((string)($commerce['schedule_id']??''));
    $course=null;$schedule=null;
    foreach (($context['intensive_options']??[]) as $option) {
        if (!is_array($option)||(string)($option['id']??'')!==$courseId||strtoupper((string)($option['sede_clave']??''))!==$expectedSede)continue;
        $course=$option;
        foreach (($option['schedules']??[]) as $candidate) if(is_array($candidate)&&(string)($candidate['id']??'')===$scheduleId)$schedule=$candidate;
        break;
    }
    if (!is_array($course)||!is_array($schedule)) {
        return [$state,hache_sharky_orchestrator_decision('registration_course_invalid','La fecha de inicio o el horario del formulario dejó de estar disponible. No hice cambios; abriré opciones actualizadas.',[],['type'=>'refresh_intensive_options'])];
    }
    $data=is_array($flow['data']??null)?$flow['data']:[];
    $data['course_id']=$courseId;
    $data['fecha_inicio']=(string)($course['fecha_inicio']??'');
    $data['course_price']=is_numeric($course['precio']??null)?(float)$course['precio']:null;
    $data['schedule_id']=$scheduleId;
    $data['name']=$name;
    $data['birthdate']=$birth;
    $data['age']=$birthObj->diff($todayObj)->y;
    $state=hache_sharky_orchestrator_flow($state,'register_intensive','confirm',$data,(int)($context['now']??time()));
    $venue=$expectedSede==='MONTEVERDE'?'Colegio Monteverde':'Palapas Protudec';
    $summary='Revisa: '.$name.' · '.$venue.' · inicio '.date('d/m/Y',strtotime($data['fecha_inicio'])).' · horario '.(string)($schedule['label']??'').' · nacimiento '.$birthObj->format('d/m/Y').'. ¿Confirmas la inscripción?';
    return [$state,hache_sharky_orchestrator_yes_no('registration_confirm',$summary,'flow:confirm')];
}

/**
 * Handles normalized commerce Flow submissions before stale-interactive routing.
 * Returns the adapter-compatible result fields, or null for normal messages.
 */
function hache_sharky_commerce_handle_event(PDO $pdo,array $state,array $event,array $context,array $business): ?array
{
    $commerce=is_array($event['commerce']??null)?$event['commerce']:null;
    $id=strtolower(trim((string)($event['interactive_id']??'')));
    if ($commerce===null&&str_starts_with($id,'commerce:pay:')) {
        $method=substr($id,13);
        if (in_array($method,['transfer','card','cash'],true))$commerce=['flow_kind'=>'payment_method','method'=>$method];
    }
    if (!is_array($commerce)) return null;
    $flowKind=strtolower(trim((string)($commerce['flow_kind']??'')));
    $contact=(string)($event['from']??'');

    if ($flowKind==='enrollment') {
        $action=strtolower(trim((string)($commerce['user_action']??'')));
        if ($action==='cancel') {
            $state=hache_sharky_orchestrator_clear_flow($state);
            $decision=function_exists('hache_sharky_whatsapp_commercial_next_action')
                ?hache_sharky_whatsapp_commercial_next_action($state,'Entendido, cancelé el formulario. Conservé tu curso y sede.')
                :hache_sharky_orchestrator_decision('commerce_enrollment_cancelled','Entendido, cancelé el formulario. No registré nada.');
            return ['state'=>$state,'decision'=>$decision,'payload'=>hache_sharky_whatsapp_render($contact,$decision),'action_result'=>null];
        }
        if ($action==='submit') {
            [$state,$decision]=hache_sharky_commerce_enrollment_submit($state,$event,$context);
            return ['state'=>$state,'decision'=>$decision,'payload'=>hache_sharky_whatsapp_render($contact,$decision),'action_result'=>null];
        }
        return null;
    }

    if ($flowKind==='payment_method') {
        $registration=hache_sharky_commerce_pending_registration($pdo,$contact);
        if (!is_array($registration)) {
            $decision=hache_sharky_orchestrator_decision('payment_registration_ambiguous','No pude identificar de forma segura qué inscripción está pendiente. Te dejo con el equipo para revisarlo.',[],['type'=>'human_takeover']);
            return ['state'=>$state,'decision'=>$decision,'payload'=>hache_sharky_whatsapp_text_payload($contact,$decision['message']),'action_result'=>['ok'=>true,'code'=>'HANDOFF']];
        }
        $method=strtolower(trim((string)($commerce['method']??'')));
        if ($method==='transfer') {
            $decision=hache_sharky_orchestrator_decision('payment_transfer_selected','Transferencia SPEI seleccionada.');
            return ['state'=>$state,'decision'=>$decision,'payload'=>hache_sharky_commerce_transfer_payload($contact,$registration,$business,(int)($context['now']??time())),'action_result'=>null];
        }
        if ($method==='card') {
            $payload=hache_sharky_commerce_card_payload($contact,$registration,$business,null,null,(int)($context['now']??time()));
            $decision=hache_sharky_orchestrator_decision('payment_card_selected','Pago con tarjeta mediante Mercado Pago.');
            return ['state'=>$state,'decision'=>$decision,'payload'=>$payload,'action_result'=>null];
        }
        if ($method==='cash') {
            $message='💵 Pago en efectivo solicitado. Está sujeto a disponibilidad y debe coordinarse previamente con el profesor. Te dejo con el equipo para pactarlo por este mismo chat.';
            $decision=hache_sharky_orchestrator_decision('payment_cash_handoff',$message,[],['type'=>'human_takeover']);
            return ['state'=>$state,'decision'=>$decision,'payload'=>hache_sharky_whatsapp_text_payload($contact,$message),'action_result'=>['ok'=>true,'code'=>'HANDOFF']];
        }
        return null;
    }

    if ($flowKind==='payment_proof') {
        $registration=hache_sharky_commerce_pending_registration($pdo,$contact);
        $studentId=trim((string)($commerce['student_id']??''));$courseId=trim((string)($commerce['course_id']??''));
        if (!is_array($registration)||$studentId===''||$courseId===''||$studentId!==(string)$registration['student_id']||$courseId!==(string)$registration['course_id']) {
            $decision=hache_sharky_orchestrator_decision('payment_proof_stale','Recibí el archivo, pero no pude asociarlo de forma segura con una inscripción pendiente. Te dejo con el equipo para revisarlo.',[],['type'=>'human_takeover']);
            return ['state'=>$state,'decision'=>$decision,'payload'=>hache_sharky_whatsapp_text_payload($contact,$decision['message']),'action_result'=>['ok'=>true,'code'=>'HANDOFF']];
        }
        $message='✅ Recibí tu comprobante. Tu inscripción sigue pendiente hasta que el pago sea validado por el equipo. No necesitas volver a enviar la captura.';
        $decision=hache_sharky_orchestrator_decision('payment_proof_received',$message);
        return ['state'=>$state,'decision'=>$decision,'payload'=>hache_sharky_whatsapp_text_payload($contact,$message),'action_result'=>null];
    }
    return null;
}

function hache_sharky_mp_followup_payload(string $contact,array $meta,array $business): array
{
    $registration=[
        'student_id'=>(string)($meta['student_id']??''),
        'course_id'=>(string)($meta['course_id']??''),
    ];
    return hache_sharky_commerce_payment_method_payload($contact,$registration,$business,true);
}

function hache_sharky_mp_followup_after_card_sent(PDO $pdo,string $contact,array $meta,?int $now=null): void
{
    $now??=time();$token=trim((string)($meta['token']??''));$studentId=trim((string)($meta['student_id']??''));$courseId=trim((string)($meta['course_id']??''));$external=trim((string)($meta['external_reference']??''));
    if($token===''||$studentId===''||$courseId===''||$external==='')return;
    $business=function_exists('hache_sharky_business_values')?hache_sharky_business_values($pdo):[];
    $followMeta=[
        'token'=>$token,'student_id'=>$studentId,'course_id'=>$courseId,'external_reference'=>$external,
        'watch_from'=>(int)($meta['watch_from']??$now),'card_sent_at'=>$now,'due_at'=>$now+HACHE_SHARKY_MP_CARD_FOLLOWUP_SECONDS,
    ];
    $payload=hache_sharky_mp_followup_payload($contact,$followMeta,$business);
    $payload['_sharky_mp_followup']=$followMeta;
    if(!hache_sharky_outbox_enqueue_raw($pdo,$contact,$payload,'mp-card-followup|'.$token,$followMeta['due_at']))error_log('[sharky-mp] followup schedule failed');
}

/** @return array{ok:bool,reason?:string,reschedule_at?:int} */
function hache_sharky_mp_followup_validate_before_send(PDO $pdo,string $contact,array $meta,?int $now=null,?callable $credentialResolver=null,?callable $requester=null): array
{
    $now??=time();$studentId=trim((string)($meta['student_id']??''));$courseId=trim((string)($meta['course_id']??''));$external=trim((string)($meta['external_reference']??''));$watchFrom=(int)($meta['watch_from']??0);$sentAt=(int)($meta['card_sent_at']??0);
    if($studentId===''||$courseId===''||$external===''||$watchFrom<=0||$sentAt<=0)return ['ok'=>false,'reason'=>'MP_FOLLOWUP_INVALID'];
    if($now>$sentAt+HACHE_SHARKY_MP_CARD_FOLLOWUP_MAX_AGE_SECONDS)return ['ok'=>false,'reason'=>'MP_FOLLOWUP_EXPIRED'];
    $pending=hache_sharky_payment_reminder_registration_pending($pdo,$studentId,$courseId);if($pending!==true)return ['ok'=>false,'reason'=>$pending===false?'REGISTRATION_RESOLVED':'MP_FOLLOWUP_STATE_UNAVAILABLE'];
    $paid=hache_sharky_payment_reminder_payment_confirmed($pdo,$studentId,$courseId);if($paid!==false)return ['ok'=>false,'reason'=>$paid===true?'PAYMENT_ALREADY_CONFIRMED':'MP_FOLLOWUP_STATE_UNAVAILABLE'];
    $proof=hache_sharky_payment_reminder_proof_received($pdo,$contact,$watchFrom);if($proof!==false)return ['ok'=>false,'reason'=>$proof===true?'PAYMENT_PROOF_RECEIVED':'MP_FOLLOWUP_STATE_UNAVAILABLE'];
    $status=hache_sharky_mp_status_by_external_reference($external,$credentialResolver,$requester);
    if(($status['state']??'')==='approved')return ['ok'=>false,'reason'=>'MP_APPROVED'];
    if(($status['state']??'')==='pending')return ['ok'=>false,'reason'=>'MP_PENDING','reschedule_at'=>$now+HACHE_SHARKY_MP_CARD_FOLLOWUP_SECONDS];
    if(($status['state']??'')==='unavailable')return ['ok'=>false,'reason'=>'MP_STATUS_UNAVAILABLE','reschedule_at'=>$now+HACHE_SHARKY_MP_CARD_FOLLOWUP_SECONDS];
    return ['ok'=>true];
}
