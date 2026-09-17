<?php
declare(strict_types=1);

require_once __DIR__.'/../config/reglas-acceso.php';

function regular_monthly_alert_expect(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

$reference=new DateTimeImmutable('2026-09-17');
$p15=regla_periodo_regular_actual('PALAPAS','P15',$reference);
regular_monthly_alert_expect($p15['inicio']==='2026-09-15'&&$p15['fin']==='2026-10-14','La autoridad vigente debe conservar el período P15 de Palapas.');
$monthly=regla_periodo_regular_actual('MONTEVERDE',null,$reference);
regular_monthly_alert_expect($monthly['inicio']==='2026-09-01'&&$monthly['fin']==='2026-09-30','La autoridad vigente debe conservar el período mensual regular.');

$alerts=file_get_contents(__DIR__.'/../api/alertas.php')?:'';
regular_monthly_alert_expect(str_contains($alerts,"require_once __DIR__.'/../config/reglas-acceso.php'"),'F5 debe cargar la misma autoridad de cobertura que F1/F2.');
regular_monthly_alert_expect(str_contains($alerts,"SELECT a.id,a.ciclo_pago FROM alumnos a WHERE a.sede_id=:s AND a.plan_actual_id IS NOT NULL AND a.estado_administrativo<>'BAJA'"),'F5 debe conservar el mismo alcance regular del Centro de pendientes.');
regular_monthly_alert_expect(str_contains($alerts,"ci.estado IN ('PROGRAMADO','EN_CURSO')"),'Un alumno cubierto por intensivo activo no debe generar la señal regular.');
regular_monthly_alert_expect(str_contains($alerts,'regla_mensualidad_regular_cubierta($pdo,(string)$r[\'id\'],(string)$sid,$clave'),'F5 debe delegar la decisión de cobertura a regla_mensualidad_regular_cubierta().');
regular_monthly_alert_expect(!str_contains($alerts,":hoy BETWEEN m.periodo_inicio AND m.periodo_fin"),'La fórmula histórica basada en fecha contenida no debe competir con la autoridad F1/F2.');
regular_monthly_alert_expect(str_contains($alerts,"['tipo'=>'PAGO','nivel'=>'NEUTRA'"),'La señal F5 de mensualidad debe mantener prioridad NEUTRA.');
regular_monthly_alert_expect(str_contains($alerts,"'href'=>'/pendientes.php'"),'La señal debe llevar al Centro de pendientes que ya materializa MENSUALIDAD_REGULAR_SIN_COBERTURA.');
regular_monthly_alert_expect(str_contains($alerts,'Misma regla de cobertura vigente que usa F1/F2.'),'La presentación debe dejar explícito que no existe una segunda fórmula.');

echo "REGULAR_MONTHLY_COVERAGE_ALERT_REGRESSION_OK\n";
