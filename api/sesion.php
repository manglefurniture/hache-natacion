<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/auth.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
if($method==='GET'){
    $u=auth_revalidate_user(true);
    if($u && empty($u['sede_activa'])){$_SESSION['hache_usuario']['sede_activa']=auth_active_sede_clave();$u=auth_user();}
    echo json_encode(['ok'=>true,'autenticado'=>(bool)$u,'usuario'=>$u,'sede_activa'=>$u?auth_active_sede_clave():null],JSON_UNESCAPED_UNICODE);exit;
}
if($method==='POST'){
    $in=json_decode(file_get_contents('php://input'),true);
    if(!is_array($in)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'JSON inválido'],JSON_UNESCAPED_UNICODE);exit;}
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
        }catch(Throwable $e){error_log('[sesion] No se pudo cambiar la sede: '.$e->getMessage());http_response_code(403);echo json_encode(['ok'=>false,'error'=>'No se pudo cambiar la sede activa'],JSON_UNESCAPED_UNICODE);exit;}
    }
}
http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Método no permitido'],JSON_UNESCAPED_UNICODE);
