<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/reglas-acceso.php';
auth_require(['ADMIN','VERIFICADOR']);
$config=require __DIR__.'/../config/database.php';

function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;}

try{
    $pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    $sedeClave=auth_active_sede_clave();
    $st=$pdo->prepare("SELECT id FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");
    $st->execute([':c'=>$sedeClave]);
    $sedeId=$st->fetchColumn();
    if(!$sedeId)out(['ok'=>false,'error'=>'Sede activa inválida'],422);

    $precioInscripcion=$sedeClave==='PALAPAS'?400.00:300.00;

    $sql="SELECT a.id,a.ciclo_pago FROM alumnos a
      WHERE a.sede_id=:sede_a AND a.plan_actual_id IS NOT NULL AND a.estado_administrativo<>'BAJA'";
    $st=$pdo->prepare($sql);
    $st->execute([':sede_a'=>$sedeId]);
    $items=[];
    foreach($st as $r){
        $ins=regla_inscripcion_regular_cubierta($pdo,(string)$r['id'],(string)$sedeId,$sedeClave);
        $men=regla_mensualidad_regular_cubierta($pdo,(string)$r['id'],(string)$sedeId,$sedeClave,$r['ciclo_pago']);
        $items[(string)$r['id']]=[
            'inscripcion_pagada'=>$ins,
            'mensualidad_pagada'=>$men,
            'precio_inscripcion'=>$precioInscripcion,
            'pendientes'=>array_values(array_filter([
                !$ins?'INSCRIPCION':null,
                !$men?'MENSUALIDAD':null,
            ])),
        ];
    }
    $periodo=regla_periodo_regular_actual($sedeClave,$sedeClave==='PALAPAS'?'P15':null);
    out(['ok'=>true,'sede'=>$sedeClave,'mes'=>$periodo['mes'],'anio'=>$periodo['anio'],'periodo_inicio'=>$periodo['inicio'],'periodo_fin'=>$periodo['fin'],'alumnos'=>$items]);
}catch(Throwable $e){error_log('[obligaciones-alumnos] '.$e->getMessage());out(['ok'=>false,'error'=>'No se pudieron calcular las obligaciones'],500);}
