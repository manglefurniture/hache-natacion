<?php
declare(strict_types=1);

require_once __DIR__.'/centro-pendientes.php';
require_once __DIR__.'/intensive-no-continuity-alert.php';

const CENTRO_PENDIENTES_CONTINUIDAD_TIPO='INTENSIVO_SIN_CONTINUIDAD';

function centro_pendientes_continuidad_fuentes_activas(PDO $pdo,string $sedeId,string $sedeNombre): array
{
    $pendientes=[];
    foreach(hache_internal_intensive_no_continuity_candidates($pdo,$sedeId) as$candidate){
        $alumnoId=(string)$candidate['alumno_id'];
        $cursoId=(string)$candidate['curso_id'];
        $estado=$candidate['continua_regular']===null?'continuidad sin evaluar':'marcado que no continúa';
        centro_pendientes_agregar($pendientes,[
            'tipo'=>CENTRO_PENDIENTES_CONTINUIDAD_TIPO,
            'origen_tipo'=>'CURSO_INTENSIVO_CONTINUIDAD',
            'origen_id'=>(string)$candidate['relacion_id'],
            'alumno_id'=>$alumnoId,
            'alumno_nombre'=>(string)$candidate['alumno_nombre'],
            'sede_id'=>$sedeId,
            'sede_nombre'=>$sedeNombre,
            'periodo_inicio'=>null,
            'periodo_fin'=>null,
            'fecha_referencia'=>(string)$candidate['fecha_fin'],
            'explicacion'=>'El intensivo terminó el '.date('d/m/Y',strtotime((string)$candidate['fecha_fin'])).' y la relación sigue '.$estado.'.',
            'href'=>'/intensivo-detalle.php?id='.rawurlencode($cursoId),
            'causa_activa'=>true,
        ]);
    }
    return centro_pendientes_indizar($pendientes);
}

function centro_pendientes_continuidad_causa_activa(PDO $pdo,array $pendiente,string $sedeId): bool
{
    if(!centro_pendientes_mismo_alcance($pendiente,$sedeId))return false;
    if((string)($pendiente['tipo']??'')!==CENTRO_PENDIENTES_CONTINUIDAD_TIPO)return false;
    $relationId=trim((string)($pendiente['origen_id']??''));
    if($relationId==='')return false;
    foreach(hache_internal_intensive_no_continuity_candidates($pdo,$sedeId) as$candidate){
        if(hash_equals((string)$candidate['relacion_id'],$relationId))return true;
    }
    return false;
}

function centro_pendientes_continuidad_descripcion_tipo(string $tipo): ?string
{
    return $tipo===CENTRO_PENDIENTES_CONTINUIDAD_TIPO?'Intensivo terminado sin continuidad':null;
}

function centro_pendientes_continuidad_href_historico(string $tipo): ?string
{
    return $tipo===CENTRO_PENDIENTES_CONTINUIDAD_TIPO?'/intensivos.php':null;
}
