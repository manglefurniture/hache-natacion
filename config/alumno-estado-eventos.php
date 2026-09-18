<?php
declare(strict_types=1);

require_once __DIR__.'/admin-historical-corrections.php';

/**
 * Persiste, dentro de la transacción de la mutación, el evento fiable de entrada
 * o salida de BAJA. Si no puede escribirse, la mutación llamadora debe fallar y
 * hacer rollback: F6 no acepta huecos silenciosos después del inicio de cobertura.
 */
function hache_alumno_estado_evento(
    PDO $pdo,
    array $actor,
    array $alumno,
    string $sedeId,
    string $nuevoEstado,
    string $tipoHistorial,
    string $accionAuditoria,
    string $ruta='/api/alumno-gestion.php'
): void {
    $alumnoId=(string)$alumno['id'];
    $anterior=(string)$alumno['estado_administrativo'];
    $descripcion=$tipoHistorial==='BAJA'
        ?'Alumno dado de baja administrativamente.'
        :'Alumno reactivado administrativamente.';

    hache_admin_history(
        $pdo,
        $alumnoId,
        $tipoHistorial,
        $descripcion,
        (string)$actor['id'],
        'ALUMNO_ESTADO',
        $alumnoId,
    );

    $detalle=json_encode([
        'sede_id'=>$sedeId,
        'estado_anterior'=>$anterior,
        'estado_nuevo'=>$nuevoEstado,
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(!is_string($detalle))throw new RuntimeException('No se pudo serializar el cambio de estado del alumno.');

    $st=$pdo->prepare("INSERT INTO auditoria_eventos(
            usuario_id,usuario_nombre,accion,entidad,entidad_id,detalle,metodo,ruta
        ) VALUES(:uid,:un,:accion,'alumno',:aid,:detalle,'POST',:ruta)");
    $st->execute([
        ':uid'=>(string)$actor['id'],
        ':un'=>$actor['usuario']??null,
        ':accion'=>$accionAuditoria,
        ':aid'=>$alumnoId,
        ':detalle'=>$detalle,
        ':ruta'=>$ruta,
    ]);
}

/**
 * Construye la evidencia mínima de una edición administrativa de alumno.
 *
 * Los campos operativos no sensibles conservan before/after. Los campos con PII
 * solo declaran que fueron modificados para no duplicar sus valores en auditoría.
 */
function hache_alumno_edicion_detalle(array $antes,array $despues,string $sedeId): ?array
{
    $normalizar=static function(mixed $valor): ?string {
        if($valor===null)return null;
        $texto=trim((string)$valor);
        return $texto===''?null:$texto;
    };

    $cambios=[];
    foreach(['horario_preferido_id','plan_actual_id'] as $campo){
        $anterior=$normalizar($antes[$campo]??null);
        $nuevo=$normalizar($despues[$campo]??null);
        if($anterior!==$nuevo){
            $cambios[$campo]=['anterior'=>$anterior,'nuevo'=>$nuevo];
        }
    }

    foreach(['nombre','fecha_nacimiento','whatsapp','correo','observaciones'] as $campo){
        $anterior=$normalizar($antes[$campo]??null);
        $nuevo=$normalizar($despues[$campo]??null);
        if($anterior!==$nuevo){
            $cambios[$campo]=['modificado'=>true,'valores_omitidos'=>'PII'];
        }
    }

    if(!$cambios)return null;
    return ['sede_id'=>$sedeId,'cambios'=>$cambios];
}

/**
 * Persiste la edición en la misma transacción que actualiza alumnos.
 * Si esta evidencia no puede escribirse, la mutación llamadora debe hacer rollback.
 */
function hache_alumno_edicion_evento(
    PDO $pdo,
    array $actor,
    array $antes,
    array $despues,
    string $sedeId,
    string $ruta='/public/editar-alumno.php'
): void {
    $detalle=hache_alumno_edicion_detalle($antes,$despues,$sedeId);
    if($detalle===null)return;

    $json=json_encode($detalle,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(!is_string($json))throw new RuntimeException('No se pudo serializar la edición administrativa del alumno.');

    $st=$pdo->prepare("INSERT INTO auditoria_eventos(
            usuario_id,usuario_nombre,accion,entidad,entidad_id,detalle,metodo,ruta
        ) VALUES(:uid,:un,'ALUMNO_DATOS_ACTUALIZADOS','alumno',:aid,:detalle,'POST',:ruta)");
    $st->execute([
        ':uid'=>(string)$actor['id'],
        ':un'=>$actor['usuario']??null,
        ':aid'=>(string)$antes['id'],
        ':detalle'=>$json,
        ':ruta'=>$ruta,
    ]);
}

