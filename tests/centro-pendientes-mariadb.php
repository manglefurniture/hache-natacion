<?php

declare(strict_types=1);

function pendientes_db_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pendientes_db_run_migrator(string $root, string $host, string $database, string $user, string $password): void
{
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/migrate-centro-pendientes.php');
    $pipes = [];
    $process = proc_open($command, [1=>['pipe','w'], 2=>['pipe','w']], $pipes, $root, [
        'DB_HOST'=>$host,
        'DB_NAME'=>$database,
        'DB_USER'=>$user,
        'DB_PASS'=>$password,
        'DB_CHARSET'=>'utf8mb4',
    ]);
    pendientes_db_expect(is_resource($process), 'No se pudo iniciar el runner de migración.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    pendientes_db_expect($exitCode === 0, 'El runner de Centro de pendientes falló: '.trim($stderr));
    pendientes_db_expect(str_contains($stdout, 'CENTRO_PENDIENTES_MIGRATION_OK'), 'El runner no confirmó la migración aplicada.');
}

$host = (string)(getenv('DELIVERY_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('DELIVERY_DB_PORT') ?: 3306);
$database = (string)(getenv('DELIVERY_DB_NAME') ?: 'hache_delivery_test');
$user = (string)(getenv('DELIVERY_DB_USER') ?: 'root');
$password = (string)(getenv('DELIVERY_DB_PASS') ?: 'root');
$admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
$testDatabase = 'hache_pendientes_'.getmypid();
$admin->exec("DROP DATABASE IF EXISTS `{$testDatabase}`");
$admin->exec("CREATE DATABASE `{$testDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$testDatabase};charset=utf8mb4", $user, $password, [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE sedes (id CHAR(36) NOT NULL PRIMARY KEY) {$engine}");
    $pdo->exec("CREATE TABLE alumnos (id CHAR(36) NOT NULL PRIMARY KEY) {$engine}");
    $pdo->exec("CREATE TABLE usuarios (id CHAR(36) NOT NULL PRIMARY KEY) {$engine}");

$root = dirname(__DIR__);
pendientes_db_run_migrator($root, $host, $testDatabase, $user, $password);
pendientes_db_run_migrator($root, $host, $testDatabase, $user, $password);

$table = $pdo->query("SELECT ENGINE,TABLE_COLLATION FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='pendientes_gestion'")->fetch();
pendientes_db_expect(is_array($table), 'La tabla pendientes_gestion no fue creada.');
pendientes_db_expect(strtoupper((string)$table['ENGINE']) === 'INNODB', 'pendientes_gestion debe usar InnoDB.');
pendientes_db_expect(strtolower((string)$table['TABLE_COLLATION']) === 'utf8mb4_unicode_ci', 'La colación de pendientes_gestion cambió.');

$indexes = [
    'PRIMARY'=>['id'],
    'uq_pendientes_gestion_identidad'=>['identidad'],
    'idx_pendientes_gestion_sede_estado'=>['sede_id','estado'],
    'idx_pendientes_gestion_origen'=>['origen_tipo','origen_id'],
];
$indexQuery = $pdo->prepare('SELECT column_name,non_unique FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=:table AND index_name=:index ORDER BY seq_in_index');
foreach ($indexes as $name=>$columns) {
    $indexQuery->execute([':table'=>'pendientes_gestion', ':index'=>$name]);
    $rows = $indexQuery->fetchAll();
    pendientes_db_expect(array_column($rows, 'column_name') === $columns, "El índice {$name} no conserva sus columnas.");
    if ($name === 'PRIMARY' || $name === 'uq_pendientes_gestion_identidad') {
        pendientes_db_expect(count($rows) === 1 && (int)$rows[0]['non_unique'] === 0, "El índice {$name} debe ser único.");
    }
}

$foreignKeys = [
    'fk_pendientes_gestion_sede'=>['sede_id','sedes','RESTRICT'],
    'fk_pendientes_gestion_alumno'=>['alumno_id','alumnos','SET NULL'],
    'fk_pendientes_gestion_atendido_por'=>['atendido_por','usuarios','SET NULL'],
    'fk_pendientes_gestion_resuelto_por'=>['resuelto_por','usuarios','SET NULL'],
];
$foreignKeyQuery = $pdo->prepare('SELECT k.column_name,k.referenced_table_name,r.delete_rule FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name WHERE k.table_schema=DATABASE() AND k.table_name=:table AND k.constraint_name=:constraint');
foreach ($foreignKeys as $name=>$expected) {
    $foreignKeyQuery->execute([':table'=>'pendientes_gestion', ':constraint'=>$name]);
    $row = $foreignKeyQuery->fetch();
    pendientes_db_expect(is_array($row), "Falta la clave foránea {$name}.");
    pendientes_db_expect([$row['column_name'],$row['referenced_table_name'],$row['delete_rule']] === $expected, "La clave foránea {$name} cambió.");
}

$sede = '00000000-0000-0000-0000-000000000090';
$pdo->prepare('INSERT IGNORE INTO sedes(id) VALUES(:id)')->execute([':id'=>$sede]);
$insert = $pdo->prepare("INSERT INTO pendientes_gestion(id,identidad,sede_id,tipo,origen_tipo,origen_id) VALUES(:id,:identidad,:sede,'MENSUALIDAD_REGULAR_SIN_COBERTURA','ALUMNO_REGULAR','alumno-1')");
$insert->execute([':id'=>'00000000-0000-0000-0000-000000000091', ':identidad'=>str_repeat('a', 64), ':sede'=>$sede]);
$duplicateBlocked = false;
try {
    $insert->execute([':id'=>'00000000-0000-0000-0000-000000000092', ':identidad'=>str_repeat('a', 64), ':sede'=>$sede]);
} catch (PDOException $e) {
    $duplicateBlocked = true;
}
pendientes_db_expect($duplicateBlocked, 'La identidad de gestión debe permanecer única.');

fwrite(STDOUT, "CENTRO_PENDIENTES_MARIADB_OK\n");
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$testDatabase}`");
}
