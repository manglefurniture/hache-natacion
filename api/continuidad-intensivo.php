<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/reglas-acceso.php';
$config=require __DIR__.'/../config/database.php';

function continuidad_out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;}

try{
    $admin=auth_require(['ADMIN']);
    $pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    $sedeClave=auth_resolve_sede_clave($method==='GET'?(string)($_GET['sede']??''):null);
    $st=$pdo->prepare("SELECT id,clave,nombre FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");$st->execute([':c'=>$sedeClave]);$sede=$st->fetch();if(!$sede)continuidad_out(['ok'=>false,'error'=>'Sede inválida'],422);

    if($method==='GET'){
        $cursoId=trim((string)($_GET['curso_id']??''));$alumnoId=trim((string)($_GET['alumno_id']??''));
        if($cursoId===''||$alumnoId==='')continuidad_out(['ok'=>false,'error'=>'curso_id y alumno_id son obligatorios'],422);
        $st=$pdo->prepare("SELECT cia.id,cia.curso_intensivo_id,cia.alumno_id,a.nombre alumno_nombre,cia.continua_regular,cia.plan_continuidad_id,cia.importe_continuidad,cia.observacion_continuidad,a.plan_actual_id,a.horario_preferido_id,a.ciclo_pago,s.clave sede_clave FROM curso_intensivo_alumnos cia INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id INNER JOIN alumnos a ON a.id=cia.alumno_id AND a.sede_id=ci.sede_id INNER JOIN sedes s ON s.id=ci.sede_id WHERE cia.curso_intensivo_id=:c AND cia.alumno_id=:a AND ci.sede_id=:s LIMIT 1");
        $st->execute([':c'=>$cursoId,':a'=>$alumnoId,':s'=>$sede['id']]);$rel=$st->fetch();if(!$rel)continuidad_out(['ok'=>false,'error'=>'El alumno no pertenece a este intensivo de la sede activa'],404);
        $st=$pdo->prepare("SELECT id,nombre,sesiones_semana,precio FROM planes WHERE sede_id=:s AND activo=1 ORDER BY sesiones_semana,precio");$st->execute([':s'=>$sede['id']]);$planes=$st->fetchAll();
        $st=$pdo->prepare("SELECT id,hora_inicio,hora_fin FROM horarios WHERE sede_id=:s AND activo=1 AND regular=1 ORDER BY hora_inicio");$st->execute([':s'=>$sede['id']]);$horarios=$st->fetchAll();

        $cicloRel=$rel['ciclo_pago']!==null?strtoupper((string)$rel['ciclo_pago']):null;
        $periodoActual=regla_periodo_regular_actual($sedeClave,$cicloRel);
        $referenciaProxima=(new DateTimeImmutable($periodoActual['fin']))->modify('+1 day');
        $periodoProximo=regla_periodo_regular_actual($sedeClave,$cicloRel,$referenciaProxima);
        $st=$pdo->prepare("SELECT periodo_inicio,periodo_fin FROM mensualidades WHERE alumno_id=:a AND sede_id=:s AND ((periodo_inicio=:ai AND periodo_fin=:af) OR (periodo_inicio=:pi AND periodo_fin=:pf))");
        $st->execute([':a'=>$alumnoId,':s'=>$sede['id'],':ai'=>$periodoActual['inicio'],':af'=>$periodoActual['fin'],':pi'=>$periodoProximo['inicio'],':pf'=>$periodoProximo['fin']]);
        $tieneActual=false;$tieneProximo=false;
        foreach($st->fetchAll() as $m){
            if($m['periodo_inicio']===$periodoActual['inicio']&&$m['periodo_fin']===$periodoActual['fin'])$tieneActual=true;
            if($m['periodo_inicio']===$periodoProximo['inicio']&&$m['periodo_fin']===$periodoProximo['fin'])$tieneProximo=true;
        }
        $inicioRegular=$tieneProximo&&!$tieneActual?'PROXIMO':'ACTUAL';
        continuidad_out(['ok'=>true,'sede'=>$sede,'requiere_ciclo_pago'=>$sedeClave==='PALAPAS','relacion'=>$rel,'planes'=>$planes,'horarios'=>$horarios,'inicio_regular'=>$inicioRegular,'periodo_actual'=>$periodoActual,'periodo_proximo'=>$periodoProximo]);
    }

    if($method!=='POST')continuidad_out(['ok'=>false,'error'=>'Método no permitido'],405);
    $in=json_decode(file_get_contents('php://input'),true);if(!is_array($in))continuidad_out(['ok'=>false,'error'=>'JSON inválido'],400);
    $cursoId=trim((string)($in['curso_id']??''));$alumnoId=trim((string)($in['alumno_id']??''));$continua=filter_var($in['continua_regular']??null,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);$planId=trim((string)($in['plan_id']??''));$horarioId=trim((string)($in['horario_id']??''));$importe=$in['importe_continuidad']??null;$observacion=trim((string)($in['observacion_continuidad']??''));$ciclo=strtoupper(trim((string)($in['ciclo_pago']??'')));$inicioRegular=strtoupper(trim((string)($in['inicio_regular']??'ACTUAL')));
    if($cursoId===''||$alumnoId===''||$continua===null)continuidad_out(['ok'=>false,'error'=>'Datos de continuidad incompletos'],422);
    if(mb_strlen($observacion)>2000)continuidad_out(['ok'=>false,'error'=>'La observación no puede exceder 2000 caracteres'],422);
    if($importe!==null&&$importe!==''&&(!is_numeric($importe)||(float)$importe<=0||(float)$importe>1000000))continuidad_out(['ok'=>false,'error'=>'El importe de continuidad debe ser mayor a cero'],422);
    if($continua&&!in_array($inicioRegular,['ACTUAL','PROXIMO'],true))continuidad_out(['ok'=>false,'error'=>'Selecciona cuándo inicia la continuidad regular'],422);
    $pdo->beginTransaction();
    $st=$pdo->prepare("SELECT cia.id FROM curso_intensivo_alumnos cia INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id INNER JOIN alumnos a ON a.id=cia.alumno_id AND a.sede_id=ci.sede_id WHERE cia.curso_intensivo_id=:c AND cia.alumno_id=:a AND ci.sede_id=:s LIMIT 1 FOR UPDATE");$st->execute([':c'=>$cursoId,':a'=>$alumnoId,':s'=>$sede['id']]);$relId=$st->fetchColumn();if(!$relId){$pdo->rollBack();continuidad_out(['ok'=>false,'error'=>'El alumno no pertenece a este intensivo de la sede activa'],404);}

    if(!$continua){$st=$pdo->prepare("UPDATE curso_intensivo_alumnos SET continua_regular=0,plan_continuidad_id=NULL,importe_continuidad=NULL,observacion_continuidad=:o WHERE id=:id");$st->execute([':o'=>$observacion!==''?$observacion:null,':id'=>$relId]);regla_recalcular_alumno($pdo,$alumnoId);$pdo->commit();continuidad_out(['ok'=>true,'mensaje'=>'Continuidad marcada como no']);}
    if($planId===''||$horarioId===''){$pdo->rollBack();continuidad_out(['ok'=>false,'error'=>'Selecciona plan y horario regular'],422);}
    if($sedeClave==='PALAPAS'&&!in_array($ciclo,['P1','P15'],true)){$pdo->rollBack();continuidad_out(['ok'=>false,'error'=>'Para continuar como regular en Palapas selecciona P1 o P15'],422);}
    if($sedeClave!=='PALAPAS')$ciclo='';
    $st=$pdo->prepare("SELECT id,precio FROM planes WHERE id=:id AND sede_id=:s AND activo=1 LIMIT 1 FOR UPDATE");$st->execute([':id'=>$planId,':s'=>$sede['id']]);$plan=$st->fetch();if(!$plan){$pdo->rollBack();continuidad_out(['ok'=>false,'error'=>'El plan seleccionado no pertenece a la sede activa'],422);}
    $st=$pdo->prepare("SELECT id FROM horarios WHERE id=:id AND sede_id=:s AND activo=1 AND regular=1 LIMIT 1 FOR UPDATE");$st->execute([':id'=>$horarioId,':s'=>$sede['id']]);if(!$st->fetch()){$pdo->rollBack();continuidad_out(['ok'=>false,'error'=>'El horario seleccionado no pertenece a la sede activa'],422);}
    $importeFinal=($importe!==null&&$importe!==''&&is_numeric($importe))?number_format((float)$importe,2,'.',''):number_format((float)$plan['precio'],2,'.','');
    $importeEstandar=number_format((float)$plan['precio'],2,'.','');

    $periodoActual=regla_periodo_regular_actual($sedeClave,$ciclo!==''?$ciclo:null);
    $referenciaProxima=(new DateTimeImmutable($periodoActual['fin']))->modify('+1 day');
    $periodoProximo=regla_periodo_regular_actual($sedeClave,$ciclo!==''?$ciclo:null,$referenciaProxima);
    $periodoContinuidad=$inicioRegular==='PROXIMO'?$periodoProximo:$periodoActual;
    $periodoAlterno=$inicioRegular==='PROXIMO'?$periodoActual:$periodoProximo;
    $referenciaContinuidad=new DateTimeImmutable($periodoContinuidad['inicio']);

    $st=$pdo->prepare("UPDATE curso_intensivo_alumnos SET continua_regular=1,plan_continuidad_id=:p,importe_continuidad=:i,observacion_continuidad=:o WHERE id=:id");$st->execute([':p'=>$planId,':i'=>$importeFinal,':o'=>$observacion!==''?$observacion:null,':id'=>$relId]);
    $st=$pdo->prepare("UPDATE alumnos SET plan_actual_id=:p,horario_preferido_id=:h,ciclo_pago=:c,estado_administrativo='PENDIENTE',updated_at=NOW() WHERE id=:a AND sede_id=:s");$st->execute([':p'=>$planId,':h'=>$horarioId,':c'=>$ciclo!==''?$ciclo:null,':a'=>$alumnoId,':s'=>$sede['id']]);

    $st=$pdo->prepare("DELETE m FROM mensualidades m WHERE m.alumno_id=:a AND m.sede_id=:s AND m.mes=:m AND m.anio=:y AND m.estado='PENDIENTE' AND m.importe_cobrado IS NULL AND NOT EXISTS (SELECT 1 FROM pagos p WHERE p.mensualidad_id=m.id)");
    $st->execute([':a'=>$alumnoId,':s'=>$sede['id'],':m'=>$periodoAlterno['mes'],':y'=>$periodoAlterno['anio']]);

    $st=$pdo->prepare("SELECT m.id,m.estado,m.importe_cobrado,EXISTS(SELECT 1 FROM pagos p WHERE p.mensualidad_id=m.id) tiene_pagos FROM mensualidades m WHERE m.alumno_id=:a AND m.sede_id=:s AND m.mes=:m AND m.anio=:y LIMIT 1 FOR UPDATE");
    $st->execute([':a'=>$alumnoId,':s'=>$sede['id'],':m'=>$periodoContinuidad['mes'],':y'=>$periodoContinuidad['anio']]);
    $mensualidadObjetivo=$st->fetch()?:null;

    if($mensualidadObjetivo&&($mensualidadObjetivo['estado']!=='PENDIENTE'||$mensualidadObjetivo['importe_cobrado']!==null||(int)$mensualidadObjetivo['tiene_pagos']===1)){
        $pdo->rollBack();
        continuidad_out(['ok'=>false,'error'=>'La mensualidad de ese periodo ya tiene historial financiero y no puede modificarse desde Continuidad'],409);
    }

    if(!$mensualidadObjetivo){
        regla_crear_mensualidad_pendiente($pdo,$alumnoId,(string)$sede['id'],$sedeClave,$ciclo!==''?$ciclo:null,$planId,(float)$plan['precio'],(string)$admin['id'],$referenciaContinuidad);
        $st=$pdo->prepare("SELECT m.id,m.estado,m.importe_cobrado,EXISTS(SELECT 1 FROM pagos p WHERE p.mensualidad_id=m.id) tiene_pagos FROM mensualidades m WHERE m.alumno_id=:a AND m.sede_id=:s AND m.mes=:m AND m.anio=:y LIMIT 1 FOR UPDATE");
        $st->execute([':a'=>$alumnoId,':s'=>$sede['id'],':m'=>$periodoContinuidad['mes'],':y'=>$periodoContinuidad['anio']]);
        $mensualidadObjetivo=$st->fetch()?:null;
        if(!$mensualidadObjetivo)throw new RuntimeException('No se pudo materializar la mensualidad de continuidad');
    }

    $importeAjustado=abs((float)$importeFinal-(float)$plan['precio'])>0.009;
    $observacionMensualidad='Continuidad desde intensivo: '.($inicioRegular==='PROXIMO'?'próximo periodo':'periodo actual').($importeAjustado?' · importe ajustado':'');
    if($observacion!=='')$observacionMensualidad.=' · '.$observacion;
    $st=$pdo->prepare("UPDATE mensualidades SET periodo_inicio=:pi,periodo_fin=:pf,plan_id=:plan,importe_estandar=:estandar,importe_a_cobrar=:importe,observacion=:obs,updated_at=NOW() WHERE id=:id AND alumno_id=:a AND sede_id=:s AND estado='PENDIENTE'");
    $st->execute([':pi'=>$periodoContinuidad['inicio'],':pf'=>$periodoContinuidad['fin'],':plan'=>$planId,':estandar'=>$importeEstandar,':importe'=>$importeFinal,':obs'=>$observacionMensualidad,':id'=>$mensualidadObjetivo['id'],':a'=>$alumnoId,':s'=>$sede['id']]);

    $acceso=regla_recalcular_alumno_regular($pdo,$alumnoId);
    $pdo->commit();
    continuidad_out(['ok'=>true,'mensaje'=>$sedeClave==='PALAPAS'?'Continuidad regular creada. Requiere inscripción y mensualidad para activar acceso.':'Continuidad regular creada. Inscripción exenta por venir de intensivo; requiere mensualidad para activar acceso.','reglas'=>['inscripcion'=>$sedeClave==='PALAPAS'?'OBLIGATORIA':'EXENTA_POR_INTENSIVO','mensualidad'=>'OBLIGATORIA','ciclo_pago'=>$ciclo!==''?$ciclo:null],'inicio_regular'=>$inicioRegular,'periodo_continuidad'=>$periodoContinuidad,'acceso_regular'=>$acceso]);
}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();error_log('[continuidad-intensivo] '.$e->getMessage());continuidad_out(['ok'=>false,'error'=>'No se pudo guardar la continuidad'],500);}
