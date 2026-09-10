<?php

declare(strict_types=1);

$pdo = require __DIR__.'/../config/pdo.php';

$brainKey = 'sharky_brain_conversacional_habilitado';
$markerKey = 'sharky_brain_conversacional_reactivated_20260910';
$description = 'Brain conversacional experimental activo. Reactivado tras corregir la regresión de contexto numérico del 2026-09-10.';
$markerDescription = 'Marcador one-shot: reactivación controlada del Brain conversacional tras incidente de contexto 2026-09-10.';

$pdo->beginTransaction();
try {
    $check = $pdo->prepare('SELECT valor FROM configuracion WHERE clave=:clave LIMIT 1 FOR UPDATE');
    $check->execute([':clave'=>$markerKey]);
    $marker = trim((string)($check->fetchColumn() ?: ''));
    if ($marker === '1') {
        $pdo->commit();
        fwrite(STDOUT, "SHARKY_BRAIN_CONVERSATIONAL_REACTIVATION_ALREADY_APPLIED\n");
        exit(0);
    }

    $upsert = $pdo->prepare(
        'INSERT INTO configuracion(clave,valor,descripcion,updated_by,updated_at) '
        .'VALUES(:clave,:valor,:descripcion,NULL,NOW()) '
        .'ON DUPLICATE KEY UPDATE valor=VALUES(valor),descripcion=VALUES(descripcion),updated_by=NULL,updated_at=NOW()'
    );
    $upsert->execute([':clave'=>$brainKey,':valor'=>'1',':descripcion'=>$description]);
    $upsert->execute([':clave'=>$markerKey,':valor'=>'1',':descripcion'=>$markerDescription]);
    $pdo->commit();
    fwrite(STDOUT, "SHARKY_BRAIN_CONVERSATIONAL_REACTIVATED\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
