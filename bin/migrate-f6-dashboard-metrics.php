<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){
    fwrite(STDERR,"CLI only\n");
    exit(2);
}

/** @return list<string> */
function f6_dashboard_metrics_split_sql(string $sql): array
{
    $withoutComments=preg_replace('/^\s*--.*$/m','',$sql);
    if(!is_string($withoutComments))throw new RuntimeException('No se pudo normalizar la migración F6.');
    return array_values(array_filter(array_map('trim',explode(';',$withoutComments)),static fn(string $statement):bool=>$statement!==''));
}

function f6_dashboard_metrics_schema_ready(PDO $pdo): bool
{
    $st=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='sesion_asistencia_cobertura'");
    if((int)$st->fetchColumn()!==1)return false;

    $expected=['sesion_id','expected_count','marked_count','present_count','justified_count','unjustified_count','complete','captured_by','captured_at'];
    $st=$pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sesion_asistencia_cobertura' ORDER BY ordinal_position");
    if(array_values($st->fetchAll(PDO::FETCH_COLUMN))!==$expected)return false;

    $st=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='sharky_prospect_opportunities'");
    if((int)$st->fetchColumn()!==1)return false;

    $opportunityExpected=['id','contact_hash','origin_message_hash','entry_source','sede_clave','status','conversion_action_hash','opened_at','closed_at','updated_at'];
    $st=$pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sharky_prospect_opportunities' ORDER BY ordinal_position");
    if(array_values($st->fetchAll(PDO::FETCH_COLUMN))!==$opportunityExpected)return false;

    $st=$pdo->query("SELECT non_unique FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='sharky_prospect_opportunities' AND index_name='uq_sharky_prospect_origin' LIMIT 1");
    if((string)$st->fetchColumn()!=='0')return false;
    $st=$pdo->query("SELECT non_unique FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='sharky_prospect_opportunities' AND index_name='uq_sharky_prospect_conversion_action' LIMIT 1");
    if((string)$st->fetchColumn()!=='0')return false;

    $st=$pdo->query("SELECT clave FROM configuracion WHERE clave IN ('dashboard_bajas_cobertura_desde','dashboard_asistencia_cobertura_desde') ORDER BY clave");
    return array_values($st->fetchAll(PDO::FETCH_COLUMN))===['dashboard_asistencia_cobertura_desde','dashboard_bajas_cobertura_desde'];
}

try{
    $root=dirname(__DIR__);
    /** @var PDO $pdo */
    $pdo=require $root.'/config/pdo.php';
    $lock=(int)$pdo->query("SELECT GET_LOCK('hache_f6_dashboard_metrics_migration',10)")->fetchColumn();
    if($lock!==1)throw new RuntimeException('No se pudo adquirir el bloqueo de la migración F6.');
    try{
        $sql=file_get_contents($root.'/database/migrations/20260917_f6_dashboard_metrics.sql');
        if(!is_string($sql))throw new RuntimeException('No se pudo leer la migración F6.');
        foreach(f6_dashboard_metrics_split_sql($sql) as $statement)$pdo->exec($statement);
        if(!f6_dashboard_metrics_schema_ready($pdo))throw new RuntimeException('La verificación del esquema F6 falló.');
        fwrite(STDOUT,"F6_DASHBOARD_METRICS_MIGRATION_OK\n");
    }finally{
        try{$pdo->query("SELECT RELEASE_LOCK('hache_f6_dashboard_metrics_migration')");}catch(Throwable $ignored){}
    }
}catch(Throwable $e){
    fwrite(STDERR,'F6_DASHBOARD_METRICS_MIGRATION_FAILED: '.$e->getMessage().PHP_EOL);
    exit(1);
}
