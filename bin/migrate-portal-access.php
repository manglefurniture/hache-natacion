<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only\n");exit(2);}

function portal_access_split_sql(string $sql): array
{
    $withoutComments=preg_replace('/^\s*--.*$/m','',$sql);
    if(!is_string($withoutComments))throw new RuntimeException('No se pudo normalizar la migración de acceso al portal.');
    return array_values(array_filter(array_map('trim',explode(';',$withoutComments)),static fn(string $statement): bool=>$statement!==''));
}

function portal_access_schema_ready(PDO $pdo): bool
{
    $table=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='portal_access_tokens'");
    if((int)$table->fetchColumn()!==1)return false;

    $indexes=[
        'PRIMARY'=>['id'],
        'uq_portal_access_token_hash'=>['token_hash'],
        'idx_portal_access_user_state'=>['user_id','consumed_at','expires_at'],
    ];
    $index=$pdo->prepare('SELECT column_name FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=:table AND index_name=:index ORDER BY seq_in_index');
    foreach($indexes as $name=>$columns){
        $index->execute([':table'=>'portal_access_tokens',':index'=>$name]);
        if(array_column($index->fetchAll(PDO::FETCH_ASSOC),'column_name')!==$columns)return false;
    }

    $fk=$pdo->prepare("SELECT referenced_table_name,delete_rule FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name AND r.table_name=k.table_name WHERE k.table_schema=DATABASE() AND k.table_name='portal_access_tokens' AND k.constraint_name='fk_portal_access_user' LIMIT 1");
    $fk->execute();$row=$fk->fetch(PDO::FETCH_ASSOC);
    return is_array($row)&&($row['referenced_table_name']??'')==='usuarios'&&($row['delete_rule']??'')==='CASCADE';
}

try{
    $root=dirname(__DIR__);
    /** @var PDO $pdo */
    $pdo=require $root.'/config/pdo.php';
    $lock=(int)$pdo->query("SELECT GET_LOCK('hache_portal_access_migration',10)")->fetchColumn();
    if($lock!==1)throw new RuntimeException('No se pudo adquirir el bloqueo de la migración de acceso al portal.');
    try{
        $sql=file_get_contents($root.'/database/migrations/20260916_portal_access_tokens.sql');
        if(!is_string($sql))throw new RuntimeException('No se pudo leer la migración de acceso al portal.');
        foreach(portal_access_split_sql($sql) as $statement)$pdo->exec($statement);
        if(!portal_access_schema_ready($pdo))throw new RuntimeException('La verificación del esquema de acceso al portal falló.');
        fwrite(STDOUT,"PORTAL_ACCESS_MIGRATION_OK\n");
    }finally{
        try{$pdo->query("SELECT RELEASE_LOCK('hache_portal_access_migration')");}catch(Throwable $ignored){}
    }
}catch(Throwable $e){
    fwrite(STDERR,'PORTAL_ACCESS_MIGRATION_FAILED: '.$e->getMessage().PHP_EOL);
    exit(1);
}
