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

$runId = trim((string) ($_SERVER['HTTP_X_HACHE_EVIDENCE_RUN_ID'] ?? ''));
if ($runId !== '' && !preg_match('/^[0-9]{1,20}$/', $runId)) {
    pr_internal_out(404, ['ok' => false]);
}
$tokenPath = $runId === ''
    ? '/tmp/hache-pr-evidence-token'
    : '/tmp/hache-pr-evidence-token-' . $runId;
$mtime = @filemtime($tokenPath);
if (!is_int($mtime) || $mtime < time() - 120 || !is_readable($tokenPath)) {
    pr_internal_out(404, ['ok' => false]);
}

$expected = trim((string) @file_get_contents($tokenPath));
$provided = trim((string) ($_SERVER['HTTP_X_HACHE_EVIDENCE_TOKEN'] ?? ''));
if (!preg_match('/^[a-f0-9]{64}$/', $expected) || !hash_equals($expected, $provided)) {
    pr_internal_out(404, ['ok' => false]);
}

$root = dirname(__DIR__);
require_once $root . '/config/production-rum.php';
$deployedSha = hache_rum_deployed_sha($root);
if (!is_string($deployedSha)) {
    pr_internal_out(500, ['ok' => false]);
}

$mode = trim((string) ($_SERVER['HTTP_X_HACHE_EVIDENCE_MODE'] ?? ''));
if ($mode === 'sharky_reengagement_backfill_dry_run') {
    try {
        require_once $root . '/bin/sharky-reengagement-backfill-dry-run.php';
        $payload = ['ok' => true] + hache_sharky_reengagement_backfill_dry_run();
        $payload['deployed_sha'] = $deployedSha;
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
        exit;
    } catch (Throwable $e) {
        error_log('[sharky-backfill-dry-run] internal scan failed');
        pr_internal_out(500, ['ok' => false, 'reason' => 'DRY_RUN_UNAVAILABLE']);
    }
}
if ($mode !== '') {
    pr_internal_out(404, ['ok' => false]);
}

ob_start();
define('HACHE_PR_INTERNAL_HTTP', true);
require $root . '/bin/production-readiness-evidence.php';
$raw = ob_get_clean();

try {
    $payload = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    pr_internal_out(500, ['ok' => false]);
}
if (!is_array($payload) || ($payload['ok'] ?? false) !== true) {
    pr_internal_out(500, ['ok' => false]);
}

// El endpoint HTTP usa la misma frontera autoritativa que /api/rum-build.php.
// El collector CLI conserva su resolución Git para diagnósticos fuera de FPM.
$payload['deployed_sha'] = $deployedSha;
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
