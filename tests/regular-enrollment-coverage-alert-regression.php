<?php
declare(strict_types=1);

require_once __DIR__.'/../config/reglas-acceso.php';

function regular_enrollment_alert_expect(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

$rules=file_get_contents(__DIR__.'/../config/reglas-acceso.php')?:'';
regular_enrollment_alert_expect(str_contains($rules,'function regla_inscripcion_regular_cubierta'),'Debe existir una única autoridad de cobertura de inscripción regular.');
regular_enrollment_alert_expect(str_contains($rules,'regla_inscripcion_historica_cubierta($pdo,$alumnoId)'),'La autoridad debe conservar la cobertura histórica existente.');
regular_enrollment_alert_expect(str_contains($rules,"strtoupper($sedeClave)==='MONTEVERDE' && regla_es_continuidad_intensivo_monteverde"),'La autoridad debe conservar la excepción vigente de continuidad en Monteverde.');

$alerts=file_get_contents(__DIR__.'/../api/alertas.php')?:'';
regular_enrollment_alert_expect(str_contains($alerts,"SELECT a.id,a.ciclo_pago FROM alumnos a WHERE a.sede_id=:s AND a.plan_actual_id IS NOT NULL AND a.estado_administrativo<>'BAJA'"),'F5 debe reutilizar el mismo alcance regular ya usado por el Centro de pendientes.');
regular_enrollment_alert_expect(str_contains($alerts,"ci.estado IN ('PROGRAMADO','EN_CURSO')"),'Un alumno cubierto por intensivo activo no debe generar la señal regular.');
regular_enrollment_alert_expect(str_contains($alerts,"regla_inscripcion_regular_cubierta($pdo,(string)$r['id'],(string)$sid,$clave"),'F5 debe delegar la cobertura a regla_inscripcion_regular_cubierta().');
regular_enrollment_alert_expect(str_contains($alerts,"['tipo'=>'INSCRIPCION','nivel'=>'NEUTRA'"),'La señal nueva debe permanecer NEUTRA mientras F5 no tenga prioridad aprobada.');
regular_enrollment_alert_expect(str_contains($alerts,"'href'=>'/pendientes.php'"),'La señal debe abrir el Centro de pendientes que materializa la causa individual.');
regular_enrollment_alert_expect(str_contains($alerts,'Misma autoridad de inscripción que usa F1/F2'),'La presentación debe declarar la autoridad compartida y no una segunda fórmula.');

echo "REGULAR_ENROLLMENT_COVERAGE_ALERT_REGRESSION_OK\n";
