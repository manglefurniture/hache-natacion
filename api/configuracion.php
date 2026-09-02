<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';

$me = auth_require(['ADMIN','VERIFICADOR']);
$config = require __DIR__.'/../config/database.php';
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

function out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$sedeClave = auth_active_sede_clave();
$stmt = $pdo->prepare('SELECT id,nombre,clave FROM sedes WHERE clave=:clave AND activo=1 LIMIT 1');
$stmt->execute([':clave' => $sedeClave]);
$sede = $stmt->fetch();
if (!$sede) out(['ok'=>false,'error'=>'Sede activa inválida'], 422);
$sedeId = (string)$sede['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if (($me['rol'] ?? '') === 'ADMIN') {
        $cfg = $pdo->query('SELECT clave,valor,descripcion,updated_at FROM configuracion ORDER BY clave')->fetchAll();
        // Las claves de Sharky tienen validaciones más estrictas y se administran solo
        // desde /sharky-admin.php. No se exponen aquí para evitar dos superficies de edición.
        $cfg = array_values(array_filter($cfg, static fn(array $row): bool => !str_starts_with((string)($row['clave'] ?? ''), 'sharky_')));
    } else {
        $visibles = ['nombre_app','dias_clase','version_app','alerta_dias_fin_intensivo','minimo_proa_mensual'];
        $marcas = implode(',', array_fill(0, count($visibles), '?'));
        $cfgStmt = $pdo->prepare("SELECT clave,valor,descripcion,updated_at FROM configuracion WHERE clave IN ($marcas) ORDER BY clave");
        $cfgStmt->execute($visibles);
        $cfg = $cfgStmt->fetchAll();
    }
    $stmt = $pdo->prepare('SELECT id,nombre,sesiones_semana,precio,activo FROM planes WHERE sede_id=:sede ORDER BY activo DESC,sesiones_semana,nombre');
    $stmt->execute([':sede' => $sedeId]);
    out(['ok'=>true,'sede'=>['clave'=>$sede['clave'],'nombre'=>$sede['nombre']],'configuracion'=>$cfg,'planes'=>$stmt->fetchAll()]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(['ok'=>false,'error'=>'Método no permitido'], 405);
if (($me['rol'] ?? '') !== 'ADMIN') out(['ok'=>false,'error'=>'No tienes permiso para modificar la configuración'], 403);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) out(['ok'=>false,'error'=>'Solicitud JSON inválida'], 400);
$accion = strtoupper(trim((string)($input['accion'] ?? '')));

if ($accion === 'CONFIG') {
    $clave = trim((string)($input['clave'] ?? ''));
    $valor = trim((string)($input['valor'] ?? ''));
    if (!preg_match('/^[a-z][a-z0-9_]{1,79}$/', $clave) || mb_strlen($valor) > 500) {
        out(['ok'=>false,'error'=>'Parámetro de configuración inválido'], 422);
    }
    if (str_starts_with($clave, 'sharky_')) {
        out(['ok'=>false,'error'=>'La configuración de Sharky se modifica únicamente desde Sharky Admin'], 422);
    }
    $stmt = $pdo->prepare('UPDATE configuracion SET valor=:valor,updated_by=:usuario,updated_at=NOW() WHERE clave=:clave');
    $stmt->execute([':valor'=>$valor,':usuario'=>$me['id'],':clave'=>$clave]);
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare('SELECT 1 FROM configuracion WHERE clave=:clave');
        $check->execute([':clave'=>$clave]);
        if (!$check->fetchColumn()) out(['ok'=>false,'error'=>'Parámetro no encontrado'], 404);
    }
    out(['ok'=>true]);
}

if ($accion === 'PLAN') {
    $id = trim((string)($input['id'] ?? ''));
    $nombre = trim((string)($input['nombre'] ?? ''));
    $sesiones = filter_var($input['sesiones_semana'] ?? null, FILTER_VALIDATE_INT);
    $precioRaw = $input['precio'] ?? null;
    $precio = is_numeric($precioRaw) ? (float)$precioRaw : -1;
    $activo = !empty($input['activo']) ? 1 : 0;
    if ($nombre === '' || mb_strlen($nombre) > 100 || $sesiones === false || $sesiones < 1 || $sesiones > 7 || $precio < 0 || $precio > 100000) {
        out(['ok'=>false,'error'=>'Datos de plan inválidos'], 422);
    }

    try {
        if ($id !== '') {
            $stmt = $pdo->prepare('UPDATE planes SET nombre=:nombre,sesiones_semana=:sesiones,precio=:precio,activo=:activo WHERE id=:id AND sede_id=:sede');
            $stmt->execute([':nombre'=>$nombre,':sesiones'=>$sesiones,':precio'=>$precio,':activo'=>$activo,':id'=>$id,':sede'=>$sedeId]);
            if ($stmt->rowCount() === 0) {
                $check = $pdo->prepare('SELECT 1 FROM planes WHERE id=:id AND sede_id=:sede');
                $check->execute([':id'=>$id,':sede'=>$sedeId]);
                if (!$check->fetchColumn()) out(['ok'=>false,'error'=>'Plan no encontrado en la sede activa'], 404);
            }
        } else {
            $stmt = $pdo->prepare('INSERT INTO planes(id,sede_id,nombre,sesiones_semana,precio,activo) VALUES(UUID(),:sede,:nombre,:sesiones,:precio,:activo)');
            $stmt->execute([':sede'=>$sedeId,':nombre'=>$nombre,':sesiones'=>$sesiones,':precio'=>$precio,':activo'=>$activo]);
        }
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') out(['ok'=>false,'error'=>'Ya existe un plan con ese nombre o número de sesiones en esta sede'], 409);
        throw $e;
    }
    out(['ok'=>true]);
}

out(['ok'=>false,'error'=>'Acción inválida'], 422);
