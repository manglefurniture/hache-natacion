<?php

declare(strict_types=1);

header('Cache-Control: no-store');

function whatsapp_secret(string $name): string
{
    $value = trim((string) getenv($name));
    if ($value !== '') {
        return $value;
    }

    $envFiles = [
        dirname(__DIR__, 2).'/.env',
        dirname(__DIR__, 3).'/.env',
    ];

    foreach ($envFiles as $envFile) {
        if (!is_readable($envFile)) {
            continue;
        }
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            if (!str_starts_with($line, $name.'=')) {
                continue;
            }
            $value = trim(substr($line, strlen($name) + 1));
            return trim($value, "\"'");
        }
    }

    return '';
}

function whatsapp_json(int $status, array $body): never
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function whatsapp_acknowledge(): void
{
    $body = json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"ok":true}';
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

function whatsapp_graph_version(): string
{
    $version = whatsapp_secret('WHATSAPP_GRAPH_VERSION');
    return preg_match('/^v\d+\.\d+$/', $version) === 1 ? $version : 'v26.0';
}

function whatsapp_extract_text_messages(array $payload): array
{
    $messages = [];

    foreach (($payload['entry'] ?? []) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        foreach (($entry['changes'] ?? []) as $change) {
            if (!is_array($change)) {
                continue;
            }
            $value = $change['value'] ?? null;
            if (!is_array($value)) {
                continue;
            }

            $phoneNumberId = trim((string) ($value['metadata']['phone_number_id'] ?? ''));
            foreach (($value['messages'] ?? []) as $message) {
                if (!is_array($message) || ($message['type'] ?? '') !== 'text') {
                    continue;
                }

                $id = trim((string) ($message['id'] ?? ''));
                $from = preg_replace('/\D+/', '', (string) ($message['from'] ?? '')) ?: '';
                $text = trim((string) ($message['text']['body'] ?? ''));
                if ($id === '' || $from === '' || $text === '') {
                    continue;
                }

                $messages[] = [
                    'id' => $id,
                    'from' => $from,
                    'text' => mb_substr($text, 0, 700),
                    'phone_number_id' => $phoneNumberId,
                ];
            }
        }
    }

    return $messages;
}

function whatsapp_extract_business_echoes(array $payload): array
{
    $echoes = [];

    foreach (($payload['entry'] ?? []) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        foreach (($entry['changes'] ?? []) as $change) {
            if (!is_array($change) || ($change['field'] ?? '') !== 'smb_message_echoes') {
                continue;
            }
            $value = $change['value'] ?? null;
            if (!is_array($value)) {
                continue;
            }

            $phoneNumberId = trim((string) ($value['metadata']['phone_number_id'] ?? ''));
            foreach (($value['message_echoes'] ?? []) as $echo) {
                if (!is_array($echo)) {
                    continue;
                }

                $id = trim((string) ($echo['id'] ?? ''));
                $to = preg_replace('/\D+/', '', (string) ($echo['to'] ?? '')) ?: '';
                if ($id === '' || $to === '') {
                    continue;
                }

                $echoes[] = [
                    'id' => $id,
                    'to' => $to,
                    'phone_number_id' => $phoneNumberId,
                ];
            }
        }
    }

    return $echoes;
}

function whatsapp_claim_message(string $messageId): bool
{
    $dir = rtrim(sys_get_temp_dir(), '/').'/hache-whatsapp-dedupe';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        error_log('[whatsapp-webhook] dedupe directory unavailable');
        return true;
    }

    $path = $dir.'/'.hash('sha256', $messageId);
    $handle = @fopen($path, 'x');
    if ($handle === false) {
        return false;
    }
    fwrite($handle, (string) time());
    fclose($handle);

    if (random_int(1, 20) === 1) {
        $cutoff = time() - 172800;
        foreach (glob($dir.'/*') ?: [] as $candidate) {
            $mtime = @filemtime($candidate);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($candidate);
            }
        }
    }

    return true;
}

function whatsapp_history_key(string $from): string
{
    $secret = whatsapp_secret('META_APP_SECRET');
    return hash_hmac('sha256', $from, $secret !== '' ? $secret : 'hache-whatsapp-history');
}

function whatsapp_human_takeover_path(string $contact): string
{
    $candidates = [
        '/var/tmp/hache-whatsapp-human',
        rtrim(sys_get_temp_dir(), '/').'/hache-whatsapp-human',
    ];

    foreach ($candidates as $dir) {
        if ((is_dir($dir) || @mkdir($dir, 0700, true)) && is_writable($dir)) {
            return $dir.'/'.whatsapp_history_key($contact);
        }
    }

    return '';
}

function whatsapp_mark_human_takeover(string $contact): bool
{
    $path = whatsapp_human_takeover_path($contact);
    if ($path === '') {
        error_log('[whatsapp-webhook] human takeover storage unavailable');
        return false;
    }

    return @file_put_contents($path, (string) time(), LOCK_EX) !== false;
}

function whatsapp_human_takeover_active(string $contact): bool
{
    $path = whatsapp_human_takeover_path($contact);
    return $path !== '' && is_file($path);
}

function whatsapp_sharky_answer(string $message, array $history): string
{
    $payload = json_encode([
        'message' => $message,
        'history' => $history,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return '';
    }

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
        error_log('[whatsapp-webhook] Sharky request failed http='.$status);
        return '';
    }

    $result = json_decode($response, true);
    if (!is_array($result) || ($result['ok'] ?? false) !== true) {
        error_log('[whatsapp-webhook] Sharky returned an invalid response');
        return '';
    }

    return trim((string) ($result['answer'] ?? ''));
}

function whatsapp_answer_with_history(string $from, string $message): string
{
    $dir = rtrim(sys_get_temp_dir(), '/').'/hache-whatsapp-history';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        error_log('[whatsapp-webhook] history directory unavailable');
        return whatsapp_sharky_answer($message, []);
    }

    $path = $dir.'/'.whatsapp_history_key($from).'.json';
    $handle = @fopen($path, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        return whatsapp_sharky_answer($message, []);
    }

    rewind($handle);
    $raw = stream_get_contents($handle);
    $stored = json_decode(is_string($raw) ? $raw : '', true);
    $history = [];
    if (is_array($stored) && (int) ($stored['updated_at'] ?? 0) >= time() - 86400 && is_array($stored['turns'] ?? null)) {
        $history = array_slice($stored['turns'], -12);
    }

    $answer = whatsapp_sharky_answer($message, $history);
    if ($answer !== '') {
        $history[] = ['role' => 'user', 'content' => $message];
        $history[] = ['role' => 'assistant', 'content' => $answer];
        $stored = json_encode([
            'updated_at' => time(),
            'turns' => array_slice($history, -12),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($stored !== false) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $stored);
        }
    }

    flock($handle, LOCK_UN);
    fclose($handle);
    return $answer;
}

function whatsapp_send_text(string $to, string $body): bool
{
    $token = whatsapp_secret('WHATSAPP_ACCESS_TOKEN');
    $phoneNumberId = whatsapp_secret('WHATSAPP_PHONE_NUMBER_ID');
    if ($token === '' || $phoneNumberId === '') {
        error_log('[whatsapp-webhook] outbound credentials are not configured');
        return false;
    }

    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $to,
        'type' => 'text',
        'text' => [
            'preview_url' => false,
            'body' => mb_substr(trim($body), 0, 4000),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return false;
    }

    $url = sprintf(
        'https://graph.facebook.com/%s/%s/messages',
        rawurlencode(whatsapp_graph_version()),
        rawurlencode($phoneNumberId)
    );
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer '.$token,
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '' || $status < 200 || $status >= 300) {
        error_log('[whatsapp-webhook] outbound send failed http='.$status);
        return false;
    }

    return true;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    // PHP normalizes dots in query parameter names to underscores, so Meta's
    // hub.mode / hub.verify_token / hub.challenge arrive as hub_mode, etc.
    $mode = (string) ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
    $verifyToken = (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
    $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
    $expectedToken = whatsapp_secret('WHATSAPP_VERIFY_TOKEN');

    if ($mode === 'subscribe' && $expectedToken !== '' && hash_equals($expectedToken, $verifyToken)) {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(200);
        echo $challenge;
        exit;
    }

    whatsapp_json(403, ['ok' => false, 'error' => 'Webhook verification failed']);
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw === false) {
        $raw = '';
    }

    $appSecret = whatsapp_secret('META_APP_SECRET');
    if ($appSecret === '') {
        error_log('[whatsapp-webhook] META_APP_SECRET is not configured');
        whatsapp_json(503, ['ok' => false, 'error' => 'Webhook not configured']);
    }

    $signature = trim((string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''));
    $expectedSignature = 'sha256='.hash_hmac('sha256', $raw, $appSecret);
    if ($signature === '' || !hash_equals($expectedSignature, $signature)) {
        error_log('[whatsapp-webhook] rejected invalid signature');
        whatsapp_json(401, ['ok' => false, 'error' => 'Invalid signature']);
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        whatsapp_json(400, ['ok' => false, 'error' => 'Invalid JSON']);
    }

    $object = is_string($payload['object'] ?? null) ? $payload['object'] : 'unknown';
    $entryCount = is_array($payload['entry'] ?? null) ? count($payload['entry']) : 0;
    error_log(sprintf('[whatsapp-webhook] accepted object=%s entries=%d', $object, $entryCount));

    $configuredPhoneId = whatsapp_secret('WHATSAPP_PHONE_NUMBER_ID');

    foreach (whatsapp_extract_business_echoes($payload) as $echo) {
        if ($configuredPhoneId !== '' && $echo['phone_number_id'] !== '' && !hash_equals($configuredPhoneId, $echo['phone_number_id'])) {
            continue;
        }
        if (whatsapp_mark_human_takeover($echo['to'])) {
            error_log('[whatsapp-webhook] human takeover activated');
        }
    }

    $jobs = [];
    foreach (whatsapp_extract_text_messages($payload) as $message) {
        if ($configuredPhoneId !== '' && $message['phone_number_id'] !== '' && !hash_equals($configuredPhoneId, $message['phone_number_id'])) {
            continue;
        }
        if (whatsapp_human_takeover_active($message['from'])) {
            error_log('[whatsapp-webhook] inbound text skipped human_takeover=1');
            continue;
        }
        if (!whatsapp_claim_message($message['id'])) {
            continue;
        }
        $jobs[] = $message;
    }

    whatsapp_acknowledge();
    ignore_user_abort(true);
    @set_time_limit(70);

    foreach ($jobs as $job) {
        // Re-check after acknowledging to close the race where a human reply
        // arrives while an inbound message is already queued for Sharky.
        if (whatsapp_human_takeover_active($job['from'])) {
            error_log('[whatsapp-webhook] queued text skipped human_takeover=1');
            continue;
        }

        $answer = whatsapp_answer_with_history($job['from'], $job['text']);
        if ($answer === '') {
            $answer = 'Sharky no puede responder ahora mismo. Intenta de nuevo en unos minutos.';
        }
        $sent = whatsapp_send_text($job['from'], $answer);
        error_log('[whatsapp-webhook] processed text message sent='.($sent ? '1' : '0'));
    }

    exit;
}

header('Allow: GET, POST');
whatsapp_json(405, ['ok' => false, 'error' => 'Method not allowed']);