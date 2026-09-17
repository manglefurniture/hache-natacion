<?php
declare(strict_types=1);

require_once __DIR__.'/admin-historical-corrections.php';

/**
 * Persiste, dentro de la transacción de la mutación, el evento fiable de entrada
 * o salida de BAJA. Si no puede escribirse, la mutación llamadora debe fallar y
 * hacer rollback: F6 no acepta huecos silenciosos después del inicio de cobertura.
 */
function hache_alumno_estado_evento(
    PDO $pdo,
    array $actor,
    array $alumno,
    string $sedeId,
    string $nuevoEstado,
    string $tipoHistorial,
    string $accionAuditoria,
    string $ruta='/api/alumno-gestion.php'
): void {
    $alumnoId=(string)$alumno['id'];
    $anterior=(string)$alumno['estado_administrativo'];
    $descripcion=$tipoHistorial==='BAJA'
        ?'Alumno dado de baja administrativamente.'
        :'Alumno reactivado administrativamente.';

    hache_admin_history(
        $pdo,
        $alumnoId,
        $tipoHistorial,
        $descripcion,
        (string)$actor['id'],
        'ALUMNO_ESTADO',
        $alumnoId,
    );

    $detalle=json_encode([
        'sede_id'=>$sedeId,
        'estado_anterior'=>$anterior,
        'estado_nuevo'=>$nuevoEstado,
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(!is_string($detalle))throw new RuntimeException('No se pudo serializar el cambio de estado del alumno.');

    $st=$pdo->prepare("INSERT INTO auditoria_eventos(
            usuario_id,usuario_nombre,accion,entidad,entidad_id,detalle,metodo,ruta
        ) VALUES(:uid,:un,:accion,'alumno',:aid,:detalle,'POST',:ruta)");
    $st->execute([
        ':uid'=>(string)$actor['id'],
        ':un'=>$actor['usuario']??null,
        ':accion'=>$accionAuditoria,
        ':aid'=>$alumnoId,
        ':detalle'=>$detalle,
        ':ruta'=>$ruta,
    ]);
}
