<?php
declare(strict_types=1);

putenv('SHARKY_CONTACT_HASH_KEY='.str_repeat('k',64));
require_once __DIR__.'/../config/prospect-followup-alert.php';

function followup_alert_expect(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,$message."\n");exit(1);}
}

$now=new DateTimeImmutable('2026-09-17 12:00:00',new DateTimeZone('America/Cancun'));
$state=['commercial_context'=>['_idle_followup'=>['status'=>'idle']]];
$base=[
    'gestion_disponible'=>true,
    'gestion_estado'=>'SIN_GESTION',
    'ultimo_contacto'=>'2026-09-16 12:00:00',
    'pausa_durable_disponible'=>true,
    'seguimiento_pausado'=>false,
];

followup_alert_expect(HACHE_INTERNAL_PROSPECT_FOLLOWUP_THRESHOLD_SECONDS===86400,'F5 debe conservar el umbral aprobado de 24 horas.');
followup_alert_expect(hache_internal_prospect_followup_due($base,$state,$now),'Exactamente 24 horas sin gestión deben activar la alerta.');
followup_alert_expect(hache_internal_prospect_followup_due($base,[],$now),'Un estado conversacional expirado no debe apagar la alerta cuando el último inbound durable confirma que no hubo pausa.');

$recent=$base;$recent['ultimo_contacto']='2026-09-16 12:00:01';
followup_alert_expect(!hache_internal_prospect_followup_due($recent,$state,$now),'Antes de 24 horas no debe activarse la alerta.');

$managed=$base;$managed['gestion_estado']='GESTIONADO';
followup_alert_expect(!hache_internal_prospect_followup_due($managed,$state,$now),'Una gestión que todavía cubre la actividad reciente no debe alertar.');

$activity=$base;$activity['gestion_estado']='ACTIVIDAD_POSTERIOR';
followup_alert_expect(hache_internal_prospect_followup_due($activity,$state,$now),'Actividad posterior sin nueva gestión debe volver a ser elegible al cumplir 24 horas.');

$paused=['commercial_context'=>['_idle_followup'=>['status'=>'completed_optout']]];
followup_alert_expect(!hache_internal_prospect_followup_due($base,$paused,$now),'Un seguimiento pausado/opt-out vigente no debe producir alerta interna.');

$registered=['commercial_context'=>['_idle_followup'=>['status'=>'completed_registration']]];
followup_alert_expect(!hache_internal_prospect_followup_due($base,$registered,$now),'Un seguimiento cerrado por registro no debe producir alerta interna.');

$durablyPaused=$base;$durablyPaused['seguimiento_pausado']=true;
followup_alert_expect(!hache_internal_prospect_followup_due($durablyPaused,[],$now),'Una pausa reconstruida desde el inbound durable debe seguir suprimiendo la alerta después de expirar el estado.');

$unknownPause=$base;$unknownPause['pausa_durable_disponible']=false;
followup_alert_expect(!hache_internal_prospect_followup_due($unknownPause,[],$now),'Estado expirado sin evidencia durable legible debe seguir fallando cerrado.');

$unavailable=$base;$unavailable['gestion_disponible']=false;
followup_alert_expect(!hache_internal_prospect_followup_due($unavailable,$state,$now),'Durante una ventana sin esquema de gestión disponible no se deben generar falsos positivos.');

followup_alert_expect(hache_internal_prospect_followup_event_paused(['text'=>'No por el momento','interactive_id'=>'flow:pause']),'El botón dedicado de pausa debe reconstruirse como pausa durable.');
followup_alert_expect(hache_internal_prospect_followup_event_paused(['text'=>'Déjame analizar','interactive_id'=>'']),'La pausa explícita del Brain debe reconstruirse desde texto durable.');
followup_alert_expect(!hache_internal_prospect_followup_event_paused(['text'=>'¿Qué horarios tienen?','interactive_id'=>'']),'Una pregunta comercial normal no debe convertirse en pausa.');

// Regresión del P1 de Codex: el estado persistido ya está expirado a las 24 h,
// pero el último inbound cifrado sigue siendo evidencia durable suficiente para
// reconstruir si existió una pausa sin mantener vivo el estado conversacional.
followup_alert_expect(in_array('sqlite',PDO::getAvailableDrivers(),true),'La regresión de expiración requiere PDO SQLite.');
$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('NOW',static fn():string=>'2026-09-17 12:00:00',0);
$pdo->exec('CREATE TABLE sharky_conversation_state (contact_hash TEXT PRIMARY KEY,state_json TEXT NULL,state_ciphertext TEXT NULL,state_iv TEXT NULL,state_tag TEXT NULL,expires_at TEXT NOT NULL)');
$pdo->exec('CREATE TABLE sharky_message_receipts (message_id TEXT PRIMARY KEY,contact_hash TEXT NOT NULL,message_type TEXT NOT NULL,payload_ciphertext TEXT NULL,payload_iv TEXT NULL,payload_tag TEXT NULL,received_at TEXT NOT NULL)');
$hash=str_repeat('a',64);
$insertState=$pdo->prepare('INSERT INTO sharky_conversation_state(contact_hash,state_json,state_ciphertext,state_iv,state_tag,expires_at) VALUES(:c,NULL,NULL,NULL,NULL,:e)');
$insertState->execute([':c'=>$hash,':e'=>'2026-09-17 11:59:59']);
[$scope,$params]=hache_sharky_crm_hash_scope([$hash]);
$expiredStates=hache_sharky_crm_bulk_states($pdo,$scope,$params);
followup_alert_expect(!isset($expiredStates[$hash]),'La prueba debe confirmar primero que el estado persistido ya expiró y el CRM dejó de devolverlo.');

$insertReceipt=$pdo->prepare('INSERT INTO sharky_message_receipts(message_id,contact_hash,message_type,payload_ciphertext,payload_iv,payload_tag,received_at) VALUES(:m,:c,:t,:p,:iv,:tag,:r)');
$normal=hache_sharky_inbox_encrypt([
    'text'=>'Quiero revisar los horarios',
    'interactive_id'=>'',
    'timestamp_ms'=>1789646400000,
    '_inbox_arrival_us'=>1789646400000000,
]);
$insertReceipt->execute([':m'=>'m-normal',':c'=>$hash,':t'=>'message',':p'=>$normal['ciphertext'],':iv'=>$normal['iv'],':tag'=>$normal['tag'],':r'=>'2026-09-16 12:00:00']);
$evidence=hache_internal_prospect_followup_durable_pause_evidence($pdo,[$hash]);
followup_alert_expect(($evidence[$hash]['known']??false)===true&&($evidence[$hash]['paused']??true)===false,'Con estado expirado, un inbound durable normal debe permitir evaluar la alerta.');
$expiredRow=$base;$expiredRow['pausa_durable_disponible']=($evidence[$hash]['known']??false)===true;$expiredRow['seguimiento_pausado']=($evidence[$hash]['paused']??false)===true;
followup_alert_expect(hache_internal_prospect_followup_due($expiredRow,$expiredStates[$hash]??[],$now),'La alerta debe seguir siendo elegible a las 24 h aunque el estado persistido ya haya expirado.');

// Dos receipts del prospecto pueden compartir el segundo DATETIME. La marca
// durable de llegada dentro del payload decide cuál fue realmente el último.
$pause=hache_sharky_inbox_encrypt([
    'text'=>'No por el momento',
    'interactive_id'=>'flow:pause',
    'timestamp_ms'=>1789646400001,
    '_inbox_arrival_us'=>1789646400001000,
]);
$insertReceipt->execute([':m'=>'m-pause',':c'=>$hash,':t'=>'message',':p'=>$pause['ciphertext'],':iv'=>$pause['iv'],':tag'=>$pause['tag'],':r'=>'2026-09-16 12:00:00']);
$evidence=hache_internal_prospect_followup_durable_pause_evidence($pdo,[$hash]);
followup_alert_expect(($evidence[$hash]['known']??false)===true&&($evidence[$hash]['paused']??false)===true,'El último inbound durable debe conservar la exclusión de pausa después de expirar el estado.');
$pausedExpired=$base;$pausedExpired['pausa_durable_disponible']=true;$pausedExpired['seguimiento_pausado']=true;
followup_alert_expect(!hache_internal_prospect_followup_due($pausedExpired,$expiredStates[$hash]??[],$now),'La evidencia durable de pausa debe impedir falsos positivos con estado expirado.');

// Un reply saliente del staff vuelve como receipt `echo`; aunque sea posterior,
// no debe reemplazar la última decisión real del prospecto.
$echo=hache_sharky_inbox_encrypt([
    'text'=>'Claro, aquí quedamos',
    'interactive_id'=>'',
    'timestamp_ms'=>1789646401000,
    '_inbox_arrival_us'=>1789646401000000,
]);
$insertReceipt->execute([':m'=>'m-echo',':c'=>$hash,':t'=>'echo',':p'=>$echo['ciphertext'],':iv'=>$echo['iv'],':tag'=>$echo['tag'],':r'=>'2026-09-16 12:00:01']);
$evidence=hache_internal_prospect_followup_durable_pause_evidence($pdo,[$hash]);
followup_alert_expect(($evidence[$hash]['known']??false)===true&&($evidence[$hash]['paused']??false)===true,'Un echo posterior del staff no debe tapar la pausa durable del prospecto.');

$corrupt=$pdo->prepare('INSERT INTO sharky_message_receipts(message_id,contact_hash,message_type,payload_ciphertext,payload_iv,payload_tag,received_at) VALUES(:m,:c,:t,:p,:iv,:tag,:r)');
$corrupt->execute([':m'=>'m-corrupt',':c'=>$hash,':t'=>'message',':p'=>'invalid',':iv'=>'invalid',':tag'=>'invalid',':r'=>'2026-09-16 12:00:02']);
$evidence=hache_internal_prospect_followup_durable_pause_evidence($pdo,[$hash]);
followup_alert_expect(($evidence[$hash]['known']??true)===false,'Si el último inbound durable no puede descifrarse, la pausa debe quedar como desconocida.');
$unknownPersisted=$base;$unknownPersisted['pausa_durable_disponible']=false;
followup_alert_expect(!hache_internal_prospect_followup_due($unknownPersisted,[],$now),'Evidencia durable ilegible debe fallar cerrado, no generar una alerta dudosa.');

$api=file_get_contents(__DIR__.'/../api/alertas.php')?:'';
$page=file_get_contents(__DIR__.'/../public/alertas.php')?:'';
$doc=file_get_contents(__DIR__.'/../docs/F5-PROSPECT-FOLLOWUP-ALERT.md')?:'';
followup_alert_expect(str_contains($api,'hache_internal_prospect_followup_candidates')&&str_contains($api,"'nivel'=>'NEUTRA'")&&str_contains($api,"'href'=>'/prospectos.php'"),'La API de alertas debe consumir la regla compartida sin inventar prioridad.');
followup_alert_expect(str_contains($page,'.NEUTRA')&&str_contains($page,"'NEUTRA'"),'La UI debe representar la alerta sin convertirla en prioridad alta/media/baja.');
followup_alert_expect(str_contains($doc,'24 horas')&&str_contains($doc,'no envía mensajes')&&str_contains($doc,'Centro de pendientes')&&str_contains($doc,'último inbound persistido'),'La decisión de F5, el fallback durable y sus límites deben quedar documentados.');

echo "Prospect follow-up internal alert regression: OK\n";
