<?php

declare(strict_types=1);

// Compuerta central de seguridad para los endpoints de la API.
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
if (str_starts_with($uri, '/api/')) {
    $publicApi = ['/api/login.php','/api/health.php','/api/sesion.php','/api/cambiar-password.php'];
    if (!in_array($uri, $publicApi, true)) {
        require_once __DIR__ . '/auth.php';
        if ($uri === '/api/portal-alumno.php') {
            auth_require(['ALUMNO']);
        } else {
            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            if (in_array($method, ['GET','HEAD'], true)) auth_require(['ADMIN','VERIFICADOR']);
            else auth_require(['ADMIN']);
        }
    }
}

$localConfig = __DIR__ . '/database.local.php';
$config = is_file($localConfig) ? require $localConfig : [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'dbname' => getenv('DB_NAME') ?: 'hache_natacion',
    'user' => getenv('DB_USER') ?: '',
    'password' => getenv('DB_PASS') ?: '',
    'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
];

// Auditoría genérica de toda mutación de API. No interfiere con la respuesta si la tabla aún no existe.
if (str_starts_with($uri, '/api/')) {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $skipAudit = ['/api/login.php','/api/health.php','/api/sesion.php','/api/cambiar-password.php','/api/auditoria.php'];
    if (!in_array($method, ['GET','HEAD','OPTIONS'], true) && !in_array($uri, $skipAudit, true)) {
        $auditConfig = $config;
        $auditUri = $uri;
        $auditMethod = $method;
        $auditStarted = microtime(true);
        register_shutdown_function(static function() use ($auditConfig,$auditUri,$auditMethod,$auditStarted): void {
            try {
                $status = http_response_code();
                if ($status >= 500) $accion = 'ERROR';
                elseif ($auditMethod === 'DELETE') $accion = 'ELIMINAR';
                elseif ($auditMethod === 'POST') $accion = 'CREAR_O_EJECUTAR';
                elseif (in_array($auditMethod,['PUT','PATCH'],true)) $accion = 'MODIFICAR';
                else $accion = $auditMethod;
                $u = function_exists('auth_user') ? auth_user() : null;
                $pdo = new PDO(
                    "mysql:host={$auditConfig['host']};dbname={$auditConfig['dbname']};charset={$auditConfig['charset']}",
                    $auditConfig['user'],$auditConfig['password'],
                    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
                );
                $exists = $pdo->query("SHOW TABLES LIKE 'auditoria_eventos'")->fetchColumn();
                if (!$exists) return;
                $entidad = preg_replace('/\.php$/','',basename($auditUri)) ?: 'api';
                $detalle = json_encode([
                    'http_status'=>$status,
                    'duracion_ms'=>(int)round((microtime(true)-$auditStarted)*1000)
                ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $st=$pdo->prepare("INSERT INTO auditoria_eventos(usuario_id,usuario_nombre,accion,entidad,detalle,metodo,ruta,ip) VALUES(:uid,:un,:ac,:en,:de,:me,:ru,:ip)");
                $st->execute([
                    ':uid'=>$u['id']??null, ':un'=>$u['usuario']??null, ':ac'=>$accion, ':en'=>$entidad,
                    ':de'=>$detalle, ':me'=>$auditMethod, ':ru'=>$auditUri, ':ip'=>$_SERVER['REMOTE_ADDR']??null
                ]);
            } catch (Throwable $ignored) {}
        });
    }
}

return $config;
