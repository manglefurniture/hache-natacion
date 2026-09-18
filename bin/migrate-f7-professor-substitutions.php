<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){
    fwrite(STDERR,"CLI only\n");
    exit(2);
}

/** @return list<string> */
function f7_professor_substitutions_split_sql(string $sql): array
{
    $withoutComments=preg_replace('/^\s*--.*$/m','',$sql);
    if(!is_string($withoutComments))throw new RuntimeException('No se pudo normalizar la migración F7.2.');
    return array_values(array_filter(array_map('trim',explode(';',$withoutComments)),static fn(string $statement):bool=>$statement!==''));
}

function f7_professor_substitutions_schema_ready(PDO $pdo): bool
{
    $expected=['id','sesion_id','profesor_original_id','profesor_sustituto_id','motivo','origen','estado','created_by','created_at','anulada_by','anulada_at','motivo_anulacion','activa'];
    $st=$pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='profesor_sustituciones' ORDER BY ordinal_position");
    if(array_values($st->fetchAll(PDO::FETCH_COLUMN))!==$expected)return false;

    $st=$pdo->query("SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') cols,MIN(non_unique) non_unique FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='profesor_sustituciones' AND index_name='uq_profesor_sustitucion_activa' GROUP BY index_name");
    $idx=$st->fetch(PDO::FETCH_ASSOC);
    if(!is_array($idx)||(string)($idx['cols']??'')!=='sesion_id,profesor_original_id,activa'||(int)($idx['non_unique']??1)!==0)return false;

    $st=$pdo->query("SELECT COUNT(*) FROM configuracion WHERE clave='profesores_sustituciones_cobertura_desde'");
    return (int)$st->fetchColumn()===1;
}

try{
    $root=dirname(__DIR__);
    /** @var PDO $pdo */
    $pdo=require $root.'/config/pdo.php';
    $lock=(int)$pdo->query("SELECT GET_LOCK('hache_f7_professor_substitutions_migration',10)")->fetchColumn();
    if($lock!==1)throw new RuntimeException('No se pudo adquirir el bloqueo de la migración F7.2.');
    try{
        $sql=file_get_contents($root.'/database/migrations/20260918_f7_professor_substitutions.sql');
        if(!is_string($sql))throw new RuntimeException('No se pudo leer la migración F7.2.');
        foreach(f7_professor_substitutions_split_sql($sql) as $statement)$pdo->exec($statement);
        if(!f7_professor_substitutions_schema_ready($pdo))throw new RuntimeException('La verificación del esquema F7.2 falló.');
        fwrite(STDOUT,"F7_PROFESSOR_SUBSTITUTIONS_MIGRATION_OK\n");
    }finally{
        try{$pdo->query("SELECT RELEASE_LOCK('hache_f7_professor_substitutions_migration')");}catch(Throwable $ignored){}
    }
}catch(Throwable $e){
    fwrite(STDERR,'F7_PROFESSOR_SUBSTITUTIONS_MIGRATION_FAILED: '.$e->getMessage().PHP_EOL);
    exit(1);
}
