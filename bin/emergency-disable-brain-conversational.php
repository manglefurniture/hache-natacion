<?php

declare(strict_types=1);

$pdo = require __DIR__.'/../config/pdo.php';

$rows = [
    [
        'clave'=>'sharky_brain_conversacional_habilitado',
        'valor'=>'0',
        'descripcion'=>'Brain conversacional retirado del routing vivo de Sharky 3.0.',
    ],
    [
        'clave'=>'sharky_brain_2ba_habilitado',
        'valor'=>'0',
        'descripcion'=>'Brain Fase 2B-A retirado del routing vivo de Sharky 3.0.',
    ],
    [
        'clave'=>'sharky_brain_2ba_canary_pct',
        'valor'=>'0',
        'descripcion'=>'Canary Brain desactivado mientras Brain permanece retirado del routing vivo.',
    ],
];

$stmt = $pdo->prepare(
    'INSERT INTO configuracion(clave,valor,descripcion,updated_by,updated_at) '
    .'VALUES(:clave,:valor,:descripcion,NULL,NOW()) '
    .'ON DUPLICATE KEY UPDATE valor=VALUES(valor),descripcion=VALUES(descripcion),updated_by=NULL,updated_at=NOW()'
);

$pdo->beginTransaction();
try {
    foreach ($rows as $row) $stmt->execute($row);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

fwrite(STDOUT, "SHARKY_BRAIN_LIVE_DISABLED\n");
