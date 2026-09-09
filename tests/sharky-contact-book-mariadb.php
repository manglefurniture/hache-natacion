<?php

declare(strict_types=1);

putenv('SHARKY_STATE_ENCRYPTION_KEY=contact-book-mariadb-state-key-2026-abcdef');
putenv('SHARKY_CONTACT_HASH_KEY=contact-book-mariadb-hash-key-2026-abcdef');
putenv('GOOGLE_CONTACTS_SYNC_ENABLED=0');
require_once __DIR__.'/../config/sharky-contact-book.php';
require_once __DIR__.'/../config/sharky-contact-profiles.php';

function contact_book_db_expect(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"SHARKY CONTACT BOOK DB FAIL: {$message}\n");exit(1);}
}

function contact_book_db_payload(PDO $pdo,string $phone): array
{
    $hash=hache_sharky_orchestrator_contact_hash(preg_replace('/\D+/','',$phone)?:'');
    $st=$pdo->prepare('SELECT * FROM sharky_contacts WHERE contact_hash=:c');$st->execute([':c'=>$hash]);$row=$st->fetch(PDO::FETCH_ASSOC);
    contact_book_db_expect(is_array($row),'Expected contact row for '.$phone);
    $payload=hache_sharky_contact_book_decrypt($row);
    contact_book_db_expect(is_array($payload),'Expected decryptable payload for '.$phone);
    return ['row'=>$row,'payload'=>$payload];
}

$host=(string)(getenv('DELIVERY_DB_HOST')?:'127.0.0.1');
$port=(int)(getenv('DELIVERY_DB_PORT')?:3306);
$user=(string)(getenv('DELIVERY_DB_USER')?:'root');
$pass=(string)(getenv('DELIVERY_DB_PASS')?:'root');
$testDb='hache_contact_book_test';
$admin=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$admin->exec("DROP DATABASE IF EXISTS `{$testDb}`");
$admin->exec("CREATE DATABASE `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$testDb};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

$pdo->exec("CREATE TABLE configuracion(clave VARCHAR(120) PRIMARY KEY,valor VARCHAR(255) NOT NULL)");
$pdo->exec("CREATE TABLE sedes(id VARCHAR(36) PRIMARY KEY,clave VARCHAR(40) NOT NULL,nombre VARCHAR(120) NOT NULL,activo TINYINT(1) NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE horarios(id VARCHAR(36) PRIMARY KEY,sede_id VARCHAR(36) NOT NULL,hora_inicio TIME NOT NULL)");
$pdo->exec("CREATE TABLE alumnos(id CHAR(36) PRIMARY KEY,nombre VARCHAR(180) NOT NULL,whatsapp VARCHAR(32) NOT NULL,sede_id VARCHAR(36) NOT NULL,plan_actual_id VARCHAR(36) NULL,horario_preferido_id VARCHAR(36) NULL)");
$pdo->exec("CREATE TABLE profesores(id CHAR(36) PRIMARY KEY,nombre VARCHAR(180) NOT NULL,whatsapp VARCHAR(32) NOT NULL,activo TINYINT(1) NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE cursos_intensivos(id VARCHAR(36) PRIMARY KEY,sede_id VARCHAR(36) NOT NULL,fecha_inicio DATE NOT NULL,fecha_fin DATE NOT NULL,estado VARCHAR(30) NOT NULL)");
$pdo->exec("CREATE TABLE curso_intensivo_alumnos(id VARCHAR(36) PRIMARY KEY,curso_intensivo_id VARCHAR(36) NOT NULL,alumno_id VARCHAR(36) NOT NULL,horario_id VARCHAR(36) NOT NULL)");
$pdo->exec("INSERT INTO sedes(id,clave,nombre,activo) VALUES('s-mv','MONTEVERDE','Colegio Monteverde',1),('s-pal','PALAPAS','Palapas Protudec',1)");
$pdo->exec("INSERT INTO horarios(id,sede_id,hora_inicio) VALUES('h-mv-8','s-mv','08:00:00'),('h-mv-19','s-mv','19:00:00'),('h-pal-8','s-pal','08:00:00'),('h-pal-20','s-pal','20:00:00')");

$sql=file_get_contents(__DIR__.'/../database/migrations/20260908_sharky_contact_book.sql');
contact_book_db_expect(is_string($sql)&&trim($sql)!=='','Migration SQL must be readable.');
$pdo->exec($sql);
contact_book_db_expect(hache_sharky_contact_book_schema_ready($pdo),'Migration must create the verified contact-book schema.');

$configRows=hache_sharky_contact_naming_config_rows($pdo);
contact_book_db_expect(count($configRows)===2,'Every active venue must expose one contact-sigla setting.');
contact_book_db_expect(($configRows[0]['valor']??'')==='MV'||($configRows[1]['valor']??'')==='MV','Monteverde must default to MV.');
contact_book_db_expect(($configRows[0]['valor']??'')==='PAL'||($configRows[1]['valor']??'')==='PAL','Palapas must default to PAL.');

$profilePayload=['entry'=>[['changes'=>[['value'=>[
    'metadata'=>['phone_number_id'=>'PHONE-HACHE'],
    'contacts'=>[['wa_id'=>'529981111222','profile'=>['name'=>'María López']]],
    'messages'=>[['id'=>'wamid.profile','from'=>'529981111222','type'=>'text','text'=>['body'=>'Hola']]],
]]]]]];
contact_book_db_expect(hache_sharky_contact_book_capture_profiles_payload($pdo,$profilePayload,'PHONE-OTHER')===0,'A profile delivered for another WhatsApp business number must be ignored.');
contact_book_db_expect(hache_sharky_contact_book_capture_profiles_payload($pdo,$profilePayload,'PHONE-HACHE')===1,'Signed WhatsApp contacts profile must be captured once for the configured number.');
$profile=contact_book_db_payload($pdo,'529981111222');
contact_book_db_expect(($profile['row']['role']??'')==='PROSPECT','WhatsApp profile must seed a prospect contact.');
contact_book_db_expect(($profile['payload']['managed_name']??'')==='MARÍA LÓPEZ — PROSPECTO HACHE','Prospect contacts must be uppercase.');

// MV intensive: MV SEP 14 - NAME SURNAME (8 AM)
$mvIntensiveId='11111111-1111-1111-1111-111111111111';
$pdo->prepare('INSERT INTO alumnos(id,nombre,whatsapp,sede_id,plan_actual_id,horario_preferido_id) VALUES(?,?,?,?,?,?)')->execute([$mvIntensiveId,'Juan Pérez Gómez','+529981234567','s-mv',null,'h-mv-19']);
$pdo->exec("INSERT INTO cursos_intensivos(id,sede_id,fecha_inicio,fecha_fin,estado) VALUES('ci-mv','s-mv','2099-09-14','2099-10-02','PROGRAMADO')");
$pdo->prepare('INSERT INTO curso_intensivo_alumnos(id,curso_intensivo_id,alumno_id,horario_id) VALUES(?,?,?,?)')->execute(['cia-mv','ci-mv',$mvIntensiveId,'h-mv-8']);
contact_book_db_expect(hache_sharky_contact_book_capture_event($pdo,['from'=>'529981234567','kind'=>'registration_created','data'=>['full_name'=>'Juan Pérez Gómez']]),'MV intensive student capture must succeed.');
$mvIntensive=contact_book_db_payload($pdo,'529981234567');
contact_book_db_expect(($mvIntensive['payload']['managed_name']??'')==='MV SEP 14 - JUAN PÉREZ (8 AM)','Monteverde intensive contact format is incorrect.');

// PAL intensive: PAL SEP 14 - NAME SURNAME (8 PM)
$palIntensiveId='22222222-2222-2222-2222-222222222222';
$pdo->prepare('INSERT INTO alumnos(id,nombre,whatsapp,sede_id,plan_actual_id,horario_preferido_id) VALUES(?,?,?,?,?,?)')->execute([$palIntensiveId,'María Gómez Díaz','+529981234568','s-pal',null,'h-pal-8']);
$pdo->exec("INSERT INTO cursos_intensivos(id,sede_id,fecha_inicio,fecha_fin,estado) VALUES('ci-pal','s-pal','2099-09-14','2099-10-02','PROGRAMADO')");
$pdo->prepare('INSERT INTO curso_intensivo_alumnos(id,curso_intensivo_id,alumno_id,horario_id) VALUES(?,?,?,?)')->execute(['cia-pal','ci-pal',$palIntensiveId,'h-pal-20']);
contact_book_db_expect(hache_sharky_contact_book_capture_event($pdo,['from'=>'529981234568','kind'=>'message']),'PAL intensive student capture must succeed.');
$palIntensive=contact_book_db_payload($pdo,'529981234568');
contact_book_db_expect(($palIntensive['payload']['managed_name']??'')==='PAL SEP 14 - MARÍA GÓMEZ (8 PM)','Palapas intensive contact format is incorrect.');

// MV regular: MV - NAME SURNAME (7 PM)
$mvRegularId='33333333-3333-3333-3333-333333333333';
$pdo->prepare('INSERT INTO alumnos(id,nombre,whatsapp,sede_id,plan_actual_id,horario_preferido_id) VALUES(?,?,?,?,?,?)')->execute([$mvRegularId,'Ana Ruiz Castro','+529981234569','s-mv','plan-regular','h-mv-19']);
contact_book_db_expect(hache_sharky_contact_book_capture_event($pdo,['from'=>'529981234569','kind'=>'message']),'MV regular student capture must succeed.');
$mvRegular=contact_book_db_payload($pdo,'529981234569');
contact_book_db_expect(($mvRegular['payload']['managed_name']??'')==='MV - ANA RUIZ (7 PM)','Monteverde regular contact format is incorrect.');

// PAL regular: PAL - NAME SURNAME (8 AM)
$palRegularId='44444444-4444-4444-4444-444444444444';
$pdo->prepare('INSERT INTO alumnos(id,nombre,whatsapp,sede_id,plan_actual_id,horario_preferido_id) VALUES(?,?,?,?,?,?)')->execute([$palRegularId,'Luis Mora Torres','+529981234570','s-pal','plan-regular','h-pal-8']);
contact_book_db_expect(hache_sharky_contact_book_capture_event($pdo,['from'=>'529981234570','kind'=>'message']),'PAL regular student capture must succeed.');
$palRegular=contact_book_db_payload($pdo,'529981234570');
contact_book_db_expect(($palRegular['payload']['managed_name']??'')==='PAL - LUIS MORA (8 AM)','Palapas regular contact format is incorrect.');

// Backend override applies without changing naming code.
$pdo->exec("INSERT INTO configuracion(clave,valor) VALUES('sharky_contact_sigla_palapas','PP')");
contact_book_db_expect(hache_sharky_contact_book_capture_event($pdo,['from'=>'529981234570','kind'=>'message']),'PAL sigla override capture must succeed.');
$palOverride=contact_book_db_payload($pdo,'529981234570');
contact_book_db_expect(($palOverride['payload']['managed_name']??'')==='PP - LUIS MORA (8 AM)','Configured venue sigla must override the default.');
contact_book_db_expect(($palOverride['row']['sync_status']??'')==='PENDING','A naming change must re-arm Google synchronization.');

// Teacher identity still wins over a student with the same phone and remains uppercase.
$teacherId='55555555-5555-5555-5555-555555555555';
$pdo->prepare('INSERT INTO profesores(id,nombre,whatsapp,activo) VALUES(?,?,?,1)')->execute([$teacherId,'Heidy Garcia','+529981234567']);
contact_book_db_expect(hache_sharky_contact_book_capture_event($pdo,['from'=>'529981234567','kind'=>'message']),'Teacher reconciliation must succeed.');
$teacher=contact_book_db_payload($pdo,'529981234567');
contact_book_db_expect(($teacher['row']['role']??'')==='TEACHER'&&($teacher['row']['profesor_id']??'')===$teacherId,'Active teacher identity must take precedence.');
contact_book_db_expect(($teacher['payload']['managed_name']??'')==='HEIDY GARCIA — COACH HACHE','Teacher contact must remain uppercase.');

$admin->exec("DROP DATABASE IF EXISTS `{$testDb}`");
fwrite(STDOUT,"SHARKY_CONTACT_BOOK_MARIADB_OK\n");
