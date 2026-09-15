<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

/** @return list<string> */
function centro_pendientes_split_sql(string $sql): array
{
    $withoutComments = preg_replace('/^\s*--.*$/m', '', $sql);
    if (!is_string($withoutComments)) {
        throw new RuntimeException('No se pudo normalizar la migración del Centro de pendientes.');
    }

    return array_values(array_filter(
        array_map('trim', explode(';', $withoutComments)),
        static fn(string $statement): bool => $statement !== ''
    ));
}

function centro_pendientes_schema_ready(PDO $pdo): bool
{
    $table = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='pendientes_gestion'");
    if ((int)$table->fetchColumn() !== 1) {
        return false;
    }

    $indexes = [
        'PRIMARY'=>['columns'=>['id'], 'non_unique'=>0],
        'uq_pendientes_gestion_identidad'=>['columns'=>['identidad'], 'non_unique'=>0],
        'idx_pendientes_gestion_sede_estado'=>['columns'=>['sede_id','estado'], 'non_unique'=>1],
        'idx_pendientes_gestion_origen'=>['columns'=>['origen_tipo','origen_id'], 'non_unique'=>1],
    ];
    $index = $pdo->prepare('SELECT column_name,non_unique FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=:table AND index_name=:index ORDER BY seq_in_index');
    foreach ($indexes as $name=>$expected) {
        $index->execute([':table'=>'pendientes_gestion', ':index'=>$name]);
        $rows = $index->fetchAll(PDO::FETCH_ASSOC);
        if (array_column($rows, 'column_name') !== $expected['columns']) {
            return false;
        }
        foreach ($rows as $row) {
            if ((int)($row['non_unique'] ?? -1) !== $expected['non_unique']) {
                return false;
            }
        }
    }

    $foreignKeys = [
        'fk_pendientes_gestion_sede'=>['column'=>'sede_id', 'table'=>'sedes', 'delete_rule'=>'RESTRICT'],
        'fk_pendientes_gestion_alumno'=>['column'=>'alumno_id', 'table'=>'alumnos', 'delete_rule'=>'SET NULL'],
        'fk_pendientes_gestion_atendido_por'=>['column'=>'atendido_por', 'table'=>'usuarios', 'delete_rule'=>'SET NULL'],
        'fk_pendientes_gestion_resuelto_por'=>['column'=>'resuelto_por', 'table'=>'usuarios', 'delete_rule'=>'SET NULL'],
    ];
    $foreignKey = $pdo->prepare('SELECT k.column_name,k.referenced_table_name,r.delete_rule FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name WHERE k.table_schema=DATABASE() AND k.table_name=:table AND k.constraint_name=:constraint');
    foreach ($foreignKeys as $name=>$expected) {
        $foreignKey->execute([':table'=>'pendientes_gestion', ':constraint'=>$name]);
        $row = $foreignKey->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || ($row['column_name'] ?? null) !== $expected['column']
            || ($row['referenced_table_name'] ?? null) !== $expected['table']
            || ($row['delete_rule'] ?? null) !== $expected['delete_rule']) {
            return false;
        }
    }

    return true;
}

try {
    $root = dirname(__DIR__);
    /** @var PDO $pdo */
    $pdo = require $root.'/config/pdo.php';
    $lock = (int)$pdo->query("SELECT GET_LOCK('hache_centro_pendientes_migration',10)")->fetchColumn();
    if ($lock !== 1) {
        throw new RuntimeException('No se pudo adquirir el bloqueo de la migración del Centro de pendientes.');
    }

    try {
        $file = $root.'/database/migrations/20260915_centro_pendientes.sql';
        $sql = file_get_contents($file);
        if (!is_string($sql)) {
            throw new RuntimeException('No se pudo leer la migración del Centro de pendientes.');
        }
        foreach (centro_pendientes_split_sql($sql) as $statement) {
            $pdo->exec($statement);
        }
        if (!centro_pendientes_schema_ready($pdo)) {
            throw new RuntimeException('La verificación de tabla, claves e índices del Centro de pendientes falló.');
        }
        fwrite(STDOUT, "CENTRO_PENDIENTES_MIGRATION_OK\n");
    } finally {
        try {
            $pdo->query("SELECT RELEASE_LOCK('hache_centro_pendientes_migration')");
        } catch (Throwable $ignored) {
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'CENTRO_PENDIENTES_MIGRATION_FAILED: '.$e->getMessage().PHP_EOL);
    exit(1);
}
