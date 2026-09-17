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
 * @param list<array{estado?:string,fecha?:string,asistencia_id?:string}> $rows
 * @return array{ausencias_consecutivas:int,no_justificadas_consecutivas:int,fecha_ultima_marca:?string,fecha_inicio_racha:?string,origen_asistencia_id:?string,alerta:bool}
 */
function hache_internal_consecutive_absence_state(array $rows): array
{
    $absences = 0;
    $unjustified = 0;
    $unjustifiedOpen = true;
    $latestDate = null;
    $streakStartDate = null;
    $originAttendanceId = null;

    foreach ($rows as $row) {
        $state = (string)($row['estado'] ?? '');
        if ($state === 'PRESENTE') {
            break;
        }
        if (!in_array($state, ['AUSENTE_JUSTIFICADA', 'AUSENTE_NO_JUSTIFICADA'], true)) {
            break;
        }

        $date = trim((string)($row['fecha'] ?? ''));
        $attendanceId = trim((string)($row['asistencia_id'] ?? ''));
        if ($latestDate === null && $date !== '') {
            $latestDate = $date;
        }
        if ($date !== '') {
            $streakStartDate = $date;
        }
        if ($attendanceId !== '') {
            $originAttendanceId = $attendanceId;
        }

        $absences++;
        if ($unjustifiedOpen && $state === 'AUSENTE_NO_JUSTIFICADA') {
            $unjustified++;
        } else {
            $unjustifiedOpen = false;
        }
    }

    return [
        'ausencias_consecutivas' => $absences,
        'no_justificadas_consecutivas' => $unjustified,
        'fecha_ultima_marca' => $latestDate,
        'fecha_inicio_racha' => $streakStartDate,
        'origen_asistencia_id' => $originAttendanceId,
        'alerta' => $absences >= HACHE_INTERNAL_CONSECUTIVE_ABSENCE_THRESHOLD
            || $unjustified >= HACHE_INTERNAL_CONSECUTIVE_UNJUSTIFIED_THRESHOLD,
    ];
}

/**
 * Regla F5 de solo lectura. Usa exclusivamente marcas de asistencia existentes
 * en sesiones REALIZADAS y cerradas de la sede. No genera sesiones, no infiere
 * ausencias a partir de huecos y no cuenta clases canceladas.
 *
 * La consulta conserva toda la racha marcada posterior a la última presencia,
 * no solo las primeras tres marcas, para que el primer registro de ausencia de
 * la racha sea un origen estable mientras la misma racha siga creciendo.
 *
 * @return list<array{alumno_id:string,alumno_nombre:string,ausencias_consecutivas:int,no_justificadas_consecutivas:int,fecha_ultima_marca:?string,fecha_inicio_racha:?string,origen_asistencia_id:string}>
 */
function hache_internal_consecutive_absence_candidates(PDO $pdo, string $sedeId, ?string $alumnoId = null): array
{
    $studentWhere = '';
    $params = [':sede_alumno'=>$sedeId, ':sede_horario'=>$sedeId];
    if ($alumnoId !== null && trim($alumnoId) !== '') {
        $studentWhere = ' AND al.id=:alumno';
        $params[':alumno'] = trim($alumnoId);
    }

    $sql = "WITH ranked AS (
            SELECT aa.id asistencia_id,aa.alumno_id,al.nombre,aa.estado,s.fecha,
                   COALESCE(aa.updated_at,aa.created_at) marca_at,
                   SUM(CASE WHEN aa.estado='PRESENTE' THEN 1 ELSE 0 END) OVER (
                       PARTITION BY aa.alumno_id
                       ORDER BY s.fecha DESC,COALESCE(aa.updated_at,aa.created_at) DESC,aa.id DESC
                       ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                   ) presentes_hasta
            FROM asistencias aa
            INNER JOIN sesiones s ON s.id=aa.sesion_id
            INNER JOIN horarios h ON h.id=s.horario_id
            INNER JOIN alumnos al ON al.id=aa.alumno_id
            WHERE al.sede_id=:sede_alumno
              AND h.sede_id=:sede_horario
              AND al.estado_administrativo<>'BAJA'
              AND s.estado='REALIZADA'
              AND s.cerrada=1{$studentWhere}
        )
        SELECT asistencia_id,alumno_id,nombre,estado,fecha,marca_at
        FROM ranked
        WHERE estado IN ('AUSENTE_JUSTIFICADA','AUSENTE_NO_JUSTIFICADA')
          AND presentes_hasta=0
        ORDER BY alumno_id,fecha DESC,marca_at DESC,asistencia_id DESC";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $groups = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (string)$row['alumno_id'];
        if (!isset($groups[$id])) {
            $groups[$id] = ['nombre'=>(string)$row['nombre'], 'rows'=>[]];
        }
        $groups[$id]['rows'][] = [
            'asistencia_id'=>(string)$row['asistencia_id'],
            'estado'=>(string)$row['estado'],
            'fecha'=>(string)$row['fecha'],
        ];
    }

    $out = [];
    foreach ($groups as $id=>$group) {
        $state = hache_internal_consecutive_absence_state($group['rows']);
        if (!$state['alerta'] || $state['origen_asistencia_id'] === null) {
            continue;
        }
        $out[] = [
            'alumno_id' => (string)$id,
            'alumno_nombre' => (string)$group['nombre'],
            'ausencias_consecutivas' => (int)$state['ausencias_consecutivas'],
            'no_justificadas_consecutivas' => (int)$state['no_justificadas_consecutivas'],
            'fecha_ultima_marca' => $state['fecha_ultima_marca'],
            'fecha_inicio_racha' => $state['fecha_inicio_racha'],
            'origen_asistencia_id' => (string)$state['origen_asistencia_id'],
        ];
    }
    return $out;
}

function hache_internal_consecutive_absence_candidate(PDO $pdo, string $sedeId, string $alumnoId): ?array
{
    $candidates = hache_internal_consecutive_absence_candidates($pdo, $sedeId, $alumnoId);
    return $candidates[0] ?? null;
}
