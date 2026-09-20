<?php
declare(strict_types=1);

function remember_db_expect(bool $ok,string $message): void
{
    if(!$ok)throw new RuntimeException($message);
}

$host=(string)(getenv('DELIVERY_DB_HOST')?:'127.0.0.1');
$port=(int)(getenv('DELIVERY_DB_PORT')?:3306);
$user=(string)(getenv('DELIVERY_DB_USER')?:'root');
$pass=(string)(getenv('DELIVERY_DB_PASS')?:'root');
$db='hache_remember_'.bin2hex(random_bytes(4));
$admin=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$admin->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try{
    $pdo=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec("CREATE TABLE sedes (id CHAR(36) PRIMARY KEY,clave VARCHAR(40) NOT NULL,nombre VARCHAR(120) NOT NULL,activo TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE usuarios (id CHAR(36) PRIMARY KEY,usuario VARCHAR(100) NOT NULL,password_hash VARCHAR(255) NOT NULL,rol VARCHAR(20) NOT NULL,activo TINYINT(1) NOT NULL DEFAULT 1,alumno_id CHAR(36) NULL,sede_id CHAR(36) NULL,debe_cambiar_password TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $sql=(string)file_get_contents(__DIR__.'/../database/migrations/20260920_auth_remember_tokens.sql');
    foreach(array_filter(array_map('trim',explode(';',preg_replace('/^\s*--.*$/m','',$sql)??''))) as $statement)$pdo->exec($statement);

    require_once __DIR__.'/../config/remember-session.php';
    $_SERVER['HTTPS']='on';
    $id='11111111-1111-4111-8111-111111111111';
    $hash=password_hash('Clave-segura-123',PASSWORD_DEFAULT);
    $st=$pdo->prepare("INSERT INTO usuarios(id,usuario,password_hash,rol,activo,debe_cambiar_password) VALUES(:id,'admin-test',:p,'ADMIN',1,0)");
    $st->execute([':id'=>$id,':p'=>$hash]);
    $authUser=['id'=>$id,'usuario'=>'admin-test','password_hash'=>$hash,'rol'=>'ADMIN','activo'=>1,'alumno_id'=>null,'sede_id'=>null,'debe_cambiar_password'=>0];

    remember_db_expect(hache_remember_issue($pdo,$authUser,86400),'No se pudo emitir el token persistente.');
    $cookie=(string)($_COOKIE[HACHE_REMEMBER_COOKIE]??'');
    $parsed=hache_remember_parse_cookie($cookie);
    remember_db_expect(is_array($parsed),'La cookie emitida no tiene el formato esperado.');
    $stored=$pdo->query("SELECT token_hash,password_fingerprint FROM auth_remember_tokens LIMIT 1")->fetch();
    remember_db_expect(is_array($stored),'El token no quedó persistido.');
    remember_db_expect(hash_equals((string)$stored['token_hash'],hash('sha256',$parsed['validator'])),'La base no conserva el hash correcto del validador.');
    remember_db_expect((string)$stored['token_hash']!==$parsed['validator'],'El validador no puede guardarse en claro.');
    remember_db_expect(hash_equals((string)$stored['password_fingerprint'],hash('sha256',$hash)),'Falta el fingerprint de contraseña.');

    $restored=hache_remember_restore($pdo);
    remember_db_expect(is_array($restored)&&($restored['usuario']??'')==='admin-test','No se pudo restaurar la sesión desde el token.');
    $rotated=(string)($_COOKIE[HACHE_REMEMBER_COOKIE]??'');
    remember_db_expect($rotated!==''&&$rotated!==$cookie,'El token debe rotarse al restaurar la sesión.');

    $newHash=password_hash('Otra-clave-456',PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE usuarios SET password_hash=:p WHERE id=:id")->execute([':p'=>$newHash,':id'=>$id]);
    remember_db_expect(hache_remember_restore($pdo)===false,'Un cambio de contraseña debe invalidar el token persistente.');
    remember_db_expect(!isset($_COOKIE[HACHE_REMEMBER_COOKIE]),'La cookie inválida debe eliminarse.');

    echo "AUTH_REMEMBER_MARIADB_OK\n";
}finally{
    $admin->exec("DROP DATABASE IF EXISTS `{$db}`");
}
