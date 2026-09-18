<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/config/profesor-actividad-evidence.php';

function f76_expect(bool $ok,string $message): void
{
    if(!$ok)throw new RuntimeException($message);
}

$ctx=[
    'periodo'=>['desde'=>'2026-09-18','hasta'=>'2026-09-18'],
    'cobertura'=>[
        'asignaciones_desde_utc'=>'2026-09-18 12:00:00',
        'sustituciones_desde_utc'=>'2026-09-18 12:05:00',
        'historia_previa_reconstruida'=>false,
    ],
    'profesores'=>[
        [
            'id'=>'private-professor-a','nombre'=>'Nombre privado A','activo'=>1,
            'periodo'=>['desde'=>'2026-09-18','hasta'=>'2026-09-18'],
            'carga_prevista'=>['sesiones'=>2,'fuente'=>'ASIGNACION_DURABLE+SESIONES'],
            'carga_realizada'=>['sesiones_confirmadas'=>0,'fuente_confirmada'=>'SUSTITUCION_EXPLICITA+SESION_REALIZADA'],
            'sesiones'=>[
                [
                    'sesion_id'=>'session-single','estado'=>'REALIZADA','asignacion_prevista'=>true,'docencia_compartida'=>false,
                    'imparticion_confirmada'=>null,'incidencia'=>null,'sustituciones'=>[],
                ],
                [
                    'sesion_id'=>'session-shared','estado'=>'REALIZADA','asignacion_prevista'=>true,'docencia_compartida'=>true,
                    'imparticion_confirmada'=>false,
                    'incidencia'=>['id'=>'private-incident','motivo'=>'Dato privado'],
                    'sustituciones'=>[[
                        'id'=>'private-substitution','estado'=>'ACTIVA','profesor_original_nombre'=>'Nombre privado A',
                        'profesor_sustituto_nombre'=>'Nombre privado B','motivo'=>'Motivo privado',
                    ]],
                ],
            ],
        ],
        [
            'id'=>'private-professor-inactive','nombre'=>'Nombre privado inactivo','activo'=>0,
            'periodo'=>['desde'=>'2026-09-18','hasta'=>'2026-09-18'],
            'carga_prevista'=>['sesiones'=>1,'fuente'=>'ASIGNACION_DURABLE+SESIONES'],
            'carga_realizada'=>['sesiones_confirmadas'=>0,'fuente_confirmada'=>'SUSTITUCION_EXPLICITA+SESION_REALIZADA'],
            'sesiones'=>[[
                'sesion_id'=>'session-inactive','estado'=>'REALIZADA','asignacion_prevista'=>true,'docencia_compartida'=>false,
                'imparticion_confirmada'=>null,'incidencia'=>null,'sustituciones'=>[],
            ]],
        ],
    ],
];

$summary=hache_profesor_actividad_evidence_from_context($ctx);
f76_expect(($summary['requirements']['single_teacher_class_observed']??false)===true,'Debe detectar clase con un docente.');
f76_expect(($summary['requirements']['co_teaching_observed']??false)===true,'Debe detectar docencia compartida.');
f76_expect(($summary['requirements']['substitution_observed']??false)===true,'Debe detectar sustitución.');
f76_expect(($summary['requirements']['individual_incident_observed']??false)===true,'Debe detectar incidencia individual.');
f76_expect(($summary['requirements']['inactive_professor_history_observed']??false)===true,'Debe detectar inactivo con historia.');
f76_expect(($summary['requirements']['load_with_source_and_period_observed']??false)===true,'Debe detectar carga con fuente y periodo.');
f76_expect(($summary['requirements']['insufficient_evidence_without_reconstruction_observed']??false)===true,'Debe detectar sesiones realizadas sin atribución.');
f76_expect(($summary['requirements']['history_precoverage_reconstructed']??true)===false,'No debe afirmar reconstrucción de historia previa.');
f76_expect((int)($summary['counts']['substitution_records']??0)===1,'La sustitución debe deduplicarse entre perspectivas.');
f76_expect((int)($summary['counts']['realized_sessions_without_attribution']??0)===2,'Las sesiones sin atribución deben deduplicarse por sesión.');

$encoded=json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
foreach(['Nombre privado','private-professor','private-incident','private-substitution','Motivo privado','Dato privado'] as $private){
    f76_expect(!str_contains($encoded,$private),'La evidencia agregada no debe emitir PII ni identificadores privados.');
}
f76_expect(!preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP)\b/i',(string)file_get_contents(dirname(__DIR__).'/config/profesor-actividad-evidence.php')),'El collector F7 debe permanecer read-only.');

$collector=(string)file_get_contents(dirname(__DIR__).'/bin/production-readiness-evidence.php');
f76_expect(str_contains($collector,"'professors_f7' => hache_profesor_actividad_operational_evidence(\$pdo)"),'El snapshot seguro debe incluir la evidencia F7 minimizada.');
f76_expect(str_contains($collector,"'contains_personal_rows' => false"),'El contrato de privacidad del snapshot debe seguir negando filas personales.');

echo "F7_PROFESSOR_OPERATIONAL_EVIDENCE_OK\n";
