<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config=require __DIR__.'/../config/database.php';
try{
 if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Método no permitido'],JSON_UNESCAPED_UNICODE);exit;}
 $pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $rows=$pdo->query("SELECT id,clave,nombre,socio,porcentaje_mensualidad_socio,porcentaje_intensivo_socio,porcentaje_inscripcion_socio,minimo_mensual_socio FROM sedes WHERE activo=1 ORDER BY FIELD(clave,'MONTEVERDE','PALAPAS'),nombre")->fetchAll();
 echo json_encode(['ok'=>true,'sedes'=>$rows],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
}catch(Throwable $e){
 error_log('api/sedes.php: '.$e->getMessage());
 http_response_code(500);
 echo json_encode(['ok'=>false,'error'=>'No se pudieron cargar las sedes.'],JSON_UNESCAPED_UNICODE);
}
