<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/centro-pendientes-continuidad.php';

$me = auth_require(['ADMIN','VERIFICADOR']);
$config = require __DIR__.'/../config/database.php';

function pendientes_out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pendientes_sede(PDO $pdo, string $clave): array
{
    $st = $pdo->prepare('SELECT id,clave,nombre FROM sedes WHERE clave=:clave AND activo=1 LIMIT 1');
    $st->execute([':clave'=>$clave]);
    $sede = $st->fetch();
    if (!$sede) {
        throw new RuntimeException('Sede activa inválida');
    }
    return $sede;
}

function pendientes_tipos_habilitados(): array
{
    return array_values(array_unique([...CENTRO_PENDIENTES_TIPOS_HABILITADOS, CENTRO_PENDIENTES_CONTINUIDAD_TIPO]));
}

function pendientes_fuentes_activas(PDO $pdo, string $sedeId, string $sedeClave, string $sedeNombre): array
{
    return centro_pendientes_fuentes_activas($pdo, $sedeId, $sedeClave, $sedeNombre)
        + centro_pendientes_continuidad_fuentes_activas($pdo, $sedeId, $sedeNombre);
}

function pendientes_causa_activa(PDO $pdo, array $pendiente, string $sedeId, string $sedeClave): bool
{
    if ((string)($pendiente['tipo'] ?? '') === CENTRO_PENDIENTES_CONTINUIDAD_TIPO) {
        return centro_pendientes_continuidad_causa_activa($pdo, $pendiente, $sedeId);
    }
    return centro_pendientes_causa_activa($pdo, $pendiente, $sedeId, $sedeClave);
}

function pendientes_tipo_nombre(string $tipo): string
{
    return centro_pendientes_continuidad_descripcion_tipo($tipo) ?? centro_pendientes_descripcion_tipo($tipo);
}

function pendientes_presentar(array $pendiente, ?array $gestion, bool $causaActiva): array
{
    $estadoGestion = strtoupper((string)($gestion['estado'] ?? 'PENDIENTE'));
    $estado = centro_pendientes_estado_efectivo($gestion, $causaActiva);
    $pendiente['tipo_nombre'] = pendientes_tipo_nombre((string)$pendiente['tipo']);
    $pendiente['estado'] = $estado;
    $pendiente['estado_gestion'] = $estadoGestion;
    $pendiente['causa_activa'] = $causaActiva;
    $pendiente['resuelto_por_fuente'] = !$causaActiva && $estadoGestion !== 'RESUELTO';
    $pendiente['atendido_por'] = $gestion['atendido_por_nombre'] ?? null;
    $pendiente['atendido_at'] = $gestion['atendido_at'] ?? null;
    $pendiente['atencion_nota'] = $gestion['atencion_nota'] ?? null;
    $pendiente['resuelto_por'] = $gestion['resuelto_por_nombre'] ?? null;
    $pendiente['resuelto_at'] = $gestion['resuelto_at'] ?? null;
    $pendiente['resolucion_nota'] = $gestion['resolucion_nota'] ?? null;
    return $pendiente;
}

function pendientes_historico_presentable(array $gestion, bool $causaActiva): array
{
    $tipo = (string)$gestion['tipo'];
    $alumnoId = (string)($gestion['alumno_id'] ?? '');
    return [
        'identidad' => (string)$gestion['identidad'],
        'tipo' => $tipo,
        'origen_tipo' => (string)$gestion['origen_tipo'],
        'origen_id' => (string)$gestion['origen_id'],
        'alumno_id' => $alumnoId !== '' ? $alumnoId : null,
        'alumno_nombre' => $gestion['alumno_nombre'] ?? null,
        'sede_id' => (string)$gestion['sede_id'],
        'sede_nombre' => (string)$gestion['sede_nombre'],
        'periodo_inicio' => $gestion['periodo_inicio'] ?? null,
        'periodo_fin' => $gestion['periodo_fin'] ?? null,
        'fecha_referencia' => $gestion['periodo_inicio'] ?? $gestion['created_at'] ?? null,
        'explicacion' => $causaActiva
            ? 'La regla o el registro de origen vuelve a requerir atención.'
            : 'La causa ya no aplica según la fuente original.',
        'href' => centro_pendientes_continuidad_href_historico($tipo) ?? centro_pendientes_url($tipo, $alumnoId),
    ];
}

function pendientes_listar(PDO $pdo, array $sede): array
{
    $sedeId = (string)$sede['id'];
    $sedeClave = (string)$sede['clave'];
    $fuentes = pendientes_fuentes_activas($pdo, $sedeId, $sedeClave, (string)$sede['nombre']);
    $historico = centro_pendientes_historico($pdo, $sedeId);
    $items = [];

    foreach ($fuentes as $identidad => $pendiente) {
        $items[$identidad] = pendientes_presentar($pendiente, $historico[$identidad] ?? null, true);
    }
    foreach ($historico as $identidad => $gestion) {
        if (isset($items[$identidad])) {
            continue;
        }
        $causaActiva = pendientes_causa_activa($pdo, $gestion, $sedeId, $sedeClave);
        $items[$identidad] = pendientes_presentar(
            pendientes_historico_presentable($gestion, $causaActiva),
            $gestion,
            $causaActiva,
        );
    }

    $items = array_values($items);
    usort($items, static function (array $a, array $b): int {
        $orden = ['PENDIENTE'=>0,'ATENDIDO'=>1,'RESUELTO'=>2];
        $estado = ($orden[$a['estado']] ?? 9) <=> ($orden[$b['estado']] ?? 9);
        if ($estado !== 0) {
            return $estado;
        }
        return strcmp((string)($a['fecha_referencia'] ?? ''), (string)($b['fecha_referencia'] ?? ''));
    });
    return $items;
}

function pendientes_auditar(PDO $pdo, array $me, array $gestion, string $accion): void
{
    $detalle = json_encode([
        'identidad'=>$gestion['identidad'],
        'tipo'=>$gestion['tipo'],
        'origen_tipo'=>$gestion['origen_tipo'],
        'origen_id'=>$gestion['origen_id'],
        'estado'=>$gestion['estado'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $st = $pdo->prepare("INSERT INTO auditoria_eventos(usuario_id,usuario_nombre,accion,entidad,entidad_id,detalle,metodo,ruta)
        VALUES(:usuario_id,:usuario_nombre,:accion,'pendiente',:entidad_id,:detalle,'POST','/api/pendientes.php')");
    $st->execute([
        ':usuario_id'=>(string)$me['id'],
        ':usuario_nombre'=>(string)($me['usuario'] ?? ''),
        ':accion'=>$accion,
        ':entidad_id'=>(string)$gestion['id'],
        ':detalle'=>$detalle,
    ]);
}

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
        $config['user'],
        $config['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false],
    );
    $sede = pendientes_sede($pdo, auth_active_sede_clave());
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        pendientes_out([
            'ok'=>true,
            'sede'=>(string)$sede['clave'],
            'puede_gestionar'=>($me['rol'] ?? '') === 'ADMIN',
            'csrf'=>auth_csrf_token(),
            'tipos_habilitados'=>pendientes_tipos_habilitados(),
            'pendientes'=>pendientes_listar($pdo, $sede),
        ]);
    }
    if ($method !== 'POST') {
        pendientes_out(['ok'=>false,'error'=>'Método no permitido'], 405);
    }
    if (($me['rol'] ?? '') !== 'ADMIN') {
        pendientes_out(['ok'=>false,'error'=>'No tienes permiso para gestionar pendientes'], 403);
    }

    $input = auth_request_json();
    if (!auth_csrf_validate($input['csrf'] ?? null)) {
        pendientes_out(['ok'=>false,'error'=>'Solicitud no válida'], 403);
    }
    $accion = strtoupper(trim((string)($input['accion'] ?? '')));
    $identidad = trim((string)($input['identidad'] ?? ''));
    $nota = trim((string)($input['nota'] ?? ''));
    if (!in_array($accion, ['ATENDER','RESOLVER'], true) || !preg_match('/^[a-f0-9]{64}$/', $identidad)) {
        pendientes_out(['ok'=>false,'error'=>'Solicitud de pendiente inválida'], 422);
    }
    if (mb_strlen($nota) > 500) {
        pendientes_out(['ok'=>false,'error'=>'La nota no puede exceder 500 caracteres'], 422);
    }

    $pdo->beginTransaction();
    $sede = pendientes_sede($pdo, auth_active_sede_clave());
    $sedeId = (string)$sede['id'];
    if ($accion === 'ATENDER') {
        $fuentes = pendientes_fuentes_activas($pdo, $sedeId, (string)$sede['clave'], (string)$sede['nombre']);
        $pendiente = $fuentes[$identidad] ?? null;
        if (!$pendiente) {
            $pdo->rollBack();
            pendientes_out(['ok'=>false,'error'=>'La causa ya no requiere atención o no pertenece a la sede activa'], 409);
        }
        $atendido = centro_pendientes_gestion_atendida($pendiente, $me, $nota !== '' ? $nota : null, date('Y-m-d H:i:s'));
        $id = (string)$pdo->query('SELECT UUID()')->fetchColumn();
        $st = $pdo->prepare("INSERT INTO pendientes_gestion(
                id,identidad,sede_id,tipo,origen_tipo,origen_id,alumno_id,periodo_inicio,periodo_fin,
                estado,atendido_por,atendido_por_nombre,atendido_at,atencion_nota
            ) VALUES(
                :id,:identidad,:sede,:tipo,:origen_tipo,:origen_id,:alumno,:periodo_inicio,:periodo_fin,
                'ATENDIDO',:atendido_por,:atendido_por_nombre,:atendido_at,:atencion_nota
            ) ON DUPLICATE KEY UPDATE
                estado='ATENDIDO',
                atendido_por=VALUES(atendido_por),
                atendido_por_nombre=VALUES(atendido_por_nombre),
                atendido_at=VALUES(atendido_at),
                atencion_nota=VALUES(atencion_nota)");
        $st->execute([
            ':id'=>$id,
            ':identidad'=>$identidad,
            ':sede'=>$sedeId,
            ':tipo'=>$pendiente['tipo'],
            ':origen_tipo'=>$pendiente['origen_tipo'],
            ':origen_id'=>$pendiente['origen_id'],
            ':alumno'=>$pendiente['alumno_id'],
            ':periodo_inicio'=>$pendiente['periodo_inicio'],
            ':periodo_fin'=>$pendiente['periodo_fin'],
            ':atendido_por'=>$atendido['atendido_por'],
            ':atendido_por_nombre'=>$atendido['atendido_por_nombre'],
            ':atendido_at'=>$atendido['atendido_at'],
            ':atencion_nota'=>$atendido['atencion_nota'],
        ]);
        $st = $pdo->prepare('SELECT * FROM pendientes_gestion WHERE identidad=:identidad AND sede_id=:sede LIMIT 1 FOR UPDATE');
        $st->execute([':identidad'=>$identidad, ':sede'=>$sedeId]);
        $gestion = $st->fetch();
        if (!$gestion) {
            throw new RuntimeException('No se pudo guardar la atención del pendiente');
        }
        pendientes_auditar($pdo, $me, $gestion, 'PENDIENTE_ATENDIDO');
        $pdo->commit();
        pendientes_out(['ok'=>true,'estado'=>'ATENDIDO']);
    }

    $st = $pdo->prepare('SELECT * FROM pendientes_gestion WHERE identidad=:identidad AND sede_id=:sede LIMIT 1 FOR UPDATE');
    $st->execute([':identidad'=>$identidad, ':sede'=>$sedeId]);
    $gestion = $st->fetch();
    if (!$gestion) {
        $pdo->rollBack();
        pendientes_out(['ok'=>false,'error'=>'Solo puede resolverse un pendiente con gestión registrada'], 409);
    }
    if (!centro_pendientes_puede_resolver(pendientes_causa_activa($pdo, $gestion, $sedeId, (string)$sede['clave']))) {
        $pdo->rollBack();
        pendientes_out(['ok'=>false,'error'=>'La causa original sigue vigente; resuélvela en su módulo antes de cerrar el pendiente'], 409);
    }
    $st = $pdo->prepare("UPDATE pendientes_gestion
        SET estado='RESUELTO',resuelto_por=:usuario,resuelto_por_nombre=:nombre,resuelto_at=NOW(),resolucion_nota=:nota
        WHERE id=:id AND sede_id=:sede");
    $st->execute([
        ':usuario'=>(string)$me['id'],
        ':nombre'=>(string)($me['usuario'] ?? ''),
        ':nota'=>$nota !== '' ? $nota : null,
        ':id'=>$gestion['id'],
        ':sede'=>$sedeId,
    ]);
    $st = $pdo->prepare('SELECT * FROM pendientes_gestion WHERE id=:id LIMIT 1');
    $st->execute([':id'=>$gestion['id']]);
    $gestion = $st->fetch();
    if (!$gestion) {
        throw new RuntimeException('No se pudo guardar la resolución del pendiente');
    }
    pendientes_auditar($pdo, $me, $gestion, 'PENDIENTE_RESUELTO');
    $pdo->commit();
    pendientes_out(['ok'=>true,'estado'=>'RESUELTO']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[pendientes] '.$e->getMessage());
    pendientes_out(['ok'=>false,'error'=>'No se pudieron cargar o gestionar los pendientes'], 500);
}
