<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){
    fwrite(STDERR,"CLI only\n");
    exit(2);
}

/** @return list<string> */
function f7_professor_assignment_split_sql(string $sql): array
{
    $withoutComments=preg_replace('/^\s*--.*$/m','',$sql);
    if(!is_string($withoutComments))throw new RuntimeException('No se pudo normalizar la migración F7.1.');
    return array_values(array_filter(array_map('trim',explode(';',$withoutComments)),static fn(string $statement):bool=>$statement!==''));
}

function f7_professor_assignment_schema_ready(PDO $pdo): bool
{
    $expected=['id','profesor_horario_id','vigente_desde','vigente_hasta','origen','created_by','closed_by','created_at','abierta'];
    $st=$pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='profesor_horario_vigencias' ORDER BY ordinal_position");
    if(array_values($st->fetchAll(PDO::FETCH_COLUMN))!==$expected)return false;

    $st=$pdo->query("SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') cols,MIN(non_unique) non_unique FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='profesor_horario_vigencias' AND index_name='uq_profesor_horario_vigencia_abierta' GROUP BY index_name");
    $idx=$st->fetch(PDO::FETCH_ASSOC);
    if(!is_array($idx)||(string)($idx['cols']??'')!=='profesor_horario_id,abierta'||(int)($idx['non_unique']??1)!==0)return false;

    $st=$pdo->query("SELECT COUNT(*) FROM configuracion WHERE clave IN ('profesores_asignaciones_cobertura_desde','profesores_asignaciones_baseline_aplicado')");
    if((int)$st->fetchColumn()!==2)return false;

    $missing=$pdo->query("SELECT COUNT(*) FROM profesor_horarios ph LEFT JOIN profesor_horario_vigencias v ON v.profesor_horario_id=ph.id AND v.vigente_hasta IS NULL WHERE ph.activo=1 AND v.id IS NULL")->fetchColumn();
    if((int)$missing!==0)return false;
    $stale=$pdo->query("SELECT COUNT(*) FROM profesor_horarios ph JOIN profesor_horario_vigencias v ON v.profesor_horario_id=ph.id AND v.vigente_hasta IS NULL WHERE ph.activo=0")->fetchColumn();
    return (int)$stale===0;
}

try{
    $root=dirname(__DIR__);
    /** @var PDO $pdo */
    $pdo=require $root.'/config/pdo.php';
    $lock=(int)$pdo->query("SELECT GET_LOCK('hache_f7_professor_assignment_migration',10)")->fetchColumn();
    if($lock!==1)throw new RuntimeException('No se pudo adquirir el bloqueo de la migración F7.1.');
    try{
        $sql=file_get_contents($root.'/database/migrations/20260918_f7_professor_assignment_validity.sql');
        if(!is_string($sql))throw new RuntimeException('No se pudo leer la migración F7.1.');
        foreach(f7_professor_assignment_split_sql($sql) as $statement)$pdo->exec($statement);
        if(!f7_professor_assignment_schema_ready($pdo))throw new RuntimeException('La verificación del esquema F7.1 falló.');
        fwrite(STDOUT,"F7_PROFESSOR_ASSIGNMENT_VALIDITY_MIGRATION_OK\n");
    }finally{
        try{$pdo->query("SELECT RELEASE_LOCK('hache_f7_professor_assignment_migration')");}catch(Throwable $ignored){}
    }
}catch(Throwable $e){
    fwrite(STDERR,'F7_PROFESSOR_ASSIGNMENT_VALIDITY_MIGRATION_FAILED: '.$e->getMessage().PHP_EOL);
    exit(1);
}
