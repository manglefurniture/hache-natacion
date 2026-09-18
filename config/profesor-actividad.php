<?php

declare(strict_types=1);

require_once __DIR__.'/profesores-asignaciones.php';
require_once __DIR__.'/profesor-sustituciones.php';

function hache_profesor_actividad_schema_ready(PDO $pdo): bool
{
    if(!hache_profesores_vigencias_schema_ready($pdo)||!hache_profesor_sustituciones_schema_ready($pdo))return false;
    try{
        $required=['profesores','profesor_cancelaciones','sesiones','horarios','sedes'];
        $marks=implode(',',array_fill(0,count($required),'?'));
        $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ($marks)");
        $st->execute($required);
        return (int)$st->fetchColumn()===count($required);
    }catch(Throwable $e){return false;}
}

function hache_profesor_actividad_duracion_minutos(string $inicio,string $fin): int
{
    $parse=static function(string $value): ?int {
        $parts=explode(':',trim($value));
        if(count($parts)<2||count($parts)>3)return null;
        $h=(int)$parts[0];$m=(int)$parts[1];$s=(int)($parts[2]??0);
        if($h<0||$h>23||$m<0||$m>59||$s<0||$s>59)return null;
        return $h*3600+$m*60+$s;
    };
    $a=$parse($inicio);$b=$parse($fin);
    if($a===null||$b===null||$b<=$a)return 0;
    return (int)floor(($b-$a)/60);
}

/** @return array<string,mixed> */
function hache_profesor_actividad_contexto(PDO $pdo,string $desde,string $hasta,?string $profesorId=null): array
{
    $profesorId=$profesorId!==null?trim($profesorId):null;
    $coverageAssignments=(string)$pdo->query("SELECT valor FROM configuracion WHERE clave='profesores_asignaciones_cobertura_desde' LIMIT 1")->fetchColumn();
    $coverageSubstitutions=(string)$pdo->query("SELECT valor FROM configuracion WHERE clave='profesores_sustituciones_cobertura_desde' LIMIT 1")->fetchColumn();

    if($profesorId!==null&&$profesorId!==''){
        $st=$pdo->prepare('SELECT id,nombre,activo FROM profesores WHERE id=:id LIMIT 1');
        $st->execute([':id'=>$profesorId]);
    }else{
        $st=$pdo->query('SELECT id,nombre,activo FROM profesores ORDER BY activo DESC,nombre');
    }
    $profesores=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
        $id=(string)$row['id'];
        $profesores[$id]=[
            'id'=>$id,
            'nombre'=>(string)$row['nombre'],
            'activo'=>(int)$row['activo'],
        ];
    }

    $sessionsSt=$pdo->prepare("SELECT se.id,se.fecha,se.estado,se.cerrada,h.id horario_id,h.hora_inicio,h.hora_fin,
        s.id sede_id,s.clave sede_clave,s.nombre sede_nombre
        FROM sesiones se
        JOIN horarios h ON h.id=se.horario_id
        JOIN sedes s ON s.id=h.sede_id
        WHERE se.fecha BETWEEN :d AND :h
        ORDER BY se.fecha,h.hora_inicio,s.nombre,se.id");
    $sessionsSt->execute([':d'=>$desde,':h'=>$hasta]);
    $sesiones=[];
    foreach($sessionsSt->fetchAll(PDO::FETCH_ASSOC) as $row){
        $row['duracion_minutos']=hache_profesor_actividad_duracion_minutos((string)$row['hora_inicio'],(string)$row['hora_fin']);
        $sesiones[(string)$row['id']]=$row;
    }

    $assigned=[];
    $assignmentSt=$pdo->prepare("SELECT se.id sesion_id,se.fecha,h.hora_inicio,ph.profesor_id,v.vigente_desde,v.vigente_hasta
        FROM sesiones se
        JOIN horarios h ON h.id=se.horario_id
        JOIN profesor_horarios ph ON ph.horario_id=h.id
        JOIN profesor_horario_vigencias v ON v.profesor_horario_id=ph.id
        WHERE se.fecha BETWEEN :d AND :h
        ORDER BY se.fecha,h.hora_inicio,ph.profesor_id");
    $assignmentSt->execute([':d'=>$desde,':h'=>$hasta]);
    foreach($assignmentSt->fetchAll(PDO::FETCH_ASSOC) as $row){
        $sessionId=(string)$row['sesion_id'];$teacherId=(string)$row['profesor_id'];
        if(!isset($sesiones[$sessionId]))continue;
        $startUtc=hache_profesor_sesion_inicio_utc((string)$row['fecha'],(string)$row['hora_inicio']);
        if((string)$row['vigente_desde']>$startUtc)continue;
        if($row['vigente_hasta']!==null&&(string)$row['vigente_hasta']<$startUtc)continue;
        $assigned[$sessionId][$teacherId]=true;
    }

    $cancellations=[];
    $cancelSt=$pdo->prepare("SELECT pc.id,pc.profesor_id,pc.sesion_id,pc.motivo,pc.source,pc.created_at
        FROM profesor_cancelaciones pc
        JOIN sesiones se ON se.id=pc.sesion_id
        WHERE se.fecha BETWEEN :d AND :h
        ORDER BY se.fecha,pc.created_at,pc.id");
    $cancelSt->execute([':d'=>$desde,':h'=>$hasta]);
    foreach($cancelSt->fetchAll(PDO::FETCH_ASSOC) as $row){
        $cancellations[(string)$row['sesion_id']][(string)$row['profesor_id']]=$row;
    }

    $substitutions=[];
    $subSt=$pdo->prepare("SELECT ps.id,ps.sesion_id,ps.profesor_original_id,po.nombre profesor_original_nombre,
        ps.profesor_sustituto_id,pr.nombre profesor_sustituto_nombre,ps.motivo,ps.origen,ps.estado,
        ps.created_at,uc.usuario created_by_usuario,ps.anulada_at,ua.usuario anulada_by_usuario,ps.motivo_anulacion
        FROM profesor_sustituciones ps
        JOIN sesiones se ON se.id=ps.sesion_id
        JOIN profesores po ON po.id=ps.profesor_original_id
        JOIN profesores pr ON pr.id=ps.profesor_sustituto_id
        LEFT JOIN usuarios uc ON uc.id=ps.created_by
        LEFT JOIN usuarios ua ON ua.id=ps.anulada_by
        WHERE se.fecha BETWEEN :d AND :h
        ORDER BY se.fecha,ps.created_at,ps.id");
    $subSt->execute([':d'=>$desde,':h'=>$hasta]);
    foreach($subSt->fetchAll(PDO::FETCH_ASSOC) as $row){
        $sid=(string)$row['sesion_id'];
        $original=(string)$row['profesor_original_id'];
        $replacement=(string)$row['profesor_sustituto_id'];
        $base=[
            'id'=>(string)$row['id'],
            'profesor_original_id'=>$original,
            'profesor_original_nombre'=>(string)$row['profesor_original_nombre'],
            'profesor_sustituto_id'=>$replacement,
            'profesor_sustituto_nombre'=>(string)$row['profesor_sustituto_nombre'],
            'motivo'=>(string)$row['motivo'],
            'origen'=>(string)$row['origen'],
            'estado'=>(string)$row['estado'],
            'created_at'=>(string)$row['created_at'],
            'created_by_usuario'=>$row['created_by_usuario']!==null?(string)$row['created_by_usuario']:null,
            'anulada_at'=>$row['anulada_at']!==null?(string)$row['anulada_at']:null,
            'anulada_by_usuario'=>$row['anulada_by_usuario']!==null?(string)$row['anulada_by_usuario']:null,
            'motivo_anulacion'=>$row['motivo_anulacion']!==null?(string)$row['motivo_anulacion']:null,
        ];
        $substitutions[$sid][$original][]=array_merge($base,['rol'=>'ORIGINAL']);
        $substitutions[$sid][$replacement][]=array_merge($base,['rol'=>'SUSTITUTO']);
    }

    $resultado=[];
    foreach($profesores as $teacherId=>$profesor){
        $plannedSessions=0;$plannedMinutes=0;$plannedCancelled=0;
        $confirmedSessions=0;$confirmedMinutes=0;$pendingAttribution=0;$confirmedNotTaught=0;$incidentCount=0;
        $activeAsOriginal=0;$activeAsSubstitute=0;$events=[];

        foreach($sesiones as $sessionId=>$session){
            $isAssigned=isset($assigned[$sessionId][$teacherId]);
            $incident=$cancellations[$sessionId][$teacherId]??null;
            $teacherSubs=$substitutions[$sessionId][$teacherId]??[];
            if(!$isAssigned&&$incident===null&&!$teacherSubs)continue;

            $activeOriginal=null;$activeSubstitute=null;
            foreach($teacherSubs as $sub){
                if(($sub['estado']??'')!=='ACTIVA')continue;
                if(($sub['rol']??'')==='ORIGINAL')$activeOriginal=$sub;
                if(($sub['rol']??'')==='SUSTITUTO')$activeSubstitute=$sub;
            }

            $duration=(int)$session['duracion_minutos'];
            if($isAssigned){
                $plannedSessions++;$plannedMinutes+=$duration;
                if((string)$session['estado']==='CANCELADA')$plannedCancelled++;
            }
            if($incident!==null)$incidentCount++;
            if($activeOriginal!==null)$activeAsOriginal++;
            if($activeSubstitute!==null)$activeAsSubstitute++;

            $confirmed=null;$confirmationSource=null;$attributionNote=null;
            if((string)$session['estado']==='CANCELADA'){
                $confirmed=false;$confirmationSource='SESION_CANCELADA';
            }elseif($incident!==null){
                $confirmed=false;$confirmationSource='PROFESOR_CANCELACION';
            }elseif($activeOriginal!==null){
                $confirmed=false;$confirmationSource='SUSTITUCION_EXPLICITA';
            }elseif((string)$session['estado']==='REALIZADA'&&$activeSubstitute!==null){
                $confirmed=true;$confirmationSource='SUSTITUCION_EXPLICITA+SESION_REALIZADA';
            }elseif((string)$session['estado']==='REALIZADA'&&$isAssigned){
                $attributionNote='La sesión fue realizada y la asignación estaba vigente, pero no existe una marca positiva de asistencia del profesor; no se acredita como carga realizada.';
            }

            if($confirmed===true){$confirmedSessions++;$confirmedMinutes+=$duration;}
            elseif($confirmed===false)$confirmedNotTaught++;
            elseif((string)$session['estado']==='REALIZADA'&&$isAssigned)$pendingAttribution++;

            $sources=['SESION'];
            if($isAssigned)$sources[]='ASIGNACION_DURABLE';
            if($incident!==null)$sources[]='PROFESOR_CANCELACION';
            if($teacherSubs)$sources[]='SUSTITUCION_EXPLICITA';

            $events[]=[
                'sesion_id'=>$sessionId,
                'fecha'=>(string)$session['fecha'],
                'estado'=>(string)$session['estado'],
                'cerrada'=>(int)$session['cerrada'],
                'horario_id'=>(string)$session['horario_id'],
                'hora_inicio'=>(string)$session['hora_inicio'],
                'hora_fin'=>(string)$session['hora_fin'],
                'duracion_minutos'=>$duration,
                'sede_id'=>(string)$session['sede_id'],
                'sede_clave'=>(string)$session['sede_clave'],
                'sede_nombre'=>(string)$session['sede_nombre'],
                'asignacion_prevista'=>$isAssigned,
                'docencia_compartida'=>count($assigned[$sessionId]??[])>1,
                'incidencia'=>$incident===null?null:[
                    'id'=>(string)$incident['id'],
                    'tipo'=>'NO_DISPONIBILIDAD',
                    'motivo'=>(string)$incident['motivo'],
                    'origen'=>(string)$incident['source'],
                    'created_at'=>(string)$incident['created_at'],
                ],
                'sustituciones'=>$teacherSubs,
                'imparticion_confirmada'=>$confirmed,
                'fuente_imparticion'=>$confirmationSource,
                'nota_atribucion'=>$attributionNote,
                'fuentes'=>$sources,
            ];
        }

        $resultado[]=array_merge($profesor,[
            'periodo'=>['desde'=>$desde,'hasta'=>$hasta],
            'carga_prevista'=>[
                'sesiones'=>$plannedSessions,
                'minutos'=>$plannedMinutes,
                'horas'=>round($plannedMinutes/60,2),
                'canceladas'=>$plannedCancelled,
                'fuente'=>'ASIGNACION_DURABLE+SESIONES',
            ],
            'carga_realizada'=>[
                'sesiones_confirmadas'=>$confirmedSessions,
                'minutos_confirmados'=>$confirmedMinutes,
                'horas_confirmadas'=>round($confirmedMinutes/60,2),
                'sesiones_realizadas_sin_atribucion'=>$pendingAttribution,
                'sesiones_confirmadas_no_impartidas'=>$confirmedNotTaught,
                'fuente_confirmada'=>'SUSTITUCION_EXPLICITA+SESION_REALIZADA',
                'limitacion'=>'Una sesión REALIZADA con asignación durable no acredita por sí sola qué profesor la impartió. Solo se confirma carga realizada cuando existe evidencia explícita disponible.',
            ],
            'incidencias'=>$incidentCount,
            'sustituciones_activas'=>[
                'como_original'=>$activeAsOriginal,
                'como_sustituto'=>$activeAsSubstitute,
            ],
            'sesiones'=>$events,
        ]);
    }

    return [
        'periodo'=>['desde'=>$desde,'hasta'=>$hasta],
        'cobertura'=>[
            'asignaciones_desde_utc'=>$coverageAssignments,
            'sustituciones_desde_utc'=>$coverageSubstitutions,
            'historia_previa_reconstruida'=>false,
        ],
        'profesores'=>$resultado,
    ];
}
