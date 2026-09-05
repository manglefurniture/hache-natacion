<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-activation.php';

if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only\n");exit(2);}

function hache_sharky_migration_foreign_key_ddl(): array
{
    return [
        'fk_sharky_referral_alumno'=>'ALTER TABLE sharky_referrals ADD CONSTRAINT fk_sharky_referral_alumno FOREIGN KEY (alumno_id) REFERENCES alumnos(id) ON DELETE SET NULL',
        'fk_sharky_identity_student'=>'ALTER TABLE sharky_identity_challenges ADD CONSTRAINT fk_sharky_identity_student FOREIGN KEY (verified_student_id) REFERENCES alumnos(id) ON DELETE SET NULL',
        'fk_sharky_action_alumno'=>'ALTER TABLE sharky_action_audit ADD CONSTRAINT fk_sharky_action_alumno FOREIGN KEY (alumno_id) REFERENCES alumnos(id) ON DELETE SET NULL',
    ];
}

function hache_sharky_migration_ensure_constraints(PDO $pdo): void
{
    $ddl=hache_sharky_migration_foreign_key_ddl();
    foreach(hache_sharky_activation_foreign_key_specs() as $spec){
        if(hache_sharky_activation_constraint_present($pdo,$spec))continue;
        $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name=:t AND column_name=:c AND referenced_table_name IS NOT NULL");
        $st->execute([':t'=>(string)$spec['table'],':c'=>(string)$spec['column']]);
        if((int)$st->fetchColumn()>0)throw new RuntimeException('Incompatible existing foreign key on '.(string)$spec['table'].'.'.(string)$spec['column']);
        $name=(string)$spec['name'];
        if(!isset($ddl[$name]))throw new RuntimeException('Missing migration DDL for foreign key '.$name);
        $pdo->exec($ddl[$name]);
        if(!hache_sharky_activation_constraint_present($pdo,$spec))throw new RuntimeException('Unable to verify foreign key '.$name);
    }
}

$root=dirname(__DIR__);
$migrations=[
    $root.'/database/migrations/20260902_sharky_orchestrator.sql',
    $root.'/database/migrations/20260903_sharky_orchestrator_hardening.sql',
    $root.'/database/migrations/20260905_sharky_delivery_status.sql',
];

try{
    $flag=hache_sharky_orchestrator_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED');
    if($flag!=='0')throw new RuntimeException('Refusing migration unless SHARKY_ORCHESTRATOR_LAB_ENABLED=0 explicitly.');
    /** @var PDO $pdo */
    $pdo=require $root.'/config/pdo.php';
    $lock=(int)$pdo->query("SELECT GET_LOCK('hache_sharky_orchestrator_migration',10)")->fetchColumn();
    if($lock!==1)throw new RuntimeException('Could not acquire Sharky migration lock.');
    try{
        foreach($migrations as $file){
            if(!is_readable($file))throw new RuntimeException('Missing migration: '.basename($file));
            $sql=file_get_contents($file);if(!is_string($sql))throw new RuntimeException('Unable to read migration: '.basename($file));
            $statements=hache_sharky_activation_split_sql($sql);
            fwrite(STDOUT,'Applying '.basename($file).' ('.count($statements).' statements)'.PHP_EOL);
            foreach($statements as $index=>$statement){
                try{$pdo->exec($statement);}
                catch(Throwable $e){throw new RuntimeException(basename($file).' statement '.($index+1).' failed',0,$e);}
            }
        }
        hache_sharky_migration_ensure_constraints($pdo);
        $schema=hache_sharky_activation_schema_report($pdo);
        if(($schema['ok']??false)!==true){
            throw new RuntimeException('Schema verification failed: '.json_encode($schema,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        }
        fwrite(STDOUT,"SHARKY_MIGRATION_OK\n");
    }finally{
        try{$pdo->query("SELECT RELEASE_LOCK('hache_sharky_orchestrator_migration')");}catch(Throwable $e){}
    }
}catch(Throwable $e){
    fwrite(STDERR,'Sharky migration: '.$e->getMessage().PHP_EOL);
    if($e->getPrevious())fwrite(STDERR,'Cause: '.$e->getPrevious()->getMessage().PHP_EOL);
    exit(1);
}
