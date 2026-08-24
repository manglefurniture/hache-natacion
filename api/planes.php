<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
$config = require __DIR__ . '/../config/database.php';
try {
    $pdo = new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Método no permitido'],JSON_UNESCAPED_UNICODE);exit;}
    auth_require(['ADMIN','VERIFICADOR']);
    $clave=auth_resolve_sede_clave((string)($_GET['sede']??'MONTEVERDE'));
    $st=$pdo->prepare("SELECT p.id,p.nombre,p.sesiones_semana,p.precio,s.clave sede_clave FROM planes p INNER JOIN sedes s ON s.id=p.sede_id WHERE p.activo=1 AND s.clave=:s ORDER BY p.sesiones_semana,p.precio,p.nombre");
    $st->execute([':s'=>$clave]);
    echo json_encode(['ok'=>true,'sede'=>$clave,'planes'=>$st->fetchAll()],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    error_log('api/planes.php: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'No se pudieron cargar los planes.'],JSON_UNESCAPED_UNICODE);
}
