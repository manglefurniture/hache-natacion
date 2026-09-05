<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/production-rum.php';

function rum_marker_fail(string $message): never
{
    fwrite(STDERR, "PRODUCTION_RUM_BUILD_MARKER_FAIL: {$message}\n");
    exit(1);
}

function rum_marker_assert(bool $condition, string $message): void
{
    if (!$condition) {
        rum_marker_fail($message);
    }
}

$root = sys_get_temp_dir() . '/hache-rum-marker-' . bin2hex(random_bytes(6));
$gitRefDir = $root . '/.git/refs/heads';
if (!mkdir($gitRefDir, 0700, true) && !is_dir($gitRefDir)) {
    rum_marker_fail('could not create temp git directory');
}

try {
    $markerSha = str_repeat('a', 40);
    file_put_contents($root . '/.hache-deployed-sha', $markerSha . "\n");
    chmod($root . '/.hache-deployed-sha', 0644);

    file_put_contents($root . '/.git/HEAD', "ref: refs/heads/main\n");
    file_put_contents($root . '/.git/refs/heads/main', str_repeat('b', 40) . "\n");
    chmod($root . '/.git/refs/heads/main', 0000);

    rum_marker_assert(
        hache_rum_deployed_sha($root) === $markerSha,
        'readable marker must expose the full authoritative deployed SHA even when loose git ref is unreadable'
    );
    rum_marker_assert(
        hache_rum_deployed_build_id($root) === 'git-' . substr($markerSha, 0, 12),
        'readable marker must win even when loose git ref is unreadable'
    );

    file_put_contents($root . '/.hache-deployed-sha', "invalid\n");
    rum_marker_assert(
        hache_rum_deployed_sha($root) === null,
        'malformed present marker must fail closed for full SHA resolution'
    );
    rum_marker_assert(
        hache_rum_deployed_build_id($root) === null,
        'malformed present marker must fail closed instead of falling back to git'
    );

    unlink($root . '/.hache-deployed-sha');
    chmod($root . '/.git/refs/heads/main', 0644);
    $legacySha = str_repeat('b', 40);
    rum_marker_assert(
        hache_rum_deployed_sha($root) === $legacySha,
        'legacy git fallback must resolve the full SHA when marker is absent'
    );
    rum_marker_assert(
        hache_rum_deployed_build_id($root) === 'git-' . substr($legacySha, 0, 12),
        'legacy git fallback must remain available when marker is absent'
    );

    echo "PRODUCTION_RUM_BUILD_MARKER_OK\n";
} finally {
    @chmod($root . '/.git/refs/heads/main', 0644);
    $files = [
        $root . '/.hache-deployed-sha',
        $root . '/.git/refs/heads/main',
        $root . '/.git/HEAD',
    ];
    foreach ($files as $file) {
        if (is_file($file) || is_link($file)) {
            @unlink($file);
        }
    }
    @rmdir($root . '/.git/refs/heads');
    @rmdir($root . '/.git/refs');
    @rmdir($root . '/.git');
    @rmdir($root);
}
