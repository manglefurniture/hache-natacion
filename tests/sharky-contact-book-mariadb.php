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

$host=(string)(getenv('DELIVERY_DB_HOST')?:'127.0.0.1');
$port=(int)(getenv('DELIVERY_DB_PORT')?:3306);
$db=(string)(getenv('DELIVERY_DB_NAME')?:'hache_delivery_test');
$user=(string)(getenv('DELIVERY_DB_USER')?:'root');
$pass=(string)(getenv('DELIVERY_DB_PASS')?:'root');
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

$pdo->exec('DROP TABLE IF EXISTS sharky_contacts');
$pdo->exec('DROP TABLE IF EXISTS alumnos');
$pdo->exec('DROP TABLE IF EXISTS profesores');
$pdo->exec("CREATE TABLE alumnos(id CHAR(36) PRIMARY KEY,nombre VARCHAR(180) NOT NULL,whatsapp VARCHAR(32) NOT NULL)");
$pdo->exec("CREATE TABLE profesores(id CHAR(36) PRIMARY KEY,nombre VARCHAR(180) NOT NULL,whatsapp VARCHAR(32) NOT NULL,activo TINYINT(1) NOT NULL DEFAULT 1)");
$sql=file_get_contents(__DIR__.'/../database/migrations/20260908_sharky_contact_book.sql');
contact_book_db_expect(is_string($sql)&&trim($sql)!=='','Migration SQL must be readable.');
$pdo->exec($sql);
contact_book_db_expect(hache_sharky_contact_book_schema_ready($pdo),'Migration must create the verified contact-book schema.');

$profilePayload=['entry'=>[['changes'=>[['value'=>[
    'contacts'=>[['wa_id'=>'529981111222','profile'=>['name'=>'María de la Cruz']]],
    'messages'=>[['id'=>'wamid.profile','from'=>'529981111222','type'=>'text','text'=>['body'=>'Hola']]],
]]]]]];
contact_book_db_expect(hache_sharky_contact_book_capture_profiles_payload($pdo,$profilePayload)===1,'Signed WhatsApp contacts profile must be captured once.');
$profileHash=hache_sharky_orchestrator_contact_hash('529981111222');
$st=$pdo->prepare('SELECT * FROM sharky_contacts WHERE contact_hash=:c');$st->execute([':c'=>$profileHash]);$profileRow=$st->fetch();
contact_book_db_expect(is_array($profileRow)&&$profileRow['role']==='PROSPECT','WhatsApp profile must seed a prospect contact.');
$profileContact=hache_sharky_contact_book_decrypt($profileRow);
contact_book_db_expect(($profileContact['managed_name']??'')==='María de la Cruz — Prospecto Hache','WhatsApp profile name must be used before enrollment asks for a legal/full name.');

$event=['id'=>'wamid.prospect','from'=>'529981234567','kind'=>'commerce_flow','commerce'=>['full_name'=>'Juan Pérez']];
contact_book_db_expect(hache_sharky_contact_book_capture_event($pdo,$event),'Prospect event must be captured.');
$hash=hache_sharky_orchestrator_contact_hash('529981234567');
$st=$pdo->prepare('SELECT * FROM sharky_contacts WHERE contact_hash=:c');$st->execute([':c'=>$hash]);$row=$st->fetch();
contact_book_db_expect(is_array($row)&&$row['role']==='PROSPECT'&&$row['sync_status']==='PENDING','Fresh direct contact must be a pending prospect.');
$payload=hache_sharky_contact_book_decrypt($row);
contact_book_db_expect(($payload['managed_name']??'')==='Juan Pérez — Prospecto Hache','Encrypted prospect payload must keep the collected name.');
contact_book_db_expect(($payload['e164']??'')==='+529981234567','Encrypted prospect payload must keep E.164 only inside ciphertext.');

$studentId='11111111-1111-1111-1111-111111111111';
$st=$pdo->prepare('INSERT INTO alumnos(id,nombre,whatsapp) VALUES(:i,:n,:w)');$st->execute([':i'=>$studentId,':n'=>'Juan Pérez Gómez',':w'=>'+529981234567']);
$event2=['id'=>'wamid.student','from'=>'529981234567','kind'=>'message','text'=>'hola'];
contact_book_db_expect(hache_sharky_contact_book_capture_event($pdo,$event2),'Student reconciliation must update the same contact.');
$st=$pdo->prepare('SELECT * FROM sharky_contacts WHERE contact_hash=:c');$st->execute([':c'=>$hash]);$studentRow=$st->fetch();
contact_book_db_expect(is_array($studentRow)&&$studentRow['role']==='STUDENT'&&$studentRow['alumno_id']===$studentId,'Same phone must promote prospect to authoritative student.');
$studentPayload=hache_sharky_contact_book_decrypt($studentRow);
contact_book_db_expect(($studentPayload['managed_name']??'')==='Juan Pérez Gómez — Alumno Hache','Student authority must replace the prospect label/name.');
contact_book_db_expect($studentRow['sync_status']==='PENDING','Identity promotion must re-arm Google synchronization.');

$teacherId='22222222-2222-2222-2222-222222222222';
$st=$pdo->prepare('INSERT INTO profesores(id,nombre,whatsapp,activo) VALUES(:i,:n,:w,1)');$st->execute([':i'=>$teacherId,':n'=>'Heidy Coach',':w'=>'+529981234567']);
contact_book_db_expect(hache_sharky_contact_book_capture_event($pdo,['id'=>'wamid.teacher','from'=>'529981234567','kind'=>'message','text'=>'agenda']),'Teacher reconciliation must succeed.');
$st=$pdo->prepare('SELECT * FROM sharky_contacts WHERE contact_hash=:c');$st->execute([':c'=>$hash]);$teacherRow=$st->fetch();
contact_book_db_expect(is_array($teacherRow)&&$teacherRow['role']==='TEACHER'&&$teacherRow['profesor_id']===$teacherId,'Active teacher identity must take precedence over student contact labeling.');
$teacherPayload=hache_sharky_contact_book_decrypt($teacherRow);
contact_book_db_expect(($teacherPayload['managed_name']??'')==='Heidy Coach — Coach Hache','Teacher contact must use the coach label.');

fwrite(STDOUT,"SHARKY_CONTACT_BOOK_MARIADB_OK\n");
