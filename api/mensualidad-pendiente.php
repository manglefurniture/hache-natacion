<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
$config=require __DIR__.'/../config/database.php';

function mensualidad_pendiente_out(array $data,int $status=200):never
{
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    exit;
}

try{
    auth_require(['ADMIN','VERIFICADOR']);
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')mensualidad_pendiente_out(['ok'=>false,'error'=>'Método no permitido'],405);

    $pdo=new PDO(
        "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
        $config['user'],$config['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
    );

    $sedeClave=auth_active_sede_clave();
    $st=$pdo->prepare("SELECT id FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");
    $st->execute([':c'=>$sedeClave]);
    $sedeId=(string)$st->fetchColumn();
    if($sedeId==='')mensualidad_pendiente_out(['ok'=>false,'error'=>'Sede activa inválida'],422);

    $alumnoId=trim((string)($_GET['alumno_id']??''));
    if($alumnoId==='')mensualidad_pendiente_out(['ok'=>false,'error'=>'alumno_id es obligatorio'],422);

    $st=$pdo->prepare("SELECT id,mes,anio,periodo_inicio,periodo_fin,importe_estandar,importe_a_cobrar,estado
        FROM mensualidades
        WHERE alumno_id=:a AND sede_id=:s AND estado='PENDIENTE'
        ORDER BY
            (CURDATE() BETWEEN periodo_inicio AND periodo_fin) DESC,
            (periodo_inicio>CURDATE()) DESC,
            CASE WHEN periodo_inicio>CURDATE() THEN periodo_inicio END ASC,
            periodo_inicio DESC
        LIMIT 1");
    $st->execute([':a'=>$alumnoId,':s'=>$sedeId]);
    $mensualidad=$st->fetch()?:null;

    mensualidad_pendiente_out(['ok'=>true,'mensualidad'=>$mensualidad]);
}catch(Throwable $e){
    error_log('[mensualidad-pendiente] '.$e->getMessage());
    mensualidad_pendiente_out(['ok'=>false,'error'=>'No se pudo cargar la mensualidad pendiente'],500);
}
