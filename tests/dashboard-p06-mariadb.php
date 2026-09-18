<?php
declare(strict_types=1);

function f6m_expect(bool $ok,string $message): void
{
    if(!$ok)throw new RuntimeException($message);
}

$host=(string)(getenv('DELIVERY_DB_HOST')?:'127.0.0.1');
$port=(string)(getenv('DELIVERY_DB_PORT')?:'3306');
$user=(string)(getenv('DELIVERY_DB_USER')?:'root');
$pass=(string)(getenv('DELIVERY_DB_PASS')?:'root');
$db='hache_f6_dashboard_metrics_test';

$server=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);

try{
    $server->exec("DROP DATABASE IF EXISTS `{$db}`");
    $server->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    $pdo->exec("CREATE TABLE usuarios(id CHAR(36) PRIMARY KEY) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE sesiones(id CHAR(36) PRIMARY KEY) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE alumnos(id CHAR(36) PRIMARY KEY) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE configuracion(clave VARCHAR(100) PRIMARY KEY,valor VARCHAR(255) NOT NULL,descripcion TEXT NULL) ENGINE=InnoDB");

    $sql=file_get_contents(dirname(__DIR__).'/database/migrations/20260917_f6_dashboard_metrics.sql');
    f6m_expect(is_string($sql),'No se pudo leer la migración F6.');
    $withoutComments=preg_replace('/^\s*--.*$/m','',$sql);
    f6m_expect(is_string($withoutComments),'No se pudo normalizar la migración F6.');
    foreach(array_values(array_filter(array_map('trim',explode(';',$withoutComments)),static fn(string $s):bool=>$s!=='')) as $statement){
        $pdo->exec($statement);
    }

    $columns=$pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sesion_asistencia_cobertura' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
    f6m_expect($columns===['sesion_id','expected_count','marked_count','present_count','justified_count','unjustified_count','complete','captured_by','captured_at'],'Columnas inesperadas en snapshot F6.');
    $opportunityColumns=$pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sharky_prospect_opportunities' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
    f6m_expect($opportunityColumns===['id','contact_hash','entry_source','sede_clave','status','open_slot','alumno_id','created_at','converted_at','updated_at'],'Columnas inesperadas en oportunidades F6.');

    $uid='00000000-0000-0000-0000-000000000001';
    $sid='00000000-0000-0000-0000-000000000002';
    $pdo->prepare('INSERT INTO usuarios(id) VALUES(?)')->execute([$uid]);
    $pdo->prepare('INSERT INTO sesiones(id) VALUES(?)')->execute([$sid]);
    $pdo->prepare("INSERT INTO sesion_asistencia_cobertura(sesion_id,expected_count,marked_count,present_count,justified_count,unjustified_count,complete,captured_by) VALUES(?,3,3,2,1,0,1,?)")->execute([$sid,$uid]);
    f6m_expect((int)$pdo->query("SELECT complete FROM sesion_asistencia_cobertura WHERE sesion_id='{$sid}'")->fetchColumn()===1,'Snapshot válido no persistió.');

    $invalidRejected=false;
    try{
        $sid2='00000000-0000-0000-0000-000000000003';
        $pdo->prepare('INSERT INTO sesiones(id) VALUES(?)')->execute([$sid2]);
        $pdo->prepare("INSERT INTO sesion_asistencia_cobertura(sesion_id,expected_count,marked_count,present_count,justified_count,unjustified_count,complete,captured_by) VALUES(?,1,1,2,0,0,1,?)")->execute([$sid2,$uid]);
    }catch(PDOException){
        $invalidRejected=true;
    }
    f6m_expect($invalidRejected,'El CHECK debe rechazar un numerador incompatible con el denominador.');

    $student='00000000-0000-0000-0000-000000000004';
    $pdo->prepare('INSERT INTO alumnos(id) VALUES(?)')->execute([$student]);
    $contact=str_repeat('a',64);
    $pdo->prepare("INSERT INTO sharky_prospect_opportunities(id,contact_hash,entry_source,sede_clave,status,open_slot,alumno_id,created_at) VALUES(UUID(),?,'direct',NULL,'OPEN',1,NULL,UTC_TIMESTAMP())")->execute([$contact]);
    $duplicateOpenRejected=false;
    try{
        $pdo->prepare("INSERT INTO sharky_prospect_opportunities(id,contact_hash,entry_source,sede_clave,status,open_slot,alumno_id,created_at) VALUES(UUID(),?,'direct',NULL,'OPEN',1,NULL,UTC_TIMESTAMP())")->execute([$contact]);
    }catch(PDOException){
        $duplicateOpenRejected=true;
    }
    f6m_expect($duplicateOpenRejected,'La oportunidad abierta debe ser única por contacto técnico.');
    $pdo->prepare("UPDATE sharky_prospect_opportunities SET status='CONVERTED',open_slot=NULL,alumno_id=?,converted_at=UTC_TIMESTAMP() WHERE contact_hash=? AND open_slot=1")->execute([$student,$contact]);
    f6m_expect((int)$pdo->query("SELECT COUNT(*) FROM sharky_prospect_opportunities WHERE status='CONVERTED' AND alumno_id='{$student}'")->fetchColumn()===1,'La conversión debe quedar vinculada al alumno.');

    $keys=$pdo->query("SELECT clave FROM configuracion WHERE clave IN ('dashboard_asistencia_cobertura_desde','dashboard_bajas_cobertura_desde','dashboard_prospectos_cobertura_desde') ORDER BY clave")->fetchAll(PDO::FETCH_COLUMN);
    f6m_expect($keys===['dashboard_asistencia_cobertura_desde','dashboard_bajas_cobertura_desde','dashboard_prospectos_cobertura_desde'],'Faltan marcadores de inicio de cobertura.');

    echo "F6_DASHBOARD_METRICS_MARIADB_OK\n";
}finally{
    $server->exec("DROP DATABASE IF EXISTS `{$db}`");
}
