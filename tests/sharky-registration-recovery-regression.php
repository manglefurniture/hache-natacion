<?php

declare(strict_types=1);

function registration_recovery_expect(bool $ok,string $message):void
{
    if(!$ok){fwrite(STDERR,"REGISTRATION RECOVERY FAIL: $message\n");exit(1);}
}

$orchestrator=file_get_contents(__DIR__.'/../config/sharky-orchestrator-db.php')?:'';
$recovery=file_get_contents(__DIR__.'/../config/sharky-registration-recovery.php')?:'';
$business=file_get_contents(__DIR__.'/../config/sharky-business-actions.php')?:'';

registration_recovery_expect(str_contains($orchestrator,"require_once __DIR__.'/sharky-registration-recovery.php'"),'Executor must load locked registration recovery.');
$registerStart=strpos($orchestrator,"if(\$type==='register_intensive')");
$registerEnd=strpos($orchestrator,"if(\$type==='human_takeover')",$registerStart===false?0:$registerStart);
registration_recovery_expect($registerStart!==false&&$registerEnd!==false,'Registration action branch must be discoverable.');
$registerRegion=substr($orchestrator,$registerStart,$registerEnd-$registerStart);
registration_recovery_expect(!str_contains($registerRegion,'hache_sharky_business_identity_by_whatsapp'),'Registration must not rely on an unlocked identity precheck before the business transaction.');
registration_recovery_expect(str_contains($registerRegion,"codeName!=='PHONE_ALREADY_REGISTERED'")&&str_contains($registerRegion,'hache_sharky_registration_recover_locked'),'A duplicate observed after a stolen lease must enter locked reconciliation instead of becoming a false FAILED audit.');
registration_recovery_expect(str_contains($registerRegion,"$recovered?'RECOVERED':$code"),'Recovered registration must finish the current audit owner as a successful recovery.');

$lockPos=strpos($recovery,'regla_bloquear_identidades_alumnos($pdo)');
$matchPos=strpos($recovery,'WHERE a.whatsapp=:w',$lockPos===false?0:$lockPos);
$rotatePos=strpos($recovery,'UPDATE usuarios SET password_hash=:p',$matchPos===false?0:$matchPos);
$commitPos=strpos($recovery,'$pdo->commit();',$rotatePos===false?0:$rotatePos);
registration_recovery_expect($lockPos!==false&&$matchPos!==false&&$rotatePos!==false&&$commitPos!==false&&$lockPos<$matchPos&&$matchPos<$rotatePos&&$rotatePos<$commitPos,'Identity match, credential rotation and recovery commit must stay under one serialized transaction.');
registration_recovery_expect(str_contains($recovery,"'code'=>'RECOVERED'")&&str_contains($recovery,"'recovered'=>true"),'Locked reconciliation must return an explicit recovered result.');
registration_recovery_expect(str_contains($business,'regla_bloquear_identidades_alumnos($pdo)'),'Fresh intensive creation must continue using the same identity serialization lock.');

fwrite(STDOUT,"SHARKY_REGISTRATION_RECOVERY_OK\n");
