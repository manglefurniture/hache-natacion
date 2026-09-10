<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-orchestrator.php';
require_once __DIR__.'/../config/sharky-followup.php';

function reengagement_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY REENGAGEMENT FAIL: $message\n");exit(1);}
}

$tz=new DateTimeZone('America/Cancun');
$ts=static fn(string $local):int=>(new DateTimeImmutable($local,$tz))->getTimestamp();

reengagement_ok(HACHE_SHARKY_FOLLOWUP_REENGAGEMENT_DELAY_SECONDS===172800,'El seguimiento comercial debe quedar exactamente a 48 horas.');
reengagement_ok(HACHE_SHARKY_FOLLOWUP_REENGAGEMENT_GRACE_SECONDS===86400,'La ventana de gracia debe conservar 24 horas adicionales.');
reengagement_ok(HACHE_SHARKY_FOLLOWUP_REENGAGEMENT_RETENTION_SECONDS>=HACHE_SHARKY_FOLLOWUP_REENGAGEMENT_DELAY_SECONDS+HACHE_SHARKY_FOLLOWUP_REENGAGEMENT_GRACE_SECONDS,'La retención del estado debe cubrir las 48h y toda la gracia.');
reengagement_ok(HACHE_SHARKY_FOLLOWUP_RESUME_TEMPLATE==='hache_retomar_inscripcion','Debe usar la plantilla aprobada hache_retomar_inscripcion.');

$state=hache_sharky_orchestrator_state(null,$ts('2026-09-10 13:00:00'));
$state['identity']=array_replace($state['identity'],['kind'=>'prospect','verified'=>true,'source'=>'self_declared']);
$state['commercial_context']['program']='intensive';
$state['commercial_context']['sede_clave']='PALAPAS';
$state['last_user_text']='Quiero información del curso intensivo';

$p3=hache_sharky_followup_payload('529980000000',$state,3,'token-48h',(int)$state['updated_at']);
reengagement_ok(($p3['type']??'')==='template','El contacto de 48h debe salir como plantilla de WhatsApp, no como texto libre.');
reengagement_ok(($p3['template']['name']??'')==='hache_retomar_inscripcion','La plantilla de 48h debe ser la aprobada para retomar inscripción.');
reengagement_ok(($p3['template']['language']['code']??'')==='es_MX','La plantilla debe usar español de México.');
reengagement_ok(($p3['_sharky_followup']['stage']??0)===3,'El mensaje de 48h debe conservar metadatos internos de etapa 3 para revalidación.');
reengagement_ok(($p3['_sharky_followup']['user_turn_at']??0)===$state['updated_at'],'La etapa 3 debe quedar ligada al turno exacto del prospecto.');

$p1=hache_sharky_followup_payload('529980000000',$state,1,'token-48h',(int)$state['updated_at']);
$p2=hache_sharky_followup_payload('529980000000',$state,2,'token-48h',(int)$state['updated_at']);
reengagement_ok(($p1['type']??'')==='interactive'&&($p2['type']??'')==='interactive','Los seguimientos actuales de 15 y 90 minutos deben seguir siendo interactivos.');

$lateDue=hache_sharky_followup_next_allowed_at($ts('2026-09-12 23:00:00'));
reengagement_ok($lateDue===$ts('2026-09-13 08:00:00'),'Si las 48h caen de noche, debe esperar hasta las 08:00.');

$source=file_get_contents(__DIR__.'/../config/sharky-followup.php')?:'';
$dbSource=file_get_contents(__DIR__.'/../config/sharky-orchestrator-db.php')?:'';
reengagement_ok(str_contains($source,"if(\$stage===2){"),'La tercera etapa debe programarse únicamente después de entregar la segunda.');
reengagement_ok(str_contains($source,'$due=$userTurnAt+HACHE_SHARKY_FOLLOWUP_REENGAGEMENT_DELAY_SECONDS;'),'La fila debe vencer primero a las 48h exactas para poder revalidar y refrescar estado antes de diferir por horario silencioso.');
reengagement_ok(!str_contains($source,'hache_sharky_followup_next_allowed_at($userTurnAt+HACHE_SHARKY_FOLLOWUP_REENGAGEMENT_DELAY_SECONDS)'),'No debe diferirse la fila antes de la revalidación de las 48h.');
reengagement_ok(substr_count($source,"'idle-followup|'.\$token.'|3'")===1,'La plantilla de 48h debe tener un único punto de programación e idempotencia.');
reengagement_ok(substr_count($source,'HACHE_SHARKY_FOLLOWUP_REENGAGEMENT_RETENTION_SECONDS')>=3,'La etapa 3 debe conservar el estado durante programación y reintentos por horario silencioso.');
reengagement_ok(str_contains($dbSource,'const HACHE_SHARKY_STATE_MAX_TTL = 345600;'),'El almacén durable debe aceptar una retención superior a 48 horas.');
reengagement_ok(str_contains($dbSource,'min(HACHE_SHARKY_STATE_MAX_TTL,$ttl)'),'El límite durable debe usar la nueva retención máxima.');
reengagement_ok(str_contains($source,"[1,2,3]"),'La validación previa al envío debe reconocer la etapa 3.');
reengagement_ok(str_contains($source,"REGISTRATION_EXISTS"),'El seguimiento tardío debe seguir cancelándose si el prospecto ya se registró.');
reengagement_ok(str_contains($source,"PENDING_INBOUND"),'El seguimiento tardío debe cancelarse si hay una respuesta nueva pendiente de procesar.');

echo "SHARKY_REENGAGEMENT_48H_OK\n";
