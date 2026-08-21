<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('hache_session');
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'use_strict_mode' => true,
    ]);
}

function auth_user(): ?array
{
    $u = $_SESSION['hache_usuario'] ?? null;
    return is_array($u) ? $u : null;
}

function auth_login(array $user): void
{
    session_regenerate_id(true);
    $sedeClave = !empty($user['sede_clave']) ? strtoupper((string)$user['sede_clave']) : null;
    $_SESSION['hache_usuario'] = [
        'id' => (string)$user['id'],
        'usuario' => (string)$user['usuario'],
        'rol' => (string)$user['rol'],
        'alumno_id' => $user['alumno_id'] ?: null,
        'sede_id' => !empty($user['sede_id']) ? (string)$user['sede_id'] : null,
        'sede_clave' => $sedeClave,
        'sede_nombre' => !empty($user['sede_nombre']) ? (string)$user['sede_nombre'] : null,
        'sede_activa' => (($user['rol'] ?? '') === 'VERIFICADOR' && in_array($sedeClave, ['MONTEVERDE','PALAPAS'], true)) ? $sedeClave : 'MONTEVERDE',
        'debe_cambiar_password' => !empty($user['debe_cambiar_password']) ? 1 : 0,
    ];
}

function auth_refresh_password_flag(bool $mustChange): void
{
    if (isset($_SESSION['hache_usuario']) && is_array($_SESSION['hache_usuario'])) {
        $_SESSION['hache_usuario']['debe_cambiar_password'] = $mustChange ? 1 : 0;
    }
}

function auth_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function auth_require(array $roles = [], bool $allowForcedPassword = false): array
{
    $u = auth_user();
    if (!$u) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>'Sesión no iniciada'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$allowForcedPassword && !empty($u['debe_cambiar_password'])) {
        http_response_code(428);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>'Debes cambiar tu contraseña antes de continuar','password_change_required'=>true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($roles && !in_array($u['rol'], $roles, true)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>'No tienes permiso para realizar esta acción'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $u;
}

function auth_active_sede_clave(): string
{
    $u = auth_user();
    if (!$u) return 'MONTEVERDE';
    if (($u['rol'] ?? '') === 'VERIFICADOR') {
        $own = strtoupper(trim((string)($u['sede_clave'] ?? '')));
        return in_array($own, ['MONTEVERDE','PALAPAS'], true) ? $own : 'MONTEVERDE';
    }
    $active = strtoupper(trim((string)($u['sede_activa'] ?? 'MONTEVERDE')));
    return in_array($active, ['MONTEVERDE','PALAPAS'], true) ? $active : 'MONTEVERDE';
}

function auth_set_active_sede(string $clave): string
{
    $u = auth_user();
    if (!$u) {
        throw new RuntimeException('Sesión no iniciada');
    }
    $clave = strtoupper(trim($clave));
    if (!in_array($clave, ['MONTEVERDE','PALAPAS'], true)) {
        throw new InvalidArgumentException('Sede inválida');
    }
    if (($u['rol'] ?? '') === 'VERIFICADOR') {
        $own = strtoupper(trim((string)($u['sede_clave'] ?? '')));
        if ($clave !== $own) throw new RuntimeException('No tienes permiso para cambiar de sede');
        $_SESSION['hache_usuario']['sede_activa'] = $own;
        return $own;
    }
    $_SESSION['hache_usuario']['sede_activa'] = $clave;
    return $clave;
}

function auth_resolve_sede_clave(?string $requested = null): string
{
    $u = auth_user();
    if ($u) return auth_active_sede_clave();
    $req = strtoupper(trim((string)$requested));
    return in_array($req, ['MONTEVERDE','PALAPAS'], true) ? $req : 'MONTEVERDE';
}

function page_require(array $roles = [], bool $allowForcedPassword = false): array
{
    $u = auth_user();
    if (!$u) {
        header('Location: /');
        exit;
    }
    if (!$allowForcedPassword && !empty($u['debe_cambiar_password'])) {
        header('Location: /cambiar-password.php');
        exit;
    }
    if ($roles && !in_array($u['rol'], $roles, true)) {
        header('Location: '.($u['rol']==='ALUMNO' ? '/mi-cuenta.php' : '/dashboard.php'));
        exit;
    }
    return $u;
}
