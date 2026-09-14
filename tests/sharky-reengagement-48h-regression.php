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
reengagement_ok(HACHE_SHARKY_FOLLOWUP_LEARN_TEMPLATE==='hache_seguimiento_aprender_nadar','El seguimiento nuevo debe usar una plantilla específica para Aprende a nadar.');
reengagement_ok(HACHE_SHARKY_FOLLOWUP_LEARN_TEMPLATE!==HACHE_SHARKY_FOLLOWUP_RESUME_TEMPLATE,'La plantilla vieja no puede seguir siendo la plantilla del seguimiento de 48h.');
reengagement_ok(HACHE_SHARKY_FOLLOWUP_LEARN_TEMPLATE_BODY==='Hola, hace unos días nos escribiste porque querías aprender a nadar y nos quedamos pendientes de tu respuesta. ¿Podemos ayudarte en algo más?','El texto aprobado debe quedar congelado para la plantilla nueva.');

$learn=hache_sharky_orchestrator_state(null,$ts('2026-09-14 10:00:00'));
$learn['identity']=array_replace($learn['identity'],['kind'=>'prospect','verified'=>true,'source'=>'self_declared']);
$learn['commercial_context']['program']='intensive';
$learn['commercial_context']['program_button_choice']='learn';
$learn['commercial_context']['program_button_choice_at']=$learn['updated_at'];
$learn['flow']=['name'=>'meta_ad_onboarding','step'=>'venue','data'=>['program'=>'intensive'],'updated_at'=>$learn['updated_at']];
$learn['last_user_text']='Aprende a nadar';
reengagement_ok(hache_sharky_followup_learn_reengagement_eligible($learn),'Quien tocó Aprende a nadar debe poder entrar al seguimiento de 48h aun antes de elegir sede.');

$p3=hache_sharky_followup_payload('529980000000',$learn,3,'token-48h',(int)$learn['updated_at']);
reengagement_ok(($p3['type']??'')==='template','El contacto de 48h debe salir como plantilla de WhatsApp, no como texto libre.');
reengagement_ok(($p3['template']['name']??'')===HACHE_SHARKY_FOLLOWUP_LEARN_TEMPLATE,'La etapa 3 debe usar exclusivamente la plantilla nueva de Aprende a nadar.');
reengagement_ok(($p3['template']['name']??'')!=='hache_retomar_inscripcion','El mensaje genérico anterior no debe volver a enviarse desde la etapa 3.');
reengagement_ok(($p3['template']['language']['code']??'')==='es_MX','La plantilla debe usar español de México.');
reengagement_ok(($p3['_sharky_followup']['stage']??0)===3,'El mensaje de 48h debe conservar metadatos internos de etapa 3 para revalidación.');
reengagement_ok(($p3['_sharky_followup']['program_button_choice']??'')==='learn','La etapa 3 debe transportar la marca explícita del botón Aprende a nadar.');

$regular=$learn;
$regular['commercial_context']['program']='regular';
$regular['commercial_context']['program_button_choice']='regular';
$regular['flow']=['name'=>'meta_ad_onboarding','step'=>'regular_background','data'=>[],'updated_at'=>$regular['updated_at']];
$regular['last_user_text']='Clases regulares';
reengagement_ok(!hache_sharky_followup_learn_reengagement_eligible($regular),'Clases regulares no debe recibir seguimiento de 48h.');

$regularNo=$learn;
$regularNo['commercial_context']['program']='intensive';
$regularNo['commercial_context']['program_button_choice']='regular';
$regularNo['commercial_context']['background']='no_formal';
$regularNo['last_user_text']='No, curso básico';
reengagement_ok(!hache_sharky_followup_learn_reengagement_eligible($regularNo),'Llegar al intensivo desde Clases regulares → No no equivale a tocar Aprende a nadar.');

$noMark=$learn;
unset($noMark['commercial_context']['program_button_choice'],$noMark['commercial_context']['program_button_choice_at']);
reengagement_ok(!hache_sharky_followup_learn_reengagement_eligible($noMark),'Un intensivo sin evidencia del botón Aprende a nadar debe fallar cerrado para 48h.');

$deferred=$learn;$deferred['last_user_text']='Déjame pensarlo y te aviso';
reengagement_ok(!hache_sharky_followup_learn_reengagement_eligible($deferred),'Quien pidió tiempo para pensarlo debe conservar el cierre automático de seguimiento.');

$registration=$learn;$registration['flow']=['name'=>'register_intensive','step'=>'form','data'=>[],'updated_at'=>$registration['updated_at']];
reengagement_ok(!hache_sharky_followup_learn_reengagement_eligible($registration),'Un Flow de inscripción activo no debe recibir reengagement comercial.');

$commercialReady=$learn;$commercialReady['flow']=null;$commercialReady['commercial_context']['sede_clave']='PALAPAS';
$p1=hache_sharky_followup_payload('529980000000',$commercialReady,1,'token-48h',(int)$commercialReady['updated_at']);
$p2=hache_sharky_followup_payload('529980000000',$commercialReady,2,'token-48h',(int)$commercialReady['updated_at']);
reengagement_ok(($p1['type']??'')==='interactive'&&($p2['type']??'')==='interactive','Los seguimientos actuales de 15 y 90 minutos deben seguir siendo interactivos.');

$lateDue=hache_sharky_followup_next_allowed_at($ts('2026-09-16 23:00:00'));
reengagement_ok($lateDue===$ts('2026-09-17 08:00:00'),'Si las 48h caen de noche, debe esperar hasta las 08:00.');

$source=file_get_contents(__DIR__.'/../config/sharky-followup.php')?:'';
$dbSource=file_get_contents(__DIR__.'/../config/sharky-orchestrator-db.php')?:'';
$backfillSource=file_get_contents(__DIR__.'/../bin/sharky-learn-reengagement-backfill-once.php')?:'';
reengagement_ok(str_contains($source,'hache_sharky_followup_latest_program_choice'),'La marca explícita debe recuperarse desde el inbox cifrado, no inferirse solo por producto intensivo.');
reengagement_ok(str_contains($source,"['meta:program:learn','meta:program:regular']"),'La autoridad de marca debe distinguir los dos quick replies canónicos.');
reengagement_ok(str_contains($source,"\$startStage===3"),'Aprende a nadar debe poder armar directamente la etapa 3 aunque todavía no exista sede.');
reengagement_ok(str_contains($source,'$stage===3?hache_sharky_followup_learn_reengagement_eligible'),'La revalidación tardía debe exigir la marca Aprende a nadar.');
reengagement_ok(str_contains($source,"'completed_two_sent'"),'La secuencia de clases regulares debe terminar después de la segunda etapa sin crear una etapa 3.');
reengagement_ok(substr_count($source,"'idle-followup|'.\$token.'|3'")===1,'La plantilla de 48h debe tener un único punto de programación e idempotencia.');
reengagement_ok(str_contains($source,'HACHE_SHARKY_FOLLOWUP_REENGAGEMENT_RETENTION_SECONDS'),'La etapa 3 debe conservar estado suficiente para las 48h y la gracia.');
reengagement_ok(str_contains($dbSource,'const HACHE_SHARKY_STATE_MAX_TTL = 345600;'),'El almacén durable debe aceptar una retención superior a 48 horas.');
reengagement_ok(str_contains($dbSource,'min(HACHE_SHARKY_STATE_MAX_TTL,$ttl)'),'El límite durable debe usar la retención máxima.');
reengagement_ok(str_contains($source,"[1,2,3]"),'La validación previa al envío debe reconocer la etapa 3.');
reengagement_ok(str_contains($source,"REGISTRATION_EXISTS"),'El seguimiento tardío debe seguir cancelándose si el prospecto ya se registró.');
reengagement_ok(str_contains($source,"PENDING_INBOUND"),'El seguimiento tardío debe cancelarse si hay una respuesta nueva pendiente de procesar.');
reengagement_ok(str_contains($backfillSource,'HACHE_SHARKY_LEARN_BACKFILL_WINDOW_SECONDS = 86400'),'El backfill aprobado debe limitarse a las últimas 24 horas.');
reengagement_ok(str_contains($backfillSource,"meta:program:learn")&&str_contains($backfillSource,"meta:program:regular"),'El backfill debe conservar solo a quienes tengan Aprende a nadar como última elección explícita.');
reengagement_ok(str_contains($backfillSource,"REPLACED_BY_LEARN_REENGAGEMENT_20260914"),'El corte debe cancelar filas pendientes de la plantilla genérica anterior.');
reengagement_ok(str_contains($backfillSource,"aggregate_counts_only"),'El backfill no debe imprimir PII.');

echo "SHARKY_REENGAGEMENT_48H_OK\n";
