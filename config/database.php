<?php

declare(strict_types=1);

// Compuerta central de seguridad para los endpoints de la API.
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
if (str_starts_with($uri, '/api/')) {
    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Cache-Control: no-store, max-age=0');
    }
    if (!defined('HACHE_API_ERROR_FILTER')) {
        define('HACHE_API_ERROR_FILTER', true);
        ob_start(static function (string $body): string {
            if (http_response_code() < 500 || $body === '') return $body;
            $isJson = false;
            foreach (headers_list() as $header) {
                if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'json') !== false) {
                    $isJson = true;
                    break;
                }
            }
            if (!$isJson) return $body;
            $payload = json_decode($body, true);
            if (!is_array($payload) || !array_key_exists('error', $payload)) return $body;
            error_log('Hache API error [' . ($_SERVER['REQUEST_URI'] ?? '') . ']: ' . (string)$payload['error']);
            $payload['error'] = 'No se pudo completar la operación. Intenta nuevamente.';
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $body;
        });
    }
    $publicApi = ['/api/login.php','/api/health.php','/api/sesion.php','/api/cambiar-password.php','/api/alumno-por-whatsapp.php'];
    if (!in_array($uri, $publicApi, true)) {
        require_once __DIR__ . '/auth.php';
        if ($uri === '/api/portal-alumno.php') {
            auth_require(['ALUMNO']);
        } elseif ($uri === '/api/diagnostico.php') {
            auth_require(['ADMIN','VERIFICADOR']);
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

// Alerta de nueva inscripción: observa únicamente respuestas exitosas de POST /api/alumnos.php.
// El envío sucede después de que el endpoint ya confirmó la transacción; una falla SMTP nunca cambia la respuesta ni el alta.
if ($uri === '/api/alumnos.php' && strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_once __DIR__ . '/notificaciones-email.php';
    ob_start(static function (string $body): string {
        try {
            $status = http_response_code();
            if ($status < 200 || $status >= 300 || $body === '') return $body;
            $payload = json_decode($body, true);
            if (!is_array($payload) || ($payload['ok'] ?? false) !== true || !is_array($payload['alumno'] ?? null)) return $body;
            hache_notificar_nueva_inscripcion(
                $payload['alumno'],
                (string)($payload['tipo_ingreso'] ?? 'REGULAR'),
                [
                    'curso_inicio' => (string)($payload['alumno']['fecha_inicio'] ?? ''),
                ]
            );
        } catch (Throwable $e) {
            error_log('[notificaciones-email] Falló el disparador: ' . $e->getMessage());
        }
        return $body;
    });
}

return $config;
