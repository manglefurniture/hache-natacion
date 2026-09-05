<?php

declare(strict_types=1);

function rum_db_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$host = (string) (getenv('DELIVERY_DB_HOST') ?: '127.0.0.1');
$port = (int) (getenv('DELIVERY_DB_PORT') ?: 3306);
$db = (string) (getenv('DELIVERY_DB_NAME') ?: 'hache_delivery_test');
$user = (string) (getenv('DELIVERY_DB_USER') ?: 'root');
$pass = (string) (getenv('DELIVERY_DB_PASS') ?: 'root');

$pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$pdo->exec('DROP TABLE IF EXISTS production_rum_samples');
$sql = (string) file_get_contents(__DIR__ . '/../database/migrations/20260905_production_rum.sql');
$sqlWithoutLineComments = preg_replace('/^\s*--.*$/m', '', $sql);
rum_db_expect(is_string($sqlWithoutLineComments), 'Unable to normalize RUM migration SQL.');
$statements = array_values(array_filter(
    array_map('trim', explode(';', $sqlWithoutLineComments)),
    static fn(string $statement): bool => $statement !== ''
));
foreach ($statements as $statement) {
    $pdo->exec($statement);
}

$columns = $pdo->query(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS "
    . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'production_rum_samples' ORDER BY ORDINAL_POSITION"
)->fetchAll(PDO::FETCH_COLUMN);
rum_db_expect($columns === [
    'id',
    'metric',
    'value',
    'route_group',
    'build_id',
    'form_factor',
    'created_at_utc',
], 'RUM table must contain only minimized evidence columns.');

$valueColumn = $pdo->query(
    "SELECT NUMERIC_PRECISION, NUMERIC_SCALE FROM information_schema.COLUMNS "
    . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'production_rum_samples' AND COLUMN_NAME = 'value'"
)->fetch();
rum_db_expect(is_array($valueColumn), 'RUM value column missing.');
rum_db_expect((int) $valueColumn['NUMERIC_PRECISION'] === 20, 'RUM value precision drift.');
rum_db_expect((int) $valueColumn['NUMERIC_SCALE'] === 8, 'RUM value scale drift.');

$insert = $pdo->prepare(
    'INSERT INTO production_rum_samples(metric,value,route_group,build_id,form_factor,created_at_utc) '
    . "VALUES('CLS',:value,'home','git-0123456789ab','mobile',UTC_TIMESTAMP(6))"
);
$insert->execute([':value' => '0.10000001']);
$stored = $pdo->query('SELECT CAST(value AS CHAR) FROM production_rum_samples LIMIT 1')->fetchColumn();
rum_db_expect($stored === '0.10000001', 'RUM storage must preserve eight-decimal CLS evidence.');

$indexes = $pdo->query(
    "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS "
    . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'production_rum_samples'"
)->fetchAll(PDO::FETCH_COLUMN);
rum_db_expect(in_array('idx_production_rum_window', $indexes, true), 'RUM window index missing.');
rum_db_expect(in_array('idx_production_rum_build', $indexes, true), 'RUM build index missing.');

echo "PRODUCTION_READINESS_RUM_MARIADB_OK\n";
