<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/sharky-runtime.php';
require_once __DIR__.'/../config/sharky-groups.php';

$me = auth_require(['ADMIN']);

function sharky_admin_out(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
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
            'tipo'=>'text',
        ];
    }
    $groupConfig=hache_sharky_groups_config_row();
    $groupConfig['valor']=hache_sharky_groups_enabled($pdo)?'1':'0';
    $groupConfig['tipo']='checkbox';
    $config[]=$groupConfig;

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
    $description='';
    if ($key === HACHE_SHARKY_GROUPS_KEY) {
        if (!hache_sharky_groups_config_valid($value)) sharky_admin_out(['ok'=>false, 'error'=>'Valor de configuración inválido'], 422);
        $description=(string)hache_sharky_groups_config_row()['descripcion'];
    } else {
        if (!isset($defaults[$key]) || !hache_sharky_config_value_valid($key, $value)) sharky_admin_out(['ok'=>false, 'error'=>'Valor de configuración inválido'], 422);
        if ($key === 'sharky_whatsapp') $value = preg_replace('/\D+/', '', $value) ?: '';
        $description=(string)$defaults[$key]['descripcion'];
    }
    $stmt = $pdo->prepare(
        'INSERT INTO configuracion(clave,valor,descripcion,updated_by,updated_at) VALUES(:clave,:valor,:descripcion,:usuario,NOW()) '
        .'ON DUPLICATE KEY UPDATE valor=VALUES(valor),descripcion=VALUES(descripcion),updated_by=VALUES(updated_by),updated_at=NOW()'
    );
    $stmt->execute([':clave'=>$key, ':valor'=>$value, ':descripcion'=>$description, ':usuario'=>(string) $me['id']]);
    hache_sharky_metric_increment('config_updates');
    sharky_admin_out(['ok'=>true]);
}

sharky_admin_out(['ok'=>false, 'error'=>'Acción inválida'], 422);
