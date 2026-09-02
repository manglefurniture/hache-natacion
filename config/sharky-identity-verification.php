<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-orchestrator-store.php';

function hache_sharky_verification_ready(PDO $pdo): bool
{
    try {
        $st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:t');
        $st->execute([':t'=>'sharky_identity_challenges']);
        return (int)$st->fetchColumn()===1;
    } catch (Throwable $e) {
        return false;
    }
}

function hache_sharky_verification_issue(PDO $pdo, string $contact, string $baseUrl = 'https://hnatacion.com/sharky-verificar.php', int $ttlSeconds = 900): array
{
    if (!hache_sharky_verification_ready($pdo)) throw new RuntimeException('Verification store unavailable');
    $ttlSeconds=max(300,min(1800,$ttlSeconds));
    $contactHash=hache_sharky_orchestrator_contact_hash($contact);
    $raw=bin2hex(random_bytes(32));
    $hash=hash('sha256',$raw);
    $id=(string)$pdo->query('SELECT UUID()')->fetchColumn();
    $expires=(new DateTimeImmutable())->modify('+'.$ttlSeconds.' seconds')->format('Y-m-d H:i:s');
    $st=$pdo->prepare("UPDATE sharky_identity_challenges SET status='EXPIRED' WHERE contact_hash=:c AND status='PENDING'");
    $st->execute([':c'=>$contactHash]);
    $st=$pdo->prepare("INSERT INTO sharky_identity_challenges(id,contact_hash,token_hash,status,expires_at) VALUES(:id,:c,:t,'PENDING',:expires)");
    $st->execute([':id'=>$id,':c'=>$contactHash,':t'=>$hash,':expires'=>$expires]);
    $sep=str_contains($baseUrl,'?')?'&':'?';
    return ['id'=>$id,'url'=>$baseUrl.$sep.'token='.rawurlencode($raw),'expires_in'=>$ttlSeconds];
}

function hache_sharky_verification_confirm(PDO $pdo, string $rawToken, string $studentId): array
{
    if (!hache_sharky_verification_ready($pdo)) throw new RuntimeException('Verification store unavailable');
    $rawToken=trim($rawToken);$studentId=trim($studentId);
    if (!preg_match('/^[a-f0-9]{64}$/',$rawToken) || $studentId==='') return ['ok'=>false,'code'=>'INVALID'];
    $hash=hash('sha256',$rawToken);
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare("SELECT id,status,expires_at FROM sharky_identity_challenges WHERE token_hash=:t LIMIT 1 FOR UPDATE");
        $st->execute([':t'=>$hash]);$row=$st->fetch(PDO::FETCH_ASSOC);
        if(!$row){$pdo->rollBack();return ['ok'=>false,'code'=>'NOT_FOUND'];}
        if((string)$row['status']!=='PENDING'){$pdo->rollBack();return ['ok'=>false,'code'=>(string)$row['status']];}
        if(strtotime((string)$row['expires_at'])<time()){
            $st=$pdo->prepare("UPDATE sharky_identity_challenges SET status='EXPIRED' WHERE id=:id");$st->execute([':id'=>$row['id']]);$pdo->commit();
            return ['ok'=>false,'code'=>'EXPIRED'];
        }
        $st=$pdo->prepare("SELECT id,nombre,estado_administrativo FROM alumnos WHERE id=:a LIMIT 1");$st->execute([':a'=>$studentId]);$student=$st->fetch(PDO::FETCH_ASSOC);
        if(!$student){$pdo->rollBack();return ['ok'=>false,'code'=>'STUDENT_NOT_FOUND'];}
        $st=$pdo->prepare("UPDATE sharky_identity_challenges SET status='VERIFIED',verified_student_id=:a,verified_at=NOW() WHERE id=:id");
        $st->execute([':a'=>$studentId,':id'=>$row['id']]);$pdo->commit();
        return ['ok'=>true,'code'=>'VERIFIED','student_id'=>$studentId,'name'=>(string)$student['nombre'],'status'=>(string)$student['estado_administrativo']];
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function hache_sharky_verification_status(PDO $pdo, string $contact): array
{
    if (!hache_sharky_verification_ready($pdo)) return ['verified'=>false,'code'=>'STORE_UNAVAILABLE'];
    $contactHash=hache_sharky_orchestrator_contact_hash($contact);
    $st=$pdo->prepare("SELECT c.id,c.status,c.expires_at,c.verified_student_id,a.nombre,a.estado_administrativo,s.clave sede_clave FROM sharky_identity_challenges c LEFT JOIN alumnos a ON a.id=c.verified_student_id LEFT JOIN sedes s ON s.id=a.sede_id WHERE c.contact_hash=:c ORDER BY c.created_at DESC LIMIT 1");
    $st->execute([':c'=>$contactHash]);$row=$st->fetch(PDO::FETCH_ASSOC);
    if(!$row)return ['verified'=>false,'code'=>'NONE'];
    if((string)$row['status']==='PENDING' && strtotime((string)$row['expires_at'])<time()){
        $pdo->prepare("UPDATE sharky_identity_challenges SET status='EXPIRED' WHERE id=:id AND status='PENDING'")->execute([':id'=>$row['id']]);
        return ['verified'=>false,'code'=>'EXPIRED'];
    }
    if((string)$row['status']!=='VERIFIED' || empty($row['verified_student_id']))return ['verified'=>false,'code'=>(string)$row['status']];
    return ['verified'=>true,'student_id'=>(string)$row['verified_student_id'],'name'=>(string)$row['nombre'],'status'=>(string)$row['estado_administrativo'],'sede_clave'=>(string)$row['sede_clave'],'source'=>'portal_login'];
}
