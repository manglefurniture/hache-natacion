<?php

declare(strict_types=1);

// Compuerta central de seguridad para los endpoints de la API.
// Todas las APIs productivas cargan este archivo antes de abrir MariaDB.
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
if (str_starts_with($uri, '/api/')) {
    $publicApi = ['/api/login.php','/api/health.php','/api/sesion.php','/api/cambiar-password.php'];
    if (!in_array($uri, $publicApi, true)) {
        require_once __DIR__ . '/auth.php';
        if ($uri === '/api/portal-alumno.php') {
            auth_require(['ALUMNO']);
        } else {
            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            if (in_array($method, ['GET','HEAD'], true)) {
                auth_require(['ADMIN','VERIFICADOR']);
            } else {
                auth_require(['ADMIN']);
            }
        }
    }
}

$localConfig = __DIR__ . '/database.local.php';

if (is_file($localConfig)) {
    return require $localConfig;
}

return [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'dbname' => getenv('DB_NAME') ?: 'hache_natacion',
    'user' => getenv('DB_USER') ?: '',
    'password' => getenv('DB_PASS') ?: '',
    'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
];
