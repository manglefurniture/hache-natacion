<?php

declare(strict_types=1);

function member_db_expect(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
}

$host=(string)(getenv('DELIVERY_DB_HOST')?:'127.0.0.1');
$port=(int)(getenv('DELIVERY_DB_PORT')?:3306);
$db=(string)(getenv('DELIVERY_DB_NAME')?:'hache_delivery_test');
$user=(string)(getenv('DELIVERY_DB_USER')?:'root');
$pass=(string)(getenv('DELIVERY_DB_PASS')?:'root');
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach([
    'sharky_member_payment_intents','sharky_ausencia_evidencias','profesor_cancelaciones','profesor_horarios','profesores',
    'avisos_ausencia','sesiones','mensualidades','cursos_intensivos','horarios','alumnos','usuarios'
] as $table)$pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$collation='ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
$pdo->exec("CREATE TABLE usuarios (id CHAR(36) NOT NULL PRIMARY KEY) {$collation}");
$pdo->exec("CREATE TABLE alumnos (id CHAR(36) NOT NULL PRIMARY KEY) {$collation}");
$pdo->exec("CREATE TABLE horarios (id CHAR(36) NOT NULL PRIMARY KEY) {$collation}");
$pdo->exec("CREATE TABLE sesiones (id CHAR(36) NOT NULL PRIMARY KEY) {$collation}");
$pdo->exec("CREATE TABLE avisos_ausencia (id CHAR(36) NOT NULL PRIMARY KEY) {$collation}");
$pdo->exec("CREATE TABLE mensualidades (id CHAR(36) NOT NULL PRIMARY KEY) {$collation}");
$pdo->exec("CREATE TABLE cursos_intensivos (id CHAR(36) NOT NULL PRIMARY KEY) {$collation}");

$apply=static function(PDO $pdo,string $file):void{
    $sql=(string)file_get_contents($file);
    $sql=preg_replace('/^\s*--.*$/m','',$sql);
    if(!is_string($sql))throw new RuntimeException('Unable to normalize '.basename($file));
    $statements=array_values(array_filter(array_map('trim',explode(';',$sql)),static fn(string $statement):bool=>$statement!==''));
    foreach($statements as $statement)$pdo->exec($statement);
};
$root=dirname(__DIR__);
$apply($pdo,$root.'/database/migrations/20260907_sharky_member_ops.sql');
$apply($pdo,$root.'/database/migrations/20260907_sharky_member_payments.sql');
$apply($pdo,$root.'/database/migrations/20260909_professor_coteaching.sql');

$required=['profesores','profesor_horarios','profesor_cancelaciones','sharky_ausencia_evidencias','sharky_member_payment_intents'];
$marks=implode(',',array_fill(0,count($required),'?'));
$st=$pdo->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ($marks)");
$st->execute($required);
member_db_expect(count($st->fetchAll(PDO::FETCH_COLUMN))===count($required),'All member-ops tables must be created on MariaDB 11.8.');

$admin='00000000-0000-0000-0000-000000000001';
$student='00000000-0000-0000-0000-000000000002';
$schedule='00000000-0000-0000-0000-000000000003';
$session='00000000-0000-0000-0000-000000000004';
$absence='00000000-0000-0000-0000-000000000005';
$monthly='00000000-0000-0000-0000-000000000006';
$course='00000000-0000-0000-0000-000000000007';
foreach([['usuarios',$admin],['alumnos',$student],['horarios',$schedule],['sesiones',$session],['avisos_ausencia',$absence],['mensualidades',$monthly],['cursos_intensivos',$course]] as [$table,$id]){
    $pdo->prepare('INSERT INTO `'.$table.'`(id) VALUES(:id)')->execute([':id'=>$id]);
}

$teacher='00000000-0000-0000-0000-000000000010';
$pdo->prepare("INSERT INTO profesores(id,nombre,whatsapp,activo,created_by) VALUES(:id,'Profe Uno','+529981112233',1,:u)")->execute([':id'=>$teacher,':u'=>$admin]);
$duplicatePhoneBlocked=false;
try{$pdo->prepare("INSERT INTO profesores(id,nombre,whatsapp,activo) VALUES(UUID(),'Profe Dos','+529981112233',1)")->execute();}catch(PDOException $e){$duplicatePhoneBlocked=true;}
member_db_expect($duplicatePhoneBlocked,'Professor WhatsApp must remain unique.');

$pdo->prepare("INSERT INTO profesor_horarios(id,profesor_id,horario_id,activo,created_by) VALUES(UUID(),:p,:h,1,:u)")->execute([':p'=>$teacher,':h'=>$schedule,':u'=>$admin]);
$duplicateAssignmentBlocked=false;
try{$pdo->prepare("INSERT INTO profesor_horarios(id,profesor_id,horario_id,activo) VALUES(UUID(),:p,:h,1)")->execute([':p'=>$teacher,':h'=>$schedule]);}catch(PDOException $e){$duplicateAssignmentBlocked=true;}
member_db_expect($duplicateAssignmentBlocked,'Professor/schedule assignment must be unique per pair.');
$teacher2='00000000-0000-0000-0000-000000000011';
$pdo->prepare("INSERT INTO profesores(id,nombre,whatsapp,activo,created_by) VALUES(:id,'Profe Dos','+529981112244',1,:u)")->execute([':id'=>$teacher2,':u'=>$admin]);
$pdo->prepare("INSERT INTO profesor_horarios(id,profesor_id,horario_id,activo,created_by) VALUES(UUID(),:p,:h,1,:u)")->execute([':p'=>$teacher2,':h'=>$schedule,':u'=>$admin]);
member_db_expect((int)$pdo->query("SELECT COUNT(*) FROM profesor_horarios WHERE horario_id='{$schedule}' AND activo=1")->fetchColumn()===2,'One schedule must allow multiple active professors.');

$pdo->prepare("INSERT INTO profesor_cancelaciones(id,profesor_id,sesion_id,motivo,source,action_key) VALUES(UUID(),:p,:s,'Prueba','SHARKY',:a)")->execute([':p'=>$teacher,':s'=>$session,':a'=>str_repeat('a',64)]);
$pdo->prepare("INSERT INTO profesor_cancelaciones(id,profesor_id,sesion_id,motivo,source,action_key) VALUES(UUID(),:p,:s,'Cobertura compartida','SHARKY',:a)")->execute([':p'=>$teacher2,':s'=>$session,':a'=>str_repeat('b',64)]);
member_db_expect((int)$pdo->query("SELECT COUNT(*) FROM profesor_cancelaciones WHERE sesion_id='{$session}'")->fetchColumn()===2,'Co-teachers must be able to declare unavailability independently for the same session.');
$duplicateCancellationBlocked=false;
try{$pdo->prepare("INSERT INTO profesor_cancelaciones(id,profesor_id,sesion_id,motivo) VALUES(UUID(),:p,:s,'Otra')")->execute([':p'=>$teacher,':s'=>$session]);}catch(PDOException $e){$duplicateCancellationBlocked=true;}
member_db_expect($duplicateCancellationBlocked,'The same professor/session unavailability must remain unique.');

$pdo->prepare("INSERT INTO sharky_ausencia_evidencias(id,ausencia_id,alumno_id,source_message_id,media_id,media_type) VALUES(UUID(),:aus,:a,'wamid.member.1','media-1','image')")->execute([':aus'=>$absence,':a'=>$student]);
$duplicateEvidenceBlocked=false;
try{$pdo->prepare("INSERT INTO sharky_ausencia_evidencias(id,ausencia_id,alumno_id,source_message_id,media_id,media_type) VALUES(UUID(),:aus,:a,'wamid.member.1','media-2','document')")->execute([':aus'=>$absence,':a'=>$student]);}catch(PDOException $e){$duplicateEvidenceBlocked=true;}
member_db_expect($duplicateEvidenceBlocked,'A Meta message must bind to at most one absence evidence row.');

$external='sharky:member:m:'.str_repeat('b',32);
$pdo->prepare("INSERT INTO sharky_member_payment_intents(id,external_reference,alumno_id,payment_kind,mensualidad_id,base_amount,charged_amount,preference_id) VALUES(UUID(),:e,:a,'MENSUALIDAD',:m,1000,1050,'pref-1')")->execute([':e'=>$external,':a'=>$student,':m'=>$monthly]);
$duplicateExternalBlocked=false;
try{$pdo->prepare("INSERT INTO sharky_member_payment_intents(id,external_reference,alumno_id,payment_kind,mensualidad_id,base_amount,charged_amount,preference_id) VALUES(UUID(),:e,:a,'MENSUALIDAD',:m,1000,1050,'pref-2')")->execute([':e'=>$external,':a'=>$student,':m'=>$monthly]);}catch(PDOException $e){$duplicateExternalBlocked=true;}
member_db_expect($duplicateExternalBlocked,'Payment external reference must be unique.');

$invalidTargetBlocked=false;
try{$pdo->prepare("INSERT INTO sharky_member_payment_intents(id,external_reference,alumno_id,payment_kind,mensualidad_id,intensivo_id,base_amount,charged_amount,preference_id) VALUES(UUID(),'sharky:bad',:a,'MENSUALIDAD',:m,:i,1,1,'pref-bad')")->execute([':a'=>$student,':m'=>$monthly,':i'=>$course]);}catch(PDOException $e){$invalidTargetBlocked=true;}
member_db_expect($invalidTargetBlocked,'Payment kind must point to exactly one matching billing target.');

echo "SHARKY_MEMBER_OPS_MARIADB_OK\n";
