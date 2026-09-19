<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/config/resumen-diario.php';

function f9m_expect(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
}

$host=(string)(getenv('DELIVERY_DB_HOST')?:'127.0.0.1');
$port=(string)(getenv('DELIVERY_DB_PORT')?:'3306');
$user=(string)(getenv('DELIVERY_DB_USER')?:'root');
$pass=(string)(getenv('DELIVERY_DB_PASS')?:'root');
$db='hache_f9_resumen_diario_test';

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

    $pdo->exec("CREATE TABLE horarios(
        id VARCHAR(36) PRIMARY KEY,
        sede_id VARCHAR(36) NOT NULL,
        activo TINYINT NOT NULL DEFAULT 1,
        hora_inicio TIME NOT NULL,
        hora_fin TIME NOT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE alumnos(
        id VARCHAR(36) PRIMARY KEY,
        nombre VARCHAR(120) NOT NULL,
        sede_id VARCHAR(36) NOT NULL,
        horario_preferido_id VARCHAR(36) NULL,
        estado_administrativo VARCHAR(20) NOT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE cursos_intensivos(
        id VARCHAR(36) PRIMARY KEY,
        sede_id VARCHAR(36) NOT NULL,
        estado VARCHAR(20) NOT NULL,
        fecha_inicio DATE NOT NULL,
        fecha_fin DATE NOT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE curso_intensivo_alumnos(
        alumno_id VARCHAR(36) NOT NULL,
        curso_intensivo_id VARCHAR(36) NOT NULL,
        horario_id VARCHAR(36) NOT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE pagos(
        id VARCHAR(36) PRIMARY KEY,
        folio INT NOT NULL,
        alumno_id VARCHAR(36) NOT NULL,
        tipo VARCHAR(20) NOT NULL,
        importe DECIMAL(10,2) NOT NULL,
        metodo VARCHAR(30) NOT NULL,
        fecha DATETIME NOT NULL,
        estado VARCHAR(20) NOT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE sesiones(
        id VARCHAR(36) PRIMARY KEY,
        fecha DATE NOT NULL,
        horario_id VARCHAR(36) NOT NULL,
        estado VARCHAR(20) NOT NULL,
        cerrada TINYINT NOT NULL DEFAULT 0,
        motivo_cancelacion VARCHAR(255) NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE profesores(
        id VARCHAR(36) PRIMARY KEY,
        nombre VARCHAR(120) NOT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE profesor_cancelaciones(
        id VARCHAR(36) PRIMARY KEY,
        profesor_id VARCHAR(36) NOT NULL,
        sesion_id VARCHAR(36) NOT NULL,
        motivo VARCHAR(255) NOT NULL,
        source VARCHAR(40) NOT NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE profesor_sustituciones(
        id VARCHAR(36) PRIMARY KEY,
        sesion_id VARCHAR(36) NOT NULL,
        profesor_original_id VARCHAR(36) NOT NULL,
        profesor_sustituto_id VARCHAR(36) NOT NULL,
        motivo VARCHAR(255) NOT NULL,
        origen VARCHAR(40) NOT NULL,
        estado VARCHAR(20) NOT NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE configuracion(
        clave VARCHAR(100) PRIMARY KEY,
        valor VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB");

    $site='site-1';
    $pdo->exec("INSERT INTO horarios(id,sede_id,activo,hora_inicio,hora_fin) VALUES
        ('h1','{$site}',1,'08:00:00','09:00:00'),
        ('h2','{$site}',1,'09:00:00','10:00:00')");
    $pdo->exec("INSERT INTO alumnos(id,nombre,sede_id,horario_preferido_id,estado_administrativo) VALUES
        ('a1','Alumno Regular','{$site}','h1','ACTIVO'),
        ('a2','Alumno Intensivo','{$site}',NULL,'ACTIVO')");
    $pdo->exec("INSERT INTO cursos_intensivos(id,sede_id,estado,fecha_inicio,fecha_fin)
        VALUES('c1','{$site}','EN_CURSO','2026-09-14','2026-10-02')");
    $pdo->exec("INSERT INTO curso_intensivo_alumnos(alumno_id,curso_intensivo_id,horario_id)
        VALUES('a2','c1','h2')");

    $planned=hache_resumen_diario_clases_previstas($pdo,$site,'2026-09-21','2026-09-21');
    f9m_expect(($planned['disponible']??false)===true,'La planificación actual debe estar disponible.');
    f9m_expect((int)($planned['total']??-1)===2,'Deben aparecer regular e intensivo en horarios distintos.');
    f9m_expect(count($planned['rows']??[])===2,'El detalle debe reconciliar el total de clases previstas.');

    $weekend=hache_resumen_diario_clases_previstas($pdo,$site,'2026-09-20','2026-09-20');
    f9m_expect(($weekend['disponible']??false)===true&&(int)($weekend['total']??-1)===0,'Fin de semana debe devolver cero clases previstas sin inventar sesiones.');

    $past=hache_resumen_diario_clases_previstas($pdo,$site,'2026-09-19','2026-09-21');
    f9m_expect(($past['disponible']??true)===false&&($past['total']??'x')===null,'Sin snapshot no se debe reconstruir planificación histórica.');

    $pdo->exec("INSERT INTO pagos(id,folio,alumno_id,tipo,importe,metodo,fecha,estado) VALUES
        ('p1',101,'a1','MENSUALIDAD',1000.00,'TRANSFERENCIA','2026-09-21 08:15:00','VALIDO'),
        ('p2',102,'a1','MENSUALIDAD',500.00,'EFECTIVO','2026-09-21 08:30:00','INVALIDADO'),
        ('p3',103,'a1','MENSUALIDAD',300.00,'EFECTIVO','2026-09-22 08:30:00','VALIDO')");
    $cobros=hache_resumen_diario_cobros($pdo,$site,'2026-09-21');
    f9m_expect((int)$cobros['pagos']===2,'Cobros debe filtrar por fecha efectiva del día.');
    f9m_expect((int)$cobros['validos']===1&&abs((float)$cobros['total_valido']-1000.0)<0.001,'Solo VALIDO debe sumar ingreso del día.');
    f9m_expect((int)$cobros['invalidados']===1&&abs((float)$cobros['total_invalidado']-500.0)<0.001,'El pago invalidado debe seguir visible sin sumarse como válido.');

    $pdo->exec("INSERT INTO sesiones(id,fecha,horario_id,estado,cerrada,motivo_cancelacion) VALUES
        ('s1','2026-09-21','h1','CANCELADA',1,'Alberca no disponible'),
        ('s2','2026-09-21','h2','PROGRAMADA',0,NULL)");
    $pdo->exec("INSERT INTO profesores(id,nombre) VALUES('t1','Profe Uno'),('t2','Profe Dos')");
    $pdo->exec("INSERT INTO profesor_cancelaciones(id,profesor_id,sesion_id,motivo,source,created_at)
        VALUES('pc1','t1','s2','No disponibilidad','ADMIN','2026-09-21 07:00:00')");
    $pdo->exec("INSERT INTO profesor_sustituciones(id,sesion_id,profesor_original_id,profesor_sustituto_id,motivo,origen,estado,created_at)
        VALUES('ps1','s2','t1','t2','Cobertura','ADMIN','ACTIVA','2026-09-21 07:05:00')");
    $pdo->exec("INSERT INTO configuracion(clave,valor) VALUES
        ('profesores_asignaciones_cobertura_desde','2026-09-18 11:30:36'),
        ('profesores_sustituciones_cobertura_desde','2026-09-18 11:46:33')");

    $before=[
        'horarios'=>(int)$pdo->query('SELECT COUNT(*) FROM horarios')->fetchColumn(),
        'alumnos'=>(int)$pdo->query('SELECT COUNT(*) FROM alumnos')->fetchColumn(),
        'pagos'=>(int)$pdo->query('SELECT COUNT(*) FROM pagos')->fetchColumn(),
        'sesiones'=>(int)$pdo->query('SELECT COUNT(*) FROM sesiones')->fetchColumn(),
        'cancelaciones'=>(int)$pdo->query('SELECT COUNT(*) FROM profesor_cancelaciones')->fetchColumn(),
        'sustituciones'=>(int)$pdo->query('SELECT COUNT(*) FROM profesor_sustituciones')->fetchColumn(),
    ];

    $incidencias=hache_resumen_diario_incidencias($pdo,$site,'2026-09-21');
    f9m_expect(($incidencias['disponible']??false)===true,'Las incidencias deben quedar disponibles con el esquema presente.');
    f9m_expect((int)$incidencias['sesiones_canceladas']['total']===1,'Debe distinguir la sesión cancelada.');
    f9m_expect((int)$incidencias['profesores']['total']===1,'Debe conservar la incidencia individual del profesor.');
    f9m_expect((int)$incidencias['sustituciones_activas']['total']===1,'Debe conservar la sustitución explícita activa.');

    $after=[
        'horarios'=>(int)$pdo->query('SELECT COUNT(*) FROM horarios')->fetchColumn(),
        'alumnos'=>(int)$pdo->query('SELECT COUNT(*) FROM alumnos')->fetchColumn(),
        'pagos'=>(int)$pdo->query('SELECT COUNT(*) FROM pagos')->fetchColumn(),
        'sesiones'=>(int)$pdo->query('SELECT COUNT(*) FROM sesiones')->fetchColumn(),
        'cancelaciones'=>(int)$pdo->query('SELECT COUNT(*) FROM profesor_cancelaciones')->fetchColumn(),
        'sustituciones'=>(int)$pdo->query('SELECT COUNT(*) FROM profesor_sustituciones')->fetchColumn(),
    ];
    f9m_expect($before===$after,'Las lecturas F9.1 no deben mutar las tablas de dominio.');

    fwrite(STDOUT,"F9_RESUMEN_DIARIO_MARIADB_OK\n");
}finally{
    $server->exec("DROP DATABASE IF EXISTS `{$db}`");
}
