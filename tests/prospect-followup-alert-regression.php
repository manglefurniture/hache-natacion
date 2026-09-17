<?php
declare(strict_types=1);

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
];

followup_alert_expect(HACHE_INTERNAL_PROSPECT_FOLLOWUP_THRESHOLD_SECONDS===86400,'F5 debe conservar el umbral aprobado de 24 horas.');
followup_alert_expect(hache_internal_prospect_followup_due($base,$state,$now),'Exactamente 24 horas sin gestión deben activar la alerta.');

$recent=$base;$recent['ultimo_contacto']='2026-09-16 12:00:01';
followup_alert_expect(!hache_internal_prospect_followup_due($recent,$state,$now),'Antes de 24 horas no debe activarse la alerta.');

$managed=$base;$managed['gestion_estado']='GESTIONADO';
followup_alert_expect(!hache_internal_prospect_followup_due($managed,$state,$now),'Una gestión que todavía cubre la actividad reciente no debe alertar.');

$activity=$base;$activity['gestion_estado']='ACTIVIDAD_POSTERIOR';
followup_alert_expect(hache_internal_prospect_followup_due($activity,$state,$now),'Actividad posterior sin nueva gestión debe volver a ser elegible al cumplir 24 horas.');

$paused=['commercial_context'=>['_idle_followup'=>['status'=>'completed_optout']]];
followup_alert_expect(!hache_internal_prospect_followup_due($base,$paused,$now),'Un seguimiento pausado/opt-out no debe producir alerta interna.');

$registered=['commercial_context'=>['_idle_followup'=>['status'=>'completed_registration']]];
followup_alert_expect(!hache_internal_prospect_followup_due($base,$registered,$now),'Un seguimiento cerrado por registro no debe producir alerta interna.');

followup_alert_expect(!hache_internal_prospect_followup_due($base,[],$now),'Sin estado estructurado vigente la regla debe fallar cerrada para no ignorar una pausa desconocida.');

$unavailable=$base;$unavailable['gestion_disponible']=false;
followup_alert_expect(!hache_internal_prospect_followup_due($unavailable,$state,$now),'Durante una ventana sin esquema de gestión disponible no se deben generar falsos positivos.');

$api=file_get_contents(__DIR__.'/../api/alertas.php')?:'';
$page=file_get_contents(__DIR__.'/../public/alertas.php')?:'';
$doc=file_get_contents(__DIR__.'/../docs/F5-PROSPECT-FOLLOWUP-ALERT.md')?:'';
followup_alert_expect(str_contains($api,'hache_internal_prospect_followup_candidates')&&str_contains($api,"'nivel'=>'NEUTRA'")&&str_contains($api,"'href'=>'/prospectos.php'"),'La API de alertas debe consumir la regla compartida sin inventar prioridad.');
followup_alert_expect(str_contains($page,'.NEUTRA')&&str_contains($page,"'NEUTRA'"),'La UI debe representar la alerta sin convertirla en prioridad alta/media/baja.');
followup_alert_expect(str_contains($doc,'24 horas')&&str_contains($doc,'no envía mensajes')&&str_contains($doc,'Centro de pendientes'),'La decisión de F5 y sus límites deben quedar documentados.');

echo "Prospect follow-up internal alert regression: OK\n";
