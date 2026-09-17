<?php
declare(strict_types=1);

/**
 * Lectura pura de la operación registrada para una fecha y sede.
 *
 * No crea sesiones ni completa asistencia. Si no existen sesiones atribuibles
 * a la sede por horario, el dashboard conserva el cero de filas registradas
 * pero marca la asistencia como no disponible en vez de presentarla como cero.
 */
function dashboard_operacion_fecha(PDO $pdo,string $sedeId,string $fecha):array
{
    $st=$pdo->prepare("SELECT
            COUNT(*) total,
            COALESCE(SUM(s.estado='PROGRAMADA'),0) programadas,
            COALESCE(SUM(s.estado='REALIZADA'),0) realizadas,
            COALESCE(SUM(s.estado='CANCELADA'),0) canceladas,
            COALESCE(SUM(s.cerrada=1),0) cerradas
        FROM sesiones s
        INNER JOIN horarios h ON h.id=s.horario_id
        WHERE s.fecha=:f AND h.sede_id=:s");
    $st->execute([':f'=>$fecha,':s'=>$sedeId]);
    $sesiones=$st->fetch(PDO::FETCH_ASSOC)?:[];
    $total=(int)($sesiones['total']??0);

    $asistencia=[
        'disponible'=>$total>0,
        'marcas'=>null,
        'presentes'=>null,
        'ausencias_justificadas'=>null,
        'ausencias_no_justificadas'=>null,
        'cobertura'=>null,
    ];

    if($total>0){
        $st=$pdo->prepare("SELECT aa.estado,COUNT(*) cantidad
            FROM asistencias aa
            INNER JOIN sesiones s ON s.id=aa.sesion_id
            INNER JOIN horarios h ON h.id=s.horario_id
            WHERE s.fecha=:f AND h.sede_id=:s
            GROUP BY aa.estado");
        $st->execute([':f'=>$fecha,':s'=>$sedeId]);
        $porEstado=[
            'PRESENTE'=>0,
            'AUSENTE_JUSTIFICADA'=>0,
            'AUSENTE_NO_JUSTIFICADA'=>0,
        ];
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $fila){
            $estado=(string)($fila['estado']??'');
            if(array_key_exists($estado,$porEstado))$porEstado[$estado]=(int)$fila['cantidad'];
        }
        $asistencia=[
            'disponible'=>true,
            'marcas'=>array_sum($porEstado),
            'presentes'=>$porEstado['PRESENTE'],
            'ausencias_justificadas'=>$porEstado['AUSENTE_JUSTIFICADA'],
            'ausencias_no_justificadas'=>$porEstado['AUSENTE_NO_JUSTIFICADA'],
            // F6 no publica porcentaje hasta existir un denominador/coverage contract estable.
            'cobertura'=>null,
        ];
    }

    return [
        'fecha'=>$fecha,
        'sesiones_registradas'=>$total,
        'programadas'=>(int)($sesiones['programadas']??0),
        'realizadas'=>(int)($sesiones['realizadas']??0),
        'canceladas'=>(int)($sesiones['canceladas']??0),
        'cerradas'=>(int)($sesiones['cerradas']??0),
        'asistencia'=>$asistencia,
        'alcance'=>'Solo sesiones ya registradas y atribuibles a la sede por horario; esta lectura no genera sesiones.',
    ];
}
