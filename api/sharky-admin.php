<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/sharky-runtime.php';

$me = auth_require(['ADMIN']);

function sharky_admin_out(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sharky_admin_valid_https_url(string $value, array $allowedHosts): bool
{
    if (filter_var($value, FILTER_VALIDATE_URL) === false) return false;
    $parts = parse_url($value);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') return false;
    $host = strtolower((string)($parts['host'] ?? ''));
    return in_array($host, $allowedHosts, true);
}

function sharky_admin_valid_value(string $key, string $value): bool
{
    if (mb_strlen($value) > 220) return false;
    if ($key === 'sharky_whatsapp') return preg_match('/^\d{7,15}$/', preg_replace('/\D+/', '', $value) ?: '') === 1;
    if (in_array($key, ['sharky_link_registro_monteverde','sharky_link_registro_palapas'], true)) {
        return sharky_admin_valid_https_url($value, ['go.hnatacion.com']);
    }
    if (in_array($key, ['sharky_maps_monteverde','sharky_maps_palapas'], true)) {
        return sharky_admin_valid_https_url($value, ['maps.app.goo.gl','maps.google.com','google.com','www.google.com']);
    }
    if ($key === 'sharky_pago_clabe') return preg_match('/^\d{18}$/', $value) === 1;
    if (in_array($key, ['sharky_pago_institucion','sharky_pago_beneficiario'], true)) return $value !== '' && mb_strlen($value) <= 100;
    if ($key === 'sharky_audio_habilitado') return in_array($value, ['0', '1'], true);
    if ($key === 'sharky_edad_minima') return ctype_digit($value) && (int) $value >= 1 && (int) $value <= 99;
    if ($key === 'sharky_recargo_tarjeta_pct') return is_numeric($value) && (float) $value >= 0 && (float) $value <= 100;
    if ($key === 'sharky_audio_max_mb') return ctype_digit($value) && (int) $value >= 1 && (int) $value <= 20;
    if ($key === 'sharky_escalado_intentos') return ctype_digit($value) && (int) $value >= 1 && (int) $value <= 5;
    return is_numeric($value) && (float) $value >= 0 && (float) $value <= 1000000;
}

$pdo = hache_sharky_pdo();
if (!$pdo) sharky_admin_out(['ok'=>false, 'error'=>'No se pudo conectar con la configuración'], 503);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    $defaults = hache_sharky_config_defaults();
    $values = hache_sharky_business_values($pdo);
    $config = [];
    foreach ($defaults as $key => $row) {
        $config[] = [
            'clave'=>$key,
            'valor'=>(string) ($values[$key] ?? $row['valor']),
            'descripcion'=>(string) $row['descripcion'],
        ];
    }
    $metrics = hache_sharky_metrics(7);
    $totals = [];
    foreach ($metrics as $day) {
        foreach (($day['counters'] ?? []) as $key => $value) {
            $totals[$key] = (int) ($totals[$key] ?? 0) + (int) $value;
        }
    }
    sharky_admin_out(['ok'=>true, 'admin'=>true, 'configuracion'=>$config, 'takeovers'=>hache_sharky_takeover_list(), 'metrics'=>$metrics, 'totals'=>$totals]);
}

if ($method !== 'POST') sharky_admin_out(['ok'=>false, 'error'=>'Método no permitido'], 405);
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) sharky_admin_out(['ok'=>false, 'error'=>'Solicitud JSON inválida'], 400);
$action = strtoupper(trim((string) ($input['accion'] ?? '')));

if ($action === 'RESUME') {
    $hash = strtolower(trim((string) ($input['contact_hash'] ?? '')));
    if (!preg_match('/^[a-f0-9]{64}$/', $hash)) sharky_admin_out(['ok'=>false, 'error'=>'Conversación inválida'], 422);
    if (!hache_sharky_takeover_resume_hash($hash)) sharky_admin_out(['ok'=>false, 'error'=>'La conversación ya estaba activa o no existe'], 404);
    sharky_admin_out(['ok'=>true, 'mensaje'=>'Sharky reactivado para esa conversación']);
}

if ($action === 'CONFIG') {
    $key = trim((string) ($input['clave'] ?? ''));
    $value = trim((string) ($input['valor'] ?? ''));
    $defaults = hache_sharky_config_defaults();
    if (!isset($defaults[$key]) || !sharky_admin_valid_value($key, $value)) sharky_admin_out(['ok'=>false, 'error'=>'Valor de configuración inválido'], 422);
    if ($key === 'sharky_whatsapp') $value = preg_replace('/\D+/', '', $value) ?: '';
    $stmt = $pdo->prepare(
        'INSERT INTO configuracion(clave,valor,descripcion,updated_by,updated_at) VALUES(:clave,:valor,:descripcion,:usuario,NOW()) '
        .'ON DUPLICATE KEY UPDATE valor=VALUES(valor),descripcion=VALUES(descripcion),updated_by=VALUES(updated_by),updated_at=NOW()'
    );
    $stmt->execute([':clave'=>$key, ':valor'=>$value, ':descripcion'=>(string) $defaults[$key]['descripcion'], ':usuario'=>(string) $me['id']]);
    hache_sharky_metric_increment('config_updates');
    sharky_admin_out(['ok'=>true]);
}

sharky_admin_out(['ok'=>false, 'error'=>'Acción inválida'], 422);
