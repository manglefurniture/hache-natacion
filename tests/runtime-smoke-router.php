<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$public = $root . '/public';
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

if (str_contains($path, "\0") || str_contains($path, '..')) {
    http_response_code(400);
    exit('Solicitud inválida');
}

if (str_starts_with($path, '/api/')) {
    $relative = ltrim(substr($path, 5), '/');
    if ($relative === '' || str_contains($relative, '/')) {
        http_response_code(404);
        exit('No encontrado');
    }
    $target = $root . '/api/' . $relative;
} else {
    $relative = ltrim($path, '/');
    if ($relative === '') $relative = 'index.php';
    $target = $public . '/' . $relative;
}

if (!is_file($target)) {
    http_response_code(404);
    exit('No encontrado');
}

if (pathinfo($target, PATHINFO_EXTENSION) !== 'php') {
    return false;
}

$_SERVER['SCRIPT_NAME'] = $path;
$_SERVER['PHP_SELF'] = $path;
$_SERVER['SCRIPT_FILENAME'] = $target;

require $root . '/config/backend-bootstrap.php';
require $target;

