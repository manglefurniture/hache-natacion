<?php
declare(strict_types=1);

function f71_expect(bool $ok,string $message): void
{
    if(!$ok)throw new RuntimeException($message);
}

$host=(string)(getenv('DELIVERY_DB_HOST')?:'127.0.0.1');
$port=(string)(getenv('DELIVERY_DB_PORT')?:'3306');
$user=(string)(getenv('DELIVERY_DB_USER')?:'root');
$pass=(string)(getenv('DELIVERY_DB_PASS')?:'root');
$db='hache_f7_professor_assignment_test';

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

    $c='ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE usuarios(id CHAR(36) PRIMARY KEY) {$c}");
    $pdo->exec("CREATE TABLE profesores(id CHAR(36) PRIMARY KEY,nombre VARCHAR(180) NOT NULL,whatsapp VARCHAR(20) NOT NULL,activo TINYINT(1) NOT NULL DEFAULT 1) {$c}");
    $pdo->exec("CREATE TABLE horarios(id CHAR(36) PRIMARY KEY,activo TINYINT(1) NOT NULL DEFAULT 1) {$c}");
    $pdo->exec("CREATE TABLE profesor_horarios(
        id CHAR(36) PRIMARY KEY,
        profesor_id CHAR(36) NOT NULL,
        horario_id CHAR(36) NOT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        created_by CHAR(36) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_profesor_horario(profesor_id,horario_id)
    ) {$c}");
    $pdo->exec("CREATE TABLE configuracion(clave VARCHAR(100) PRIMARY KEY,valor VARCHAR(255) NOT NULL,descripcion TEXT NULL) {$c}");

    $admin='00000000-0000-0000-0000-000000000001';
    $teacher1='00000000-0000-0000-0000-000000000010';
    $teacher2='00000000-0000-0000-0000-000000000011';
    $legacyInactive='00000000-0000-0000-0000-000000000012';
    $schedule1='00000000-0000-0000-0000-000000000020';
    $schedule2='00000000-0000-0000-0000-000000000021';
    $assignment1='00000000-0000-0000-0000-000000000030';
    $assignment2='00000000-0000-0000-0000-000000000031';
    $legacyAssignment='00000000-0000-0000-0000-000000000032';

    $pdo->prepare('INSERT INTO usuarios(id) VALUES(?)')->execute([$admin]);
    $p=$pdo->prepare('INSERT INTO profesores(id,nombre,whatsapp,activo) VALUES(?,?,?,?)');
    $p->execute([$teacher1,'Profe Uno','+529981112233',1]);
    $p->execute([$teacher2,'Profe Dos','+529981112244',1]);
    $p->execute([$legacyInactive,'Profe Legacy Inactivo','+529981112255',0]);
    $h=$pdo->prepare('INSERT INTO horarios(id,activo) VALUES(?,1)');
    $h->execute([$schedule1]);$h->execute([$schedule2]);
    $a=$pdo->prepare('INSERT INTO profesor_horarios(id,profesor_id,horario_id,activo,created_by) VALUES(?,?,?,?,?)');
    $a->execute([$assignment1,$teacher1,$schedule1,1,$admin]);
    $a->execute([$assignment2,$teacher1,$schedule2,0,$admin]);
    $a->execute([$legacyAssignment,$legacyInactive,$schedule1,1,$admin]);

    $sql=file_get_contents(dirname(__DIR__).'/database/migrations/20260918_f7_professor_assignment_validity.sql');
    f71_expect(is_string($sql),'No se pudo leer la migración F7.1.');
    $withoutComments=preg_replace('/^\s*--.*$/m','',$sql);
    f71_expect(is_string($withoutComments),'No se pudo normalizar la migración F7.1.');
    $statements=array_values(array_filter(array_map('trim',explode(';',$withoutComments)),static fn(string $s):bool=>$s!==''));
    foreach($statements as $statement)$pdo->exec($statement);
    foreach($statements as $statement)$pdo->exec($statement);

    f71_expect((int)$pdo->query("SELECT COUNT(*) FROM profesor_horario_vigencias WHERE profesor_horario_id='{$legacyAssignment}'")->fetchColumn()===0,'Un profesor ya inactivo no debe recibir baseline F7.');

    $coverage=(string)$pdo->query("SELECT valor FROM configuracion WHERE clave='profesores_asignaciones_cobertura_desde'")->fetchColumn();
    f71_expect($coverage!=='','Debe existir un marcador durable de cobertura forward-only.');
    f71_expect((int)$pdo->query("SELECT COUNT(*) FROM configuracion WHERE clave='profesores_asignaciones_baseline_aplicado'")->fetchColumn()===1,'El baseline debe quedar marcado una sola vez.');
    f71_expect((int)$pdo->query("SELECT COUNT(*) FROM profesor_horario_vigencias WHERE profesor_horario_id='{$assignment1}'")->fetchColumn()===1,'La asignación activa inicial debe recibir un único baseline.');
    f71_expect((int)$pdo->query("SELECT COUNT(*) FROM profesor_horario_vigencias WHERE profesor_horario_id='{$assignment2}'")->fetchColumn()===0,'Una asignación inactiva anterior no debe inventar historia.');
    $baseline=$pdo->query("SELECT vigente_desde,origen,created_by,vigente_hasta FROM profesor_horario_vigencias WHERE profesor_horario_id='{$assignment1}'")->fetch();
    f71_expect((string)$baseline['vigente_desde']===$coverage,'El baseline debe comenzar exactamente en el marcador de cobertura, no antes.');
    f71_expect((string)$baseline['origen']==='F7_BASELINE'&&$baseline['created_by']===null&&$baseline['vigente_hasta']===null,'El baseline no debe inventar actor ni cierre histórico.');

    require_once dirname(__DIR__).'/config/profesores-asignaciones.php';

    f71_expect(!hache_profesores_vigencias_schema_ready($pdo),'Una asignación activa de profesor inactivo debe invalidar el readiness.');
    f71_expect(hache_profesores_reconciliar_inactivos($pdo)===1,'La reparación debe desactivar exactamente la asignación legacy.');
    f71_expect((int)$pdo->query("SELECT activo FROM profesor_horarios WHERE id='{$legacyAssignment}'")->fetchColumn()===0,'La asignación legacy debe quedar inactiva antes de continuar.');

    $pdo->prepare("INSERT INTO profesor_horario_vigencias(id,profesor_horario_id,vigente_desde,origen) VALUES(UUID(),?,?, 'F7_BASELINE')")
        ->execute([$legacyAssignment,$coverage]);
    f71_expect(!hache_profesores_vigencias_schema_ready($pdo),'Un baseline abierto sobre asignación inactiva debe invalidar el readiness.');
    f71_expect(hache_profesores_reconciliar_inactivos($pdo)===1,'La reparación debe cerrar exactamente el baseline sintético legacy.');
    $legacyBaseline=$pdo->query("SELECT vigente_desde,vigente_hasta,origen FROM profesor_horario_vigencias WHERE profesor_horario_id='{$legacyAssignment}'")->fetch();
    f71_expect(is_array($legacyBaseline)&&(string)$legacyBaseline['vigente_hasta']===(string)$legacyBaseline['vigente_desde'],'La reparación debe conservar la fila y colapsarla sin inventar historia.');
    f71_expect(hache_profesores_reconciliar_inactivos($pdo)===0,'Repetir la reparación debe ser idempotente.');
    f71_expect(hache_profesores_vigencias_schema_ready($pdo),'El helper debe reconocer el esquema F7.1 y sus invariantes de actividad tras reparar.');

    hache_profesores_asignacion_set($pdo,$teacher1,$schedule1,false,$admin);
    f71_expect((int)$pdo->query("SELECT activo FROM profesor_horarios WHERE id='{$assignment1}'")->fetchColumn()===0,'Desasignar debe actualizar el estado actual.');
    $closed=$pdo->query("SELECT vigente_hasta,closed_by FROM profesor_horario_vigencias WHERE profesor_horario_id='{$assignment1}'")->fetch();
    f71_expect(!empty($closed['vigente_hasta'])&&(string)$closed['closed_by']===$admin,'Desasignar debe cerrar el periodo durable con actor.');

    hache_profesores_asignacion_set($pdo,$teacher1,$schedule1,true,$admin);
    f71_expect((int)$pdo->query("SELECT COUNT(*) FROM profesor_horario_vigencias WHERE profesor_horario_id='{$assignment1}'")->fetchColumn()===2,'Reactivar debe abrir un periodo nuevo sin reescribir el anterior.');
    f71_expect((int)$pdo->query("SELECT COUNT(*) FROM profesor_horario_vigencias WHERE profesor_horario_id='{$assignment1}' AND vigente_hasta IS NULL")->fetchColumn()===1,'Solo puede existir un periodo abierto por asignación.');
    hache_profesores_asignacion_set($pdo,$teacher1,$schedule1,true,$admin);
    f71_expect((int)$pdo->query("SELECT COUNT(*) FROM profesor_horario_vigencias WHERE profesor_horario_id='{$assignment1}'")->fetchColumn()===2,'Repetir una asignación activa debe ser idempotente.');

    $assignmentTeacher2=hache_profesores_asignacion_set($pdo,$teacher2,$schedule1,true,$admin);
    f71_expect($assignmentTeacher2!==$assignment1,'La docencia compartida debe conservar asignaciones independientes.');
    f71_expect((int)$pdo->query("SELECT COUNT(*) FROM profesor_horarios WHERE horario_id='{$schedule1}' AND activo=1")->fetchColumn()===2,'Dos profesores activos deben poder compartir un horario.');

    $pdo->beginTransaction();
    $pdo->prepare('UPDATE profesores SET activo=0 WHERE id=?')->execute([$teacher1]);
    $closedAssignments=hache_profesores_cerrar_asignaciones_profesor($pdo,$teacher1,$admin);
    $pdo->commit();
    f71_expect($closedAssignments===1,'Inactivar al profesor debe cerrar su única asignación futura activa.');
    f71_expect((int)$pdo->query("SELECT COUNT(*) FROM profesor_horarios WHERE profesor_id='{$teacher1}' AND activo=1")->fetchColumn()===0,'El profesor inactivo no debe conservar asignaciones futuras activas.');
    f71_expect((int)$pdo->query("SELECT COUNT(*) FROM profesor_horario_vigencias v JOIN profesor_horarios ph ON ph.id=v.profesor_horario_id WHERE ph.profesor_id='{$teacher1}' AND v.vigente_hasta IS NULL")->fetchColumn()===0,'La inactivación debe cerrar todos sus periodos abiertos.');
    f71_expect((int)$pdo->query("SELECT COUNT(*) FROM profesor_horarios WHERE profesor_id='{$teacher2}' AND horario_id='{$schedule1}' AND activo=1")->fetchColumn()===1,'Inactivar un profesor no debe afectar al co-docente.');

    $pdo->prepare('UPDATE profesores SET activo=1 WHERE id=?')->execute([$teacher1]);
    hache_profesores_asignacion_set($pdo,$teacher1,$schedule1,true,$admin);
    f71_expect((int)$pdo->query("SELECT COUNT(*) FROM profesor_horario_vigencias WHERE profesor_horario_id='{$assignment1}'")->fetchColumn()===3,'Reasignar tras reactivar al profesor debe crear otro periodo explícito.');

    $duplicateOpenRejected=false;
    try{
        $pdo->prepare("INSERT INTO profesor_horario_vigencias(id,profesor_horario_id,vigente_desde,origen) VALUES(UUID(),?,UTC_TIMESTAMP(),'ADMIN')")->execute([$assignment1]);
    }catch(PDOException){
        $duplicateOpenRejected=true;
    }
    f71_expect($duplicateOpenRejected,'La base debe impedir dos periodos abiertos para la misma asignación.');

    $api=(string)file_get_contents(dirname(__DIR__).'/api/profesores.php');
    f71_expect(str_contains($api,'hache_profesores_asignacion_set'),'La API debe usar la autoridad F7.1 al asignar o retirar horarios.');
    f71_expect(str_contains($api,'hache_profesores_cerrar_asignaciones_profesor'),'La inactivación del profesor debe cerrar su disponibilidad futura.');
    f71_expect(!str_contains($api,'ON DUPLICATE KEY UPDATE activo=VALUES(activo)'),'La API ya no debe mutar asignaciones sin registrar vigencia.');

    $deploy=(string)file_get_contents(dirname(__DIR__).'/ops/production-readiness/deploy-hache-natacion');
    f71_expect(str_contains($deploy,'bin/migrate-f7-professor-assignment-validity.php'),'El deploy debe aplicar la migración F7.1.');

    echo "F7_PROFESSOR_ASSIGNMENT_VALIDITY_MARIADB_OK\n";
}finally{
    $server->exec("DROP DATABASE IF EXISTS `{$db}`");
}
