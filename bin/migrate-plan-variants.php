<?php
declare(strict_types=1);

$pdo=require dirname(__DIR__).'/config/pdo.php';

try{
    $st=$pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema=DATABASE()
           AND table_name='planes'
           AND index_name='uq_planes_sede_sesiones'
           AND non_unique=0"
    );
    $st->execute();
    $exists=(int)$st->fetchColumn() > 0;

    if($exists){
        echo "Dropping uq_planes_sede_sesiones\n";
        $pdo->exec('ALTER TABLE planes DROP INDEX uq_planes_sede_sesiones');
    }else{
        echo "uq_planes_sede_sesiones already absent\n";
    }

    $check=$pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema=DATABASE()
           AND table_name='planes'
           AND index_name='uq_planes_sede_nombre'
           AND non_unique=0"
    );
    $check->execute();
    if((int)$check->fetchColumn() < 1){
        throw new RuntimeException('Falta la protección UNIQUE por sede + nombre; no se considera segura la migración.');
    }

    echo "PLAN_VARIANTS_MIGRATION_OK\n";
}catch(Throwable $e){
    fwrite(STDERR,'PLAN_VARIANTS_MIGRATION_FAILED: '.$e->getMessage().PHP_EOL);
    exit(1);
}
