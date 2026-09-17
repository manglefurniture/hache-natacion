<?php
declare(strict_types=1);

require_once __DIR__.'/../config/intensive-balance-source.php';

function balance_alert_expect(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

balance_alert_expect(in_array('sqlite',PDO::getAvailableDrivers(),true),'La regresión requiere PDO SQLite.');
$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE alumnos (id TEXT PRIMARY KEY,nombre TEXT NOT NULL)');
$pdo->exec('CREATE TABLE cursos_intensivos (id TEXT PRIMARY KEY,sede_id TEXT NOT NULL,fecha_inicio TEXT NOT NULL,fecha_fin TEXT NOT NULL,precio REAL NOT NULL,estado TEXT NOT NULL)');
$pdo->exec('CREATE TABLE curso_intensivo_alumnos (id TEXT PRIMARY KEY,curso_intensivo_id TEXT NOT NULL,alumno_id TEXT NOT NULL)');
$pdo->exec('CREATE TABLE pagos (id TEXT PRIMARY KEY,intensivo_id TEXT,alumno_id TEXT,tipo TEXT NOT NULL,importe REAL NOT NULL,estado TEXT NOT NULL)');
foreach([['a1','Ana'],['a2','Beto'],['a3','Caro'],['a4','Dani']] as$row){$st=$pdo->prepare('INSERT INTO alumnos(id,nombre) VALUES(?,?)');$st->execute($row);}
foreach([
 ['c1','s1','2026-09-01','2026-09-21',1200,'EN_CURSO'],
 ['c2','s1','2026-08-01','2026-08-21',1200,'TERMINADO'],
 ['c3','s2','2026-09-01','2026-09-21',1200,'EN_CURSO'],
 ['c4','s1','2026-09-01','2026-09-21',1200,'CANCELADO'],
 ['c5','s1','2026-10-01','2026-10-21',1200,'PROGRAMADO'],
] as$row){$st=$pdo->prepare('INSERT INTO cursos_intensivos(id,sede_id,fecha_inicio,fecha_fin,precio,estado) VALUES(?,?,?,?,?,?)');$st->execute($row);}
foreach([['r1','c1','a1'],['r2','c2','a2'],['r3','c3','a3'],['r4','c4','a4'],['r5','c5','a2']] as$row){$st=$pdo->prepare('INSERT INTO curso_intensivo_alumnos(id,curso_intensivo_id,alumno_id) VALUES(?,?,?)');$st->execute($row);}
foreach([
 ['p1','c1','a1','INTENSIVO',500,'VALIDO'],
 ['p2','c1','a1','INTENSIVO',300,'INVALIDO'],
 ['p3','c2','a2','INTENSIVO',1200,'VALIDO'],
 ['p4','c3','a3','INTENSIVO',100,'VALIDO'],
 ['p5','c4','a4','INTENSIVO',100,'VALIDO'],
] as$row){$st=$pdo->prepare('INSERT INTO pagos(id,intensivo_id,alumno_id,tipo,importe,estado) VALUES(?,?,?,?,?,?)');$st->execute($row);}

$rows=hache_intensive_pending_balance_candidates($pdo,'s1');
balance_alert_expect(array_column($rows,'curso_id')===['c1','c5'],'Solo cursos válidos de la sede con saldo deben aparecer.');
balance_alert_expect(abs((float)$rows[0]['importe_pagado']-500)<0.001&&abs((float)$rows[0]['saldo']-700)<0.001,'Pagos inválidos no deben reducir el saldo.');
balance_alert_expect(abs((float)$rows[1]['saldo']-1200)<0.001,'Un intensivo sin pagos válidos conserva el total como saldo.');
$summary=hache_intensive_pending_balance_summary($rows);
balance_alert_expect($summary['total']===2&&$summary['alumnos']===2&&abs((float)$summary['saldo']-1900)<0.001,'El resumen debe reconciliar relaciones, alumnos únicos y saldo.');
$duplicateStudent=hache_intensive_pending_balance_summary([...$rows,['alumno_id'=>'a1','saldo'=>50]]);
balance_alert_expect($duplicateStudent['total']===3&&$duplicateStudent['alumnos']===2&&abs((float)$duplicateStudent['saldo']-1950)<0.001,'Un alumno con dos cursos pendientes debe contarse una vez como alumno y dos veces como saldo.');
$one=hache_intensive_pending_balance_candidates($pdo,'s1','c1','a1');
balance_alert_expect(count($one)===1&&$one[0]['curso_id']==='c1','La misma fuente debe permitir revalidar un curso/alumno específico.');
balance_alert_expect(hache_intensive_pending_balance_candidates($pdo,'s1','c2','a2')===[],'Un saldo liquidado debe dejar de ser candidato.');

$center=file_get_contents(__DIR__.'/../config/centro-pendientes.php')?:'';
$alerts=file_get_contents(__DIR__.'/../api/alertas.php')?:'';
balance_alert_expect(str_contains($center,"require_once __DIR__.'/intensive-balance-source.php'")&&substr_count($center,'hache_intensive_pending_balance_candidates(')>=2,'F1 debe usar la fuente compartida para listar y revalidar saldo.');
balance_alert_expect(!str_contains($center,"COALESCE(SUM(CASE WHEN p.estado='VALIDO' THEN p.importe ELSE 0 END),0) pagado_valido"),'El cálculo financiero no debe quedar duplicado dentro del Centro.');
balance_alert_expect(str_contains($alerts,"require_once __DIR__.'/../config/intensive-balance-source.php'")&&str_contains($alerts,"'tipo'=>'SALDO','nivel'=>'NEUTRA'"),'F5 debe consumir la misma fuente y mantener prioridad nueva neutra.');
balance_alert_expect(str_contains($alerts,'hache_intensive_pending_balance_summary(hache_intensive_pending_balance_candidates($pdo,(string)$sid))'),'El resumen F5 debe derivar exactamente de candidatos financieros compartidos.');
balance_alert_expect(str_contains($alerts,"$students=(int)$saldoResumen['alumnos']")&&str_contains($alerts,"$balances=(int)$saldoResumen['total']"),'La presentación debe distinguir alumnos únicos de relaciones con saldo.');

echo "INTENSIVE_BALANCE_ALERT_REGRESSION_OK\n";
