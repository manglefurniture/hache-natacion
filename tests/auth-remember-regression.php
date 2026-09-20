<?php
declare(strict_types=1);

function remember_expect(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"AUTH REMEMBER FAIL: {$message}\n");exit(1);}
}

$root=dirname(__DIR__);
$service=file_get_contents($root.'/config/remember-session.php')?:'';
$auth=file_get_contents($root.'/config/auth.php')?:'';
$login=file_get_contents($root.'/api/login.php')?:'';
$page=file_get_contents($root.'/public/index.php')?:'';
$migration=file_get_contents($root.'/database/migrations/20260920_auth_remember_tokens.sql')?:'';
$deploy=file_get_contents($root.'/ops/production-readiness/deploy-hache-natacion')?:'';

remember_expect(str_contains($service,"const HACHE_REMEMBER_TTL_SECONDS = 2592000"),'La sesión recordada debe tener una vigencia explícita de 30 días.');
remember_expect(str_contains($service,'random_bytes(32)')&&str_contains($service,"hash('sha256',\$validator)"),'El token persistente debe usar entropía criptográfica y guardar solo hash.');
remember_expect(str_contains($service,"'httponly'=>true")&&str_contains($service,"'samesite'=>'Lax'")&&str_contains($service,'hache_remember_cookie_secure()'),'La cookie debe ser HttpOnly, SameSite=Lax y Secure bajo HTTPS.');
remember_expect(str_contains($service,'password_fingerprint')&&str_contains($service,"hash('sha256',(string)\$row['password_hash'])"),'Un cambio de contraseña debe invalidar el acceso persistente.');
remember_expect(str_contains($service,'LIMIT 1 FOR UPDATE')&&str_contains($service,'$newValidator=bin2hex(random_bytes(32))'),'La restauración debe bloquear y rotar el token.');
remember_expect(str_contains($migration,'token_hash CHAR(64) NOT NULL')&&!str_contains($migration,' password TEXT')&&!str_contains($migration,' password VARCHAR'),'La base nunca debe guardar la contraseña ni el token en claro.');
remember_expect(str_contains($auth,'hache_remember_restore(auth_open_pdo())'),'Una sesión PHP vencida debe poder restaurarse desde el token persistente.');
remember_expect(str_contains($auth,'hache_remember_revoke_current(auth_open_pdo())'),'Cerrar sesión debe revocar el token del dispositivo.');
remember_expect(str_contains($login,"\$recordarme=!empty(\$input['recordarme'])")&&str_contains($login,'hache_remember_issue($pdo,$user)'),'Login debe emitir el token solo cuando el usuario lo solicita.');
remember_expect(str_contains($page,'id="recordarme"')&&str_contains($page,'Mantener sesión iniciada en este dispositivo'),'La pantalla debe ofrecer la opción visible de mantener la sesión.');
remember_expect(str_contains($page,"recordarme:document.getElementById('recordarme').checked"),'La preferencia debe enviarse al backend.');
remember_expect(!str_contains($page,'localStorage.setItem(\'password\'')&&!str_contains($page,'document.cookie') ,'La interfaz no debe guardar la contraseña en almacenamiento web ni escribir cookies de credenciales.');
remember_expect(str_contains($deploy,'bin/migrate-auth-remember.php'),'El deploy debe preparar el esquema antes de usar sesiones persistentes.');

echo "AUTH_REMEMBER_REGRESSION_OK\n";
