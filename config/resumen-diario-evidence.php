<?php
declare(strict_types=1);

require_once __DIR__.'/resumen-diario.php';

/** @return array<string,mixed> */
function hache_resumen_diario_evidence_compact(
    string $fecha,
    array $operacion,
    array $asistencia,
    array $cobros,
    array $altas,
    array $incidencias,
    array $correcciones
): array {
    $validCount=0;$invalidCount=0;$validTotal=0.0;$invalidTotal=0.0;
    foreach(($cobros['rows']??[]) as $row){
        if(!is_array($row))continue;
        $amount=(float)($row['importe']??0);
        if((string)($row['estado']??'')==='VALIDO'){
            $validCount++;
            $validTotal+=$amount;
        }else{
            $invalidCount++;
            $invalidTotal+=$amount;
        }
    }

    $paymentConsistent=
        $validCount===(int)($cobros['validos']??0)
        &&$invalidCount===(int)($cobros['invalidados']??0)
        &&abs(round($validTotal,2)-(float)($cobros['total_valido']??0))<0.01
        &&abs(round($invalidTotal,2)-(float)($cobros['total_invalidado']??0))<0.01
        &&($validCount+$invalidCount)===(int)($cobros['pagos']??0);

    $opAttendance=$operacion['asistencia']??[];
    $opAttendanceConsistent=null;
    if(is_array($opAttendance)&&($opAttendance['disponible']??false)===true){
        $opAttendanceConsistent=
            (int)($opAttendance['marcas']??0)===
            (int)($opAttendance['presentes']??0)
            +(int)($opAttendance['ausencias_justificadas']??0)
            +(int)($opAttendance['ausencias_no_justificadas']??0);
    }

    $periodAttendanceConsistent=null;
    if(($asistencia['disponible']??false)===true){
        $periodAttendanceConsistent=
            (int)($asistencia['esperados']??0)===
            (int)($asistencia['presentes']??0)
            +(int)($asistencia['ausencias_justificadas']??0)
            +(int)($asistencia['ausencias_no_justificadas']??0);
    }

    return [
        'fecha'=>$fecha,
        'activity'=>[
            'sesiones_registradas'=>(int)($operacion['sesiones_registradas']??0),
            'sesiones_realizadas'=>(int)($operacion['realizadas']??0),
            'sesiones_canceladas'=>(int)($operacion['canceladas']??0),
            'pagos'=>(int)($cobros['pagos']??0),
            'cobros_validos'=>(int)($cobros['validos']??0),
            'total_valido'=>round((float)($cobros['total_valido']??0),2),
            'cobros_invalidados'=>(int)($cobros['invalidados']??0),
            'altas'=>(int)($altas['total']??0),
        ],
        'attendance'=>[
            'operacion_disponible'=>($opAttendance['disponible']??false)===true,
            'operacion_marcas'=>($opAttendance['disponible']??false)===true?(int)($opAttendance['marcas']??0):null,
            'periodo_disponible'=>($asistencia['disponible']??false)===true,
            'periodo_esperados'=>($asistencia['disponible']??false)===true?(int)($asistencia['esperados']??0):null,
            'periodo_presentes'=>($asistencia['disponible']??false)===true?(int)($asistencia['presentes']??0):null,
            'operacion_consistente'=>$opAttendanceConsistent,
            'periodo_consistente'=>$periodAttendanceConsistent,
        ],
        'reconciliation'=>[
            'cobros_consistentes'=>$paymentConsistent,
            'asistencia_operacion_consistente'=>$opAttendanceConsistent,
            'asistencia_periodo_consistente'=>$periodAttendanceConsistent,
        ],
        'incidents'=>[
            'disponible'=>($incidencias['disponible']??false)===true,
            'sesiones_canceladas'=>is_array($incidencias['sesiones_canceladas']??null)?$incidencias['sesiones_canceladas']['total']??null:null,
            'incidencias_profesor'=>is_array($incidencias['profesores']??null)?$incidencias['profesores']['total']??null:null,
            'sustituciones_activas'=>is_array($incidencias['sustituciones_activas']??null)?$incidencias['sustituciones_activas']['total']??null:null,
            'cobertura'=>$incidencias['cobertura']??[],
        ],
        'corrections'=>[
            'disponible'=>($correcciones['disponible']??false)===true,
            'ediciones_pago'=>is_array($correcciones['ediciones_pago']??null)?$correcciones['ediciones_pago']['total']??null:null,
            'invalidaciones_pago'=>is_array($correcciones['invalidaciones_pago']??null)?$correcciones['invalidaciones_pago']['total']??null:null,
            'correcciones_asistencia'=>is_array($correcciones['correcciones_asistencia']??null)?$correcciones['correcciones_asistencia']['total']??null:null,
        ],
    ];
}

/** @return array<string,mixed> */
function hache_resumen_diario_operational_evidence(PDO $pdo,?DateTimeImmutable $now=null): array
{
    $started=false;
    try{
        if($pdo->inTransaction()){
            return ['present'=>false,'evidence_status'=>'TRANSACTION_ALREADY_ACTIVE','decision'=>'HUMAN_REVIEW_REQUIRED'];
        }

        $tz=new DateTimeZone('America/Cancun');
        $now=($now??new DateTimeImmutable('now',$tz))->setTimezone($tz);
        $today=$now->format('Y-m-d');
        $yesterday=$now->modify('-1 day')->format('Y-m-d');

        $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        $started=true;

        $sites=$pdo->query("SELECT id,clave FROM sedes WHERE activo=1 ORDER BY clave")->fetchAll(PDO::FETCH_ASSOC);
        $siteEvidence=[];

        foreach($sites as $site){
            $siteId=(string)$site['id'];
            $siteKey=(string)$site['clave'];
            $latest=null;

            for($offset=0;$offset<=31;$offset++){
                $day=$now->modify("-{$offset} days")->format('Y-m-d');
                $operacion=dashboard_operacion_fecha($pdo,$siteId,$day);
                $cobros=hache_resumen_diario_cobros($pdo,$siteId,$day);
                $altas=dashboard_nuevos_alumnos($pdo,$siteId,$day,$day);
                if(
                    (int)($operacion['sesiones_registradas']??0)>0
                    ||(int)($cobros['pagos']??0)>0
                    ||(int)($altas['total']??0)>0
                ){
                    $asistencia=dashboard_asistencia_periodo($pdo,$siteId,$day,$day);
                    $incidencias=hache_resumen_diario_incidencias($pdo,$siteId,$day);
                    $correcciones=hache_resumen_diario_correcciones($pdo,$siteId,$day);
                    $latest=hache_resumen_diario_evidence_compact(
                        $day,$operacion,$asistencia,$cobros,$altas,$incidencias,$correcciones
                    );
                    break;
                }
            }

            $todayPlanning=hache_resumen_diario_clases_previstas($pdo,$siteId,$today,$today);
            $todayOperation=dashboard_operacion_fecha($pdo,$siteId,$today);
            $todayPayments=hache_resumen_diario_cobros($pdo,$siteId,$today);
            $pastPlanning=hache_resumen_diario_clases_previstas($pdo,$siteId,$yesterday,$today);

            $siteEvidence[$siteKey]=[
                'latest_active_day_observed'=>$latest!==null,
                'latest_active_day'=>$latest,
                'today_empty_case'=>[
                    'observed'=>($todayPlanning['disponible']??false)===true
                        &&(int)($todayPlanning['total']??0)===0
                        &&(int)($todayOperation['sesiones_registradas']??0)===0
                        &&(int)($todayPayments['pagos']??0)===0,
                    'classes_planned_available'=>($todayPlanning['disponible']??false)===true,
                    'classes_planned_total'=>$todayPlanning['total']??null,
                    'sessions_registered'=>(int)($todayOperation['sesiones_registradas']??0),
                    'payments'=>(int)($todayPayments['pagos']??0),
                ],
                'past_incomplete_case'=>[
                    'fecha'=>$yesterday,
                    'planning_available'=>($pastPlanning['disponible']??false)===true,
                    'planning_total'=>$pastPlanning['total']??null,
                    'reason'=>$pastPlanning['motivo']??null,
                ],
            ];
        }

        $pdo->rollBack();
        $started=false;

        return [
            'present'=>true,
            'evidence_status'=>'AVAILABLE',
            'generated_for_local_date'=>$today,
            'lookback_days'=>31,
            'read_only_transaction_completed'=>true,
            'sites'=>$siteEvidence,
            'privacy'=>[
                'contains_personal_rows'=>false,
                'contains_person_names'=>false,
                'contains_payment_ids'=>false,
            ],
            'decision'=>'HUMAN_REVIEW_REQUIRED',
        ];
    }catch(Throwable $e){
        if($started&&$pdo->inTransaction())$pdo->rollBack();
        return [
            'present'=>false,
            'evidence_status'=>'COLLECTION_FAILED',
            'read_only_transaction_completed'=>false,
            'decision'=>'HUMAN_REVIEW_REQUIRED',
        ];
    }
}
