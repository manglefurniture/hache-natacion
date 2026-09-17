<?php
declare(strict_types=1);

/**
 * Fuente financiera compartida F2/F5 para saldo de intensivo.
 * Solo suma pagos VALIDOS del mismo alumno + curso y no escribe nada.
 *
 * @return list<array{curso_id:string,alumno_id:string,alumno_nombre:string,fecha_inicio:string,fecha_fin:string,importe_total:float,importe_pagado:float,saldo:float}>
 */
function hache_intensive_pending_balance_candidates(PDO $pdo,string $sedeId,?string $cursoId=null,?string $alumnoId=null): array
{
    $scope='';
    $params=[':sede'=>$sedeId];
    if($cursoId!==null||$alumnoId!==null){
        $cursoId=trim((string)$cursoId);$alumnoId=trim((string)$alumnoId);
        if($cursoId===''||$alumnoId==='')return [];
        $scope=' AND ci.id=:curso AND cia.alumno_id=:alumno';
        $params[':curso']=$cursoId;$params[':alumno']=$alumnoId;
    }
    $sql="SELECT ci.id curso_id,cia.alumno_id,a.nombre alumno_nombre,ci.fecha_inicio,ci.fecha_fin,ci.precio,
                 COALESCE(SUM(CASE WHEN p.estado='VALIDO' THEN p.importe ELSE 0 END),0) pagado_valido
          FROM curso_intensivo_alumnos cia
          INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id
          INNER JOIN alumnos a ON a.id=cia.alumno_id
          LEFT JOIN pagos p ON p.intensivo_id=ci.id AND p.alumno_id=cia.alumno_id AND p.tipo='INTENSIVO'
          WHERE ci.sede_id=:sede
            AND ci.estado IN ('PROGRAMADO','EN_CURSO','TERMINADO'){$scope}
          GROUP BY ci.id,cia.alumno_id,a.nombre,ci.fecha_inicio,ci.fecha_fin,ci.precio
          HAVING COALESCE(SUM(CASE WHEN p.estado='VALIDO' THEN p.importe ELSE 0 END),0)+0.009<ci.precio
          ORDER BY ci.fecha_inicio,a.nombre,ci.id,cia.alumno_id";
    $st=$pdo->prepare($sql);$st->execute($params);
    $out=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as$row){
        $total=round((float)$row['precio'],2);
        $pagado=round((float)$row['pagado_valido'],2);
        $out[]=[
            'curso_id'=>(string)$row['curso_id'],
            'alumno_id'=>(string)$row['alumno_id'],
            'alumno_nombre'=>(string)$row['alumno_nombre'],
            'fecha_inicio'=>(string)$row['fecha_inicio'],
            'fecha_fin'=>(string)$row['fecha_fin'],
            'importe_total'=>$total,
            'importe_pagado'=>$pagado,
            'saldo'=>max(0.0,round($total-$pagado,2)),
        ];
    }
    return $out;
}

/** @return array{total:int,alumnos:int,saldo:float} */
function hache_intensive_pending_balance_summary(array $candidates): array
{
    $saldo=0.0;$students=[];
    foreach($candidates as$candidate){
        $saldo+=max(0.0,(float)($candidate['saldo']??0));
        $studentId=trim((string)($candidate['alumno_id']??''));
        if($studentId!=='')$students[$studentId]=true;
    }
    return ['total'=>count($candidates),'alumnos'=>count($students),'saldo'=>round($saldo,2)];
}
