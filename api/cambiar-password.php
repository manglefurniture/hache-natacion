<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/passwords.php';
require_once __DIR__.'/../config/rate-limit.php';
$me=auth_require([], true);
function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE);exit;}
$config=require __DIR__.'/../config/database.php';
try{
 $pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST')out(['ok'=>false,'error'=>'Método no permitido'],405);
 $in=json_decode(file_get_contents('php://input'),true);if(!is_array($in))out(['ok'=>false,'error'=>'JSON inválido'],400);$actual=(string)($in['actual']??'');$nueva=(string)($in['nueva']??'');
 $rateKey=(string)$me['id'];$rate=security_rate_limit_check('password-change',$rateKey,10,900);if(!$rate['allowed']){header('Retry-After: '.max(1,(int)$rate['retry_after']));out(['ok'=>false,'error'=>'Demasiados intentos. Espera unos minutos antes de volver a intentar.'],429);}
 if($passwordError=password_error_politica($nueva))out(['ok'=>false,'error'=>$passwordError],422);
 $st=$pdo->prepare("SELECT password_hash FROM usuarios WHERE id=:id AND activo=1 LIMIT 1");$st->execute([':id'=>$me['id']]);$hash=(string)$st->fetchColumn();
 if(!$hash||!password_verify($actual,$hash)){security_rate_limit_record('password-change',$rateKey,10,900);out(['ok'=>false,'error'=>'La contraseña actual no es correcta'],422);}
 if(password_verify($nueva,$hash))out(['ok'=>false,'error'=>'La nueva contraseña debe ser diferente de la actual'],422);
 $newHash=password_hash($nueva,PASSWORD_DEFAULT);$st=$pdo->prepare("UPDATE usuarios SET password_hash=:p,debe_cambiar_password=0 WHERE id=:id AND activo=1");$st->execute([':p'=>$newHash,':id'=>$me['id']]);if($st->rowCount()!==1)out(['ok'=>false,'error'=>'La cuenta dejó de estar disponible'],409);
 security_rate_limit_clear('password-change',$rateKey);session_regenerate_id(true);auth_refresh_password_flag(false);auth_refresh_password_fingerprint($newHash);out(['ok'=>true,'redirect'=>$me['rol']==='ALUMNO'?'/mi-cuenta.php':'/dashboard.php']);
}catch(Throwable $e){error_log('[cambiar-password] '.$e->getMessage());out(['ok'=>false,'error'=>'No se pudo cambiar la contraseña'],500);}
