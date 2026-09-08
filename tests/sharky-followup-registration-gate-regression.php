<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-orchestrator.php';
require_once __DIR__.'/../config/sharky-followup.php';

function followup_gate_ok(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"SHARKY FOLLOWUP REGISTRATION GATE FAIL: {$message}\n");exit(1);}
}

$source=file_get_contents(__DIR__.'/../config/sharky-followup.php')?:'';
followup_gate_ok(str_contains($source,"estado_administrativo IN ('PENDIENTE','ACTIVO')"),'Backend gate must treat pending and active students as already registered.');
followup_gate_ok(str_contains($source,"'reason'=>'REGISTRATION_EXISTS'"),'Due follow-ups must be rejected when backend already has the student.');
followup_gate_ok(str_contains($source,"'reason'=>'REGISTRATION_CHECK_UNAVAILABLE'"),'Sales follow-up must fail closed if backend authority cannot be checked.');
followup_gate_ok(str_contains($source,"if(\$reason==='REGISTRATION_EXISTS')"),'A stale scheduled reminder must persist completed_registration after backend detects the student.');

$validatePos=strpos($source,'function hache_sharky_followup_validate_before_send');
$registeredPos=$validatePos===false?false:strpos($source,'hache_sharky_followup_registered_contact($pdo,$contact)',$validatePos);
$allowedPos=$validatePos===false?false:strpos($source,"return ['ok'=>true,'state'=>\$state]",$validatePos);
followup_gate_ok($registeredPos!==false&&$allowedPos!==false&&$registeredPos<$allowedPos,'Backend registration check must happen before a delayed sales message is authorized.');

if(in_array('sqlite',PDO::getAvailableDrivers(),true)){
    $pdo=new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE alumnos (whatsapp TEXT NOT NULL, estado_administrativo TEXT NOT NULL)');
    $insert=$pdo->prepare('INSERT INTO alumnos (whatsapp,estado_administrativo) VALUES (:w,:e)');
    $insert->execute([':w'=>'+529980001111',':e'=>'PENDIENTE']);
    $insert->execute([':w'=>'+529980002222',':e'=>'ACTIVO']);
    $insert->execute([':w'=>'+529980003333',':e'=>'BAJA']);
    $insert->execute([':w'=>'+529980004444',':e'=>'PENDIENTE']);

    followup_gate_ok(hache_sharky_followup_registered_contact($pdo,'529980001111')===true,'Pending registration must suppress prospect follow-up.');
    followup_gate_ok(hache_sharky_followup_registered_contact($pdo,'529980002222')===true,'Active student must suppress prospect follow-up.');
    followup_gate_ok(hache_sharky_followup_registered_contact($pdo,'529980003333')===false,'BAJA record alone must not masquerade as a current registration.');
    followup_gate_ok(hache_sharky_followup_registered_contact($pdo,'5219980004444')===true,'Legacy 521 WhatsApp contact must normalize to the stored +52 identity.');
    followup_gate_ok(hache_sharky_followup_registered_contact($pdo,'529980009999')===false,'Unknown WhatsApp must remain eligible for normal prospect follow-up.');
}

fwrite(STDOUT,"SHARKY_FOLLOWUP_REGISTRATION_GATE_OK\n");
