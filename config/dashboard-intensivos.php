<?php
declare(strict_types=1);

/**
 * Contrato histórico de "Intensivos activos" del dashboard F6.
 *
 * Conserva exactamente la semántica previa: cursos de la sede cuyo estado
 * registrado es PROGRAMADO o EN_CURSO. No reconcilia estados ni ejecuta
 * escrituras; el detalle y el total salen de la misma consulta.
 */
function dashboard_intensivos_activos(PDO $pdo,string $sedeId):array
{
    $st=$pdo->prepare("SELECT id,fecha_inicio,fecha_fin,estado
        FROM cursos_intensivos
        WHERE sede_id=:s
          AND estado IN ('PROGRAMADO','EN_CURSO')
        ORDER BY fecha_inicio,id");
    $st->execute([':s'=>$sedeId]);

    $rows=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
        $rows[]=[
            'id'=>(string)$row['id'],
            'fecha_inicio'=>(string)$row['fecha_inicio'],
            'fecha_fin'=>(string)$row['fecha_fin'],
            'estado'=>(string)$row['estado'],
        ];
    }

    return [
        'total'=>count($rows),
        'rows'=>$rows,
        'unidad'=>'cursos',
        'fuente'=>'cursos_intensivos.estado',
    ];
}
