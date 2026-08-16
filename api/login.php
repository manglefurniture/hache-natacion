<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/auth.php';
$config = require __DIR__ . '/../config/database.php';
try {
    $pdo = new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Método no permitido'],JSON_UNESCAPED_UNICODE);exit;}
    $input=json_decode(file_get_contents('php://input'),true);if(!is_array($input)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'JSON inválido'],JSON_UNESCAPED_UNICODE);exit;}
    $usuario=trim((string)($input['usuario']??''));$password=(string)($input['password']??'');
    if($usuario===''||$password===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Usuario y contraseña son obligatorios'],JSON_UNESCAPED_UNICODE);exit;}
    $stmt=$pdo->prepare("SELECT u.id,u.usuario,u.password_hash,u.rol,u.activo,u.alumno_id,u.sede_id,u.debe_cambiar_password,s.clave sede_clave,s.nombre sede_nombre FROM usuarios u LEFT JOIN sedes s ON s.id=u.sede_id WHERE u.usuario=:usuario LIMIT 1");$stmt->execute([':usuario'=>$usuario]);$user=$stmt->fetch();
    if(!$user||!password_verify($password,$user['password_hash'])){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Usuario o contraseña incorrectos'],JSON_UNESCAPED_UNICODE);exit;}
    if((int)$user['activo']!==1){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'El usuario está inactivo'],JSON_UNESCAPED_UNICODE);exit;}
    if($user['rol']==='ALUMNO' && empty($user['alumno_id'])){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Este usuario alumno no está vinculado a una ficha'],JSON_UNESCAPED_UNICODE);exit;}
    if($user['rol']==='VERIFICADOR' && empty($user['sede_id'])){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Este verificador no tiene una sede asignada'],JSON_UNESCAPED_UNICODE);exit;}
    auth_login($user);$pdo->prepare("UPDATE usuarios SET last_login=NOW() WHERE id=:id")->execute([':id'=>$user['id']]);$safe=auth_user();
    $redirect=!empty($safe['debe_cambiar_password'])?'/cambiar-password.php':($safe['rol']==='ALUMNO'?'/mi-cuenta.php':'/dashboard.php');
    echo json_encode(['ok'=>true,'mensaje'=>'Login correcto','usuario'=>$safe,'redirect'=>$redirect],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
} catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Error interno del servidor'],JSON_UNESCAPED_UNICODE);}
