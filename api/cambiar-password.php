<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
$me=auth_require([], true);
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE);exit;}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')out(['ok'=>false,'error'=>'Método no permitido'],405);
$in=json_decode(file_get_contents('php://input'),true)?:[];$actual=(string)($in['actual']??'');$nueva=(string)($in['nueva']??'');
if(strlen($nueva)<8)out(['ok'=>false,'error'=>'La nueva contraseña debe tener al menos 8 caracteres'],422);
if($nueva==='123456')out(['ok'=>false,'error'=>'No puedes conservar la contraseña temporal'],422);
$st=$pdo->prepare("SELECT password_hash FROM usuarios WHERE id=:id AND activo=1 LIMIT 1");$st->execute([':id'=>$me['id']]);$hash=(string)$st->fetchColumn();
if(!$hash||!password_verify($actual,$hash))out(['ok'=>false,'error'=>'La contraseña actual no es correcta'],422);
$st=$pdo->prepare("UPDATE usuarios SET password_hash=:p,debe_cambiar_password=0 WHERE id=:id");$st->execute([':p'=>password_hash($nueva,PASSWORD_DEFAULT),':id'=>$me['id']]);auth_refresh_password_flag(false);out(['ok'=>true,'redirect'=>$me['rol']==='ALUMNO'?'/mi-cuenta.php':'/dashboard.php']);
