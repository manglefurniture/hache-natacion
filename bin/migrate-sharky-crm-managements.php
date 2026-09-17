<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){
    fwrite(STDERR,"CLI only\n");
    exit(2);
}

/** @return list<string> */
function sharky_crm_managements_split_sql(string $sql): array
{
    $withoutComments=preg_replace('/^\s*--.*$/m','',$sql);
    if(!is_string($withoutComments))throw new RuntimeException('No se pudo normalizar la migración CRM.');
    return array_values(array_filter(array_map('trim',explode(';',$withoutComments)),static fn(string $statement):bool=>$statement!==''));
}

function sharky_crm_managements_schema_ready(PDO $pdo): bool
{
    $st=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='sharky_crm_managements'");
    if((int)$st->fetchColumn()!==1)return false;

    $expectedColumns=['id','contact_hash','admin_user_id','managed_at','observed_last_contact_at','observed_inbound_count','observed_outbound_count'];
    $st=$pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sharky_crm_managements' ORDER BY ordinal_position");
    if(array_values($st->fetchAll(PDO::FETCH_COLUMN))!==$expectedColumns)return false;

    $expectedIndexes=[
        'PRIMARY'=>['id'],
        'idx_sharky_crm_managements_contact_time'=>['contact_hash','managed_at','id'],
        'idx_sharky_crm_managements_admin_time'=>['admin_user_id','managed_at'],
    ];
    $index=$pdo->prepare("SELECT column_name FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='sharky_crm_managements' AND index_name=:name ORDER BY seq_in_index");
    foreach($expectedIndexes as $name=>$columns){
        $index->execute([':name'=>$name]);
        if(array_values($index->fetchAll(PDO::FETCH_COLUMN))!==$columns)return false;
    }
    return true;
}

try {
    $root=dirname(__DIR__);
    /** @var PDO $pdo */
    $pdo=require $root.'/config/pdo.php';
    $lock=(int)$pdo->query("SELECT GET_LOCK('hache_sharky_crm_managements_migration',10)")->fetchColumn();
    if($lock!==1)throw new RuntimeException('No se pudo adquirir el bloqueo de la migración CRM.');
    try {
        $sql=file_get_contents($root.'/database/migrations/20260917_sharky_crm_managements.sql');
        if(!is_string($sql))throw new RuntimeException('No se pudo leer la migración CRM.');
        foreach(sharky_crm_managements_split_sql($sql) as $statement)$pdo->exec($statement);
        if(!sharky_crm_managements_schema_ready($pdo))throw new RuntimeException('La verificación de la tabla de gestión CRM falló.');
        fwrite(STDOUT,"SHARKY_CRM_MANAGEMENTS_MIGRATION_OK\n");
    } finally {
        try{$pdo->query("SELECT RELEASE_LOCK('hache_sharky_crm_managements_migration')");}catch(Throwable $ignored){}
    }
} catch (Throwable $e) {
    fwrite(STDERR,'SHARKY_CRM_MANAGEMENTS_MIGRATION_FAILED: '.$e->getMessage().PHP_EOL);
    exit(1);
}
