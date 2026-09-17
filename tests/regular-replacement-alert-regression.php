<?php
declare(strict_types=1);

require_once __DIR__.'/../config/regular-replacement-source.php';

function replacement_alert_expect(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

function replacement_function_source(string $name): string
{
    $reflection=new ReflectionFunction($name);
    $lines=file($reflection->getFileName());
    return implode('',array_slice($lines,$reflection->getStartLine()-1,$reflection->getEndLine()-$reflection->getStartLine()+1));
}

$source=replacement_function_source('hache_regular_available_replacement_candidates');
replacement_alert_expect(str_contains($source,"rr.estado='DISPONIBLE'"),'La fuente compartida solo debe considerar reposiciones DISPONIBLES.');
replacement_alert_expect(str_contains($source,'a.sede_id=:sede'),'La fuente compartida debe conservar el alcance por sede.');
replacement_alert_expect(str_contains($source,'rr.id=:reposicion'),'La revalidación debe poder consultar una reposición concreta por su identidad.');
replacement_alert_expect(!preg_match('/\b(?:INSERT|UPDATE|DELETE)\b/i',$source),'La fuente compartida debe ser exclusivamente de lectura.');

$center=file_get_contents(__DIR__.'/../config/centro-pendientes.php')?:'';
replacement_alert_expect(str_contains($center,"require_once __DIR__.'/regular-replacement-source.php'"),'F1 debe cargar la fuente compartida de reposiciones.');
replacement_alert_expect(str_contains($center,'foreach (hache_regular_available_replacement_candidates($pdo, $sedeId) as $reposicion)'),'El Centro debe generar REPOSICION_REGULAR_DISPONIBLE desde la fuente compartida.');
replacement_alert_expect(str_contains($center,'hache_regular_available_replacement_candidates($pdo, $sedeId, $origenId) !== []'),'La revalidación del pendiente debe usar la misma fuente compartida.');
replacement_alert_expect(!str_contains($center,'$reposiciones = $pdo->prepare("SELECT rr.id,rr.alumno_id,rr.created_at,a.nombre'),'El Centro no debe conservar una segunda consulta propia equivalente.');

$alerts=file_get_contents(__DIR__.'/../api/alertas.php')?:'';
replacement_alert_expect(str_contains($alerts,"require_once __DIR__.'/../config/regular-replacement-source.php'"),'F5 debe cargar la fuente compartida de reposiciones.');
replacement_alert_expect(str_contains($alerts,'hache_regular_available_replacement_candidates($pdo,(string)$sid)'),'F5 debe contar la misma fuente que F1.');
replacement_alert_expect(!str_contains($alerts,'SELECT COUNT(*) FROM reposiciones_regulares rr'),'La alerta histórica no debe competir con una consulta propia equivalente.');
replacement_alert_expect(str_contains($alerts,"['tipo'=>'REPOSICION','nivel'=>'NEUTRA'"),'La señal F5 de reposición debe conservar prioridad NEUTRA mientras no exista otra aprobada.');
replacement_alert_expect(str_contains($alerts,"'href'=>'/pendientes.php'"),'La señal F5 debe dirigir al Centro de pendientes.');

fwrite(STDOUT,"REGULAR_REPLACEMENT_ALERT_REGRESSION_OK\n");
