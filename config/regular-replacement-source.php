<?php
declare(strict_types=1);

/**
 * Fuente compartida para reposiciones regulares que siguen disponibles.
 * Solo lectura: no crea, asigna, utiliza ni cancela reposiciones.
 */
function hache_regular_available_replacement_candidates(PDO $pdo, string $sedeId, ?string $reposicionId = null): array
{
    $where = [
        "rr.estado='DISPONIBLE'",
        'a.sede_id=:sede',
    ];
    $params = [':sede'=>$sedeId];
    if ($reposicionId !== null && $reposicionId !== '') {
        $where[] = 'rr.id=:reposicion';
        $params[':reposicion'] = $reposicionId;
    }

    $sql = "SELECT rr.id reposicion_id,rr.alumno_id,rr.created_at,a.nombre alumno_nombre
        FROM reposiciones_regulares rr
        INNER JOIN alumnos a ON a.id=rr.alumno_id
        WHERE ".implode(' AND ', $where)."
        ORDER BY rr.created_at,a.nombre,rr.id";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
