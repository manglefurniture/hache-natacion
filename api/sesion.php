<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/auth.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
if($method==='GET'){
    $u=auth_user();
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
