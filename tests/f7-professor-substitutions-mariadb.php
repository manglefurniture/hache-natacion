<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/config/profesor-sustituciones.php';

function f72_expect(bool $ok,string $message): void
{
    if(!$ok)throw new RuntimeException($message);
}

function f72_throws(callable $fn,string $contains,int $status): void
{
    try{$fn();}catch(HacheProfesorSustitucionException $e){
        f72_expect($e->httpStatus===$status,'HTTP inesperado: '.$e->httpStatus.' para '.$e->getMessage());
        f72_expect(str_contains($e->getMessage(),$contains),'Mensaje inesperado: '.$e->getMessage());
        return;
    }
    throw new RuntimeException('Se esperaba HacheProfesorSustitucionException: '.$contains);
}

$host=(string)(getenv('DELIVERY_DB_HOST')?:'127.0.0.1');
$port=(string)(getenv('DELIVERY_DB_PORT')?:'3306');
$user=(string)(getenv('DELIVERY_DB_USER')?:'root');
$pass=(string)(getenv('DELIVERY_DB_PASS')?:'root');
$db='hache_f7_professor_substitutions_test';

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
    $pdo->exec("CREATE TABLE usuarios(id CHAR(36) PRIMARY KEY,usuario VARCHAR(100) NOT NULL) {$c}");
    $pdo->exec("CREATE TABLE sedes(id CHAR(36) PRIMARY KEY,clave VARCHAR(20) NOT NULL,nombre VARCHAR(100) NOT NULL) {$c}");
    $pdo->exec("CREATE TABLE profesores(id CHAR(36) PRIMARY KEY,nombre VARCHAR(180) NOT NULL,activo TINYINT(1) NOT NULL DEFAULT 1) {$c}");
    $pdo->exec("CREATE TABLE horarios(id CHAR(36) PRIMARY KEY,sede_id CHAR(36) NOT NULL,hora_inicio TIME NOT NULL,hora_fin TIME NOT NULL) {$c}");
    $pdo->exec("CREATE TABLE sesiones(id CHAR(36) PRIMARY KEY,fecha DATE NOT NULL,horario_id CHAR(36) NOT NULL,estado VARCHAR(30) NOT NULL,cerrada TINYINT(1) NOT NULL DEFAULT 0) {$c}");
    $pdo->exec("CREATE TABLE profesor_horarios(
        id CHAR(36) PRIMARY KEY,profesor_id CHAR(36) NOT NULL,horario_id CHAR(36) NOT NULL,activo TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY uq_profesor_horario(profesor_id,horario_id)
    ) {$c}");
    $pdo->exec("CREATE TABLE profesor_horario_vigencias(
        id CHAR(36) PRIMARY KEY,profesor_horario_id CHAR(36) NOT NULL,vigente_desde DATETIME NOT NULL,vigente_hasta DATETIME NULL
    ) {$c}");
    $pdo->exec("CREATE TABLE configuracion(clave VARCHAR(100) PRIMARY KEY,valor VARCHAR(255) NOT NULL,descripcion TEXT NULL) {$c}");

    $admin='00000000-0000-0000-0000-000000000001';
    $site='00000000-0000-0000-0000-000000000002';
    $schedule='00000000-0000-0000-0000-000000000010';
    $otherSchedule='00000000-0000-0000-0000-000000000011';
    $original1='00000000-0000-0000-0000-000000000020';
    $original2='00000000-0000-0000-0000-000000000021';
    $sub1='00000000-0000-0000-0000-000000000022';
    $sub2='00000000-0000-0000-0000-000000000023';
    $regular='00000000-0000-0000-0000-000000000024';
    $inactive='00000000-0000-0000-0000-000000000025';
    $unassigned='00000000-0000-0000-0000-000000000026';
    $session1='00000000-0000-0000-0000-000000000030';
    $sessionCancelled='00000000-0000-0000-0000-000000000031';
    $sessionClosed='00000000-0000-0000-0000-000000000032';

    $pdo->prepare('INSERT INTO usuarios(id,usuario) VALUES(?,?)')->execute([$admin,'admin']);
    $pdo->prepare('INSERT INTO sedes(id,clave,nombre) VALUES(?,?,?)')->execute([$site,'MONTEVERDE','Monteverde']);
    $h=$pdo->prepare('INSERT INTO horarios(id,sede_id,hora_inicio,hora_fin) VALUES(?,?,?,?)');
    $h->execute([$schedule,$site,'19:00:00','20:00:00']);
    $h->execute([$otherSchedule,$site,'20:00:00','21:00:00']);
    $p=$pdo->prepare('INSERT INTO profesores(id,nombre,activo) VALUES(?,?,?)');
    foreach([
        [$original1,'Original Uno',1],[$original2,'Original Dos',1],[$sub1,'Sustituto Uno',1],
        [$sub2,'Sustituto Dos',1],[$regular,'Regular Tres',1],[$inactive,'Inactivo',0],[$unassigned,'Sin Asignación',1],
    ] as $row)$p->execute($row);

    $ph=$pdo->prepare('INSERT INTO profesor_horarios(id,profesor_id,horario_id,activo) VALUES(?,?,?,1)');
    $v=$pdo->prepare("INSERT INTO profesor_horario_vigencias(id,profesor_horario_id,vigente_desde,vigente_hasta) VALUES(UUID(),?,'2098-01-01 00:00:00',NULL)");
    foreach([
        ['10000000-0000-0000-0000-000000000001',$original1,$schedule],
        ['10000000-0000-0000-0000-000000000002',$original2,$schedule],
        ['10000000-0000-0000-0000-000000000003',$regular,$schedule],
        ['10000000-0000-0000-0000-000000000004',$sub1,$otherSchedule],
    ] as [$id,$prof,$hor]){
        $ph->execute([$id,$prof,$hor]);$v->execute([$id]);
    }

    $s=$pdo->prepare('INSERT INTO sesiones(id,fecha,horario_id,estado,cerrada) VALUES(?,?,?,?,?)');
    $s->execute([$session1,'2099-01-10',$schedule,'PROGRAMADA',0]);
    $s->execute([$sessionCancelled,'2099-01-11',$schedule,'CANCELADA',1]);
    $s->execute([$sessionClosed,'2099-01-12',$schedule,'REALIZADA',1]);

    $sql=file_get_contents(dirname(__DIR__).'/database/migrations/20260918_f7_professor_substitutions.sql');
    f72_expect(is_string($sql),'No se pudo leer la migración F7.2.');
    $withoutComments=preg_replace('/^\s*--.*$/m','',$sql);
    f72_expect(is_string($withoutComments),'No se pudo normalizar la migración F7.2.');
    $statements=array_values(array_filter(array_map('trim',explode(';',$withoutComments)),static fn(string $x):bool=>$x!==''));
    foreach($statements as $statement)$pdo->exec($statement);
    foreach($statements as $statement)$pdo->exec($statement);

    f72_expect(hache_profesor_sustituciones_schema_ready($pdo),'El helper debe reconocer el esquema F7.2.');
    f72_expect((int)$pdo->query('SELECT COUNT(*) FROM profesor_sustituciones')->fetchColumn()===0,'La migración no debe inventar sustituciones.');
    $coverage=(string)$pdo->query("SELECT valor FROM configuracion WHERE clave='profesores_sustituciones_cobertura_desde'")->fetchColumn();
    f72_expect($coverage!==''&&$coverage<'2099-01-10 19:00:00','El marcador debe preceder al fixture futuro.');

    $id1=hache_profesor_sustitucion_registrar($pdo,$session1,$original1,$sub1,'Cobertura por ausencia',$admin);
    f72_expect($id1!=='','Debe registrar la sustitución explícita.');
    $row=$pdo->query("SELECT * FROM profesor_sustituciones WHERE id='{$id1}'")->fetch(PDO::FETCH_ASSOC);
    f72_expect((string)$row['estado']==='ACTIVA'&&(string)$row['origen']==='ADMIN','La sustitución debe quedar activa y explícita.');
    f72_expect((string)$row['created_by']===$admin&&(string)$row['motivo']==='Cobertura por ausencia','Debe conservar actor y motivo.');

    f72_throws(
        fn()=>hache_profesor_sustitucion_registrar($pdo,$session1,$original1,$sub2,'Duplicada',$admin),
        'Ya existe una sustitución activa',409
    );
    f72_throws(
        fn()=>hache_profesor_sustitucion_registrar($pdo,$session1,$original2,$regular,'Ya era co-docente',$admin),
        'ya estaba asignado regularmente',409
    );
    f72_throws(
        fn()=>hache_profesor_sustitucion_registrar($pdo,$session1,$original2,$inactive,'No activo',$admin),
        'debe estar activo',409
    );
    f72_throws(
        fn()=>hache_profesor_sustitucion_registrar($pdo,$session1,$unassigned,$sub2,'Sin relación',$admin),
        'no tiene una asignación durable',409
    );
    f72_throws(
        fn()=>hache_profesor_sustitucion_registrar($pdo,$session1,$original2,$original2,'Mismo profe',$admin),
        'debe ser distinto',422
    );
    f72_throws(
        fn()=>hache_profesor_sustitucion_registrar($pdo,$sessionCancelled,$original2,$sub2,'Cancelada',$admin),
        'sesión cancelada',409
    );

    $coId=hache_profesor_sustitucion_registrar($pdo,$session1,$original2,$sub2,'Segundo docente cubierto',$admin);
    f72_expect($coId!==$id1,'La docencia compartida debe permitir sustituciones independientes por profesor original.');
    f72_expect((int)$pdo->query("SELECT COUNT(*) FROM profesor_sustituciones WHERE sesion_id='{$session1}' AND estado='ACTIVA'")->fetchColumn()===2,'Deben coexistir dos sustituciones de co-docentes diferentes.');

    $closedId=hache_profesor_sustitucion_registrar($pdo,$sessionClosed,$original1,$sub2,'Registro posterior con evidencia',$admin);
    f72_expect($closedId!=='','Una sesión REALIZADA dentro de cobertura debe admitir un registro explícito posterior.');

    hache_profesor_sustitucion_anular($pdo,$id1,'Captura incorrecta',$admin);
    $annulled=$pdo->query("SELECT estado,anulada_by,anulada_at,motivo_anulacion FROM profesor_sustituciones WHERE id='{$id1}'")->fetch(PDO::FETCH_ASSOC);
    f72_expect((string)$annulled['estado']==='ANULADA'&&(string)$annulled['anulada_by']===$admin&&!empty($annulled['anulada_at']),'Anular debe conservar actor y fecha.');
    f72_expect((string)$annulled['motivo_anulacion']==='Captura incorrecta','Anular debe conservar motivo.');

    $replacementId=hache_profesor_sustitucion_registrar($pdo,$session1,$original1,$sub2,'Corrección explícita',$admin);
    f72_expect($replacementId!==$id1,'Tras anular debe poder registrarse una nueva sustitución sin reescribir la anterior.');
    f72_expect((int)$pdo->query("SELECT COUNT(*) FROM profesor_sustituciones WHERE sesion_id='{$session1}' AND profesor_original_id='{$original1}'")->fetchColumn()===2,'La historia anulada debe preservarse.');

    $ctx=hache_profesor_sustituciones_contexto($pdo,'2099-01-10','2099-01-12');
    f72_expect(count($ctx['sesiones'])===3,'La lectura debe listar las sesiones del rango.');
    $sessionMap=[];foreach($ctx['sesiones'] as $x)$sessionMap[(string)$x['id']]=$x;
    f72_expect(count($sessionMap[$session1]['profesores_asignados']??[])===3,'La lectura debe mostrar los profesores originalmente asignados, incluida co-docencia.');
    f72_expect(count($ctx['sustituciones'])===4,'La lectura debe conservar activas y anuladas para trazabilidad.');

    $pdo->prepare("UPDATE configuracion SET valor='2099-01-11 00:00:00' WHERE clave='profesores_sustituciones_cobertura_desde'")->execute();
    f72_throws(
        fn()=>hache_profesor_sustitucion_registrar($pdo,$session1,$original1,$sub1,'Antes de cobertura',$admin),
        'fuera de la cobertura',409
    );

    $api=(string)file_get_contents(dirname(__DIR__).'/api/profesor-sustituciones.php');
    f72_expect(str_contains($api,"auth_require(['ADMIN'])"),'La API de sustituciones debe permanecer ADMIN-only.');
    f72_expect(str_contains($api,"$action==='REGISTRAR'")&&str_contains($api,"$action==='ANULAR'"),'La API debe exponer registro y anulación explícitos.');
    f72_expect(!str_contains($api,'profesor_cancelaciones'),'La API no debe inferir sustituciones desde cancelaciones.');

    $deploy=(string)file_get_contents(dirname(__DIR__).'/ops/production-readiness/deploy-hache-natacion');
    f72_expect(str_contains($deploy,'bin/migrate-f7-professor-substitutions.php'),'El deploy debe aplicar la migración F7.2.');

    echo "F7_PROFESSOR_SUBSTITUTIONS_MARIADB_OK\n";
}finally{
    $server->exec("DROP DATABASE IF EXISTS `{$db}`");
}
