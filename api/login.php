<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/rate-limit.php';
$config = require __DIR__ . '/../config/database.php';
try {
    $pdo = new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Método no permitido'],JSON_UNESCAPED_UNICODE);exit;}
    $input=json_decode(file_get_contents('php://input'),true);if(!is_array($input)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'JSON inválido'],JSON_UNESCAPED_UNICODE);exit;}
    $usuario=trim((string)($input['usuario']??''));$password=(string)($input['password']??'');
    if($usuario===''||$password===''){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Usuario y contraseña son obligatorios'],JSON_UNESCAPED_UNICODE);exit;}
    $ipKey=security_rate_limit_client_ip();$accountKey=mb_strtolower($usuario,'UTF-8');
    $ipLimit=security_rate_limit_check('login-ip',$ipKey,30,900);$accountLimit=security_rate_limit_check('login-account',$accountKey,8,900);
    if(!$ipLimit['allowed']||!$accountLimit['allowed']){$retry=max((int)$ipLimit['retry_after'],(int)$accountLimit['retry_after']);header('Retry-After: '.max(1,$retry));http_response_code(429);echo json_encode(['ok'=>false,'error'=>'Demasiados intentos. Espera unos minutos antes de volver a intentar.'],JSON_UNESCAPED_UNICODE);exit;}
    $stmt=$pdo->prepare("SELECT u.id,u.usuario,u.password_hash,u.rol,u.activo,u.alumno_id,u.sede_id,u.debe_cambiar_password,s.clave sede_clave,s.nombre sede_nombre,s.activo sede_activo FROM usuarios u LEFT JOIN sedes s ON s.id=u.sede_id WHERE u.usuario=:usuario LIMIT 1");$stmt->execute([':usuario'=>$usuario]);$user=$stmt->fetch();
    $dummyHash='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    $passwordOk=password_verify($password,$user['password_hash']??$dummyHash);$role=strtoupper(trim((string)($user['rol']??'')));
    if(!$user||!$passwordOk||(int)$user['activo']!==1||!in_array($role,['ADMIN','VERIFICADOR','ALUMNO'],true)){security_rate_limit_record('login-ip',$ipKey,30,900);security_rate_limit_record('login-account',$accountKey,8,900);http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Usuario o contraseña incorrectos'],JSON_UNESCAPED_UNICODE);exit;}
    $user['rol']=$role;
    if($role==='ALUMNO' && empty($user['alumno_id'])){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Este usuario alumno no está vinculado a una ficha'],JSON_UNESCAPED_UNICODE);exit;}
    if($role==='VERIFICADOR' && (empty($user['sede_id'])||(int)($user['sede_activo']??0)!==1||!in_array(strtoupper((string)($user['sede_clave']??'')),['MONTEVERDE','PALAPAS'],true))){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Este verificador no tiene una sede activa asignada'],JSON_UNESCAPED_UNICODE);exit;}
    security_rate_limit_clear('login-account',$accountKey);auth_login($user);$pdo->prepare("UPDATE usuarios SET last_login=NOW() WHERE id=:id")->execute([':id'=>$user['id']]);$safe=auth_user();
    $pendingVerification=is_string($_SESSION['sharky_verification_token']??null)&&preg_match('/^[a-f0-9]{64}$/',(string)$_SESSION['sharky_verification_token'])===1;
    $redirect=!empty($safe['debe_cambiar_password'])?'/cambiar-password.php':($safe['rol']==='ALUMNO'&&$pendingVerification?'/sharky-verificar.php':($safe['rol']==='ALUMNO'?'/mi-cuenta.php':'/dashboard.php'));
    echo json_encode(['ok'=>true,'mensaje'=>'Login correcto','usuario'=>$safe,'redirect'=>$redirect],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
} catch(Throwable $e){error_log('Hache login: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Error interno del servidor'],JSON_UNESCAPED_UNICODE);}
