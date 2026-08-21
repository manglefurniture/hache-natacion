<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/auth.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
if($method==='GET'){
    $u=auth_user();
    if($u && ($u['rol']??'')==='VERIFICADOR'){
        try{
            $config=require __DIR__.'/../config/database.php';
            $pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
            $st=$pdo->prepare("SELECT u.sede_id,s.clave sede_clave,s.nombre sede_nombre FROM usuarios u LEFT JOIN sedes s ON s.id=u.sede_id WHERE u.id=:id LIMIT 1");
            $st->execute([':id'=>$u['id']]);$scope=$st->fetch();
            if($scope){$_SESSION['hache_usuario']['sede_id']=$scope['sede_id']?:null;$_SESSION['hache_usuario']['sede_clave']=$scope['sede_clave']?:null;$_SESSION['hache_usuario']['sede_nombre']=$scope['sede_nombre']?:null;$_SESSION['hache_usuario']['sede_activa']=$scope['sede_clave']?:null;$u=auth_user();}
        }catch(Throwable $e){}
    }
    if($u && empty($u['sede_activa'])){$_SESSION['hache_usuario']['sede_activa']=auth_active_sede_clave();$u=auth_user();}
    echo json_encode(['ok'=>true,'autenticado'=>(bool)$u,'usuario'=>$u,'sede_activa'=>$u?auth_active_sede_clave():null],JSON_UNESCAPED_UNICODE);exit;
}
if($method==='POST'){
    $in=json_decode(file_get_contents('php://input'),true)?:[];
    $accion=strtoupper((string)($in['accion']??''));
    if($accion==='LOGOUT'){
        auth_logout();
        echo json_encode(['ok'=>true],JSON_UNESCAPED_UNICODE);exit;
    }
    if($accion==='SET_SEDE'){
        auth_require(['ADMIN','VERIFICADOR']);
        try{
            $clave=auth_set_active_sede((string)($in['sede']??''));
            echo json_encode(['ok'=>true,'sede_activa'=>$clave],JSON_UNESCAPED_UNICODE);exit;
        }catch(InvalidArgumentException $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);exit;
        }catch(Throwable $e){http_response_code(403);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);exit;}
    }
}
http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Método no permitido'],JSON_UNESCAPED_UNICODE);
