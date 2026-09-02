<?php

declare(strict_types=1);

header('Cache-Control: no-store');

function whatsapp_verify_token(): string
{
    $token = trim((string) getenv('WHATSAPP_VERIFY_TOKEN'));
    if ($token !== '') {
        return $token;
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
            if (!str_starts_with($line, 'WHATSAPP_VERIFY_TOKEN=')) {
                continue;
            }
            $value = trim(substr($line, strlen('WHATSAPP_VERIFY_TOKEN=')));
            return trim($value, "\"'");
        }
    }

    return '';
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $mode = (string) ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
    $verifyToken = (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
    $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
    $expectedToken = whatsapp_verify_token();

    if ($mode === 'subscribe' && $expectedToken !== '' && hash_equals($expectedToken, $verifyToken)) {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(200);
        echo $challenge;
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Webhook verification failed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw === false) {
        $raw = '';
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Meta retries webhooks when the receiver does not acknowledge quickly.
    // Keep this endpoint deliberately lightweight until message processing is
    // wired to Sharky in a separate step.
    error_log('[whatsapp-webhook] '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    header('Content-Type: application/json; charset=utf-8');
    http_response_code(200);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

header('Allow: GET, POST');
header('Content-Type: application/json; charset=utf-8');
http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
