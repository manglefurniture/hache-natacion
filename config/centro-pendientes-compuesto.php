<?php
declare(strict_types=1);

require_once __DIR__.'/centro-pendientes-continuidad.php';
require_once __DIR__.'/centro-pendientes-prospectos.php';

/**
 * Composición de lectura del Centro de pendientes.
 *
 * Reúne las fuentes habilitadas por F1/F5 sin consultar endpoints HTTP ni
 * ejecutar escrituras incidentales. Las reglas de dominio permanecen en sus
 * autoridades originales.
 */
function centro_pendientes_compuesto_tipos_habilitados(bool $includeGlobalProspects = false): array
{
    $tipos = [...CENTRO_PENDIENTES_TIPOS_HABILITADOS, CENTRO_PENDIENTES_CONTINUIDAD_TIPO];
    if ($includeGlobalProspects) {
        $tipos[] = CENTRO_PENDIENTES_PROSPECTO_TIPO;
    }
    return array_values(array_unique($tipos));
}

function centro_pendientes_compuesto_fuentes_activas(PDO $pdo, array $sede, bool $includeGlobalProspects = false, ?DateTimeImmutable $referencia = null): array
{
    $sedeId = (string)$sede['id'];
    $fuentes = centro_pendientes_fuentes_activas(
        $pdo,
        $sedeId,
        (string)$sede['clave'],
        (string)$sede['nombre'],
        $referencia,
    ) + centro_pendientes_continuidad_fuentes_activas(
        $pdo,
        $sedeId,
        (string)$sede['nombre'],
    );

    if ($includeGlobalProspects) {
        $fuentes += centro_pendientes_prospectos_fuentes_activas($pdo);
    }

    return $fuentes;
}

function centro_pendientes_compuesto_historico(PDO $pdo, string $sedeId, bool $includeGlobalProspects = false): array
{
    $historico = centro_pendientes_historico($pdo, $sedeId);
    if ($includeGlobalProspects) {
        $historico += centro_pendientes_prospectos_historico($pdo);
    }
    return $historico;
}

function centro_pendientes_compuesto_causa_activa(PDO $pdo, array $pendiente, string $sedeId, string $sedeClave): bool
{
    $tipo = (string)($pendiente['tipo'] ?? '');
    if ($tipo === CENTRO_PENDIENTES_PROSPECTO_TIPO) {
        return centro_pendientes_prospectos_causa_activa($pdo, $pendiente);
    }
    if ($tipo === CENTRO_PENDIENTES_CONTINUIDAD_TIPO) {
        return centro_pendientes_continuidad_causa_activa($pdo, $pendiente, $sedeId);
    }
    return centro_pendientes_causa_activa($pdo, $pendiente, $sedeId, $sedeClave);
}

function centro_pendientes_compuesto_descripcion_tipo(string $tipo): string
{
    return centro_pendientes_prospectos_descripcion_tipo($tipo)
        ?? centro_pendientes_continuidad_descripcion_tipo($tipo)
        ?? centro_pendientes_descripcion_tipo($tipo);
}

function centro_pendientes_compuesto_href_historico(string $tipo, string $alumnoId = ''): string
{
    return centro_pendientes_prospectos_href_historico($tipo)
        ?? centro_pendientes_continuidad_href_historico($tipo)
        ?? centro_pendientes_url($tipo, $alumnoId);
}

/**
 * Resume únicamente causas activas. Un caso atendido cuya causa siga vigente
 * continúa formando parte del total operativo, pero queda separado de los
 * asuntos todavía PENDIENTES. Los históricos resueltos no inflan el resumen.
 */
function centro_pendientes_compuesto_resumen_activo(PDO $pdo, array $sede, bool $includeGlobalProspects = false, ?DateTimeImmutable $referencia = null): array
{
    $fuentes = centro_pendientes_compuesto_fuentes_activas($pdo, $sede, $includeGlobalProspects, $referencia);
    $historico = centro_pendientes_compuesto_historico($pdo, (string)$sede['id'], $includeGlobalProspects);
    $resumen = [
        'total' => 0,
        'pendientes' => 0,
        'atendidos' => 0,
        'sede' => 0,
        'globales' => 0,
        'por_tipo' => [],
    ];

    foreach ($fuentes as $identidad => $pendiente) {
        $estado = centro_pendientes_estado_efectivo($historico[$identidad] ?? null, true);
        $tipo = (string)($pendiente['tipo'] ?? '');
        if (!isset($resumen['por_tipo'][$tipo])) {
            $resumen['por_tipo'][$tipo] = [
                'tipo' => $tipo,
                'nombre' => centro_pendientes_compuesto_descripcion_tipo($tipo),
                'total' => 0,
                'pendientes' => 0,
                'atendidos' => 0,
            ];
        }

        $resumen['total']++;
        $resumen['por_tipo'][$tipo]['total']++;
        if ($estado === 'ATENDIDO') {
            $resumen['atendidos']++;
            $resumen['por_tipo'][$tipo]['atendidos']++;
        } else {
            $resumen['pendientes']++;
            $resumen['por_tipo'][$tipo]['pendientes']++;
        }

        if (($pendiente['sede_id'] ?? null) === null || (string)($pendiente['sede_id'] ?? '') === '') {
            $resumen['globales']++;
        } else {
            $resumen['sede']++;
        }
    }

    ksort($resumen['por_tipo']);
    return $resumen;
}
