<?php

declare(strict_types=1);

const HACHE_PORTAL_ACCESS_TTL_SECONDS = 86400;
const HACHE_PORTAL_ACCESS_RESET_GRANT_SECONDS = 900;

function hache_portal_access_schema_ready(PDO $pdo): bool
{
    try {
        $st = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='portal_access_tokens'");
        return (int)$st->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/** @return array{token:string,user_id:string,expires_at:int}|null */
function hache_portal_access_issue(PDO $pdo,string $studentId,int $ttlSeconds=HACHE_PORTAL_ACCESS_TTL_SECONDS): ?array
{
    $studentId=trim($studentId);
    $ttlSeconds=max(300,min(172800,$ttlSeconds));
    if($studentId===''||!hache_portal_access_schema_ready($pdo))return null;

    $st=$pdo->prepare("SELECT id FROM usuarios WHERE alumno_id=:a AND rol='ALUMNO' AND activo=1 ORDER BY created_at LIMIT 1");
    $st->execute([':a'=>$studentId]);
    $userId=trim((string)($st->fetchColumn()?:''));
    if($userId==='')return null;

    $token=bin2hex(random_bytes(32));
    $hash=hash('sha256',$token);
    $id=(string)$pdo->query('SELECT UUID()')->fetchColumn();
    $expiresAt=time()+$ttlSeconds;
    $insert=$pdo->prepare("INSERT INTO portal_access_tokens(id,user_id,token_hash,expires_at) VALUES(:id,:u,:h,FROM_UNIXTIME(:e))");
    $insert->execute([':id'=>$id,':u'=>$userId,':h'=>$hash,':e'=>$expiresAt]);
    if($insert->rowCount()!==1)return null;

    return ['token'=>$token,'user_id'=>$userId,'expires_at'=>$expiresAt];
}

/** @return array|false */
function hache_portal_access_consume(PDO $pdo,string $token): array|false
{
    $token=strtolower(trim($token));
    if(preg_match('/^[a-f0-9]{64}$/',$token)!==1||!hache_portal_access_schema_ready($pdo))return false;
    $hash=hash('sha256',$token);
    $ownTransaction=!$pdo->inTransaction();

    try {
        if($ownTransaction)$pdo->beginTransaction();
        $st=$pdo->prepare("SELECT pat.id token_id,pat.user_id,u.id,u.usuario,u.password_hash,u.rol,u.activo,u.alumno_id,u.sede_id,u.debe_cambiar_password,s.clave sede_clave,s.nombre sede_nombre,s.activo sede_activo
                          FROM portal_access_tokens pat
                          INNER JOIN usuarios u ON u.id=pat.user_id
                          LEFT JOIN sedes s ON s.id=u.sede_id
                          WHERE pat.token_hash=:h AND pat.consumed_at IS NULL AND pat.expires_at>=NOW()
                            AND u.activo=1 AND u.rol='ALUMNO'
                          LIMIT 1 FOR UPDATE");
        $st->execute([':h'=>$hash]);
        $row=$st->fetch(PDO::FETCH_ASSOC);
        if(!$row){if($ownTransaction)$pdo->rollBack();return false;}

        $consume=$pdo->prepare("UPDATE portal_access_tokens SET consumed_at=NOW() WHERE user_id=:u AND consumed_at IS NULL");
        $consume->execute([':u'=>(string)$row['user_id']]);
        if($ownTransaction)$pdo->commit();
        return $row;
    } catch(Throwable $e) {
        if($ownTransaction&&$pdo->inTransaction())$pdo->rollBack();
        error_log('[portal-access] No se pudo consumir el acceso de portal.');
        return false;
    }
}

function hache_portal_access_revoke_user(PDO $pdo,string $userId): bool
{
    $userId=trim($userId);
    if($userId===''||!hache_portal_access_schema_ready($pdo))return false;
    try {
        $st=$pdo->prepare("UPDATE portal_access_tokens SET consumed_at=COALESCE(consumed_at,NOW()) WHERE user_id=:u");
        $st->execute([':u'=>$userId]);
        return true;
    } catch(Throwable $e) {
        error_log('[portal-access] No se pudieron revocar accesos de portal.');
        return false;
    }
}
