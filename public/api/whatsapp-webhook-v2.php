<?php

declare(strict_types=1);

header('Cache-Control: no-store');
require_once __DIR__.'/../../config/sharky-runtime.php';

function whatsapp_v2_secret(string $name): string
{
    $value = trim((string) getenv($name));
    if ($value !== '') return $value;

    foreach ([dirname(__DIR__, 2).'/.env', dirname(__DIR__, 3).'/.env'] as $envFile) {
        if (!is_readable($envFile)) continue;
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (str_starts_with($line, 'export ')) $line = trim(substr($line, 7));
            if (!str_starts_with($line, $name.'=')) continue;
            return trim(trim(substr($line, strlen($name) + 1)), "\"'");
        }
    }
    return '';
}

function whatsapp_v2_json(int $status, array $body): never
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function whatsapp_v2_ack(): void
{
    $body = '{"ok":true}';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: '.strlen($body));
    http_response_code(200);
    echo $body;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }
    @ob_flush();
    flush();
}

function whatsapp_v2_graph_version(): string
{
    $version = whatsapp_v2_secret('WHATSAPP_GRAPH_VERSION');
    return preg_match('/^v\d+\.\d+$/', $version) === 1 ? $version : 'v26.0';
}

function whatsapp_v2_extract_messages(array $payload): array
{
    $messages = [];
    foreach (($payload['entry'] ?? []) as $entry) {
        if (!is_array($entry)) continue;
        foreach (($entry['changes'] ?? []) as $change) {
            if (!is_array($change)) continue;
            $value = $change['value'] ?? null;
            if (!is_array($value)) continue;
            $phoneNumberId = trim((string) ($value['metadata']['phone_number_id'] ?? ''));
            foreach (($value['messages'] ?? []) as $message) {
                if (!is_array($message)) continue;
                $type = (string) ($message['type'] ?? '');
                if (!in_array($type, ['text', 'audio'], true)) continue;
                $id = trim((string) ($message['id'] ?? ''));
                $from = preg_replace('/\D+/', '', (string) ($message['from'] ?? '')) ?: '';
                if ($id === '' || $from === '') continue;

                if ($type === 'text') {
                    $text = trim((string) ($message['text']['body'] ?? ''));
                    if ($text === '') continue;
                    $messages[] = ['id'=>$id, 'from'=>$from, 'type'=>'text', 'text'=>mb_substr($text, 0, 700), 'media_id'=>'', 'phone_number_id'=>$phoneNumberId];
                    continue;
                }

                $mediaId = trim((string) ($message['audio']['id'] ?? ''));
                if ($mediaId === '') continue;
                $messages[] = ['id'=>$id, 'from'=>$from, 'type'=>'audio', 'text'=>'', 'media_id'=>$mediaId, 'phone_number_id'=>$phoneNumberId];
            }
        }
    }
    return $messages;
}

function whatsapp_v2_extract_echoes(array $payload): array
{
    $echoes = [];
    foreach (($payload['entry'] ?? []) as $entry) {
        if (!is_array($entry)) continue;
        foreach (($entry['changes'] ?? []) as $change) {
            if (!is_array($change) || ($change['field'] ?? '') !== 'smb_message_echoes') continue;
            $value = $change['value'] ?? null;
            if (!is_array($value)) continue;
            $phoneNumberId = trim((string) ($value['metadata']['phone_number_id'] ?? ''));
            foreach (($value['message_echoes'] ?? []) as $echo) {
                if (!is_array($echo)) continue;
                $id = trim((string) ($echo['id'] ?? ''));
                $to = preg_replace('/\D+/', '', (string) ($echo['to'] ?? '')) ?: '';
                if ($id === '' || $to === '') continue;
                $echoes[] = ['id'=>$id, 'to'=>$to, 'phone_number_id'=>$phoneNumberId];
            }
        }
    }
    return $echoes;
}

function whatsapp_v2_claim(string $messageId): bool
{
    $dir = rtrim(sys_get_temp_dir(), '/').'/hache-whatsapp-dedupe';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return true;
    $path = $dir.'/'.hash('sha256', $messageId);
    $handle = @fopen($path, 'x');
    if ($handle === false) return false;
    fwrite($handle, (string) time());
    fclose($handle);
    if (random_int(1, 20) === 1) {
        $cutoff = time() - 172800;
        foreach (glob($dir.'/*') ?: [] as $candidate) {
            $mtime = @filemtime($candidate);
            if ($mtime !== false && $mtime < $cutoff) @unlink($candidate);
        }
    }
    return true;
}

function whatsapp_v2_history_path(string $contact): string
{
    $dir = rtrim(sys_get_temp_dir(), '/').'/hache-whatsapp-history';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return '';
    return $dir.'/'.hache_sharky_contact_hash($contact).'.json';
}

function whatsapp_v2_history_read(string $contact): array
{
    $path = whatsapp_v2_history_path($contact);
    if ($path === '' || !is_file($path)) return ['updated_at'=>0, 'turns'=>[], 'unresolved_count'=>0];
    $stored = json_decode((string) @file_get_contents($path), true);
    if (!is_array($stored) || (int) ($stored['updated_at'] ?? 0) < time() - 86400) {
        return ['updated_at'=>0, 'turns'=>[], 'unresolved_count'=>0];
    }
    return [
        'updated_at' => (int) ($stored['updated_at'] ?? 0),
        'turns' => is_array($stored['turns'] ?? null) ? array_slice($stored['turns'], -12) : [],
        'unresolved_count' => max(0, (int) ($stored['unresolved_count'] ?? 0)),
    ];
}

function whatsapp_v2_history_write(string $contact, array $turns, int $unresolvedCount): void
{
    $path = whatsapp_v2_history_path($contact);
    if ($path === '') return;
    $encoded = json_encode([
        'updated_at' => time(),
        'turns' => array_slice($turns, -12),
        'unresolved_count' => max(0, $unresolvedCount),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded !== false) @file_put_contents($path, $encoded, LOCK_EX);
}

function whatsapp_v2_sharky_answer(string $message, array $history): string
{
    $payload = json_encode(['message'=>$message, 'history'=>$history, 'channel'=>'whatsapp'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) return '';
    $ch = curl_init('https://hnatacion.com/api/sharky.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RESOLVE => ['hnatacion.com:443:127.0.0.1'],
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($response === false || $curlError !== '' || $status < 200 || $status >= 300) {
        error_log('[whatsapp-webhook-v2] Sharky request failed http='.$status);
        hache_sharky_metric_increment('errors_sharky_request');
        return '';
    }
    $result = json_decode($response, true);
    if (!is_array($result) || ($result['ok'] ?? false) !== true) return '';
    return trim((string) ($result['answer'] ?? ''));
}

function whatsapp_v2_send_text(string $to, string $body): bool
{
    $token = whatsapp_v2_secret('WHATSAPP_ACCESS_TOKEN');
    $phoneNumberId = whatsapp_v2_secret('WHATSAPP_PHONE_NUMBER_ID');
    if ($token === '' || $phoneNumberId === '') return false;
    $payload = json_encode([
        'messaging_product'=>'whatsapp',
        'recipient_type'=>'individual',
        'to'=>$to,
        'type'=>'text',
        'text'=>['preview_url'=>false, 'body'=>mb_substr(trim($body), 0, 4000)],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) return false;
    $url = sprintf('https://graph.facebook.com/%s/%s/messages', rawurlencode(whatsapp_v2_graph_version()), rawurlencode($phoneNumberId));
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST=>true,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>15,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'Authorization: Bearer '.$token],
        CURLOPT_POSTFIELDS=>$payload,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    $ok = $response !== false && $curlError === '' && $status >= 200 && $status < 300;
    hache_sharky_metric_increment($ok ? 'messages_sent' : 'errors_send');
    if (!$ok) error_log('[whatsapp-webhook-v2] outbound send failed http='.$status);
    return $ok;
}

function whatsapp_v2_media_metadata(string $mediaId): ?array
{
    $token = whatsapp_v2_secret('WHATSAPP_ACCESS_TOKEN');
    if ($token === '') return null;
    $url = 'https://graph.facebook.com/'.rawurlencode(whatsapp_v2_graph_version()).'/'.rawurlencode($mediaId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>15,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token],
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $status < 200 || $status >= 300) return null;
    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

function whatsapp_v2_download_media(string $url): ?string
{
    $token = whatsapp_v2_secret('WHATSAPP_ACCESS_TOKEN');
    if ($token === '' || !str_starts_with($url, 'https://')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>20,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token],
    ]);
    $bytes = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return is_string($bytes) && $status >= 200 && $status < 300 ? $bytes : null;
}

function whatsapp_v2_transcribe(string $mediaId, array $business): string
{
    if (hache_sharky_config_int($business, 'sharky_audio_habilitado', 1, 0, 1) !== 1) return '';
    $meta = whatsapp_v2_media_metadata($mediaId);
    if (!$meta || !is_string($meta['url'] ?? null)) return '';
    $maxMb = hache_sharky_config_int($business, 'sharky_audio_max_mb', 4, 1, 20);
    $maxBytes = $maxMb * 1024 * 1024;
    if (isset($meta['file_size']) && (int) $meta['file_size'] > $maxBytes) {
        hache_sharky_metric_increment('audio_too_large');
        return '';
    }
    $bytes = whatsapp_v2_download_media((string) $meta['url']);
    if ($bytes === null || strlen($bytes) > $maxBytes) return '';

    $tmp = tempnam(sys_get_temp_dir(), 'hache-audio-');
    if ($tmp === false || file_put_contents($tmp, $bytes) === false) return '';
    $mime = trim(explode(';', (string) ($meta['mime_type'] ?? 'audio/ogg'))[0]) ?: 'audio/ogg';
    $key = hache_sharky_openai_key();
    if (!str_starts_with($key, 'sk-')) {
        @unlink($tmp);
        return '';
    }
    $model = trim((string) (getenv('SHARKY_TRANSCRIBE_MODEL') ?: 'gpt-4o-mini-transcribe'));
    if (!preg_match('/^[a-zA-Z0-9._-]{2,80}$/', $model)) $model = 'gpt-4o-mini-transcribe';
    $file = new CURLFile($tmp, $mime, 'nota-voz');
    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_POST=>true,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>8,
        CURLOPT_TIMEOUT=>40,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key],
        CURLOPT_POSTFIELDS=>['model'=>$model, 'file'=>$file, 'language'=>'es', 'response_format'=>'json'],
    ]);
    hache_sharky_metric_increment('audio_transcription_calls');
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmp);
    if ($response === false || $status < 200 || $status >= 300) {
        hache_sharky_metric_increment('audio_transcription_errors');
        return '';
    }
    $data = json_decode($response, true);
    $text = is_array($data) ? trim((string) ($data['text'] ?? '')) : '';
    if ($text !== '') hache_sharky_metric_increment('audio_transcribed');
    return mb_substr($text, 0, 700);
}

function whatsapp_v2_activate_handoff(string $contact, string $reason, array $turns, string $pending = ''): void
{
    $summary = hache_sharky_history_summary($turns, $pending);
    if (hache_sharky_takeover_mark($contact, $reason, $summary)) {
        hache_sharky_metric_increment('takeovers');
        hache_sharky_metric_increment('takeover_'.$reason);
        error_log('[whatsapp-webhook-v2] human takeover activated reason='.$reason);
    }
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    $mode = (string) ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
    $verifyToken = (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
    $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
    $expectedToken = whatsapp_v2_secret('WHATSAPP_VERIFY_TOKEN');
    if ($mode === 'subscribe' && $expectedToken !== '' && hash_equals($expectedToken, $verifyToken)) {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(200);
        echo $challenge;
        exit;
    }
    whatsapp_v2_json(403, ['ok'=>false, 'error'=>'Webhook verification failed']);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    whatsapp_v2_json(405, ['ok'=>false, 'error'=>'Method not allowed']);
}

$raw = file_get_contents('php://input');
if ($raw === false) $raw = '';
$appSecret = whatsapp_v2_secret('META_APP_SECRET');
if ($appSecret === '') whatsapp_v2_json(503, ['ok'=>false, 'error'=>'Webhook not configured']);
$signature = trim((string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''));
$expectedSignature = 'sha256='.hash_hmac('sha256', $raw, $appSecret);
if ($signature === '' || !hash_equals($expectedSignature, $signature)) {
    error_log('[whatsapp-webhook-v2] rejected invalid signature');
    whatsapp_v2_json(401, ['ok'=>false, 'error'=>'Invalid signature']);
}
$payload = json_decode($raw, true);
if (!is_array($payload)) whatsapp_v2_json(400, ['ok'=>false, 'error'=>'Invalid JSON']);

$object = is_string($payload['object'] ?? null) ? $payload['object'] : 'unknown';
$entryCount = is_array($payload['entry'] ?? null) ? count($payload['entry']) : 0;
error_log(sprintf('[whatsapp-webhook-v2] accepted object=%s entries=%d', $object, $entryCount));
$configuredPhoneId = whatsapp_v2_secret('WHATSAPP_PHONE_NUMBER_ID');

foreach (whatsapp_v2_extract_echoes($payload) as $echo) {
    if ($configuredPhoneId !== '' && $echo['phone_number_id'] !== '' && !hash_equals($configuredPhoneId, $echo['phone_number_id'])) continue;
    $state = whatsapp_v2_history_read($echo['to']);
    whatsapp_v2_activate_handoff($echo['to'], 'manual', $state['turns']);
}

$jobs = [];
foreach (whatsapp_v2_extract_messages($payload) as $message) {
    if ($configuredPhoneId !== '' && $message['phone_number_id'] !== '' && !hash_equals($configuredPhoneId, $message['phone_number_id'])) continue;
    if (hache_sharky_takeover_active($message['from'])) {
        error_log('[whatsapp-webhook-v2] inbound skipped human_takeover=1');
        hache_sharky_metric_increment('messages_skipped_takeover');
        continue;
    }
    if (!whatsapp_v2_claim($message['id'])) continue;
    $jobs[] = $message;
    hache_sharky_metric_increment($message['type'] === 'audio' ? 'inbound_audio' : 'inbound_text');
}

whatsapp_v2_ack();
ignore_user_abort(true);
@set_time_limit(90);
$business = hache_sharky_business_values(hache_sharky_pdo());
$threshold = hache_sharky_config_int($business, 'sharky_escalado_intentos', 2, 1, 5);

foreach ($jobs as $job) {
    if (hache_sharky_takeover_active($job['from'])) {
        error_log('[whatsapp-webhook-v2] queued skipped human_takeover=1');
        continue;
    }

    $text = (string) $job['text'];
    if ($job['type'] === 'audio') {
        $text = whatsapp_v2_transcribe((string) $job['media_id'], $business);
        if ($text === '') {
            whatsapp_v2_send_text($job['from'], 'No pude entender esa nota de voz. ¿Me la puedes escribir en un mensaje corto?');
            continue;
        }
    }

    $state = whatsapp_v2_history_read($job['from']);
    $turns = $state['turns'];

    if (hache_sharky_human_request($text)) {
        $handoff = 'Claro. Te dejo con el equipo de Hache Natación por aquí. Una persona continuará contigo en este mismo chat.';
        $sent = whatsapp_v2_send_text($job['from'], $handoff);
        if ($sent) {
            $turns[] = ['role'=>'user', 'content'=>$text];
            $turns[] = ['role'=>'assistant', 'content'=>$handoff];
            whatsapp_v2_history_write($job['from'], $turns, 0);
        }
        whatsapp_v2_activate_handoff($job['from'], 'requested_human', $turns, $sent ? '' : $text);
        continue;
    }

    if (hache_sharky_frustration($text)) {
        $handoff = 'Para no hacerte perder tiempo, te dejo con el equipo de Hache Natación. Una persona puede continuar contigo por aquí.';
        $sent = whatsapp_v2_send_text($job['from'], $handoff);
        if ($sent) {
            $turns[] = ['role'=>'user', 'content'=>$text];
            $turns[] = ['role'=>'assistant', 'content'=>$handoff];
            whatsapp_v2_history_write($job['from'], $turns, 0);
        }
        whatsapp_v2_activate_handoff($job['from'], 'frustration', $turns, $sent ? '' : $text);
        continue;
    }

    $answer = whatsapp_v2_sharky_answer($text, $turns);
    if ($answer === '') $answer = 'Sharky no puede responder ahora mismo. Intenta de nuevo en unos minutos.';
    $unresolved = hache_sharky_answer_needs_human($answer) ? ((int) $state['unresolved_count'] + 1) : 0;

    if ($unresolved >= $threshold) {
        $answer = 'Para no hacerte dar vueltas, te dejo con el equipo de Hache Natación. Una persona continuará contigo por este mismo chat.';
        $sent = whatsapp_v2_send_text($job['from'], $answer);
        if ($sent) {
            $turns[] = ['role'=>'user', 'content'=>$text];
            $turns[] = ['role'=>'assistant', 'content'=>$answer];
            whatsapp_v2_history_write($job['from'], $turns, 0);
        }
        whatsapp_v2_activate_handoff($job['from'], 'unresolved', $turns, $sent ? '' : $text);
        continue;
    }

    $sent = whatsapp_v2_send_text($job['from'], $answer);
    if ($sent) {
        $turns[] = ['role'=>'user', 'content'=>$text];
        $turns[] = ['role'=>'assistant', 'content'=>$answer];
        whatsapp_v2_history_write($job['from'], $turns, $unresolved);
    }
    error_log('[whatsapp-webhook-v2] processed message type='.$job['type'].' sent='.($sent ? '1' : '0'));
}

exit;
