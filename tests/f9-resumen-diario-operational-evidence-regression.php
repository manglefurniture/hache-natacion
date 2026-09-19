<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/config/resumen-diario-evidence.php';

function f93_expect(bool $ok,string $message): void
{
    if(!$ok)throw new RuntimeException($message);
}

$operacion=[
    'sesiones_registradas'=>2,'realizadas'=>1,'canceladas'=>1,
    'asistencia'=>[
        'disponible'=>true,'marcas'=>3,'presentes'=>2,
        'ausencias_justificadas'=>1,'ausencias_no_justificadas'=>0,
    ],
];
$asistencia=[
    'disponible'=>true,'esperados'=>3,'presentes'=>2,
    'ausencias_justificadas'=>1,'ausencias_no_justificadas'=>0,
];
$cobros=[
    'pagos'=>2,'validos'=>1,'total_valido'=>800.0,'invalidados'=>1,'total_invalidado'=>800.0,
    'rows'=>[
        ['pago_id'=>'private-payment-a','alumno'=>'Nombre privado','importe'=>800.0,'estado'=>'VALIDO'],
        ['pago_id'=>'private-payment-b','alumno'=>'Nombre privado 2','importe'=>800.0,'estado'=>'INVALIDO'],
    ],
];
$altas=['total'=>1,'rows'=>[['alumno_id'=>'private-student','nombre'=>'Nombre privado']]];
$incidencias=[
    'disponible'=>true,
    'sesiones_canceladas'=>['total'=>1,'rows'=>[['sesion_id'=>'private-session']]],
    'profesores'=>['total'=>1,'rows'=>[['profesor'=>'Nombre privado coach']]],
    'sustituciones_activas'=>['total'=>0,'rows'=>[]],
    'cobertura'=>[
        'profesores_asignaciones_cobertura_desde'=>'2026-09-18 12:00:00',
        'profesores_sustituciones_cobertura_desde'=>'2026-09-18 12:05:00',
    ],
];
$correcciones=[
    'disponible'=>true,
    'ediciones_pago'=>['total'=>1,'rows'=>[['pago_id'=>'private-payment-a']]],
    'invalidaciones_pago'=>['total'=>1,'rows'=>[['pago_id'=>'private-payment-b']]],
    'correcciones_asistencia'=>['total'=>0,'rows'=>[]],
];

$summary=hache_resumen_diario_evidence_compact(
    '2026-09-19',$operacion,$asistencia,$cobros,$altas,$incidencias,$correcciones
);

f93_expect(($summary['reconciliation']['cobros_consistentes']??false)===true,'Cobros debe reconciliar conteos y totales del contrato F9.');
f93_expect(($summary['reconciliation']['asistencia_operacion_consistente']??false)===true,'Asistencia operativa debe reconciliar sus marcas.');
f93_expect(($summary['reconciliation']['asistencia_periodo_consistente']??false)===true,'Asistencia con cobertura completa debe reconciliar esperado/presente/ausencias.');
f93_expect((int)($summary['activity']['cobros_invalidados']??0)===1,'Las invalidaciones deben permanecer visibles como agregado.');
f93_expect((int)($summary['corrections']['ediciones_pago']??0)===1,'Las correcciones de pago deben quedar agregadas.');
f93_expect(($summary['incidents']['cobertura']['profesores_asignaciones_cobertura_desde']??null)==='2026-09-18 12:00:00','La evidencia debe preservar marcadores de cobertura F7.');

$encoded=json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
foreach(['Nombre privado','private-payment','private-student','private-session','private-professor'] as $private){
    f93_expect(!str_contains($encoded,$private),'La evidencia F9 no debe emitir PII ni identificadores privados.');
}

$collector=(string)file_get_contents(dirname(__DIR__).'/config/resumen-diario-evidence.php');
f93_expect(!preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE)\b/i',$collector),'El collector F9 debe permanecer read-only.');
f93_expect(str_contains($collector,"SET TRANSACTION READ ONLY"),'La evidencia real debe ejecutarse dentro de una transacción read-only.');
f93_expect(str_contains($collector,"'decision'=>'HUMAN_REVIEW_REQUIRED'"),'La evidencia no debe autoaprobar F9.');

$production=(string)file_get_contents(dirname(__DIR__).'/bin/production-readiness-evidence.php');
f93_expect(str_contains($production,"'daily_summary_f9' => hache_resumen_diario_operational_evidence(\$pdo)"),'El snapshot seguro debe incluir la evidencia F9 minimizada.');
f93_expect(str_contains($production,"'contains_personal_rows' => false"),'El contrato global de privacidad debe seguir negando filas personales.');

echo "F9_DAILY_SUMMARY_OPERATIONAL_EVIDENCE_OK\n";
