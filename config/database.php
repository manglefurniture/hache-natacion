<?php

declare(strict_types=1);

$localConfig = __DIR__ . '/database.local.php';

if (is_file($localConfig)) {
    return require $localConfig;
}

return [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'dbname' => getenv('DB_NAME') ?: 'hache_natacion',
    'user' => getenv('DB_USER') ?: '',
    'password' => getenv('DB_PASS') ?: '',
    'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
];
