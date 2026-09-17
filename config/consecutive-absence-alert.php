<?php
declare(strict_types=1);

const HACHE_INTERNAL_CONSECUTIVE_ABSENCE_THRESHOLD = 3;
const HACHE_INTERNAL_CONSECUTIVE_UNJUSTIFIED_THRESHOLD = 2;

/**
 * Evalúa una secuencia de marcas de asistencia ya ordenada de la más reciente
 * a la más antigua. Solo las marcas válidas participan en la racha.
 * PRESENTE corta ambas rachas; una justificada mantiene la racha general pero
 * corta la racha específica de no justificadas.
 *
 * @param list<array{estado?:string,fecha?:string}> $rows
 * @return array{ausencias_consecutivas:int,no_justificadas_consecutivas:int,fecha_ultima_marca:?string,alerta:bool}
 */
function hache_internal_consecutive_absence_state(array $rows): array
{
    $absences = 0;
    $unjustified = 0;
    $unjustifiedOpen = true;
    $latestDate = null;

    foreach ($rows as $row) {
        $state = (string)($row['estado'] ?? '');
        if ($state === 'PRESENTE') {
            break;
        }
        if (!in_array($state, ['AUSENTE_JUSTIFICADA', 'AUSENTE_NO_JUSTIFICADA'], true)) {
            break;
        }
        if ($latestDate === null) {
            $date = trim((string)($row['fecha'] ?? ''));
            $latestDate = $date !== '' ? $date : null;
        }

        $absences++;
        if ($unjustifiedOpen && $state === 'AUSENTE_NO_JUSTIFICADA') {
            $unjustified++;
        } else {
            $unjustifiedOpen = false;
        }

        if ($absences >= HACHE_INTERNAL_CONSECUTIVE_ABSENCE_THRESHOLD) {
            break;
        }
    }

    return [
        'ausencias_consecutivas' => $absences,
        'no_justificadas_consecutivas' => $unjustified,
        'fecha_ultima_marca' => $latestDate,
        'alerta' => $absences >= HACHE_INTERNAL_CONSECUTIVE_ABSENCE_THRESHOLD
            || $unjustified >= HACHE_INTERNAL_CONSECUTIVE_UNJUSTIFIED_THRESHOLD,
    ];
}

/**
 * Regla F5 de solo lectura. Usa exclusivamente marcas de asistencia existentes
 * en sesiones REALIZADAS y cerradas de la sede. No genera sesiones, no infiere
 * ausencias a partir de huecos y no cuenta clases canceladas.
 *
 * @return list<array{alumno_id:string,alumno_nombre:string,ausencias_consecutivas:int,no_justificadas_consecutivas:int,fecha_ultima_marca:?string}>
 */
function hache_internal_consecutive_absence_candidates(PDO $pdo, string $sedeId): array
{
    $limit = HACHE_INTERNAL_CONSECUTIVE_ABSENCE_THRESHOLD;
    $sql = "SELECT ranked.alumno_id,ranked.nombre,ranked.estado,ranked.fecha,ranked.rn
        FROM (
            SELECT aa.alumno_id,al.nombre,aa.estado,s.fecha,
                   ROW_NUMBER() OVER (
                       PARTITION BY aa.alumno_id
                       ORDER BY s.fecha DESC,COALESCE(aa.updated_at,aa.created_at) DESC,aa.id DESC
                   ) rn
            FROM asistencias aa
            INNER JOIN sesiones s ON s.id=aa.sesion_id
            INNER JOIN horarios h ON h.id=s.horario_id
            INNER JOIN alumnos al ON al.id=aa.alumno_id
            WHERE al.sede_id=:sede_alumno
              AND h.sede_id=:sede_horario
              AND al.estado_administrativo<>'BAJA'
              AND s.estado='REALIZADA'
              AND s.cerrada=1
        ) ranked
        WHERE ranked.rn <= {$limit}
        ORDER BY ranked.alumno_id,ranked.rn";
    $st = $pdo->prepare($sql);
    $st->execute([':sede_alumno'=>$sedeId, ':sede_horario'=>$sedeId]);

    $groups = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (string)$row['alumno_id'];
        if (!isset($groups[$id])) {
            $groups[$id] = ['nombre'=>(string)$row['nombre'], 'rows'=>[]];
        }
        $groups[$id]['rows'][] = ['estado'=>(string)$row['estado'], 'fecha'=>(string)$row['fecha']];
    }

    $out = [];
    foreach ($groups as $id=>$group) {
        $state = hache_internal_consecutive_absence_state($group['rows']);
        if (!$state['alerta']) {
            continue;
        }
        $out[] = [
            'alumno_id' => (string)$id,
            'alumno_nombre' => (string)$group['nombre'],
            'ausencias_consecutivas' => (int)$state['ausencias_consecutivas'],
            'no_justificadas_consecutivas' => (int)$state['no_justificadas_consecutivas'],
            'fecha_ultima_marca' => $state['fecha_ultima_marca'],
        ];
    }
    return $out;
}
