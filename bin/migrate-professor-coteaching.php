<?php
declare(strict_types=1);
require_once __DIR__.'/../config/sharky-activation.php';
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only\n");exit(2);}
$root=dirname(__DIR__);
try{
    /** @var PDO $pdo */
    $pdo=require $root.'/config/pdo.php';
    $lock=(int)$pdo->query("SELECT GET_LOCK('hache_professor_coteaching_migration',10)")->fetchColumn();
    if($lock!==1)throw new RuntimeException('Could not acquire professor co-teaching migration lock.');
    try{
        $file=$root.'/database/migrations/20260909_professor_coteaching.sql';
        $sql=file_get_contents($file);if(!is_string($sql))throw new RuntimeException('Unable to read professor co-teaching migration.');
        foreach(hache_sharky_activation_split_sql($sql) as $statement)$pdo->exec($statement);
        $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='profesor_cancelaciones' AND index_name='uq_profesor_cancelacion_profesor_sesion' AND non_unique=0");$st->execute();
        if((int)$st->fetchColumn()!==2)throw new RuntimeException('Professor co-teaching unique index verification failed.');
        $old=$pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='profesor_cancelaciones' AND index_name='uq_profesor_cancelacion_sesion'");$old->execute();
        if((int)$old->fetchColumn()!==0)throw new RuntimeException('Legacy session-only professor cancellation index is still present.');
        fwrite(STDOUT,"PROFESSOR_COTEACHING_MIGRATION_OK\n");
    }finally{try{$pdo->query("SELECT RELEASE_LOCK('hache_professor_coteaching_migration')");}catch(Throwable $e){}}
}catch(Throwable $e){fwrite(STDERR,'Professor co-teaching migration: '.$e->getMessage().PHP_EOL);exit(1);}
