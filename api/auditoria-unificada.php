<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/auditoria-unificada.php';

auth_require(['ADMIN']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['ok'=>false,'error'=>'Método no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

function f8_auditoria_fecha(string $value, string $label): ?DateTimeImmutable
{
    if ($value === '') {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        http_response_code(422);
        echo json_encode(['ok'=>false,'error'=>$label.' inválida'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $date;
}

$source = strtolower(trim((string)($_GET['fuente'] ?? '')));
if (!in_array($source, ['', 'auditoria_eventos', 'historial'], true)) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'Fuente inválida'], JSON_UNESCAPED_UNICODE);
    exit;
}

$limitRaw = $_GET['limite'] ?? 100;
$limit = filter_var($limitRaw, FILTER_VALIDATE_INT);
if ($limit === false || $limit < 20 || $limit > 300) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'El límite debe estar entre 20 y 300'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fromRaw = trim((string)($_GET['desde'] ?? ''));
$toRaw = trim((string)($_GET['hasta'] ?? ''));
$from = f8_auditoria_fecha($fromRaw, 'Fecha desde');
$to = f8_auditoria_fecha($toRaw, 'Fecha hasta');
if ($from && $to && $to < $from) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'La fecha hasta no puede ser anterior a la fecha desde'], JSON_UNESCAPED_UNICODE);
    exit;
}
$toExclusive = $to ? $to->modify('+1 day') : null;

$config = require __DIR__.'/../config/database.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
        $config['user'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ],
    );

    $events = [];
    $sourceLimit = (int)$limit;

    if ($source === '' || $source === 'auditoria_eventos') {
        $where = [];
        $params = [];
        if ($from) {
            $where[] = 'created_at>=:audit_from';
            $params[':audit_from'] = $from->format('Y-m-d 00:00:00');
        }
        if ($toExclusive) {
            $where[] = 'created_at<:audit_to';
            $params[':audit_to'] = $toExclusive->format('Y-m-d 00:00:00');
        }
        $sql = 'SELECT id,usuario_id,usuario_nombre,accion,entidad,entidad_id,detalle,metodo,ruta,created_at FROM auditoria_eventos';
        if ($where) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC,id DESC LIMIT '.$sourceLimit;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll() as $row) {
            $events[] = hache_auditoria_evento_normalizar($row);
        }
    }

    if ($source === '' || $source === 'historial') {
        $where = [];
        $params = [];
        if ($from) {
            $where[] = 'h.fecha_hora>=:history_from';
            $params[':history_from'] = $from->format('Y-m-d 00:00:00');
        }
        if ($toExclusive) {
            $where[] = 'h.fecha_hora<:history_to';
            $params[':history_to'] = $toExclusive->format('Y-m-d 00:00:00');
        }
        $sql = 'SELECT h.id,h.alumno_id,h.tipo,h.fecha_hora,h.descripcion,h.usuario_id,u.usuario usuario_nombre,h.referencia_tipo,h.referencia_id
            FROM historial h
            LEFT JOIN usuarios u ON u.id=h.usuario_id';
        if ($where) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }
        $sql .= ' ORDER BY h.fecha_hora DESC,h.id DESC LIMIT '.$sourceLimit;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll() as $row) {
            $events[] = hache_auditoria_historial_normalizar($row);
        }
    }

    $events = hache_auditoria_agregar([$events], $limit);

    $returnedSources = [];
    foreach ($events as $event) {
        $key = (string)$event['source'];
        $returnedSources[$key] = ($returnedSources[$key] ?? 0) + 1;
    }

    echo json_encode([
        'ok'=>true,
        'contract'=>'F8.1',
        'read_only'=>true,
        'eventos'=>$events,
        'meta'=>[
            'limite'=>$limit,
            'fuente'=>$source !== '' ? $source : null,
            'desde'=>$fromRaw !== '' ? $fromRaw : null,
            'hasta'=>$toRaw !== '' ? $toRaw : null,
            'fuentes_devuelta'=>$returnedSources,
            'before_after'=>'solo_evidencia_durable',
            'sede'=>'sin_filtro_hasta_fuente_historica_segura',
            'correlacion'=>'sin_heuristicas',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[auditoria-unificada] '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'No se pudo cargar la auditoría unificada'], JSON_UNESCAPED_UNICODE);
}
