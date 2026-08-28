<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
require_once __DIR__ . '/../config/auth.php';
$me = auth_require(['ADMIN']);
$config = require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/pasarelas.php';

$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
    $config['user'],
    $config['password'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

function mp_out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mp_panel_payload(?array $row): array
{
    return [
        'activo' => $row ? (bool)$row['activo'] : false,
        'entorno' => $row && strtoupper((string)$row['entorno']) === 'PRODUCTION' ? 'PRODUCTION' : 'TEST',
        'public_key' => $row ? trim((string)$row['public_key']) : '',
        'access_token_configurado' => $row && trim((string)($row['access_token_enc'] ?? '')) !== '',
        'webhook_secret_configurado' => $row && trim((string)($row['webhook_secret_enc'] ?? '')) !== '',
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'GET') {
    mp_out(['ok' => true, 'mercadopago' => mp_panel_payload(pasarela_mercadopago_fila($pdo))]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    mp_out(['ok' => false, 'error' => 'Método no permitido'], 405);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) mp_out(['ok' => false, 'error' => 'Solicitud JSON inválida'], 400);
$accion = strtoupper(trim((string)($input['accion'] ?? '')));

if ($accion === 'GUARDAR') {
    $activo = !empty($input['activo']) ? 1 : 0;
    $entorno = strtoupper(trim((string)($input['entorno'] ?? 'TEST')));
    $publicKey = trim((string)($input['public_key'] ?? ''));
    $accessToken = trim((string)($input['access_token'] ?? ''));
    $webhookSecret = trim((string)($input['webhook_secret'] ?? ''));
    $clearAccess = !empty($input['limpiar_access_token']);
    $clearWebhook = !empty($input['limpiar_webhook_secret']);

    if (!in_array($entorno, ['TEST', 'PRODUCTION'], true)) {
        mp_out(['ok' => false, 'error' => 'Entorno de Mercado Pago inválido'], 422);
    }
    if (mb_strlen($publicKey) > 255 || mb_strlen($accessToken) > 500 || mb_strlen($webhookSecret) > 500) {
        mp_out(['ok' => false, 'error' => 'Credencial de Mercado Pago demasiado larga'], 422);
    }
    if ($publicKey !== '' && preg_match('/\s/', $publicKey)) {
        mp_out(['ok' => false, 'error' => 'La Public Key no puede contener espacios'], 422);
    }
    if ($accessToken !== '' && preg_match('/\s/', $accessToken)) {
        mp_out(['ok' => false, 'error' => 'El Access Token no puede contener espacios'], 422);
    }

    try {
        $pdo->beginTransaction();
        $row = pasarela_mercadopago_fila($pdo, true);
        if (!$row) {
            $pdo->exec("INSERT INTO pasarelas_pago_config(proveedor,activo,entorno) VALUES('mercadopago',0,'TEST')");
            $row = pasarela_mercadopago_fila($pdo, true);
        }

        $accessEnc = $clearAccess ? '' : (string)($row['access_token_enc'] ?? '');
        $webhookEnc = $clearWebhook ? '' : (string)($row['webhook_secret_enc'] ?? '');
        if ($accessToken !== '') $accessEnc = pasarela_cifrar($accessToken);
        if ($webhookSecret !== '') $webhookEnc = pasarela_cifrar($webhookSecret);

        if ($activo && ($publicKey === '' || $accessEnc === '')) {
            $pdo->rollBack();
            mp_out(['ok' => false, 'error' => 'Para activar Mercado Pago necesitas Public Key y Access Token'], 422);
        }

        $stmt = $pdo->prepare(
            "UPDATE pasarelas_pago_config
             SET activo=:activo,entorno=:entorno,public_key=:public_key,
                 access_token_enc=:access_token,webhook_secret_enc=:webhook_secret,
                 updated_by=:usuario,updated_at=NOW()
             WHERE proveedor='mercadopago'"
        );
        $stmt->execute([
            ':activo' => $activo,
            ':entorno' => $entorno,
            ':public_key' => $publicKey !== '' ? $publicKey : null,
            ':access_token' => $accessEnc !== '' ? $accessEnc : null,
            ':webhook_secret' => $webhookEnc !== '' ? $webhookEnc : null,
            ':usuario' => $me['id'],
        ]);
        $pdo->commit();

        mp_out(['ok' => true, 'mercadopago' => mp_panel_payload(pasarela_mercadopago_fila($pdo))]);
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        mp_out(['ok' => false, 'error' => $e->getMessage()], 503);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Mercado Pago config save: ' . $e->getMessage());
        mp_out(['ok' => false, 'error' => 'No se pudo guardar la configuración de Mercado Pago'], 500);
    }
}

if ($accion === 'PROBAR') {
    try {
        $credentials = pasarela_mercadopago_credenciales($pdo);
        if ($credentials['access_token'] === '') {
            mp_out(['ok' => false, 'error' => 'No hay un Access Token configurado'], 422);
        }
        if (!function_exists('curl_init')) {
            mp_out(['ok' => false, 'error' => 'El servidor no tiene cURL disponible para probar la conexión'], 501);
        }

        $ch = curl_init('https://api.mercadopago.com/users/me');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $credentials['access_token'],
                'Accept: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($response === false) {
            error_log('Mercado Pago connection test: ' . $curlError);
            mp_out(['ok' => false, 'error' => 'No se pudo contactar a Mercado Pago'], 502);
        }
        if ($status !== 200) {
            mp_out(['ok' => false, 'error' => 'Mercado Pago rechazó las credenciales configuradas'], 422);
        }

        $remote = json_decode((string)$response, true);
        mp_out([
            'ok' => true,
            'mensaje' => 'Conexión con Mercado Pago verificada',
            'entorno' => $credentials['entorno'],
            'cuenta_id' => is_array($remote) && isset($remote['id']) ? (string)$remote['id'] : null,
        ]);
    } catch (RuntimeException $e) {
        mp_out(['ok' => false, 'error' => $e->getMessage()], 503);
    } catch (Throwable $e) {
        error_log('Mercado Pago connection test: ' . $e->getMessage());
        mp_out(['ok' => false, 'error' => 'No se pudo probar la conexión con Mercado Pago'], 500);
    }
}

mp_out(['ok' => false, 'error' => 'Acción inválida'], 422);
