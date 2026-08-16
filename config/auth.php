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
    $_SESSION['hache_usuario'] = [
        'id' => (string)$user['id'],
        'usuario' => (string)$user['usuario'],
        'rol' => (string)$user['rol'],
        'alumno_id' => $user['alumno_id'] ?: null,
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
