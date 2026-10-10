<?php
declare(strict_types=1);

/**
 * Observaciones históricas del monitor. No representan PRL confirmados ni ingresos.
 * Los grupos con varias réplicas se registran, pero no se atribuye a cada
 * instancia el hashrate mezclado de los logs del Container Group.
 */
function hache_salad_history_dir(): string
{
    return (string)(getenv('SALAD_MONITOR_HISTORY_DIR') ?: '/var/lib/hache-natacion/salad-monitor-history');
}

/** @return list<array<string,mixed>> */
function hache_salad_history_rows(array $groups, DateTimeImmutable $now): array
{
    $nowUtc = $now->setTimezone(new DateTimeZone('UTC'));
    $nowTs = $nowUtc->getTimestamp();
    $localDate = $nowUtc->setTimezone(new DateTimeZone('America/Cancun'))->format('Y-m-d');
    $rows = [];
    foreach ($groups as $group) {
        if (!is_array($group)) continue;
        $name = (string)($group['group'] ?? '');
        if ($name === '') continue;
        $instances = is_array($group['instances'] ?? null) ? $group['instances'] : [];
        $active = [];
        foreach ($instances as $instance) {
            if (!is_array($instance)) continue;
            $state = strtolower((string)($instance['state'] ?? ''));
            if (($instance['ready'] ?? false) === true &&
                (($instance['started'] ?? false) === true || $state === 'running')) {
                $active[] = $instance;
            }
        }
        $metrics = is_array($group['metrics'] ?? null) ? $group['metrics'] : [];
        $hashLog = (string)($metrics['hashrate_at'] ?? '');
        $hashTs = $hashLog !== '' ? strtotime($hashLog) : false;
        $instanceUpdated = count($active) === 1 ? (string)($active[0]['update_time'] ?? '') : '';
        $instanceTs = $instanceUpdated !== '' ? strtotime($instanceUpdated) : false;
        $hash = $metrics['hashrate_ths'] ?? null;
        // Sin timestamp de la GPU y del nodo no podemos atribuir una lectura
        // reciente a la instancia activa (especialmente tras reallocate).
        $valid = !($group['stale'] ?? false) && count($active) === 1 &&
            is_numeric($hash) && is_finite((float)$hash) && (float)$hash > 0 &&
            $hashTs !== false && $hashTs <= $nowTs + 120 && $nowTs - $hashTs <= 600 &&
            $instanceTs !== false && $hashTs >= $instanceTs;
        $shares = is_array($metrics['shares'] ?? null) ? $metrics['shares'] : [];
        $rows[] = [
            'at' => $nowUtc->format(DATE_ATOM),
            'date' => $localDate,
            'group' => substr($name, 0, 160),
            'gpu' => substr((string)($metrics['gpu'] ?? ''), 0, 160),
            'priority' => substr(strtolower((string)($group['priority'] ?? 'unknown')), 0, 30),
            'state' => substr((string)($group['state'] ?? ''), 0, 40),
            'active' => count($active),
            'instance_id' => count($active) === 1 ? substr((string)($active[0]['id'] ?? ''), 0, 100) : null,
            'machine_id' => count($active) === 1 ? substr((string)($active[0]['machine_id'] ?? ''), 0, 100) : null,
            'hashrate_ths' => $valid ? round((float)$hash, 3) : null,
            'hashrate_source_at' => $valid ? gmdate(DATE_ATOM,$hashTs) : null,
            'reported_ths_unattributed' => count($active) > 1 && is_numeric($hash) ? round((float)$hash, 3) : null,
            'accepted_shares_snapshot' => max(0, (int)($shares['accepted'] ?? 0)),
            'stale' => (bool)($group['stale'] ?? false),
        ];
    }
    return $rows;
}

/** @param list<array<string,mixed>> $groups */
function hache_salad_history_append(array $groups, ?DateTimeImmutable $now = null): void
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $rows = hache_salad_history_rows($groups, $now);
    if ($rows === []) return;
    $dir = hache_salad_history_dir();
    if (is_link($dir)) throw new RuntimeException('Directorio de histórico inseguro.');
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear el histórico.');
    }
    $date = $rows[0]['date'];
    $path = $dir.'/'.$date.'.jsonl';
    if (is_link($path)) throw new RuntimeException('Archivo de histórico inseguro.');
    $handle = fopen($path, 'ab');
    if ($handle === false) throw new RuntimeException('No se pudo abrir el histórico.');
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('No se pudo bloquear el histórico.');
        chmod($path, 0600);
        $line = json_encode(['at' => $rows[0]['at'], 'groups' => $rows], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        if (fwrite($handle, $line) !== strlen($line) || !fflush($handle)) {
            throw new RuntimeException('No se pudo escribir el histórico.');
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * Métricas de capacidad observada, NO PRL reales.
 * Solo integra pares de lecturas consecutivas (<=11 min), misma instancia,
 * misma GPU, mismo día Cancún y ninguna lectura stale/múltiple.
 *
 * @return array{days:int,rows:list<array<string,mixed>>,recorded_samples:int}
 */
function hache_salad_history_report(int $days = 7, ?DateTimeImmutable $now = null): array
{
    $days = max(1, min(14, $days));
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $today = $now->setTimezone(new DateTimeZone('America/Cancun'))->setTime(0, 0);
    $dir = hache_salad_history_dir();
    $result = [];
    $previous = [];
    $recorded = 0;
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = $today->modify('-'.$i.' days')->format('Y-m-d');
        $path = $dir.'/'.$date.'.jsonl';
        if (is_link($path) || !is_file($path)) continue;
        if (filesize($path) > 5 * 1024 * 1024) throw new RuntimeException('Histórico diario demasiado grande.');
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) throw new RuntimeException('No se pudo leer el histórico.');
        foreach ($lines as $line) {
            $event = json_decode($line, true);
            if (!is_array($event) || !is_array($event['groups'] ?? null)) continue;
            foreach ($event['groups'] as $row) {
                if (!is_array($row)) continue;
                $group = (string)($row['group'] ?? '');
                $gpu = (string)($row['gpu'] ?? '');
                $priority = (string)($row['priority'] ?? '');
                $rowDate = (string)($row['date'] ?? '');
                $ts = strtotime((string)($row['at'] ?? ''));
                if ($group === '' || $ts === false || $rowDate !== $date) continue;
                $key = json_encode([$rowDate,$group,$gpu,$priority], JSON_THROW_ON_ERROR);
                if (!isset($result[$key])) {
                    $result[$key] = ['date'=>$rowDate,'group'=>$group,'gpu'=>$gpu,
                        'priority'=>$priority,'samples'=>0,'max_active'=>0,
                        'ambiguous_samples'=>0,'monitored_minutes'=>0.0,
                        'capacity_ths_hours'=>0.0,'average_ths'=>null];
                }
                $r = &$result[$key];
                $r['samples']++;
                $r['max_active'] = max($r['max_active'], (int)($row['active'] ?? 0));
                if ((int)($row['active'] ?? 0) > 1) $r['ambiguous_samples']++;
                $recorded++;
                $hash = $row['hashrate_ths'] ?? null;
                $id = (string)($row['instance_id'] ?? '');
                $source = (string)($row['hashrate_source_at'] ?? '');
                $sourceTs = $source !== '' ? strtotime($source) : false;
                $valid = $id !== '' && (int)($row['active'] ?? 0) === 1
                    && !($row['stale'] ?? false) && is_numeric($hash)
                    && is_finite((float)$hash) && (float)$hash > 0
                    && $sourceTs !== false && $sourceTs <= $ts + 120;
                if ($valid && isset($previous[$group])) {
                    $old = $previous[$group];
                    $sameNode = $old['id'] === $id && $old['key'] === $key && $old['valid'];
                    // Repetir un mismo log no equivale a otra observación de
                    // tasa. Conservamos el primer punto como ancla temporal.
                    if ($sameNode && $sourceTs === $old['source_ts']) {
                        unset($r);
                        continue;
                    }
                    $gap = $ts - $old['ts'];
                    if ($sameNode && $sourceTs > $old['source_ts'] &&
                        $gap > 0 && $gap <= 660) {
                        $hours = $gap / 3600;
                        $r['capacity_ths_hours'] += (($old['hash'] + (float)$hash) / 2) * $hours;
                        $r['monitored_minutes'] += $gap / 60;
                    }
                }
                $previous[$group] = ['ts'=>$ts,'key'=>$key,'id'=>$id,
                    'valid'=>$valid,'source_ts'=>$valid?$sourceTs:0,
                    'hash'=>$valid?(float)$hash:0.0];
                unset($r);
            }
        }
    }
    foreach ($result as &$row) {
        if ($row['monitored_minutes'] > 0) {
            $row['average_ths'] = round($row['capacity_ths_hours'] / ($row['monitored_minutes']/60), 2);
        }
        $row['monitored_minutes'] = round($row['monitored_minutes'], 1);
        $row['capacity_ths_hours'] = round($row['capacity_ths_hours'], 2);
    }
    unset($row);
    $rows = array_values($result);
    usort($rows, static fn(array $a, array $b): int =>
        strcmp($b['date'], $a['date']) ?: strcmp($a['group'], $b['group']));
    return ['days'=>$days,'rows'=>$rows,'recorded_samples'=>$recorded];
}
