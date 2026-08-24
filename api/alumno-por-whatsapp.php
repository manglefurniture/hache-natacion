<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__.'/../config/telefono.php';
require_once __DIR__.'/../config/rate-limit.php';
function out(array $d,int $s=200):never{http_response_code($s);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')out(['ok'=>false,'error'=>'Método no permitido'],405);
$rate=security_rate_limit_record('n8n-alumno-lookup',security_rate_limit_client_ip(),120,60);if(!$rate['allowed']){header('Retry-After: '.max(1,(int)$rate['retry_after']));out(['ok'=>false,'error'=>'Demasiadas consultas. Intenta nuevamente en unos segundos.'],429);}
$secret=(string)(getenv('HACHE_N8N_LOOKUP_TOKEN')?:'');
$auth=(string)($_SERVER['HTTP_AUTHORIZATION']??'');
$token=preg_match('/^Bearer\s+(.+)$/i',$auth,$m)?trim($m[1]):'';
if($secret===''||$token===''||!hash_equals($secret,$token))out(['ok'=>false,'error'=>'No autorizado'],401);
$c=require __DIR__.'/../config/database.php';
$raw=trim((string)($_GET['telefono']??''));if($raw==='')out(['ok'=>false,'error'=>'Falta telefono'],422);if(strlen($raw)>40)out(['ok'=>false,'error'=>'Teléfono inválido'],422);
$digits=telefono_digitos($raw);
// WhatsApp/Meta puede entregar algunos números mexicanos históricos con 521.
if(strlen($digits)===13 && str_starts_with($digits,'521'))$digits='52'.substr($digits,3);
$e164='+'.$digits;if(!telefono_es_e164($e164))out(['ok'=>false,'error'=>'Teléfono internacional inválido'],422);
try{$pdo=new PDO("mysql:host={$c['host']};dbname={$c['dbname']};charset={$c['charset']}",$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$st=$pdo->prepare("SELECT a.id,a.nombre,a.whatsapp,a.estado_administrativo,s.clave sede_clave,s.nombre sede_nombre FROM alumnos a INNER JOIN sedes s ON s.id=a.sede_id WHERE a.whatsapp=:w LIMIT 2");$st->execute([':w'=>$e164]);$rows=$st->fetchAll();if(!$rows)out(['ok'=>true,'encontrado'=>false,'telefono'=>$e164]);if(count($rows)>1)out(['ok'=>false,'error'=>'Teléfono duplicado en base de datos','telefono'=>$e164],409);out(['ok'=>true,'encontrado'=>true,'telefono'=>$e164,'alumno'=>$rows[0]]);}catch(Throwable $e){error_log('Hache n8n alumno lookup: '.$e->getMessage());out(['ok'=>false,'error'=>'Error interno'],500);}
