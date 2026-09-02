<?php
declare(strict_types=1);

require_once __DIR__.'/sharky-runtime.php';

$runtimePdo = isset($pdo) && $pdo instanceof PDO ? $pdo : null;
$values = hache_sharky_business_values($runtimePdo);

return [
    'institucion' => (string)($values['sharky_pago_institucion'] ?? 'Mercado Pago W'),
    'beneficiario' => (string)($values['sharky_pago_beneficiario'] ?? 'Heidy Garcia Liranza'),
    'clabe' => (string)($values['sharky_pago_clabe'] ?? '722969010319748145'),
];
