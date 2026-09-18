<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/config/profesor-actividad.php';

function f74_expect(bool $ok,string $message): void
{
    if(!$ok)throw new RuntimeException($message);
}

function f74_prof(array $ctx,string $id): array
{
    foreach($ctx['profesores']??[] as $row)if((string)($row['id']??'')===$id)return $row;
    throw new RuntimeException('Profesor no encontrado en contexto: '.$id);
}

function f74_session(array $prof,string $id): array
{
    foreach($prof['sesiones']??[] as $row)if((string)($row['sesion_id']??'')===$id)return $row;
    throw new RuntimeException('Sesión no encontrada en profesor: '.$id);
}

$host=(string)(getenv('DELIVERY_DB_HOST')?:'127.0.0.1');
$port=(string)(getenv('DELIVERY_DB_PORT')?:'3306');
$user=(string)(getenv('DELIVERY_DB_USER')?:'root');
$pass=(string)(getenv('DELIVERY_DB_PASS')?:'root');
$db='hache_f7_professor_activity_test';

$server=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);

try{
    $server->exec("DROP DATABASE IF EXISTS \`{$db}\`");
    $server->exec("CREATE DATABASE \`{$db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    $c='ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $pdo->exec("CREATE TABLE usuarios(id CHAR(36) PRIMARY KEY,usuario VARCHAR(100) NOT NULL) {$c}");
    $pdo->exec("CREATE TABLE configuracion(clave VARCHAR(100) PRIMARY KEY,valor VARCHAR(255) NOT NULL) {$c}");
    $pdo->exec("CREATE TABLE sedes(id CHAR(36) PRIMARY KEY,clave VARCHAR(20) NOT NULL,nombre VARCHAR(100) NOT NULL) {$c}");
    $pdo->exec("CREATE TABLE profesores(id CHAR(36) PRIMARY KEY,nombre VARCHAR(180) NOT NULL,activo TINYINT(1) NOT NULL DEFAULT 1) {$c}");
    $pdo->exec("CREATE TABLE horarios(id CHAR(36) PRIMARY KEY,sede_id CHAR(36) NOT NULL,hora_inicio TIME NOT NULL,hora_fin TIME NOT NULL,activo TINYINT(1) NOT NULL DEFAULT 1) {$c}");
    $pdo->exec("CREATE TABLE sesiones(id CHAR(36) PRIMARY KEY,fecha DATE NOT NULL,horario_id CHAR(36) NOT NULL,estado VARCHAR(30) NOT NULL,cerrada TINYINT(1) NOT NULL DEFAULT 0) {$c}");
    $pdo->exec("CREATE TABLE profesor_horarios(
        id CHAR(36) PRIMARY KEY,profesor_id CHAR(36) NOT NULL,horario_id CHAR(36) NOT NULL,activo TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY uq_profesor_horario(profesor_id,horario_id)
    ) {$c}");
    $pdo->exec("CREATE TABLE profesor_horario_vigencias(
        id CHAR(36) PRIMARY KEY,
        profesor_horario_id CHAR(36) NOT NULL,
        vigente_desde DATETIME NOT NULL,
        vigente_hasta DATETIME NULL,
        origen VARCHAR(30) NOT NULL DEFAULT 'ADMIN',
        created_by CHAR(36) NULL,
        closed_by CHAR(36) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        abierta TINYINT AS (IF(vigente_hasta IS NULL,1,NULL)) STORED,
        UNIQUE KEY uq_profesor_horario_vigencia_abierta(profesor_horario_id,abierta)
    ) {$c}");
    $pdo->exec("CREATE TABLE profesor_cancelaciones(
        id CHAR(36) PRIMARY KEY,profesor_id CHAR(36) NOT NULL,sesion_id CHAR(36) NOT NULL,motivo VARCHAR(500) NOT NULL,
        source VARCHAR(20) NOT NULL,action_key CHAR(64) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_profesor_cancelacion_profesor_sesion(profesor_id,sesion_id)
    ) {$c}");
    $pdo->exec("CREATE TABLE profesor_sustituciones(
        id CHAR(36) PRIMARY KEY,sesion_id CHAR(36) NOT NULL,profesor_original_id CHAR(36) NOT NULL,profesor_sustituto_id CHAR(36) NOT NULL,
        motivo VARCHAR(500) NOT NULL,origen VARCHAR(30) NOT NULL,estado VARCHAR(20) NOT NULL,created_by CHAR(36) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,anulada_by CHAR(36) NULL,anulada_at DATETIME NULL,motivo_anulacion VARCHAR(500) NULL,
        activa TINYINT AS (IF(estado='ACTIVA',1,NULL)) STORED,
        UNIQUE KEY uq_profesor_sustitucion_activa(sesion_id,profesor_original_id,activa)
    ) {$c}");

    $admin='00000000-0000-0000-0000-000000000001';
    $site='00000000-0000-0000-0000-000000000002';
    $h1='00000000-0000-0000-0000-000000000010';
    $h2='00000000-0000-0000-0000-000000000011';
    $a='00000000-0000-0000-0000-000000000020';
    $b='00000000-0000-0000-0000-000000000021';
    $sub='00000000-0000-0000-0000-000000000022';
    $inactive='00000000-0000-0000-0000-000000000023';
    $s1='00000000-0000-0000-0000-000000000031';
    $s2='00000000-0000-0000-0000-000000000032';
    $s3='00000000-0000-0000-0000-000000000033';
    $s4='00000000-0000-0000-0000-000000000034';
    $s5='00000000-0000-0000-0000-000000000035';
    $s6='00000000-0000-0000-0000-000000000036';

    $pdo->prepare('INSERT INTO usuarios(id,usuario) VALUES(?,?)')->execute([$admin,'admin']);
    $pdo->prepare('INSERT INTO configuracion(clave,valor) VALUES(?,?),(?,?),(?,?)')->execute([
        'profesores_asignaciones_cobertura_desde','2099-01-01 00:00:00',
        'profesores_asignaciones_baseline_aplicado','2099-01-01 00:00:00',
        'profesores_sustituciones_cobertura_desde','2099-01-01 00:00:00',
    ]);
    $pdo->prepare('INSERT INTO sedes(id,clave,nombre) VALUES(?,?,?)')->execute([$site,'MONTEVERDE','Monteverde']);
    $h=$pdo->prepare('INSERT INTO horarios(id,sede_id,hora_inicio,hora_fin,activo) VALUES(?,?,?,?,1)');
    $h->execute([$h1,$site,'07:00:00','08:00:00']);
    $h->execute([$h2,$site,'08:00:00','08:45:00']);

    $p=$pdo->prepare('INSERT INTO profesores(id,nombre,activo) VALUES(?,?,?)');
    foreach([[$a,'Profe A',1],[$b,'Profe B',1],[$sub,'Profe Sustituto',1],[$inactive,'Profe Inactivo',0]] as $row)$p->execute($row);

    $ph=$pdo->prepare('INSERT INTO profesor_horarios(id,profesor_id,horario_id,activo) VALUES(?,?,?,?)');
    $v=$pdo->prepare("INSERT INTO profesor_horario_vigencias(id,profesor_horario_id,vigente_desde,vigente_hasta) VALUES(UUID(),?,'2098-01-01 00:00:00',?)");
    $assignA='10000000-0000-0000-0000-000000000001';
    $assignB='10000000-0000-0000-0000-000000000002';
    $assignInactive='10000000-0000-0000-0000-000000000003';
    $ph->execute([$assignA,$a,$h1,1]);$v->execute([$assignA,null]);
    $ph->execute([$assignB,$b,$h1,1]);$v->execute([$assignB,null]);
    $ph->execute([$assignInactive,$inactive,$h2,0]);$v->execute([$assignInactive,'2099-02-01 15:00:00']);

    $s=$pdo->prepare('INSERT INTO sesiones(id,fecha,horario_id,estado,cerrada) VALUES(?,?,?,?,?)');
    $s->execute([$s1,'2099-01-10',$h1,'REALIZADA',1]);
    $s->execute([$s2,'2099-01-11',$h1,'REALIZADA',1]);
    $s->execute([$s3,'2099-01-12',$h1,'REALIZADA',1]);
    $s->execute([$s4,'2099-01-13',$h1,'CANCELADA',1]);
    $s->execute([$s5,'2099-01-14',$h2,'REALIZADA',1]);
    $s->execute([$s6,'2099-01-15',$h1,'PROGRAMADA',0]);

    $pdo->prepare("INSERT INTO profesor_cancelaciones(id,profesor_id,sesion_id,motivo,source,created_at) VALUES(UUID(),?,?,?,'SHARKY','2099-01-12 10:00:00')")
        ->execute([$b,$s3,'No disponible']);
    $pdo->prepare("INSERT INTO profesor_cancelaciones(id,profesor_id,sesion_id,motivo,source,created_at) VALUES(UUID(),?,?,?,'BACKEND','2099-01-14 10:00:00')")
        ->execute([$b,$s6,'Aviso futuro']);

    $subInsert=$pdo->prepare("INSERT INTO profesor_sustituciones(
        id,sesion_id,profesor_original_id,profesor_sustituto_id,motivo,origen,estado,created_by,created_at,anulada_by,anulada_at,motivo_anulacion
    ) VALUES(?,?,?,?,?,'ADMIN',?,?,?, ?,?,?)");
    $subInsert->execute([
        '20000000-0000-0000-0000-000000000001',$s1,$a,$sub,'Captura corregida','ANULADA',$admin,'2099-01-10 09:00:00',
        $admin,'2099-01-10 09:05:00','No correspondía',
    ]);
    $subInsert->execute([
        '20000000-0000-0000-0000-000000000002',$s2,$a,$sub,'Cobertura confirmada','ACTIVA',$admin,'2099-01-11 09:00:00',
        null,null,null,
    ]);
    $subInsert->execute([
        '20000000-0000-0000-0000-000000000003',$s6,$a,$sub,'Cobertura programada','ACTIVA',$admin,'2099-01-14 10:05:00',
        null,null,null,
    ]);

    f74_expect(hache_profesor_actividad_schema_ready($pdo),'El helper debe reconocer las autoridades F7.1/F7.2 y las fuentes operativas existentes.');
    f74_expect(hache_profesor_actividad_duracion_minutos('07:00:00','08:00:00')===60,'La carga debe derivar minutos desde el horario.');
    f74_expect(hache_profesor_actividad_duracion_minutos('08:00:00','08:45:00')===45,'La duración parcial debe conservarse.');

    $before=[
        'vigencias'=>(int)$pdo->query('SELECT COUNT(*) FROM profesor_horario_vigencias')->fetchColumn(),
        'sustituciones'=>(int)$pdo->query('SELECT COUNT(*) FROM profesor_sustituciones')->fetchColumn(),
        'cancelaciones'=>(int)$pdo->query('SELECT COUNT(*) FROM profesor_cancelaciones')->fetchColumn(),
        'sesiones'=>(int)$pdo->query('SELECT COUNT(*) FROM sesiones')->fetchColumn(),
    ];
    $ctx=hache_profesor_actividad_contexto($pdo,'2099-01-10','2099-01-15');
    $after=[
        'vigencias'=>(int)$pdo->query('SELECT COUNT(*) FROM profesor_horario_vigencias')->fetchColumn(),
        'sustituciones'=>(int)$pdo->query('SELECT COUNT(*) FROM profesor_sustituciones')->fetchColumn(),
        'cancelaciones'=>(int)$pdo->query('SELECT COUNT(*) FROM profesor_cancelaciones')->fetchColumn(),
        'sesiones'=>(int)$pdo->query('SELECT COUNT(*) FROM sesiones')->fetchColumn(),
    ];
    f74_expect($before===$after,'La lectura F7.4 no debe escribir ni reconstruir historia.');
    f74_expect(($ctx['cobertura']['historia_previa_reconstruida']??true)===false,'Debe declarar explícitamente que no reconstruye historia previa.');
    f74_expect(($ctx['cobertura']['asignaciones_desde_utc']??'')==='2099-01-01 00:00:00','Debe publicar la cobertura de asignaciones.');
    f74_expect(($ctx['cobertura']['sustituciones_desde_utc']??'')==='2099-01-01 00:00:00','Debe publicar la cobertura de sustituciones.');
    f74_expect(count($ctx['profesores']??[])===4,'Debe conservar también profesores inactivos.');

    $pa=f74_prof($ctx,$a);
    f74_expect((int)$pa['carga_prevista']['sesiones']===5&&(int)$pa['carga_prevista']['minutos']===300,'Profe A debe tener cinco sesiones previstas por vigencia durable.');
    f74_expect((int)$pa['carga_realizada']['sesiones_confirmadas']===0,'Una sesión REALIZADA y asignada no debe inventar que el profesor la impartió.');
    f74_expect((int)$pa['carga_realizada']['sesiones_realizadas_sin_atribucion']===2,'Las sesiones realizadas sin evidencia positiva deben quedar sin atribución.');
    f74_expect((int)$pa['carga_realizada']['sesiones_confirmadas_no_impartidas']===2,'Sustitución explícita y sesión cancelada deben acreditar no impartición.');
    f74_expect((int)$pa['sustituciones_activas']['como_original']===2,'Las sustituciones activas deben conservarse aunque una sesión siga programada.');

    $aS2=f74_session($pa,$s2);
    f74_expect($aS2['imparticion_confirmada']===false&&$aS2['fuente_imparticion']==='SUSTITUCION_EXPLICITA+SESION_REALIZADA','El original sustituido no debe acreditarse como quien impartió una sesión realizada.');
    f74_expect($aS2['docencia_compartida']===true,'La sesión debe conservar que existían varios docentes asignados.');

    $pb=f74_prof($ctx,$b);
    f74_expect((int)$pb['carga_prevista']['sesiones']===5,'El co-docente debe mantener su propia carga prevista sin colapsarse con Profe A.');
    f74_expect((int)$pb['incidencias']===2,'Las incidencias realizadas y futuras deben aparecer sin confundirse con carga realizada.');
    $bS3=f74_session($pb,$s3);
    f74_expect($bS3['imparticion_confirmada']===false&&$bS3['fuente_imparticion']==='PROFESOR_CANCELACION+SESION_REALIZADA','La incidencia sobre sesión realizada debe acreditar que ese profesor no impartió la clase.');
    f74_expect((string)$bS3['incidencia']['motivo']==='No disponible','La incidencia debe conservar su motivo.');

    $ps=f74_prof($ctx,$sub);
    f74_expect((int)$ps['carga_prevista']['sesiones']===0,'Una sustitución no debe convertirse en asignación regular.');
    f74_expect((int)$ps['carga_realizada']['sesiones_confirmadas']===1&&(int)$ps['carga_realizada']['minutos_confirmados']===60,'La sustitución activa sobre sesión REALIZADA sí debe acreditar carga realizada.');
    f74_expect((int)$ps['sustituciones_activas']['como_sustituto']===2,'Debe conservar también la sustitución activa de una sesión futura sin acreditarla como realizada.');
    $subS1=f74_session($ps,$s1);
    f74_expect($subS1['imparticion_confirmada']===null,'Una sustitución anulada debe conservar historia sin atribuir docencia.');
    f74_expect(count($subS1['sustituciones'])===1&&$subS1['sustituciones'][0]['estado']==='ANULADA','La historia anulada debe seguir visible.');
    $subS2=f74_session($ps,$s2);
    f74_expect($subS2['imparticion_confirmada']===true&&$subS2['fuente_imparticion']==='SUSTITUCION_EXPLICITA+SESION_REALIZADA','La fuente de impartición confirmada debe quedar explícita.');

    $pi=f74_prof($ctx,$inactive);
    f74_expect((int)$pi['activo']===0,'El profesor inactivo debe conservarse en la lectura.');
    f74_expect((int)$pi['carga_prevista']['sesiones']===1&&(int)$pi['carga_prevista']['minutos']===45,'La inactivación posterior no debe borrar una asignación histórica cubierta por vigencia.');
    f74_expect((int)$pi['carga_realizada']['sesiones_realizadas_sin_atribucion']===1,'Tampoco debe inventarse que el profesor inactivo impartió su sesión histórica.');

    $aS6=f74_session($pa,$s6);
    f74_expect($aS6['imparticion_confirmada']===null&&str_contains((string)$aS6['nota_atribucion'],'aún no acredita'),'Una sustitución programada no debe inflar carga realizada antes de cerrar la sesión.');
    $bS6=f74_session($pb,$s6);
    f74_expect($bS6['imparticion_confirmada']===null&&str_contains((string)$bS6['nota_atribucion'],'aún no acredita'),'Una incidencia futura no debe convertirse en no impartición realizada antes de tiempo.');
    $subS6=f74_session($ps,$s6);
    f74_expect($subS6['imparticion_confirmada']===null,'El sustituto programado solo se acredita cuando la sesión queda REALIZADA.');

    $only=hache_profesor_actividad_contexto($pdo,'2099-01-10','2099-01-15',$sub);
    f74_expect(count($only['profesores']??[])===1&&(string)$only['profesores'][0]['id']===$sub,'El filtro por profesor debe limitar la lectura sin alterar la autoridad.');

    $api=(string)file_get_contents(dirname(__DIR__).'/api/profesor-actividad.php');
    f74_expect(str_contains($api,"auth_require(['ADMIN'])"),'La lectura integrada debe permanecer ADMIN-only.');
    f74_expect(str_contains($api,"new DateTimeZone('America/Cancun')"),'El periodo por defecto debe usar la fecha operativa de Cancún.');
    f74_expect(str_contains($api,"\$days>62"),'La API debe limitar rangos demasiado amplios.');
    f74_expect(!preg_match('/\b(?:INSERT|UPDATE|DELETE)\b/i',$api),'La API F7.4 debe ser de solo lectura.');

    echo "F7_PROFESSOR_ACTIVITY_MARIADB_OK\n";
}finally{
    $server->exec("DROP DATABASE IF EXISTS \`{$db}\`");
}
