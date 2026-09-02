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

    // Acknowledge quickly so Meta does not retry. Until Sharky processing is
    // wired in, log only non-sensitive envelope metadata rather than message
    // contents or customer phone numbers.
    $object = is_string($payload['object'] ?? null) ? $payload['object'] : 'unknown';
    $entryCount = is_array($payload['entry'] ?? null) ? count($payload['entry']) : 0;
    error_log(sprintf('[whatsapp-webhook] accepted object=%s entries=%d', $object, $entryCount));

    whatsapp_json(200, ['ok' => true]);
}

header('Allow: GET, POST');
whatsapp_json(405, ['ok' => false, 'error' => 'Method not allowed']);
