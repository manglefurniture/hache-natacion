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
            if($scope){$_SESSION['hache_usuario']['sede_id']=$scope['sede_id']?:null;$_SESSION['hache_usuario']['sede_clave']=$scope['sede_clave']?:null;$_SESSION['hache_usuario']['sede_nombre']=$scope['sede_nombre']?:null;$u=auth_user();}
        }catch(Throwable $e){}
    }
    echo json_encode(['ok'=>true,'autenticado'=>(bool)$u,'usuario'=>$u],JSON_UNESCAPED_UNICODE);exit;
}
if($method==='POST'){
    $in=json_decode(file_get_contents('php://input'),true)?:[];
    if(strtoupper((string)($in['accion']??''))==='LOGOUT'){
        auth_logout();
        echo json_encode(['ok'=>true],JSON_UNESCAPED_UNICODE);exit;
    }
}
http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Método no permitido'],JSON_UNESCAPED_UNICODE);
