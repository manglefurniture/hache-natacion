<?php

declare(strict_types=1);

$internalHttp = defined('HACHE_PR_INTERNAL_HTTP') && HACHE_PR_INTERNAL_HTTP === true;
if (PHP_SAPI !== 'cli' && !$internalHttp) {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

umask(0077);
require_once __DIR__ . '/../config/sharky-delivery-status.php';

/** @return array{exists:bool,engine:?string} */
function pr_table_state(PDO $pdo, string $table): array
{
    $st = $pdo->prepare(
        'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1'
    );
    $st->execute([':table' => $table]);
    $engine = $st->fetchColumn();

    return [
        'exists' => $engine !== false,
        'engine' => $engine === false ? null : (string) $engine,
    ];
}

function pr_trigger_exists(PDO $pdo, string $trigger): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = :trigger LIMIT 1'
    );
    $st->execute([':trigger' => $trigger]);
    return (bool) $st->fetchColumn();
}

function pr_unique_column_exists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.STATISTICS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column AND NON_UNIQUE = 0 LIMIT 1'
    );
    $st->execute([':table' => $table, ':column' => $column]);
    return (bool) $st->fetchColumn();
}

function pr_deployed_sha(string $root): ?string
{
    if (!function_exists('shell_exec')) {
        return null;
    }
    $rootArg = escapeshellarg($root);
    $command = sprintf('git -c safe.directory=%s -C %s rev-parse HEAD 2>/dev/null', $rootArg, $rootArg);
    $sha = trim((string) shell_exec($command));
    return preg_match('/^[a-f0-9]{40}$/', $sha) ? $sha : null;
}

/** @return array{present:bool,status_counts:array<string,int>,latest_sent_day_stored:?string,sent_at_semantics:string} */
function pr_sharky_outbox_summary(PDO $pdo): array
{
    $semantics = 'sent_at is a timezone-naive DATETIME; day is reported exactly as stored and is not labeled UTC.';
    if (!pr_table_state($pdo, 'sharky_outbox')['exists']) {
        return [
            'present' => false,
            'status_counts' => [],
            'latest_sent_day_stored' => null,
            'sent_at_semantics' => $semantics,
        ];
    }

    $known = ['PENDING', 'SENT', 'DEAD', 'CANCELLED'];
    $counts = array_fill_keys($known, 0);
    $other = 0;
    $st = $pdo->query('SELECT status, COUNT(*) AS total FROM sharky_outbox GROUP BY status');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = strtoupper((string) ($row['status'] ?? ''));
        $total = max(0, (int) ($row['total'] ?? 0));
        if (array_key_exists($status, $counts)) {
            $counts[$status] = $total;
        } else {
            $other += $total;
        }
    }
    if ($other > 0) {
        $counts['OTHER'] = $other;
    }

    $latest = $pdo->query("SELECT DATE_FORMAT(MAX(sent_at), '%Y-%m-%d') FROM sharky_outbox WHERE status='SENT'")->fetchColumn();

    return [
        'present' => true,
        'status_counts' => $counts,
        'latest_sent_day_stored' => $latest === false || $latest === null || $latest === '' ? null : (string) $latest,
        'sent_at_semantics' => $semantics,
    ];
}

/** @param list<float> $values */
function pr_nearest_rank_p75(array $values): float
{
    sort($values, SORT_NUMERIC);
    $count = count($values);
    if ($count === 0) {
        throw new InvalidArgumentException('empty_values');
    }
    $index = max(0, (int) ceil(0.75 * $count) - 1);
    return (float) $values[$index];
}

/** @return array<string,mixed> */
function pr_rum_summary(PDO $pdo): array
{
    $tableState = pr_table_state($pdo, 'production_rum_samples');
    $targets = ['LCP' => 2500.0, 'INP' => 200.0, 'CLS' => 0.1];
    $windowDays = 14;
    $projectSampleFloor = 20;
    $maxRows = 100000;

    if (!$tableState['exists']) {
        return [
            'present' => false,
            'window_days' => $windowDays,
            'minimum_sample_count_per_group' => $projectSampleFloor,
            'production_readiness_state' => 'NOT EVALUATED',
            'decision' => 'HUMAN_REVIEW_REQUIRED',
            'groups' => [],
        ];
    }

    $total = (int) $pdo->query(
        "SELECT COUNT(*) FROM production_rum_samples WHERE created_at_utc >= UTC_TIMESTAMP(6) - INTERVAL {$windowDays} DAY"
    )->fetchColumn();

    if ($total > $maxRows) {
        return [
            'present' => true,
            'window_days' => $windowDays,
            'sample_count' => $total,
            'minimum_sample_count_per_group' => $projectSampleFloor,
            'production_readiness_state' => 'NOT EVALUATED',
            'decision' => 'HUMAN_REVIEW_REQUIRED',
            'evidence_status' => 'WINDOW_TOO_LARGE_FOR_INLINE_AGGREGATION',
            'groups' => [],
        ];
    }

    $st = $pdo->query(
        "SELECT metric, value, route_group, build_id, form_factor "
        . "FROM production_rum_samples "
        . "WHERE created_at_utc >= UTC_TIMESTAMP(6) - INTERVAL {$windowDays} DAY "
        . "ORDER BY id ASC"
    );

    /** @var array<string,array{metric:string,route_group:string,build_id:string,form_factor:string,values:list<float>}> $groups */
    $groups = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $metric = strtoupper((string) ($row['metric'] ?? ''));
        $route = (string) ($row['route_group'] ?? '');
        $build = (string) ($row['build_id'] ?? '');
        $form = (string) ($row['form_factor'] ?? '');
        $value = (float) ($row['value'] ?? -1);
        if (!isset($targets[$metric]) || $route === '' || $build === '' || !in_array($form, ['mobile', 'desktop'], true) || !is_finite($value) || $value < 0) {
            continue;
        }
        $key = implode("\0", [$metric, $route, $build, $form]);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'metric' => $metric,
                'route_group' => $route,
                'build_id' => $build,
                'form_factor' => $form,
                'values' => [],
            ];
        }
        $groups[$key]['values'][] = $value;
    }

    $summaries = [];
    foreach ($groups as $group) {
        $count = count($group['values']);
        if ($count === 0) {
            continue;
        }
        $p75 = pr_nearest_rank_p75($group['values']);
        $target = $targets[$group['metric']];
        $summaries[] = [
            'metric' => $group['metric'],
            'route_group' => $group['route_group'],
            'build_id' => $group['build_id'],
            'form_factor' => $group['form_factor'],
            'sample_count' => $count,
            'p75' => $p75,
            'target_max' => $target,
            'within_target' => $p75 <= $target,
            'meets_project_sample_floor' => $count >= $projectSampleFloor,
        ];
    }

    usort($summaries, static function (array $left, array $right): int {
        return [$left['route_group'], $left['form_factor'], $left['metric'], $left['build_id']]
            <=> [$right['route_group'], $right['form_factor'], $right['metric'], $right['build_id']];
    });

    return [
        'present' => true,
        'window_days' => $windowDays,
        'sample_count' => $total,
        'percentile_method' => 'nearest-rank',
        'minimum_sample_count_per_group' => $projectSampleFloor,
        'production_readiness_state' => 'NOT EVALUATED',
        'decision' => 'HUMAN_REVIEW_REQUIRED',
        'note' => 'Targets, p75 and the project sample floor are evidence only. Field PASS requires representative CUF/form-factor coverage and human review.',
        'groups' => $summaries,
    ];
}

try {
    $root = dirname(__DIR__);
    $configPath = $internalHttp ? $root . '/config/database.local.php' : $root . '/config/database.php';
    if ($internalHttp && !is_readable($configPath)) {
        throw new RuntimeException('database_config_unreadable');
    }
    $config = require $configPath;
    if (!is_array($config)) {
        throw new RuntimeException('database_config_invalid');
    }

    foreach (['host', 'dbname', 'user', 'password', 'charset'] as $key) {
        if (!array_key_exists($key, $config)) {
            throw new RuntimeException('database_config_incomplete');
        }
    }

    $pdo = new PDO(
        'mysql:host=' . $config['host'] . ';dbname=' . $config['dbname'] . ';charset=' . $config['charset'],
        (string) $config['user'],
        (string) $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $criticalTables = [
        'pagos',
        'mensualidades',
        'inscripciones',
        'cursos_intensivos',
        'curso_intensivo_alumnos',
        'cierres_mensuales',
        'auditoria_eventos',
        'sharky_outbox',
    ];
    $tables = [];
    foreach ($criticalTables as $table) {
        $tables[$table] = pr_table_state($pdo, $table);
    }

    $deliverySchemaReady = hache_sharky_delivery_schema_ready($pdo);
    $deliverySummary = hache_sharky_delivery_correlated_summary($pdo);
    $providerEvidenceState = $deliverySchemaReady && $deliverySummary['correlated_total'] > 0
        ? 'EVIDENCE AVAILABLE — HUMAN REVIEW REQUIRED'
        : 'NOT EVALUATED';

    $payload = [
        'schema_version' => 1,
        'collector' => 'hache-natacion-production-readiness-pilot-c',
        'ok' => true,
        'generated_at_utc' => gmdate('c'),
        'deployed_sha' => pr_deployed_sha($root),
        'runtime' => [
            'php_version' => PHP_VERSION,
            'pdo_mysql' => extension_loaded('pdo_mysql'),
        ],
        'database' => [
            'critical_tables' => $tables,
            'financial_guards' => [
                'pagos_folio_unique' => pr_unique_column_exists($pdo, 'pagos', 'folio'),
                'trg_un_pago_valido_insert' => pr_trigger_exists($pdo, 'trg_un_pago_valido_insert'),
                'trg_un_pago_valido_update' => pr_trigger_exists($pdo, 'trg_un_pago_valido_update'),
            ],
        ],
        'communication' => [
            'sharky_outbox' => pr_sharky_outbox_summary($pdo),
            'delivery_schema_ready' => $deliverySchemaReady,
            'provider_delivery' => $deliverySummary,
            'provider_delivery_status' => $providerEvidenceState,
            'note' => 'Local outbox SENT means the provider HTTP call was accepted. DELIVERED/READ evidence comes only from signed Meta status webhooks correlated by provider message id.',
        ],
        'field' => [
            'rum' => pr_rum_summary($pdo),
        ],
        'gates' => [
            'field' => 'NOT EVALUATED',
            'restore' => 'PARTIAL',
            'communication_delivery' => 'PARTIAL',
        ],
        'privacy' => [
            'contains_personal_rows' => false,
            'contains_message_payloads' => false,
            'contains_contact_identifiers' => false,
            'contains_credentials' => false,
        ],
    ];

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $e) {
    $error = [
        'schema_version' => 1,
        'collector' => 'hache-natacion-production-readiness-pilot-c',
        'ok' => false,
        'generated_at_utc' => gmdate('c'),
        'error' => 'EVIDENCE_COLLECTION_FAILED',
    ];
    $encoded = json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    if ($internalHttp) {
        http_response_code(500);
        echo $encoded;
    } else {
        fwrite(STDERR, $encoded);
    }
    exit(1);
}
