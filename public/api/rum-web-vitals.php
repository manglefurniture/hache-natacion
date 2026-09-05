<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

function rum_out(int $status, array $body = []): never
{
    http_response_code($status);
    if ($body !== []) {
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    header('Allow: POST');
    rum_out(405, ['ok' => false]);
}

$contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
if (!str_starts_with($contentType, 'application/json')) {
    rum_out(415, ['ok' => false]);
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength < 1 || $contentLength > 1024) {
    rum_out(413, ['ok' => false]);
}

$raw = file_get_contents('php://input', false, null, 0, 2048);
if (!is_string($raw) || $raw === '' || strlen($raw) > 1024) {
    rum_out(400, ['ok' => false]);
}

try {
    $sample = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    rum_out(400, ['ok' => false]);
}

if (!is_array($sample) || array_is_list($sample)) {
    rum_out(400, ['ok' => false]);
}

$allowedKeys = ['schema_version', 'metric', 'value', 'route_group', 'build_id', 'form_factor'];
$keys = array_keys($sample);
sort($keys);
$expectedKeys = $allowedKeys;
sort($expectedKeys);
if ($keys !== $expectedKeys) {
    rum_out(400, ['ok' => false]);
}

if (($sample['schema_version'] ?? null) !== 1) {
    rum_out(400, ['ok' => false]);
}

$metric = strtoupper(trim((string) ($sample['metric'] ?? '')));
$routeGroup = trim((string) ($sample['route_group'] ?? ''));
$buildId = trim((string) ($sample['build_id'] ?? ''));
$formFactor = strtolower(trim((string) ($sample['form_factor'] ?? '')));
$valueRaw = $sample['value'] ?? null;

if (!in_array($metric, ['LCP', 'INP', 'CLS'], true)) {
    rum_out(400, ['ok' => false]);
}
if (!in_array($routeGroup, ['home', 'registration', 'admin_payments'], true)) {
    rum_out(400, ['ok' => false]);
}
if ($buildId !== 'pilot-c-field-v1') {
    rum_out(400, ['ok' => false]);
}
if (!in_array($formFactor, ['mobile', 'desktop'], true)) {
    rum_out(400, ['ok' => false]);
}
if (!is_int($valueRaw) && !is_float($valueRaw)) {
    rum_out(400, ['ok' => false]);
}

$value = (float) $valueRaw;
if (!is_finite($value) || $value < 0) {
    rum_out(400, ['ok' => false]);
}
if (($metric === 'CLS' && $value > 10.0) || ($metric !== 'CLS' && $value > 120000.0)) {
    rum_out(400, ['ok' => false]);
}

try {
    $config = require __DIR__ . '/../../config/database.php';
    if (!is_array($config)) {
        throw new RuntimeException('database_config_invalid');
    }

    $pdo = new PDO(
        'mysql:host=' . $config['host'] . ';dbname=' . $config['dbname'] . ';charset=' . $config['charset'],
        (string) $config['user'],
        (string) $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $schema = $pdo->query(
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'production_rum_samples' LIMIT 1"
    )->fetchColumn();
    if (!$schema) {
        rum_out(503, ['ok' => false]);
    }

    // Global, identifier-free abuse ceiling. The collector intentionally does not retain IPs or fingerprints.
    $recent = (int) $pdo->query(
        'SELECT COUNT(*) FROM production_rum_samples WHERE created_at_utc >= UTC_TIMESTAMP(6) - INTERVAL 1 MINUTE'
    )->fetchColumn();
    if ($recent >= 600) {
        rum_out(202, ['ok' => true, 'stored' => false]);
    }

    $st = $pdo->prepare(
        'INSERT INTO production_rum_samples(metric, value, route_group, build_id, form_factor, created_at_utc) '
        . 'VALUES(:metric, :value, :route_group, :build_id, :form_factor, UTC_TIMESTAMP(6))'
    );
    $st->execute([
        ':metric' => $metric,
        // Preserve substantially more precision than the normative CLS boundary requires.
        ':value' => sprintf('%.8F', $value),
        ':route_group' => $routeGroup,
        ':build_id' => $buildId,
        ':form_factor' => $formFactor,
    ]);

    // Project retention for this pilot: at most 35 days of minimized samples.
    $pdo->exec(
        'DELETE FROM production_rum_samples WHERE created_at_utc < UTC_TIMESTAMP(6) - INTERVAL 35 DAY LIMIT 250'
    );

    rum_out(202, ['ok' => true, 'stored' => true]);
} catch (Throwable $e) {
    // Do not log request bodies or derived client data from this telemetry endpoint.
    error_log('Hache RUM collector unavailable: ' . get_class($e));
    rum_out(503, ['ok' => false]);
}
