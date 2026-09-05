<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
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

try {
    $root = dirname(__DIR__);
    $config = require $root . '/config/database.php';
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
    fwrite(STDERR, json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}