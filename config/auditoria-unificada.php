<?php

declare(strict_types=1);

function hache_auditoria_json(?string $raw): array
{
    if ($raw === null || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function hache_auditoria_actor(?string $id, ?string $name, string $type = 'human'): array
{
    $id = $id !== null && trim($id) !== '' ? trim($id) : null;
    $name = $name !== null && trim($name) !== '' ? trim($name) : null;
    $known = $id !== null || $name !== null;

    return [
        'known' => $known,
        'type' => $known ? $type : 'unknown',
        'id' => $id,
        'name' => $name,
    ];
}

function hache_auditoria_marca(?string $value, string $semantic): array
{
    $value = $value !== null && trim($value) !== '' ? trim($value) : null;
    return [
        'known' => $value !== null,
        'value' => $value,
        'semantic' => $value !== null ? $semantic : 'unknown',
    ];
}

function hache_auditoria_valor(bool $available, mixed $value = null): array
{
    return [
        'available' => $available,
        'value' => $available ? $value : null,
    ];
}

function hache_auditoria_modulo_desde_entidad(string $entity): string
{
    return match ($entity) {
        'alumno', 'alumnos', 'alumno-gestion', 'alumno-rapido' => 'alumnos',
        'pago', 'pagos', 'editar-pago', 'invalidar-pago', 'cierres-mensuales' => 'finanzas',
        'pendiente', 'pendientes' => 'pendientes',
        'configuracion' => 'configuracion',
        'profesor', 'profesores', 'profesor-sustituciones' => 'profesores',
        'sesiones', 'asistencia' => 'operacion',
        'intensivo-alumnos', 'intensivos' => 'intensivos',
        default => $entity !== '' ? $entity : 'transversal',
    };
}

function hache_auditoria_evento_normalizar(array $row): array
{
    $action = trim((string)($row['accion'] ?? ''));
    $entity = trim((string)($row['entidad'] ?? ''));
    $detail = hache_auditoria_json(isset($row['detalle']) ? (string)$row['detalle'] : null);
    $before = hache_auditoria_valor(false);
    $after = hache_auditoria_valor(false);
    $module = hache_auditoria_modulo_desde_entidad($entity);
    $level = 'technical';
    $resultCode = array_key_exists('http_status', $detail) ? (int)$detail['http_status'] : null;
    $resultDetail = array_key_exists('duracion_ms', $detail) ? 'duracion_ms='.(int)$detail['duracion_ms'] : null;
    $reason = null;
    $scopeSede = null;
    $scopeKnown = false;
    $history = 'native';
    $beforeAfter = 'none';
    $referenceType = null;
    $referenceId = null;

    if ($action === 'CONFIG_ALERTA_ACTUALIZADA') {
        $module = 'configuracion';
        $level = 'confirmed';
        $resultCode = null;
        $resultDetail = null;
        if (array_key_exists('anterior', $detail)) {
            $before = hache_auditoria_valor(true, ['valor' => $detail['anterior']]);
        }
        if (array_key_exists('nuevo', $detail)) {
            $after = hache_auditoria_valor(true, ['valor' => $detail['nuevo']]);
        }
        if ($before['available'] || $after['available']) {
            $beforeAfter = 'structured';
        }
    } elseif (in_array($action, ['ALUMNO_BAJA', 'ALUMNO_REACTIVACION'], true)) {
        $module = 'alumnos';
        $level = 'confirmed';
        $resultCode = null;
        $resultDetail = null;
        if (array_key_exists('estado_anterior', $detail)) {
            $before = hache_auditoria_valor(true, ['estado' => $detail['estado_anterior']]);
        }
        if (array_key_exists('estado_nuevo', $detail)) {
            $after = hache_auditoria_valor(true, ['estado' => $detail['estado_nuevo']]);
        }
        if ($before['available'] || $after['available']) {
            $beforeAfter = 'structured';
        }
        if (isset($detail['sede_id']) && trim((string)$detail['sede_id']) !== '') {
            $scopeSede = trim((string)$detail['sede_id']);
            $scopeKnown = true;
        }
    } elseif (in_array($action, ['PENDIENTE_ATENDIDO', 'PENDIENTE_RESUELTO'], true)) {
        $module = 'pendientes';
        $level = 'confirmed';
        $resultCode = null;
        $resultDetail = null;
        if (array_key_exists('estado', $detail)) {
            $after = hache_auditoria_valor(true, ['estado' => $detail['estado']]);
            $beforeAfter = 'structured';
        }
        if (isset($detail['sede_id']) && trim((string)$detail['sede_id']) !== '') {
            $scopeSede = trim((string)$detail['sede_id']);
            $scopeKnown = true;
        }
        if (isset($detail['nota']) && trim((string)$detail['nota']) !== '') {
            $reason = trim((string)$detail['nota']);
        }
    } elseif ($action === 'ALUMNO_DATOS_ACTUALIZADOS') {
        $module = 'alumnos';
        $level = 'confirmed';
        $resultCode = null;
        $beforeValues = [];
        $afterValues = [];
        $redacted = [];
        $changes = isset($detail['cambios']) && is_array($detail['cambios']) ? $detail['cambios'] : [];
        foreach ($changes as $field => $change) {
            if (!is_array($change)) {
                continue;
            }
            if (array_key_exists('anterior', $change) || array_key_exists('nuevo', $change)) {
                $beforeValues[(string)$field] = $change['anterior'] ?? null;
                $afterValues[(string)$field] = $change['nuevo'] ?? null;
            } elseif (($change['modificado'] ?? false) === true) {
                $redacted[] = (string)$field;
            }
        }
        if ($beforeValues || $afterValues) {
            $before = hache_auditoria_valor(true, $beforeValues);
            $after = hache_auditoria_valor(true, $afterValues);
            $beforeAfter = 'structured';
        }
        $resultDetail = $redacted
            ? 'Campos modificados con valores omitidos: '.implode(', ', $redacted)
            : null;
        if (isset($detail['sede_id']) && trim((string)$detail['sede_id']) !== '') {
            $scopeSede = trim((string)$detail['sede_id']);
            $scopeKnown = true;
        }
    } elseif ($action === 'PERIODO_FINANCIERO_RANGO_ACTUALIZADO') {
        $module = 'finanzas';
        $level = 'confirmed';
        $resultCode = null;
        $resultDetail = null;
        if (isset($detail['anterior']) && is_array($detail['anterior'])
            && isset($detail['siguiente_anterior']) && is_array($detail['siguiente_anterior'])) {
            $before = hache_auditoria_valor(true, [
                'periodo' => $detail['anterior'],
                'siguiente_periodo' => $detail['siguiente_anterior'],
            ]);
        }
        if (isset($detail['nuevo']) && is_array($detail['nuevo'])
            && isset($detail['siguiente_nuevo']) && is_array($detail['siguiente_nuevo'])) {
            $after = hache_auditoria_valor(true, [
                'periodo' => $detail['nuevo'],
                'siguiente_periodo' => $detail['siguiente_nuevo'],
            ]);
        }
        if ($before['available'] || $after['available']) {
            $beforeAfter = 'structured';
        }
        if (isset($detail['siguiente_periodo_id']) && trim((string)$detail['siguiente_periodo_id']) !== '') {
            $referenceType = 'periodo_financiero_siguiente';
            $referenceId = trim((string)$detail['siguiente_periodo_id']);
        }
        if (isset($detail['sede_id']) && trim((string)$detail['sede_id']) !== '') {
            $scopeSede = trim((string)$detail['sede_id']);
            $scopeKnown = true;
        }
    } elseif ($action === 'ASISTENCIA_CORREGIDA') {
        $module = 'operacion';
        $level = 'confirmed';
        $resultCode = null;
        $beforeValues = [];
        $afterValues = [];
        $redacted = [];
        $changes = isset($detail['cambios']) && is_array($detail['cambios']) ? $detail['cambios'] : [];
        foreach ($changes as $field => $change) {
            if (!is_array($change)) {
                continue;
            }
            if (array_key_exists('anterior', $change) || array_key_exists('nuevo', $change)) {
                $beforeValues[(string)$field] = $change['anterior'] ?? null;
                $afterValues[(string)$field] = $change['nuevo'] ?? null;
            } elseif (($change['modificado'] ?? false) === true) {
                $redacted[] = (string)$field;
            }
        }
        if ($beforeValues || $afterValues) {
            $before = hache_auditoria_valor(true, $beforeValues);
            $after = hache_auditoria_valor(true, $afterValues);
            $beforeAfter = 'structured';
        }
        $resultDetail = $redacted
            ? 'Campos modificados con valores omitidos: '.implode(', ', $redacted)
            : null;
        if (isset($detail['sesion_id']) && trim((string)$detail['sesion_id']) !== '') {
            $referenceType = 'sesion';
            $referenceId = trim((string)$detail['sesion_id']);
        }
        if (isset($detail['sede_id']) && trim((string)$detail['sede_id']) !== '') {
            $scopeSede = trim((string)$detail['sede_id']);
            $scopeKnown = true;
        }
    } elseif ($action === 'PAGO_INVALIDADO') {
        $module = 'finanzas';
        $level = 'confirmed';
        $resultCode = null;
        $resultDetail = null;
        if (array_key_exists('estado_anterior', $detail)) {
            $before = hache_auditoria_valor(true, ['estado' => $detail['estado_anterior']]);
        }
        if (array_key_exists('estado_nuevo', $detail)) {
            $after = hache_auditoria_valor(true, ['estado' => $detail['estado_nuevo']]);
        }
        if ($before['available'] || $after['available']) {
            $beforeAfter = 'structured';
        }
        if (isset($detail['sede_id']) && trim((string)$detail['sede_id']) !== '') {
            $scopeSede = trim((string)$detail['sede_id']);
            $scopeKnown = true;
        }
    } elseif ($action === 'INTENSIVO_ALUMNO_RETIRADO') {
        $module = 'intensivos';
        $level = 'confirmed';
        $resultCode = null;
        $resultDetail = null;
        if (array_key_exists('presente_anterior', $detail)) {
            $beforeValue = ['presente' => (bool)$detail['presente_anterior']];
            if (array_key_exists('alumno_id', $detail)) {
                $beforeValue['alumno_id'] = $detail['alumno_id'];
            }
            if (array_key_exists('curso_intensivo_id', $detail)) {
                $beforeValue['curso_intensivo_id'] = $detail['curso_intensivo_id'];
            }
            $before = hache_auditoria_valor(true, $beforeValue);
        }
        if (array_key_exists('presente_nuevo', $detail)) {
            $after = hache_auditoria_valor(true, ['presente' => (bool)$detail['presente_nuevo']]);
        }
        if ($before['available'] || $after['available']) {
            $beforeAfter = 'structured';
        }
        if (isset($detail['curso_intensivo_id']) && trim((string)$detail['curso_intensivo_id']) !== '') {
            $referenceType = 'curso_intensivo';
            $referenceId = trim((string)$detail['curso_intensivo_id']);
        }
        if (isset($detail['sede_id']) && trim((string)$detail['sede_id']) !== '') {
            $scopeSede = trim((string)$detail['sede_id']);
            $scopeKnown = true;
        }
    } elseif ($action === 'PROFESOR_DATOS_ACTUALIZADOS') {
        $module = 'profesores';
        $level = 'confirmed';
        $resultCode = null;
        $beforeValues = [];
        $afterValues = [];
        $redacted = [];
        $changes = isset($detail['cambios']) && is_array($detail['cambios']) ? $detail['cambios'] : [];
        foreach ($changes as $field => $change) {
            if (!is_array($change)) {
                continue;
            }
            if (array_key_exists('anterior', $change) || array_key_exists('nuevo', $change)) {
                $beforeValues[(string)$field] = $change['anterior'] ?? null;
                $afterValues[(string)$field] = $change['nuevo'] ?? null;
            } elseif (($change['modificado'] ?? false) === true) {
                $redacted[] = (string)$field;
            }
        }
        if ($beforeValues || $afterValues) {
            $before = hache_auditoria_valor(true, $beforeValues);
            $after = hache_auditoria_valor(true, $afterValues);
            $beforeAfter = 'structured';
        }
        $resultDetail = $redacted
            ? 'Campos modificados con valores omitidos: '.implode(', ', $redacted)
            : null;
    } elseif ($action === 'ELIMINAR_DEFINITIVO') {
        $module = 'alumnos';
        $level = 'confirmed';
        $resultCode = null;
        $resultDetail = 'Eliminación definitiva confirmada por la transacción de origen.';
    }

    return [
        'id' => 'auditoria_eventos:'.(string)($row['id'] ?? ''),
        'source' => 'auditoria_eventos',
        'source_id' => (string)($row['id'] ?? ''),
        'module' => $module,
        'action' => $action !== '' ? $action : 'EVENTO',
        'action_raw' => $action !== '' ? $action : null,
        'actor' => hache_auditoria_actor(
            isset($row['usuario_id']) ? (string)$row['usuario_id'] : null,
            isset($row['usuario_nombre']) ? (string)$row['usuario_nombre'] : null,
        ),
        'occurred_at' => hache_auditoria_marca(
            isset($row['created_at']) ? (string)$row['created_at'] : null,
            $level === 'confirmed' ? 'domain_change' : 'request_result',
        ),
        'entity' => [
            'type' => $entity !== '' ? $entity : null,
            'id' => isset($row['entidad_id']) && trim((string)$row['entidad_id']) !== '' ? trim((string)$row['entidad_id']) : null,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ],
        'before' => $before,
        'after' => $after,
        'result' => [
            'level' => $level,
            'code' => $resultCode,
            'detail' => $resultDetail,
        ],
        'reason' => $reason,
        'route' => isset($row['ruta']) && trim((string)$row['ruta']) !== '' ? trim((string)$row['ruta']) : null,
        'method' => isset($row['metodo']) && trim((string)$row['metodo']) !== '' ? strtoupper(trim((string)$row['metodo'])) : null,
        'scope' => [
            'sede_id' => $scopeKnown ? $scopeSede : null,
            'sede_known' => $scopeKnown,
        ],
        'coverage' => [
            'history' => $history,
            'before_after' => $beforeAfter,
        ],
    ];
}

function hache_auditoria_historial_modulo(string $type): string
{
    return match ($type) {
        'PAGO', 'MENSUALIDAD', 'INSCRIPCION', 'INVALIDACION_PAGO' => 'finanzas',
        'INTENSIVO' => 'intensivos',
        'CAMBIO_PLAN', 'BAJA', 'REACTIVACION' => 'alumnos',
        default => 'alumnos',
    };
}

function hache_auditoria_historial_normalizar(array $row): array
{
    $type = trim((string)($row['tipo'] ?? ''));
    $description = trim((string)($row['descripcion'] ?? ''));
    $textualBeforeAfter = $description !== ''
        && str_contains($description, 'Antes:')
        && str_contains($description, 'Después:');

    return [
        'id' => 'historial:'.(string)($row['id'] ?? ''),
        'source' => 'historial',
        'source_id' => (string)($row['id'] ?? ''),
        'module' => hache_auditoria_historial_modulo($type),
        'action' => $type !== '' ? $type : 'HISTORIAL',
        'action_raw' => $type !== '' ? $type : null,
        'actor' => hache_auditoria_actor(
            isset($row['usuario_id']) ? (string)$row['usuario_id'] : null,
            isset($row['usuario_nombre']) ? (string)$row['usuario_nombre'] : null,
        ),
        'occurred_at' => hache_auditoria_marca(
            isset($row['fecha_hora']) ? (string)$row['fecha_hora'] : null,
            'domain_change',
        ),
        'entity' => [
            'type' => 'alumno',
            'id' => isset($row['alumno_id']) && trim((string)$row['alumno_id']) !== '' ? trim((string)$row['alumno_id']) : null,
            'reference_type' => isset($row['referencia_tipo']) && trim((string)$row['referencia_tipo']) !== '' ? trim((string)$row['referencia_tipo']) : null,
            'reference_id' => isset($row['referencia_id']) && trim((string)$row['referencia_id']) !== '' ? trim((string)$row['referencia_id']) : null,
        ],
        'before' => hache_auditoria_valor(false),
        'after' => hache_auditoria_valor(false),
        'result' => [
            'level' => 'confirmed',
            'code' => null,
            'detail' => $description !== '' ? $description : null,
        ],
        'reason' => null,
        'route' => null,
        'method' => null,
        'scope' => [
            'sede_id' => null,
            'sede_known' => false,
        ],
        'coverage' => [
            'history' => 'native',
            'before_after' => $textualBeforeAfter ? 'textual' : 'none',
        ],
    ];
}

function hache_auditoria_ordenar(array &$events): void
{
    usort($events, static function (array $a, array $b): int {
        $av = (string)($a['occurred_at']['value'] ?? '');
        $bv = (string)($b['occurred_at']['value'] ?? '');
        if ($av !== $bv) {
            return strcmp($bv, $av);
        }
        return strcmp((string)($b['id'] ?? ''), (string)($a['id'] ?? ''));
    });
}
