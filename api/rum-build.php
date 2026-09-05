<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo '{"ok":false}';
    exit;
}

require_once __DIR__ . '/../config/production-rum.php';
$buildId = hache_rum_deployed_build_id(dirname(__DIR__));
if ($buildId === null) {
    http_response_code(503);
    echo '{"ok":false}';
    exit;
}

echo json_encode([
    'ok' => true,
    'build_id' => $buildId,
], JSON_UNESCAPED_SLASHES);
