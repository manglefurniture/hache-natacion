<?php

declare(strict_types=1);
require_once __DIR__.'/../config/intensive-partial-payment-triggers.php';
function part_ok(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}
$host=getenv('DELIVERY_DB_HOST')?:'127.0.0.1';$port=(int)(getenv('DELIVERY_DB_PORT')?:3306);$user=getenv('DELIVERY_DB_USER')?:'root';$pass=getenv('DELIVERY_DB_PASS')?:'root';
$admin=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$db='hache_partial_'.getmypid();$admin->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try{$pdo=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec("CREATE TABLE cursos_intensivos(id CHAR(36) PRIMARY KEY,precio DECIMAL(10,2) NOT NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE pagos(id CHAR(36) PRIMARY KEY,alumno_id CHAR(36) NOT NULL,inscripcion_id CHAR(36) NULL,mensualidad_id CHAR(36) NULL,intensivo_id CHAR(36) NULL,tipo ENUM('INSCRIPCION','MENSUALIDAD','INTENSIVO') NOT NULL,importe DECIMAL(10,2) NOT NULL,estado ENUM('VALIDO','INVALIDO') NOT NULL DEFAULT 'VALIDO') ENGINE=InnoDB");
$pdo->prepare("INSERT INTO cursos_intensivos(id,precio) VALUES('course-1',1200.00)")->execute();hache_intensive_partial_payment_apply($pdo);part_ok(hache_intensive_partial_payment_triggers_ready($pdo),'Los triggers nuevos deben quedar verificables');
$insert=$pdo->prepare("INSERT INTO pagos(id,alumno_id,intensivo_id,tipo,importe,estado) VALUES(:id,'student-1','course-1','INTENSIVO',:importe,'VALIDO')");$insert->execute([':id'=>'p1',':importe'=>'600.00']);$insert->execute([':id'=>'p2',':importe'=>'600.00']);$sum=(float)$pdo->query("SELECT SUM(importe) FROM pagos WHERE alumno_id='student-1' AND intensivo_id='course-1' AND estado='VALIDO'")->fetchColumn();part_ok(abs($sum-1200.0)<0.009,'Dos abonos de 600 deben liquidar un curso de 1200');
$blocked=false;try{$insert->execute([':id'=>'p3',':importe'=>'1.00']);}catch(PDOException $e){$blocked=str_contains($e->getMessage(),'El abono supera el saldo pendiente del intensivo');}part_ok($blocked,'Un tercer abono que exceda el precio debe bloquearse');
$pdo->exec("INSERT INTO pagos(id,alumno_id,mensualidad_id,tipo,importe,estado) VALUES('m1','student-1','monthly-1','MENSUALIDAD',1000,'VALIDO')");$monthlyBlocked=false;try{$pdo->exec("INSERT INTO pagos(id,alumno_id,mensualidad_id,tipo,importe,estado) VALUES('m2','student-1','monthly-1','MENSUALIDAD',1,'VALIDO')");}catch(PDOException $e){$monthlyBlocked=true;}part_ok($monthlyBlocked,'La unicidad de mensualidad debe permanecer intacta');
$updateBlocked=false;try{$pdo->exec("UPDATE pagos SET importe=700 WHERE id='p2'");}catch(PDOException $e){$updateBlocked=str_contains($e->getMessage(),'El abono supera el saldo pendiente del intensivo');}part_ok($updateBlocked,'Editar un abono tampoco puede exceder el precio del curso');
$sql=file_get_contents(__DIR__.'/../database/migrations/20260909_intensive_partial_payments.sql')?:'';part_ok(str_contains($sql,'SUM(p.importe)')&&str_contains($sql,'El abono supera el saldo pendiente del intensivo'),'La migración SQL debe documentar la misma protección');fwrite(STDOUT,"INTENSIVE_PARTIAL_PAYMENTS_MARIADB_OK\n");
}finally{$admin->exec("DROP DATABASE IF EXISTS `{$db}`");}
