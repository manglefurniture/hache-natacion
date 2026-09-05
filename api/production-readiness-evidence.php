<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('X-Robots-Tag: noindex, nofollow');

function pr_internal_out(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
    pr_internal_out(404, ['ok' => false]);
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    header('Allow: POST');
    pr_internal_out(405, ['ok' => false]);
}

$guard = trim((string) ($_SERVER['HTTP_X_HACHE_OPS'] ?? ''));
if (!hash_equals('production-readiness-evidence-v1', $guard)) {
    pr_internal_out(403, ['ok' => false]);
}

define('HACHE_PR_INTERNAL_HTTP', true);
require dirname(__DIR__) . '/bin/production-readiness-evidence.php';
