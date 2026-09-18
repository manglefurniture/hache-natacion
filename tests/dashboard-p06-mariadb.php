<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/config/sharky-prospect-opportunities.php';
require_once dirname(__DIR__).'/config/dashboard-p06.php';

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
    $pdo->exec("CREATE TABLE sharky_action_audit(idempotency_key CHAR(64) PRIMARY KEY,action_type VARCHAR(60) NOT NULL,contact_hash CHAR(64) NOT NULL,status VARCHAR(20) NOT NULL,completed_at DATETIME NULL) ENGINE=InnoDB");

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
        $opportunityColumns===['id','contact_hash','origin_message_hash','entry_source','sede_clave','status','conversion_action_hash','opened_at','closed_at','updated_at'],
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

    $pdo->prepare("UPDATE configuracion SET valor='2026-09-17 18:00:00' WHERE clave='dashboard_prospectos_cobertura_desde'")->execute();
    $readInsert=$pdo->prepare("INSERT INTO sharky_prospect_opportunities(id,contact_hash,origin_message_hash,entry_source,sede_clave,status,conversion_action_hash,opened_at,closed_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)");
    $readInsert->execute(['20000000-0000-0000-0000-000000000001',str_repeat('3',64),str_repeat('4',64),'direct',null,'OPEN',null,'2026-09-17 18:10:00',null,'2026-09-17 18:10:00']);
    $readInsert->execute(['20000000-0000-0000-0000-000000000002',str_repeat('5',64),str_repeat('6',64),'web','MONTEVERDE','CONVERTED',str_repeat('7',64),'2026-09-17 19:00:00','2026-09-18 01:00:00','2026-09-18 01:00:00']);
    $readInsert->execute(['20000000-0000-0000-0000-000000000003',str_repeat('8',64),str_repeat('9',64),'meta_ad','PALAPAS','EXCLUDED',null,'2026-09-17 20:00:00','2026-09-17 20:05:00','2026-09-17 20:05:00']);
    $read=dashboard_prospectos_conversion($pdo,'2026-09-17','2026-09-17');
    f6m_expect(($read['disponible']??false)===true,'La lectura de prospectos debe quedar disponible desde el marcador forward-only.');
    f6m_expect((int)($read['prospectos']??-1)===2,'El denominador debe incluir OPEN+CONVERTED y excluir EXCLUDED.');
    f6m_expect((int)($read['conversiones']??-1)===1,'La lectura debe contar conversiones reconciliables de la cohorte.');
    f6m_expect(($read['por_sede']['SIN_SEDE']['prospectos']??0)===1,'La sede desconocida debe permanecer SIN_SEDE.');
    f6m_expect(($read['por_sede']['MONTEVERDE']['conversiones']??0)===1,'La conversión debe conservar su sede estructurada.');
    f6m_expect(($read['por_fuente']['direct']['prospectos']??0)===1&&($read['por_fuente']['web']['conversiones']??0)===1,'La lectura debe desglosar por fuente.');
    f6m_expect(($read['por_cohorte']['2026-09-17']['prospectos']??0)===2,'La lectura debe reconciliar la cohorte por fecha local de apertura.');
    foreach(($read['rows']??[]) as $row){
        f6m_expect(!array_key_exists('contact_hash',$row),'El detalle reconciliable no debe exponer hashes de contacto.');
        f6m_expect(!array_key_exists('conversion_action_hash',$row),'El detalle reconciliable no debe exponer hashes de acciones.');
    }

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

    $openedAt=(string)$pdo->query("SELECT opened_at FROM sharky_prospect_opportunities WHERE id='{$firstOpportunity}'")->fetchColumn();
    $venueState=$producerState;
    $venueState['commercial_context']['f6_opportunity_id']=$firstOpportunity;
    $venueState['commercial_context']['sede_clave']='MONTEVERDE';
    f6m_expect(
        hache_sharky_prospect_opportunity_enrich_sede($pdo,$producerContact,$venueState),
        'Una sede confirmada debe enriquecer la oportunidad OPEN representada por el estado.'
    );
    $q=$pdo->prepare('SELECT sede_clave,status,opened_at FROM sharky_prospect_opportunities WHERE id=?');
    $q->execute([$firstOpportunity]);
    $enriched=$q->fetch(PDO::FETCH_ASSOC);
    f6m_expect((string)$enriched['sede_clave']==='MONTEVERDE','El enriquecimiento debe persistir únicamente la sede estructurada confirmada.');
    f6m_expect((string)$enriched['status']==='OPEN','Confirmar sede no debe convertir ni excluir la oportunidad.');
    f6m_expect((string)$enriched['opened_at']===$openedAt,'Confirmar sede no debe reescribir la cohorte/opened_at.');
    f6m_expect(
        hache_sharky_prospect_opportunity_enrich_sede($pdo,$producerContact,$venueState),
        'Repetir la misma sede debe ser idempotente.'
    );

    $secondOpportunity=hache_sharky_prospect_opportunity_open($pdo,$producerContact,'wamid.f6.prospect.002',$producerState);
    f6m_expect(is_string($secondOpportunity)&&$secondOpportunity!==''&&$secondOpportunity!==$firstOpportunity,'Un nuevo primer evento debe poder representar otra oportunidad del mismo contacto.');
    $q=$pdo->prepare('SELECT COUNT(*) FROM sharky_prospect_opportunities WHERE contact_hash=? AND status=\'OPEN\'');
    $q->execute([$producerContact]);
    f6m_expect((int)$q->fetchColumn()===2,'Oportunidades históricas distintas no deben colapsarse por contacto.');

    $secondVenueState=$producerState;
    $secondVenueState['commercial_context']['f6_opportunity_id']=$secondOpportunity;
    $secondVenueState['commercial_context']['sede_clave']='PALAPAS';
    f6m_expect(
        hache_sharky_prospect_opportunity_enrich_sede($pdo,$producerContact,$secondVenueState),
        'La oportunidad activa debe poder enriquecerse sin tocar otra oportunidad del mismo contacto.'
    );
    $q=$pdo->prepare('SELECT id,sede_clave FROM sharky_prospect_opportunities WHERE contact_hash=? ORDER BY opened_at,id');
    $q->execute([$producerContact]);
    $venueRows=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$venueRows[(string)$row['id']]=$row['sede_clave'];
    f6m_expect(($venueRows[$firstOpportunity]??null)==='MONTEVERDE','Enriquecer una oportunidad posterior no debe reescribir la anterior.');
    f6m_expect(($venueRows[$secondOpportunity]??null)==='PALAPAS','La segunda oportunidad debe conservar su propia sede confirmada.');

    $secondVenueState['commercial_context']['sede_clave']='MONTEVERDE';
    f6m_expect(
        hache_sharky_prospect_opportunity_enrich_sede($pdo,$producerContact,$secondVenueState),
        'Ver otra sede debe actualizar la misma oportunidad, no crear una nueva.'
    );
    $q=$pdo->prepare('SELECT sede_clave FROM sharky_prospect_opportunities WHERE id=?');
    $q->execute([$secondOpportunity]);
    f6m_expect((string)$q->fetchColumn()==='MONTEVERDE','La reselección estructurada debe sustituir solo sede_clave en la oportunidad activa.');

    $ambiguousState=$producerState;
    $ambiguousState['commercial_context']['sede_clave']='PALAPAS';
    f6m_expect(
        hache_sharky_prospect_opportunity_enrich_sede($pdo,$producerContact,$ambiguousState)===false,
        'Sin id durable, dos oportunidades OPEN del mismo contacto deben fallar sin adivinar cuál enriquecer.'
    );

    $missingState=$producerState;
    $missingState['commercial_context']['f6_opportunity_id']='ffffffff-ffff-ffff-ffff-ffffffffffff';
    $missingState['commercial_context']['sede_clave']='PALAPAS';
    $missingRequiresRetry=false;
    try{
        hache_sharky_prospect_opportunity_enrich_sede($pdo,$producerContact,$missingState);
    }catch(RuntimeException){
        $missingRequiresRetry=true;
    }
    f6m_expect(
        $missingRequiresRetry,
        'Una oportunidad durable exacta que no pueda persistir sede debe exigir retry en vez de completar silenciosamente el turno.'
    );

    $auditKey=hash('sha256','wamid.f6.convert.001|register_regular');
    $pdo->prepare("INSERT INTO sharky_action_audit(idempotency_key,action_type,contact_hash,status,completed_at) VALUES(?,'register_regular',?,'COMPLETED',UTC_TIMESTAMP())")
        ->execute([$auditKey,$producerContact]);
    f6m_expect(
        hache_sharky_prospect_opportunity_link_completed_registration($pdo,$auditKey,$secondOpportunity),
        'Una inscripción Sharky COMPLETED debe convertir únicamente la oportunidad exacta transportada por el estado.'
    );
    $q=$pdo->prepare('SELECT id,status,conversion_action_hash,closed_at FROM sharky_prospect_opportunities WHERE contact_hash=? ORDER BY opened_at,id');
    $q->execute([$producerContact]);
    $convertedRows=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$convertedRows[(string)$row['id']]=$row;
    f6m_expect(
        ($convertedRows[$firstOpportunity]['status']??null)==='OPEN'
        &&($convertedRows[$firstOpportunity]['conversion_action_hash']??null)===null,
        'Convertir una oportunidad no debe cerrar otra oportunidad OPEN del mismo contacto.'
    );
    f6m_expect(
        ($convertedRows[$secondOpportunity]['status']??null)==='CONVERTED'
        &&($convertedRows[$secondOpportunity]['conversion_action_hash']??null)===$auditKey
        &&trim((string)($convertedRows[$secondOpportunity]['closed_at']??''))!=='',
        'La conversión debe conservar el hash del audit COMPLETED y el cierre durable.'
    );
    f6m_expect(
        hache_sharky_prospect_opportunity_link_completed_registration($pdo,$auditKey,$secondOpportunity),
        'Reconciliar la misma conversión COMPLETED debe ser idempotente.'
    );
    f6m_expect(
        hache_sharky_prospect_opportunity_link_completed_registration($pdo,$auditKey,$firstOpportunity)===false,
        'Una misma acción COMPLETED no debe convertir dos oportunidades del mismo contacto.'
    );
    f6m_expect(
        (string)$pdo->query("SELECT status FROM sharky_prospect_opportunities WHERE id='{$firstOpportunity}'")->fetchColumn()==='OPEN',
        'La unicidad del audit de conversión debe conservar intacta la otra oportunidad.'
    );
    f6m_expect(
        hache_sharky_prospect_opportunity_link_completed_registration($pdo,$auditKey,'10000000-0000-0000-0000-000000000001')===false,
        'El mismo audit no debe convertir una oportunidad de otro contacto.'
    );
    f6m_expect(
        (string)$pdo->query("SELECT status FROM sharky_prospect_opportunities WHERE id='10000000-0000-0000-0000-000000000001'")->fetchColumn()==='OPEN',
        'Un UUID no perteneciente al contacto del audit debe quedar intacto.'
    );

    $existingStudentState=$producerState;
    $existingStudentState['commercial_context']['f6_opportunity_id']=$firstOpportunity;
    $existingStudentEvidence=['found'=>true,'student_id'=>'student-existing-001'];
    f6m_expect(
        hache_sharky_prospect_opportunity_exclude_durable_student($pdo,$producerContact,$existingStudentState,$existingStudentEvidence),
        'Una identidad durable de alumno existente debe excluir únicamente su oportunidad OPEN exacta.'
    );
    $q=$pdo->prepare('SELECT status,conversion_action_hash,closed_at FROM sharky_prospect_opportunities WHERE id=?');
    $q->execute([$firstOpportunity]);
    $excluded=$q->fetch(PDO::FETCH_ASSOC);
    f6m_expect(
        is_array($excluded)
        &&(string)$excluded['status']==='EXCLUDED'
        &&$excluded['conversion_action_hash']===null
        &&trim((string)($excluded['closed_at']??''))!=='',
        'La exclusión por alumno existente debe cerrar la oportunidad sin convertirla ni persistir identidad del alumno.'
    );
    f6m_expect(
        hache_sharky_prospect_opportunity_exclude_durable_student($pdo,$producerContact,$existingStudentState,$existingStudentEvidence),
        'Repetir la misma exclusión durable debe ser idempotente.'
    );
    f6m_expect(
        (string)$pdo->query("SELECT status FROM sharky_prospect_opportunities WHERE id='{$secondOpportunity}'")->fetchColumn()==='CONVERTED',
        'Excluir una oportunidad OPEN no debe degradar una conversión ya confirmada.'
    );

    $legacyOpen=hache_sharky_prospect_opportunity_open($pdo,$producerContact,'wamid.f6.prospect.legacy-open',$producerState);
    f6m_expect(is_string($legacyOpen)&&$legacyOpen!=='','Debe existir una oportunidad OPEN para comprobar el caso sin UUID.');
    f6m_expect(
        hache_sharky_prospect_opportunity_exclude_durable_student($pdo,$producerContact,$producerState,$existingStudentEvidence),
        'Una identidad durable sin UUID F6 debe omitir la exclusión sin adivinar por contacto.'
    );
    f6m_expect(
        (string)$pdo->query("SELECT status FROM sharky_prospect_opportunities WHERE id='{$legacyOpen}'")->fetchColumn()==='OPEN',
        'Sin UUID exacto, una oportunidad OPEN no debe excluirse por fallback de contacto.'
    );

    $mismatchedStudentState=$producerState;
    $mismatchedStudentState['commercial_context']['f6_opportunity_id']=$legacyOpen;
    $mismatchRequiresRetry=false;
    try{
        hache_sharky_prospect_opportunity_exclude_durable_student($pdo,str_repeat('9',64),$mismatchedStudentState,['verified'=>true,'student_id'=>'student-existing-002']);
    }catch(RuntimeException){
        $mismatchRequiresRetry=true;
    }
    f6m_expect(
        $mismatchRequiresRetry,
        'Un UUID exacto que no pertenece al contacto durable debe exigir retry y no cerrar otra oportunidad.'
    );
    f6m_expect(
        (string)$pdo->query("SELECT status FROM sharky_prospect_opportunities WHERE id='{$legacyOpen}'")->fetchColumn()==='OPEN',
        'El fallo de identidad/contacto no debe mutar la oportunidad OPEN.'
    );

    $studentState=['identity'=>['kind'=>'student'],'commercial_context'=>['entry_source'=>'direct']];
    f6m_expect(
        hache_sharky_prospect_opportunity_open($pdo,str_repeat('2',64),'wamid.f6.student.001',$studentState)===null,
        'Una identidad de alumno no debe abrir una oportunidad de prospecto.'
    );

    $keys=$pdo->query("SELECT clave FROM configuracion WHERE clave IN ('dashboard_asistencia_cobertura_desde','dashboard_bajas_cobertura_desde','dashboard_prospectos_cobertura_desde') ORDER BY clave")->fetchAll(PDO::FETCH_COLUMN);
    f6m_expect(
        $keys===['dashboard_asistencia_cobertura_desde','dashboard_bajas_cobertura_desde','dashboard_prospectos_cobertura_desde'],
        'F6 debe conservar los tres marcadores de cobertura forward-only ya publicados.'
    );

    echo "F6_DASHBOARD_METRICS_MARIADB_OK\n";
}finally{
    $server->exec("DROP DATABASE IF EXISTS `{$db}`");
}
