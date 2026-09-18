<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/config/sharky-prospect-opportunities.php';

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
    $pdo->exec("CREATE TABLE configuracion(clave VARCHAR(100) PRIMARY KEY,valor VARCHAR(255) NOT NULL,descripcion TEXT NULL) ENGINE=InnoDB");

    $sql=file_get_contents(dirname(__DIR__).'/database/migrations/20260917_f6_dashboard_metrics.sql');
    f6m_expect(is_string($sql),'No se pudo leer la migración F6.');
    $withoutComments=preg_replace('/^\s*--.*$/m','',$sql);
    f6m_expect(is_string($withoutComments),'No se pudo normalizar la migración F6.');
    $statements=array_values(array_filter(array_map('trim',explode(';',$withoutComments)),static fn(string $s):bool=>$s!==''));
    foreach($statements as $statement)$pdo->exec($statement);
    foreach($statements as $statement)$pdo->exec($statement);

    $columns=$pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sesion_asistencia_cobertura' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
    f6m_expect($columns===['sesion_id','expected_count','marked_count','present_count','justified_count','unjustified_count','complete','captured_by','captured_at'],'Columnas inesperadas en snapshot F6.');

    $opportunityColumns=$pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sharky_prospect_opportunities' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
    f6m_expect(
        $opportunityColumns===['id','contact_hash','origin_message_hash','entry_source','sede_clave','status','opened_at','closed_at','updated_at'],
        'Columnas inesperadas en la autoridad de oportunidades F6.'
    );

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

    $contactHash=str_repeat('a',64);
    $origin1=str_repeat('b',64);
    $origin2=str_repeat('c',64);
    $insert=$pdo->prepare("INSERT INTO sharky_prospect_opportunities(id,contact_hash,origin_message_hash,entry_source,sede_clave,status,opened_at) VALUES(?,?,?,?,?,'OPEN','2026-09-17 12:00:00')");
    $insert->execute(['10000000-0000-0000-0000-000000000001',$contactHash,$origin1,'direct',null]);
    $insert->execute(['10000000-0000-0000-0000-000000000002',$contactHash,$origin2,'direct','MONTEVERDE']);
    f6m_expect(
        (int)$pdo->query("SELECT COUNT(*) FROM sharky_prospect_opportunities WHERE contact_hash='{$contactHash}'")->fetchColumn()===2,
        'Un mismo contacto debe poder representar oportunidades distintas.'
    );

    $duplicateOriginRejected=false;
    try{
        $insert->execute(['10000000-0000-0000-0000-000000000003',str_repeat('d',64),$origin1,'web',null]);
    }catch(PDOException){
        $duplicateOriginRejected=true;
    }
    f6m_expect($duplicateOriginRejected,'El evento de origen debe ser idempotente y no abrir dos oportunidades.');

    $invalidStatusRejected=false;
    try{
        $pdo->prepare("INSERT INTO sharky_prospect_opportunities(id,contact_hash,origin_message_hash,status) VALUES(?,?,?,'UNKNOWN')")->execute([
            '10000000-0000-0000-0000-000000000004',
            str_repeat('e',64),
            str_repeat('f',64),
        ]);
    }catch(PDOException){
        $invalidStatusRejected=true;
    }
    f6m_expect($invalidStatusRejected,'El esquema debe rechazar estados de oportunidad no definidos.');

    $producerContact=str_repeat('1',64);
    $producerState=[
        'identity'=>['kind'=>'prospect'],
        'commercial_context'=>['entry_source'=>'web'],
    ];
    $originMessage='wamid.f6.prospect.001';
    $firstOpportunity=hache_sharky_prospect_opportunity_open($pdo,$producerContact,$originMessage,$producerState);
    f6m_expect(is_string($firstOpportunity)&&$firstOpportunity!=='','El primer turno prospecto debe abrir una oportunidad durable.');
    $retryOpportunity=hache_sharky_prospect_opportunity_open($pdo,$producerContact,$originMessage,$producerState);
    f6m_expect($retryOpportunity===$firstOpportunity,'Reintentar el mismo evento debe devolver la misma oportunidad.');
    $originHash=hash('sha256',$originMessage);
    $q=$pdo->prepare('SELECT contact_hash,origin_message_hash,entry_source,sede_clave,status FROM sharky_prospect_opportunities WHERE id=?');
    $q->execute([$firstOpportunity]);
    $produced=$q->fetch(PDO::FETCH_ASSOC);
    f6m_expect(is_array($produced),'La oportunidad producida debe poder releerse.');
    f6m_expect((string)$produced['contact_hash']===$producerContact,'La oportunidad debe conservar únicamente el hash del contacto.');
    f6m_expect((string)$produced['origin_message_hash']===$originHash,'El evento de origen debe persistirse únicamente como SHA-256.');
    f6m_expect((string)$produced['entry_source']==='web','La fuente estructurada del primer turno debe conservarse.');
    f6m_expect($produced['sede_clave']===null,'El primer turno no debe inventar una sede todavía no confirmada.');
    f6m_expect((string)$produced['status']==='OPEN','Una oportunidad recién abierta debe iniciar OPEN.');
    f6m_expect(
        (int)$pdo->query("SELECT COUNT(*) FROM sharky_prospect_opportunities WHERE origin_message_hash='{$originHash}'")->fetchColumn()===1,
        'El mismo evento de origen no debe duplicar oportunidades.'
    );

    $secondOpportunity=hache_sharky_prospect_opportunity_open($pdo,$producerContact,'wamid.f6.prospect.002',$producerState);
    f6m_expect(is_string($secondOpportunity)&&$secondOpportunity!==''&&$secondOpportunity!==$firstOpportunity,'Un nuevo primer evento debe poder representar otra oportunidad del mismo contacto.');
    $q=$pdo->prepare('SELECT COUNT(*) FROM sharky_prospect_opportunities WHERE contact_hash=? AND status=\'OPEN\'');
    $q->execute([$producerContact]);
    f6m_expect((int)$q->fetchColumn()===2,'Oportunidades históricas distintas no deben colapsarse por contacto.');

    $studentState=['identity'=>['kind'=>'student'],'commercial_context'=>['entry_source'=>'direct']];
    f6m_expect(
        hache_sharky_prospect_opportunity_open($pdo,str_repeat('2',64),'wamid.f6.student.001',$studentState)===null,
        'Una identidad de alumno no debe abrir una oportunidad de prospecto.'
    );

    $keys=$pdo->query("SELECT clave FROM configuracion WHERE clave IN ('dashboard_asistencia_cobertura_desde','dashboard_bajas_cobertura_desde','dashboard_prospectos_cobertura_desde') ORDER BY clave")->fetchAll(PDO::FETCH_COLUMN);
    f6m_expect(
        $keys===['dashboard_asistencia_cobertura_desde','dashboard_bajas_cobertura_desde'],
        'El productor no debe declarar cobertura publicable de prospectos antes de completar lifecycle y conversión.'
    );

    echo "F6_DASHBOARD_METRICS_MARIADB_OK\n";
}finally{
    $server->exec("DROP DATABASE IF EXISTS `{$db}`");
}
