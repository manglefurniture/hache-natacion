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

$tokenPath = '/tmp/hache-pr-evidence-token';
$mtime = @filemtime($tokenPath);
if (!is_int($mtime) || $mtime < time() - 120 || !is_readable($tokenPath)) {
    pr_internal_out(404, ['ok' => false]);
}

$expected = trim((string) @file_get_contents($tokenPath));
$provided = trim((string) ($_SERVER['HTTP_X_HACHE_EVIDENCE_TOKEN'] ?? ''));
if (!preg_match('/^[a-f0-9]{64}$/', $expected) || !hash_equals($expected, $provided)) {
    pr_internal_out(404, ['ok' => false]);
}

define('HACHE_PR_INTERNAL_HTTP', true);
require dirname(__DIR__) . '/bin/production-readiness-evidence.php';
