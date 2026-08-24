<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/auth.php';

$usuario = auth_require(['ADMIN']);
$config = require __DIR__ . '/../config/database.php';

function push_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function push_endpoint(mixed $value): string
{
    $endpoint = trim((string)$value);
    if ($endpoint === '' || strlen($endpoint) > 4096 || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
        push_out(['ok'=>false,'error'=>'Endpoint inválido'], 422);
    }
    $scheme = strtolower((string)parse_url($endpoint, PHP_URL_SCHEME));
    if ($scheme !== 'https') {
        push_out(['ok'=>false,'error'=>'Endpoint inválido'], 422);
    }
    return $endpoint;
}

$keysFile = __DIR__ . '/../config/push-keys.php';
if (!is_file($keysFile)) {
    push_out(['ok'=>false,'error'=>'Push no configurado en el servidor'], 503);
}
$keys = require $keysFile;

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
        $config['user'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]
    );

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        push_out(['ok'=>true,'publicKey'=>(string)($keys['publicKey'] ?? '')]);
    }

    if (!in_array($method, ['POST','DELETE'], true)) {
        push_out(['ok'=>false,'error'=>'Método no permitido'], 405);
    }

    $raw = file_get_contents('php://input');
    $input = json_decode((string)$raw, true);
    if (!is_array($input) || json_last_error() !== JSON_ERROR_NONE) {
        push_out(['ok'=>false,'error'=>'JSON inválido'], 400);
    }

    if ($method === 'POST') {
        $subscription = $input['subscription'] ?? null;
        if (!is_array($subscription) || !is_array($subscription['keys'] ?? null)) {
            push_out(['ok'=>false,'error'=>'Suscripción inválida'], 422);
        }

        $endpoint = push_endpoint($subscription['endpoint'] ?? null);
        $p256dh = trim((string)($subscription['keys']['p256dh'] ?? ''));
        $auth = trim((string)($subscription['keys']['auth'] ?? ''));
        $encoding = strtolower(trim((string)($subscription['contentEncoding'] ?? 'aes128gcm')));
        if ($p256dh === '' || strlen($p256dh) > 512 || $auth === '' || strlen($auth) > 256) {
            push_out(['ok'=>false,'error'=>'Suscripción inválida'], 422);
        }
        if (!in_array($encoding, ['aes128gcm','aesgcm'], true)) {
            push_out(['ok'=>false,'error'=>'Codificación push inválida'], 422);
        }

        $id = (string)$pdo->query('SELECT UUID()')->fetchColumn();
        $stmt = $pdo->prepare(
            'INSERT INTO push_subscriptions(id,usuario_id,endpoint,p256dh,auth,content_encoding,user_agent,activo) '
            . 'VALUES(:id,:u,:e,:p,:a,:c,:ua,1) '
            . 'ON DUPLICATE KEY UPDATE usuario_id=VALUES(usuario_id),p256dh=VALUES(p256dh),auth=VALUES(auth),'
            . 'content_encoding=VALUES(content_encoding),user_agent=VALUES(user_agent),activo=1,updated_at=NOW()'
        );
        $stmt->execute([
            ':id'=>$id,
            ':u'=>$usuario['id'],
            ':e'=>$endpoint,
            ':p'=>$p256dh,
            ':a'=>$auth,
            ':c'=>$encoding,
            ':ua'=>substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
        push_out(['ok'=>true]);
    }

    $endpoint = push_endpoint($input['endpoint'] ?? null);
    $stmt = $pdo->prepare('UPDATE push_subscriptions SET activo=0 WHERE usuario_id=:u AND endpoint=:e');
    $stmt->execute([':u'=>$usuario['id'],':e'=>$endpoint]);
    push_out(['ok'=>true]);
} catch (Throwable $e) {
    error_log('api/push.php: '.$e->getMessage());
    push_out(['ok'=>false,'error'=>'No se pudo actualizar la suscripción push.'], 500);
}
