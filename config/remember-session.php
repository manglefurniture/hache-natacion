<?php
declare(strict_types=1);

const HACHE_REMEMBER_COOKIE = 'hache_remember';
const HACHE_REMEMBER_TTL_SECONDS = 2592000;

function hache_remember_schema_ready(PDO $pdo): bool
{
    try {
        $st=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='auth_remember_tokens'");
        return (int)$st->fetchColumn()===1;
    } catch(Throwable $e) {
        return false;
    }
}

function hache_remember_cookie_secure(): bool
{
    $httpsDirect=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off';
    $httpsForwarded=strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??'')))==='https';
    return $httpsDirect||$httpsForwarded;
}

function hache_remember_set_cookie(string $value,int $expiresAt): void
{
    setcookie(HACHE_REMEMBER_COOKIE,$value,[
        'expires'=>$expiresAt,
        'path'=>'/',
        'secure'=>hache_remember_cookie_secure(),
        'httponly'=>true,
        'samesite'=>'Lax',
    ]);
    $_COOKIE[HACHE_REMEMBER_COOKIE]=$value;
}

function hache_remember_clear_cookie(): void
{
    setcookie(HACHE_REMEMBER_COOKIE,'',[
        'expires'=>time()-42000,
        'path'=>'/',
        'secure'=>hache_remember_cookie_secure(),
        'httponly'=>true,
        'samesite'=>'Lax',
    ]);
    unset($_COOKIE[HACHE_REMEMBER_COOKIE]);
}

/** @return array{selector:string,validator:string}|null */
function hache_remember_parse_cookie(?string $raw=null): ?array
{
    $raw=trim((string)($raw??($_COOKIE[HACHE_REMEMBER_COOKIE]??'')));
    if(!preg_match('/^([a-f0-9]{24})\.([a-f0-9]{64})$/',$raw,$m))return null;
    return ['selector'=>$m[1],'validator'=>$m[2]];
}

function hache_remember_issue(PDO $pdo,array $user,int $ttlSeconds=HACHE_REMEMBER_TTL_SECONDS): bool
{
    $userId=trim((string)($user['id']??''));
    $passwordHash=(string)($user['password_hash']??'');
    if($userId===''||$passwordHash===''||!hache_remember_schema_ready($pdo))return false;
    $ttlSeconds=max(86400,min(HACHE_REMEMBER_TTL_SECONDS,$ttlSeconds));
    $selector=bin2hex(random_bytes(12));
    $validator=bin2hex(random_bytes(32));
    $expiresAt=time()+$ttlSeconds;
    try {
        $pdo->prepare("DELETE FROM auth_remember_tokens WHERE expires_at<NOW()")->execute();
        $id=(string)$pdo->query('SELECT UUID()')->fetchColumn();
        $st=$pdo->prepare("INSERT INTO `auth_remember_tokens`(id,user_id,selector,token_hash,password_fingerprint,expires_at) VALUES(:id,:u,:s,:h,:p,FROM_UNIXTIME(:e))");
        $st->execute([
            ':id'=>$id,
            ':u'=>$userId,
            ':s'=>$selector,
            ':h'=>hash('sha256',$validator),
            ':p'=>hash('sha256',$passwordHash),
            ':e'=>$expiresAt,
        ]);
        if($st->rowCount()!==1)return false;
        hache_remember_set_cookie($selector.'.'.$validator,$expiresAt);
        return true;
    } catch(Throwable $e) {
        error_log('[remember-session] No se pudo crear el acceso persistente.');
        return false;
    }
}

/** @return array|false */
function hache_remember_restore(PDO $pdo): array|false
{
    $parsed=hache_remember_parse_cookie();
    if(!$parsed)return false;
    if(!hache_remember_schema_ready($pdo))return false;
    try {
        $pdo->beginTransaction();
        $st=$pdo->prepare("SELECT art.id token_id,art.user_id,art.token_hash,art.password_fingerprint,u.id,u.usuario,u.password_hash,u.rol,u.activo,u.alumno_id,u.sede_id,u.debe_cambiar_password,s.clave sede_clave,s.nombre sede_nombre,s.activo sede_activo
            FROM auth_remember_tokens art
            INNER JOIN usuarios u ON u.id=art.user_id
            LEFT JOIN sedes s ON s.id=u.sede_id
            WHERE art.selector=:s AND art.expires_at>=NOW()
            LIMIT 1 FOR UPDATE");
        $st->execute([':s'=>$parsed['selector']]);
        $row=$st->fetch(PDO::FETCH_ASSOC);
        $valid=is_array($row)
            && hash_equals((string)$row['token_hash'],hash('sha256',$parsed['validator']))
            && hash_equals((string)$row['password_fingerprint'],hash('sha256',(string)$row['password_hash']))
            && (int)$row['activo']===1;
        if($valid){
            $role=strtoupper(trim((string)$row['rol']));
            $site=strtoupper(trim((string)($row['sede_clave']??'')));
            $valid=in_array($role,['ADMIN','VERIFICADOR','ALUMNO'],true)
                && !($role==='ALUMNO'&&empty($row['alumno_id']))
                && !($role==='VERIFICADOR'&&((int)($row['sede_activo']??0)!==1||!in_array($site,['MONTEVERDE','PALAPAS'],true)));
            $row['rol']=$role;
        }
        if(!$valid){
            if(is_array($row)){
                $del=$pdo->prepare("DELETE FROM auth_remember_tokens WHERE id=:id");
                $del->execute([':id'=>$row['token_id']]);
            }
            $pdo->commit();
            hache_remember_clear_cookie();
            return false;
        }
        $newValidator=bin2hex(random_bytes(32));
        $expiresAt=time()+HACHE_REMEMBER_TTL_SECONDS;
        $up=$pdo->prepare("UPDATE auth_remember_tokens SET token_hash=:h,expires_at=FROM_UNIXTIME(:e),last_used_at=NOW() WHERE id=:id");
        $up->execute([':h'=>hash('sha256',$newValidator),':e'=>$expiresAt,':id'=>$row['token_id']]);
        $pdo->commit();
        hache_remember_set_cookie($parsed['selector'].'.'.$newValidator,$expiresAt);
        unset($row['token_id'],$row['token_hash'],$row['password_fingerprint']);
        return $row;
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        error_log('[remember-session] No se pudo restaurar la sesión persistente.');
        return false;
    }
}

function hache_remember_revoke_current(PDO $pdo): void
{
    $parsed=hache_remember_parse_cookie();
    try {
        if($parsed&&hache_remember_schema_ready($pdo)){
            $st=$pdo->prepare("DELETE FROM auth_remember_tokens WHERE selector=:s");
            $st->execute([':s'=>$parsed['selector']]);
        }
    } catch(Throwable $e) {
        error_log('[remember-session] No se pudo revocar el acceso persistente.');
    }
    hache_remember_clear_cookie();
}
