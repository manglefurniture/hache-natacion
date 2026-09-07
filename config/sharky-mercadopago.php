<?php

declare(strict_types=1);

const HACHE_SHARKY_MP_CARD_FOLLOWUP_SECONDS = 900;
const HACHE_SHARKY_MP_CARD_FOLLOWUP_MAX_AGE_SECONDS = 7200;

if (!function_exists('env')) {
    /**
     * Compatibility shim used only when the deployed Tienda Natación gateway
     * classes are loaded from the same VPS. Hache Natación itself does not rely
     * on this helper. Store values live in a short-lived global context so no
     * credential is copied into this repository or persisted in Sharky state.
     */
    function env(string $key, ?string $default = null): ?string
    {
        $context = $GLOBALS['HACHE_SHARKY_STORE_ENV_CONTEXT'] ?? null;
        if (is_array($context) && array_key_exists($key, $context)) {
            $value = $context[$key];
            return $value === null || $value === '' ? $default : (string)$value;
        }
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') return $default;
        return (string)$value;
    }
}

function hache_sharky_mp_store_root(): string
{
    $configured = trim((string)(getenv('HACHE_TIENDA_ROOT') ?: ''));
    return $configured !== '' ? rtrim($configured, '/') : '/var/www/tienda.hnatacion.com/app';
}

function hache_sharky_mp_store_environment(): array
{
    $path = hache_sharky_mp_store_root().'/.env';
    if (!is_file($path) || !is_readable($path)) return [];
    $values = @parse_ini_file($path, false, INI_SCANNER_RAW);
    return is_array($values) ? $values : [];
}

/**
 * Reads the active Mercado Pago credential from Tienda Natación on the same
 * server. A direct environment token remains a deployment/test fallback.
 * Secrets never enter Git, Sharky state, inbox or outbox payloads.
 */
function hache_sharky_mp_credentials(?callable $resolver = null): ?array
{
    if ($resolver !== null) {
        $value = $resolver();
        return is_array($value) ? $value : null;
    }

    $direct = trim((string)(getenv('MERCADOPAGO_ACCESS_TOKEN') ?: ''));
    if ($direct !== '') {
        $environment = strtoupper(trim((string)(getenv('MERCADOPAGO_ENVIRONMENT') ?: 'PRODUCTION')));
        return [
            'active'=>true,
            'environment'=>in_array($environment, ['TEST','PRODUCTION'], true) ? $environment : 'PRODUCTION',
            'access_token'=>$direct,
            'source'=>'hache-env',
        ];
    }

    $root = hache_sharky_mp_store_root();
    $cipherFile = $root.'/src/PaymentCredentialCipher.php';
    $configFile = $root.'/src/PaymentGatewayConfig.php';
    if (!is_file($cipherFile) || !is_file($configFile)) return null;

    $storeEnv = hache_sharky_mp_store_environment();
    if (!$storeEnv) return null;

    try {
        require_once $cipherFile;
        require_once $configFile;
        if (!class_exists('PaymentGatewayConfig')) return null;

        $host = (string)($storeEnv['DB_HOST'] ?? '127.0.0.1');
        $port = (string)($storeEnv['DB_PORT'] ?? '3306');
        $database = (string)($storeEnv['DB_DATABASE'] ?? 'hache_tienda');
        $username = (string)($storeEnv['DB_USERNAME'] ?? '');
        $password = (string)($storeEnv['DB_PASSWORD'] ?? '');
        if ($username === '' || $password === '') return null;

        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES=>false,
            ]
        );
        $GLOBALS['HACHE_SHARKY_STORE_ENV_CONTEXT'] = $storeEnv;
        try {
            $credential = PaymentGatewayConfig::mercadoPago($pdo);
        } finally {
            unset($GLOBALS['HACHE_SHARKY_STORE_ENV_CONTEXT']);
        }
        $token = trim((string)($credential['access_token'] ?? ''));
        if (($credential['active'] ?? false) !== true || $token === '') return null;
        return [
            'active'=>true,
            'environment'=>in_array(($credential['environment'] ?? ''), ['TEST','PRODUCTION'], true)
                ? (string)$credential['environment'] : 'PRODUCTION',
            'access_token'=>$token,
            'source'=>'tienda-natacion',
            'credential_ref'=>$credential['credential_ref'] ?? null,
        ];
    } catch (Throwable $e) {
        unset($GLOBALS['HACHE_SHARKY_STORE_ENV_CONTEXT']);
        error_log('[sharky-mp] Tienda Natación gateway unavailable');
        return null;
    }
}

function hache_sharky_mp_card_total(float $baseAmount, float $surchargePct): float
{
    $baseAmount = max(0.0, $baseAmount);
    $surchargePct = max(0.0, min(100.0, $surchargePct));
    return round($baseAmount * (1.0 + $surchargePct / 100.0), 2);
}

function hache_sharky_mp_money(float $amount): string
{
    return number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2, '.', ',');
}

function hache_sharky_mp_request(string $method, string $path, string $token, ?array $payload = null): ?array
{
    $token = trim($token);
    if ($token === '' || !str_starts_with($path, '/')) return null;
    $url = 'https://api.mercadopago.com'.$path;
    $headers = ['Authorization: Bearer '.$token, 'Accept: application/json'];
    $body = null;
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($body === false) return null;
    }
    $ch = curl_init($url); if ($ch === false) return null;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CUSTOMREQUEST=>strtoupper($method),
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_CONNECTTIMEOUT=>8,
        CURLOPT_TIMEOUT=>20,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if (!is_string($raw) || $error !== '' || $status < 200 || $status >= 300) return null;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function hache_sharky_mp_external_reference(string $studentId, string $courseId): string
{
    return 'sharky:'.substr(hash('sha256', $studentId.'|'.$courseId), 0, 32);
}

/** @return array{ok:bool,url?:string,preference_id?:string,external_reference?:string,total?:float,base?:float,surcharge_pct?:float,reason?:string} */
function hache_sharky_mp_create_preference(
    array $registration,
    array $business,
    ?callable $credentialResolver = null,
    ?callable $requester = null
): array {
    $studentId = trim((string)($registration['student_id'] ?? ''));
    $courseId = trim((string)($registration['course_id'] ?? ''));
    $base = (float)($registration['price'] ?? 0);
    if ($studentId === '' || $courseId === '' || $base <= 0) return ['ok'=>false,'reason'=>'INVALID_REGISTRATION'];

    $credential = hache_sharky_mp_credentials($credentialResolver);
    if (!is_array($credential) || ($credential['active'] ?? false) !== true) return ['ok'=>false,'reason'=>'MP_UNAVAILABLE'];
    $token = trim((string)($credential['access_token'] ?? ''));
    if ($token === '') return ['ok'=>false,'reason'=>'MP_UNAVAILABLE'];

    $pct = is_numeric($business['sharky_recargo_tarjeta_pct'] ?? null)
        ? (float)$business['sharky_recargo_tarjeta_pct'] : 5.0;
    $pct = max(0.0, min(30.0, $pct));
    $total = hache_sharky_mp_card_total($base, $pct);
    $external = hache_sharky_mp_external_reference($studentId, $courseId);
    $payload = [
        'items'=>[[
            'id'=>'sharky-intensivo',
            'title'=>'Curso intensivo Hache Natación',
            'description'=>'Inscripción gestionada por Sharky',
            'quantity'=>1,
            'currency_id'=>'MXN',
            'unit_price'=>$total,
        ]],
        'external_reference'=>$external,
        'metadata'=>[
            'source'=>'sharky',
            'student_id'=>$studentId,
            'course_id'=>$courseId,
        ],
        'statement_descriptor'=>'HACHE NATACION',
    ];
    $requester ??= static fn(string $method,string $path,string $accessToken,?array $data=null):?array
        => hache_sharky_mp_request($method,$path,$accessToken,$data);
    $response = $requester('POST', '/checkout/preferences', $token, $payload);
    if (!is_array($response)) return ['ok'=>false,'reason'=>'MP_CREATE_FAILED'];
    $environment = (string)($credential['environment'] ?? 'PRODUCTION');
    $url = trim((string)($environment === 'TEST' ? ($response['sandbox_init_point'] ?? '') : ($response['init_point'] ?? '')));
    if ($url === '') $url = trim((string)($response['init_point'] ?? $response['sandbox_init_point'] ?? ''));
    $preferenceId = trim((string)($response['id'] ?? ''));
    if ($url === '' || $preferenceId === '') return ['ok'=>false,'reason'=>'MP_INVALID_RESPONSE'];
    return [
        'ok'=>true,
        'url'=>$url,
        'preference_id'=>$preferenceId,
        'external_reference'=>$external,
        'total'=>$total,
        'base'=>$base,
        'surcharge_pct'=>$pct,
    ];
}

/** @return array{state:string,statuses:array<int,string>} */
function hache_sharky_mp_status_by_external_reference(
    string $externalReference,
    ?callable $credentialResolver = null,
    ?callable $requester = null
): array {
    $externalReference = trim($externalReference);
    if ($externalReference === '') return ['state'=>'unavailable','statuses'=>[]];
    $credential = hache_sharky_mp_credentials($credentialResolver);
    if (!is_array($credential) || ($credential['active'] ?? false) !== true) return ['state'=>'unavailable','statuses'=>[]];
    $token = trim((string)($credential['access_token'] ?? ''));
    if ($token === '') return ['state'=>'unavailable','statuses'=>[]];
    $requester ??= static fn(string $method,string $path,string $accessToken,?array $data=null):?array
        => hache_sharky_mp_request($method,$path,$accessToken,$data);
    $path = '/v1/payments/search?external_reference='.rawurlencode($externalReference).'&sort=date_created&criteria=desc&limit=20';
    $response = $requester('GET', $path, $token, null);
    if (!is_array($response)) return ['state'=>'unavailable','statuses'=>[]];
    $statuses = [];
    foreach (($response['results'] ?? []) as $payment) {
        if (!is_array($payment)) continue;
        $status = strtolower(trim((string)($payment['status'] ?? '')));
        if ($status !== '') $statuses[] = $status;
    }
    $statuses = array_values(array_unique($statuses));
    if (in_array('approved', $statuses, true)) return ['state'=>'approved','statuses'=>$statuses];
    foreach (['pending','in_process','in_mediation','authorized'] as $pending) {
        if (in_array($pending, $statuses, true)) return ['state'=>'pending','statuses'=>$statuses];
    }
    if ($statuses) return ['state'=>'failed','statuses'=>$statuses];
    return ['state'=>'not_found','statuses'=>[]];
}
