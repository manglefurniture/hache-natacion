<?php

declare(strict_types=1);

/**
 * Compatibility glue for the Sharky 2.0 laboratory.
 *
 * Keeps the draft aligned with behavior that already exists in the current
 * WhatsApp v2 path without moving ownership of those rules into the lab.
 */

function hache_sharky_draft_third_party_registration(string $text): bool
{
    $normalized = function_exists('hache_sharky_normalize_text')
        ? hache_sharky_normalize_text($text)
        : strtr(mb_strtolower(trim($text),'UTF-8'),['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    $hasAction = preg_match('/\b(inscribir|inscribirme|inscribirlo|inscribirla|registrar|registrarme|registrarlo|registrarla|anotar|anotarlo|anotarla|apuntar|apuntarlo|apuntarla|dar de alta)\b/u', $normalized) === 1;
    $hasThirdParty = preg_match('/\b(mi|a mi|para mi|para|a)\s+(hijo|hija|esposo|esposa|pareja|hermano|hermana|mama|madre|papa|padre|amigo|amiga|sobrino|sobrina)\b/u', $normalized) === 1
        || preg_match('/\b(otra persona|alguien mas|un tercero|una tercera persona)\b/u', $normalized) === 1;
    return $hasAction && $hasThirdParty;
}

function hache_sharky_draft_requires_handoff(string $text): bool
{
    if (function_exists('hache_sharky_human_request') && hache_sharky_human_request($text)) return true;
    if (function_exists('hache_sharky_frustration') && hache_sharky_frustration($text)) return true;
    return hache_sharky_draft_third_party_registration($text);
}

function hache_sharky_draft_extract_audio_events(array $payload): array
{
    $out = [];
    foreach (($payload['entry'] ?? []) as $entry) {
        if (!is_array($entry)) continue;
        foreach (($entry['changes'] ?? []) as $change) {
            if (!is_array($change)) continue;
            $value = $change['value'] ?? null;
            if (!is_array($value)) continue;
            $phoneId = trim((string) ($value['metadata']['phone_number_id'] ?? ''));
            foreach (($value['messages'] ?? []) as $message) {
                if (!is_array($message) || ($message['type'] ?? '') !== 'audio') continue;
                $id = trim((string) ($message['id'] ?? ''));
                $from = preg_replace('/\D+/', '', (string) ($message['from'] ?? '')) ?: '';
                $mediaId = trim((string) ($message['audio']['id'] ?? ''));
                if ($id === '' || $from === '' || $mediaId === '') continue;
                $event = [
                    'id' => $id,
                    'from' => $from,
                    'type' => 'audio',
                    'text' => '',
                    'media_id' => $mediaId,
                    'interactive_id' => '',
                    'phone_number_id' => $phoneId,
                    'timestamp_ms' => ((int) ($message['timestamp'] ?? time())) * 1000,
                ];
                if (is_array($message['referral'] ?? null)) $event['referral'] = $message['referral'];
                $out[] = $event;
            }
        }
    }
    return $out;
}

function hache_sharky_draft_graph_version(callable $secret): string
{
    $version = trim((string) $secret('WHATSAPP_GRAPH_VERSION'));
    return preg_match('/^v\d+\.\d+$/', $version) === 1 ? $version : 'v26.0';
}

function hache_sharky_draft_transcribe_audio(array $event, array $business, callable $secret): string
{
    if (($event['type'] ?? '') !== 'audio') return trim((string) ($event['text'] ?? ''));
    if (function_exists('hache_sharky_config_int') && hache_sharky_config_int($business, 'sharky_audio_habilitado', 1, 0, 1) !== 1) return '';

    $mediaId = trim((string) ($event['media_id'] ?? ''));
    $token = trim((string) $secret('WHATSAPP_ACCESS_TOKEN'));
    if ($mediaId === '' || $token === '') return '';

    $metaUrl = 'https://graph.facebook.com/'.rawurlencode(hache_sharky_draft_graph_version($secret)).'/'.rawurlencode($mediaId);
    $ch = curl_init($metaUrl);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token]]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $status < 200 || $status >= 300) return '';
    $meta = json_decode((string) $response, true);
    if (!is_array($meta) || !is_string($meta['url'] ?? null) || !str_starts_with((string)$meta['url'], 'https://')) return '';

    $maxMb = function_exists('hache_sharky_config_int') ? hache_sharky_config_int($business, 'sharky_audio_max_mb', 4, 1, 20) : 4;
    $maxBytes = $maxMb * 1024 * 1024;
    if (isset($meta['file_size']) && (int)$meta['file_size'] > $maxBytes) return '';

    $ch = curl_init((string) $meta['url']);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token]]);
    $bytes = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($bytes) || $status < 200 || $status >= 300 || strlen($bytes) > $maxBytes) return '';

    $key = function_exists('hache_sharky_openai_key') ? hache_sharky_openai_key() : trim((string) $secret('OPENAI_API_KEY'));
    if (!str_starts_with($key, 'sk-')) return '';
    $tmp = tempnam(sys_get_temp_dir(), 'hache-audio-');
    if ($tmp === false || file_put_contents($tmp, $bytes) === false) return '';

    $mime = trim(explode(';', (string) ($meta['mime_type'] ?? 'audio/ogg'))[0]) ?: 'audio/ogg';
    $model = trim((string) ($secret('SHARKY_TRANSCRIBE_MODEL') ?: 'gpt-4o-mini-transcribe'));
    if (!preg_match('/^[a-zA-Z0-9._-]{2,80}$/', $model)) $model = 'gpt-4o-mini-transcribe';
    $file = new CURLFile($tmp, $mime, 'nota-voz');
    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>40,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key],
        CURLOPT_POSTFIELDS=>['model'=>$model,'file'=>$file,'language'=>'es','response_format'=>'json'],
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmp);
    if ($response === false || $status < 200 || $status >= 300) return '';
    $data = json_decode((string) $response, true);
    return mb_substr(is_array($data) ? trim((string) ($data['text'] ?? '')) : '', 0, 700);
}

function hache_sharky_draft_link_attribution(PDO $pdo, string $contact, string $studentId, array $state = []): int
{
    $studentId = trim($studentId);
    if ($studentId === '' || !function_exists('hache_sharky_orchestrator_contact_hash')) return 0;
    try {
        $hash = hache_sharky_orchestrator_contact_hash($contact);
        $sourceIds = [];
        $clickIds = [];
        foreach (['first','latest'] as $touch) {
            $ref = $state['referral'][$touch] ?? null;
            if (!is_array($ref)) continue;
            $sourceId = trim((string) ($ref['source_id'] ?? ''));
            $clickId = trim((string) ($ref['ctwa_clid'] ?? ''));
            if ($sourceId !== '') $sourceIds[$sourceId] = true;
            if ($clickId !== '') $clickIds[$clickId] = true;
        }

        $params = [':a'=>$studentId, ':c'=>$hash];
        $clauses = [];
        foreach (array_keys($sourceIds) as $i=>$value) {
            $key = ':sid'.$i;
            $params[$key] = $value;
            $clauses[] = 'source_id='.$key;
        }
        foreach (array_keys($clickIds) as $i=>$value) {
            $key = ':clid'.$i;
            $params[$key] = $value;
            $clauses[] = 'ctwa_clid='.$key;
        }
        $scope = $clauses
            ? ' AND ('.implode(' OR ', $clauses).')'
            : ' AND captured_at >= (NOW() - INTERVAL 2 DAY)';
        $st = $pdo->prepare('UPDATE sharky_referrals SET alumno_id=:a WHERE contact_hash=:c AND alumno_id IS NULL'.$scope);
        $st->execute($params);
        return $st->rowCount();
    } catch (Throwable $e) {
        error_log('[sharky-orchestrator] attribution link failed');
        return 0;
    }
}

function hache_sharky_draft_payload_text(?array $payload): string
{
    if (!is_array($payload)) return '';
    if (($payload['type'] ?? '') === 'text') return trim((string) ($payload['text']['body'] ?? ''));
    if (($payload['type'] ?? '') === 'interactive') return trim((string) ($payload['interactive']['body']['text'] ?? ''));
    return '';
}

function hache_sharky_draft_escalation_path(string $contact): string
{
    if (!function_exists('hache_sharky_orchestrator_contact_hash')) return '';
    $root = is_dir('/var/tmp') && is_writable('/var/tmp') ? '/var/tmp' : sys_get_temp_dir();
    $dir = rtrim($root, '/').'/hache-sharky-escalation';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return '';
    return $dir.'/'.hache_sharky_orchestrator_contact_hash($contact).'.json';
}

function hache_sharky_draft_escalation_update(string $contact, string $answer, int $threshold): bool
{
    $threshold = max(1, min(5, $threshold));
    $needsHuman = function_exists('hache_sharky_answer_needs_human') && hache_sharky_answer_needs_human($answer);
    $path = hache_sharky_draft_escalation_path($contact);
    if ($path === '') return false;
    $count = 0;
    if ($needsHuman && is_file($path)) {
        $stored = json_decode((string) @file_get_contents($path), true);
        if (is_array($stored) && (int)($stored['updated_at'] ?? 0) >= time() - 86400) $count = max(0, (int)($stored['count'] ?? 0));
    }
    $count = $needsHuman ? $count + 1 : 0;
    @file_put_contents($path, json_encode(['count'=>$count,'updated_at'=>time()]), LOCK_EX);
    @chmod($path, 0600);
    return $needsHuman && $count >= $threshold;
}

function hache_sharky_draft_registration_message(array $actionResult, array $business): ?string
{
    if (($actionResult['ok'] ?? false) !== true) return null;
    $result = $actionResult['result'] ?? null;
    if (!is_array($result) || ($result['code'] ?? '') !== 'CREATED') return null;
    $price = (float) ($result['price'] ?? 0);
    if ($price <= 0) return null;
    $minimum = $price / 2;
    $money = static fn(float $v): string => number_format($v, fmod($v, 1.0) === 0.0 ? 0 : 2, '.', ',');
    $lines = [
        'Listo. Tu registro fue recibido y quedó pendiente de confirmación/pago.',
        '',
        'Total del curso: $'.$money($price).' MXN.',
        'Reserva mínima (50%): $'.$money($minimum).' MXN.',
    ];
    $institution = trim((string) ($business['sharky_pago_institucion'] ?? ''));
    $beneficiary = trim((string) ($business['sharky_pago_beneficiario'] ?? ''));
    $clabe = preg_replace('/\D+/', '', (string) ($business['sharky_pago_clabe'] ?? '')) ?: '';
    if ($institution !== '' && $beneficiary !== '' && strlen($clabe) === 18) {
        $lines[] = '';
        $lines[] = 'Transferencia: '.$institution.' · '.$beneficiary.' · CLABE '.$clabe.'.';
    }
    $card = (int) ($business['sharky_recargo_tarjeta_pct'] ?? 0);
    if ($card > 0) $lines[] = 'Pago con tarjeta: aplica '.$card.'% de recargo.';
    return implode("\n", $lines);
}
