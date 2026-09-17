<?php
declare(strict_types=1);

require_once __DIR__.'/dashboard-tiempo.php';
require_once __DIR__.'/internal-alert-settings.php';

function hache_internal_intensive_no_continuity_enabled(array $settings): bool
{
    return isset($settings['intensive_no_continuity_days'])
        && is_int($settings['intensive_no_continuity_days'])
        && in_array($settings['intensive_no_continuity_scope']??null,['SIN_EVALUAR','SIN_EVALUAR_O_NO'],true);
}

function hache_internal_intensive_no_continuity_due(string $fechaFin,int $days,?DateTimeImmutable $now=null): bool
{
    if($days<0)return false;
    $end=DateTimeImmutable::createFromFormat('!Y-m-d',trim($fechaFin),new DateTimeZone('America/Cancun'));
    if(!$end||$end->format('Y-m-d')!==trim($fechaFin))return false;
    $today=hache_instante_operativo($now)->setTime(0,0,0);
    if($today<=$end)return false;
    return $today>=$end->modify('+'.$days.' days');
}

function hache_internal_intensive_no_continuity_matches(?int $continuaRegular,string $scope): bool
{
    if($scope==='SIN_EVALUAR')return $continuaRegular===null;
    if($scope==='SIN_EVALUAR_O_NO')return $continuaRegular===null||$continuaRegular===0;
    return false;
}

/**
 * Regla F5 de solo lectura. La relación curso/alumno es la identidad estable.
 * No reconcilia estados del curso ni escribe continuidad; usa fecha_fin como
 * evidencia temporal y excluye únicamente cursos cancelados.
 *
 * @return list<array{relacion_id:string,curso_id:string,alumno_id:string,alumno_nombre:string,fecha_fin:string,continua_regular:?int,dias_configurados:int,alcance:string}>
 */
function hache_internal_intensive_no_continuity_candidates(PDO $pdo,string $sedeId,?DateTimeImmutable $now=null,?array $settings=null,?string $alumnoId=null): array
{
    $settings=$settings??hache_internal_alert_settings($pdo);
    if(!hache_internal_intensive_no_continuity_enabled($settings))return [];
    $days=(int)$settings['intensive_no_continuity_days'];
    $scope=(string)$settings['intensive_no_continuity_scope'];
    $today=hache_instante_operativo($now)->format('Y-m-d');
    $studentWhere='';$params=[':sede'=>$sedeId,':hoy'=>$today];
    if($alumnoId!==null&&trim($alumnoId)!==''){$studentWhere=' AND cia.alumno_id=:alumno';$params[':alumno']=trim($alumnoId);}

    $sql="SELECT cia.id relacion_id,cia.curso_intensivo_id curso_id,cia.alumno_id,a.nombre alumno_nombre,
                 ci.fecha_fin,cia.continua_regular
          FROM curso_intensivo_alumnos cia
          INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id
          INNER JOIN alumnos a ON a.id=cia.alumno_id
          WHERE ci.sede_id=:sede
            AND ci.estado<>'CANCELADO'
            AND ci.fecha_fin<:hoy{$studentWhere}
          ORDER BY ci.fecha_fin,ci.id,a.nombre,cia.id";
    $st=$pdo->prepare($sql);$st->execute($params);
    $out=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as$row){
        $continua=$row['continua_regular']===null?null:(int)$row['continua_regular'];
        if(!hache_internal_intensive_no_continuity_matches($continua,$scope))continue;
        if(!hache_internal_intensive_no_continuity_due((string)$row['fecha_fin'],$days,$now))continue;
        $out[]=[
            'relacion_id'=>(string)$row['relacion_id'],
            'curso_id'=>(string)$row['curso_id'],
            'alumno_id'=>(string)$row['alumno_id'],
            'alumno_nombre'=>(string)$row['alumno_nombre'],
            'fecha_fin'=>(string)$row['fecha_fin'],
            'continua_regular'=>$continua,
            'dias_configurados'=>$days,
            'alcance'=>$scope,
        ];
    }
    return $out;
}

function hache_internal_intensive_no_continuity_candidate(PDO $pdo,string $sedeId,string $alumnoId,?DateTimeImmutable $now=null,?array $settings=null): ?array
{
    $rows=hache_internal_intensive_no_continuity_candidates($pdo,$sedeId,$now,$settings,$alumnoId);
    return $rows[0]??null;
}
