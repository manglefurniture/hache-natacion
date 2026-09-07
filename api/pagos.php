<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/reglas-acceso.php';
$config=require __DIR__.'/../config/database.php';
try{
    $pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if($method==='GET'){
        $me=auth_require(['ADMIN','VERIFICADOR']);
        $clave=auth_resolve_sede_clave((string)($_GET['sede']??''));
        $st=$pdo->prepare("SELECT id FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");$st->execute([':c'=>$clave]);$sedeId=$st->fetchColumn();
        if(!$sedeId){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Sede inválida']);exit;}
        if($me['rol']==='ADMIN'){
            regla_reconciliar_sede_una_vez($pdo,(string)$sedeId,$clave);
        }

        $accion=strtoupper(trim((string)($_GET['accion']??'')));
        if($accion==='CURSOS_INTENSIVOS'){
            $alumnoId=trim((string)($_GET['alumno_id']??''));
            if($alumnoId===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Alumno obligatorio'],JSON_UNESCAPED_UNICODE);exit;}
            $st=$pdo->prepare(
                "SELECT ci.id,ci.fecha_inicio,ci.fecha_fin,ci.precio,ci.estado,
                        h.hora_inicio,h.hora_fin,
                        EXISTS(
                            SELECT 1 FROM pagos p
                            WHERE p.alumno_id=cia.alumno_id
                              AND p.intensivo_id=ci.id
                              AND p.tipo='INTENSIVO'
                              AND p.estado='VALIDO'
                        ) pagado
                 FROM curso_intensivo_alumnos cia
                 INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id
                 INNER JOIN alumnos a ON a.id=cia.alumno_id AND a.sede_id=ci.sede_id
                 LEFT JOIN horarios h ON h.id=cia.horario_id
                 WHERE cia.alumno_id=:a AND ci.sede_id=:s
                 ORDER BY ci.fecha_inicio DESC"
            );
            $st->execute([':a'=>$alumnoId,':s'=>$sedeId]);
            $cursos=$st->fetchAll();
            $hoy=(new DateTimeImmutable('today',new DateTimeZone('America/Cancun')))->format('Y-m-d');
            foreach($cursos as &$curso){
                $curso['pagado']=(int)$curso['pagado']===1;
                $curso['historico']=(string)$curso['fecha_fin']<$hoy;
            }
            unset($curso);
            echo json_encode(['ok'=>true,'cursos'=>$cursos],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
        }

        $st=$pdo->prepare("SELECT p.id,p.folio,p.alumno_id,a.nombre AS alumno_nombre,p.inscripcion_id,p.mensualidad_id,p.intensivo_id,p.tipo,p.importe,p.metodo,p.fecha,p.estado,p.observacion,p.created_by,p.created_at FROM pagos p INNER JOIN alumnos a ON a.id=p.alumno_id WHERE a.sede_id=:s ORDER BY p.fecha DESC,p.folio DESC");
        $st->execute([':s'=>$sedeId]);$pagos=$st->fetchAll();
        echo json_encode(['ok'=>true,'total'=>count($pagos),'pagos'=>$pagos],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;
    }
    if($method==='POST'){
        auth_require(['ADMIN']);
        $entrada=json_decode(file_get_contents('php://input'),true);
        if(!is_array($entrada)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'JSON inválido'],JSON_UNESCAPED_UNICODE);exit;}
        ob_start();
        require __DIR__.'/pagos-smart.php';
        $salida=ob_get_clean();
        $resultado=null;
        if(http_response_code()<400 && !empty($entrada['alumno_id'])){
            $tipo=strtoupper((string)($entrada['tipo']??''));
            if(in_array($tipo,['INSCRIPCION','MENSUALIDAD'],true)){
                $resultado=regla_recalcular_alumno_regular($pdo,(string)$entrada['alumno_id']);
            }
        }
        $json=json_decode((string)$salida,true);
        if(is_array($json) && $resultado!==null){$json['acceso_regular']=$resultado;echo json_encode($json,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);}else{echo $salida;}
        exit;
    }
    http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Método no permitido'],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    error_log('api/pagos.php: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'No se pudo completar la operación. Intenta nuevamente.'],JSON_UNESCAPED_UNICODE);
}
