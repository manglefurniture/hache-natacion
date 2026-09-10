<?php

declare(strict_types=1);

$pdo = require __DIR__.'/../config/pdo.php';

$key = 'sharky_brain_conversacional_habilitado';
$description = 'Kill switch del Brain conversacional experimental. Deshabilitado por incidente de contexto 2026-09-10.';

$stmt = $pdo->prepare(
    'INSERT INTO configuracion(clave,valor,descripcion,updated_by,updated_at) '
    .'VALUES(:clave,:valor,:descripcion,NULL,NOW()) '
    .'ON DUPLICATE KEY UPDATE valor=VALUES(valor),descripcion=VALUES(descripcion),updated_by=NULL,updated_at=NOW()'
);
$stmt->execute([
    ':clave'=>$key,
    ':valor'=>'0',
    ':descripcion'=>$description,
]);

fwrite(STDOUT, "SHARKY_BRAIN_CONVERSATIONAL_DISABLED\n");
