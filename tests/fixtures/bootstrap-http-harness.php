<?php

declare(strict_types=1);

$path = (string)($_GET['path'] ?? '/');
$script = (string)($_GET['script'] ?? $path);

$_SERVER['REQUEST_URI'] = $path;
$_SERVER['SCRIPT_NAME'] = $script;
$_SERVER['SCRIPT_FILENAME'] = '/var/www/hache/public' . ($script === '/' ? '/home.php' : $script);
$_SERVER['REQUEST_METHOD'] = 'GET';

require dirname(__DIR__, 2) . '/config/backend-bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
echo "BOOTSTRAP_RETURNED\n";
