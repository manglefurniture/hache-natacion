<?php
declare(strict_types=1);

/**
 * Contrato histórico de "alumnos activos" del dashboard F6.
 *
 * Un alumno cuenta una sola vez si tiene mensualidad PAGADA vigente o participa
 * en un intensivo vigente con al menos un pago VALIDO. BAJA queda excluido.
 * Esta definición no equivale al estado administrativo ACTIVO ni al derecho de acceso.
 */
function dashboard_alumnos_activos(PDO $pdo,string $sedeId,string $fecha):array
{
    $sql="SELECT x.alumno_id,x.nombre,GROUP_CONCAT(DISTINCT x.fuente) fuentes
        FROM (
            SELECT a.id alumno_id,a.nombre,'MENSUALIDAD' fuente
            FROM mensualidades m
            INNER JOIN alumnos a ON a.id=m.alumno_id AND a.sede_id=m.sede_id
            WHERE m.sede_id=:sm
              AND m.estado='PAGADA'
              AND :fecha_m BETWEEN m.periodo_inicio AND m.periodo_fin
              AND a.estado_administrativo<>'BAJA'
            UNION ALL
            SELECT a.id alumno_id,a.nombre,'INTENSIVO' fuente
            FROM curso_intensivo_alumnos cia
            INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id
            INNER JOIN alumnos a ON a.id=cia.alumno_id AND a.sede_id=ci.sede_id
            WHERE ci.sede_id=:si
              AND :fecha_i BETWEEN ci.fecha_inicio AND ci.fecha_fin
              AND a.estado_administrativo<>'BAJA'
              AND EXISTS(
                  SELECT 1 FROM pagos p
                  WHERE p.alumno_id=cia.alumno_id
                    AND p.intensivo_id=ci.id
                    AND p.tipo='INTENSIVO'
                    AND p.estado='VALIDO'
              )
        ) x
        GROUP BY x.alumno_id,x.nombre
        ORDER BY x.nombre,x.alumno_id";
    $st=$pdo->prepare($sql);
    $st->execute([
        ':sm'=>$sedeId,
        ':fecha_m'=>$fecha,
        ':si'=>$sedeId,
        ':fecha_i'=>$fecha,
    ]);

    $rows=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
        $fuentes=array_values(array_filter(array_map('trim',explode(',',(string)($row['fuentes']??'')))));
        sort($fuentes,SORT_STRING);
        $rows[]=[
            'alumno_id'=>(string)$row['alumno_id'],
            'nombre'=>(string)$row['nombre'],
            'fuentes'=>$fuentes,
        ];
    }

    return [
        'total'=>count($rows),
        'rows'=>$rows,
        'fecha'=>$fecha,
        'unidad'=>'alumnos_unicos',
        'fuente'=>'mensualidades + cursos_intensivos + pagos VALIDOS',
    ];
}
