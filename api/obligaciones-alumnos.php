<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
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

    $mes=(int)date('n');
    $anio=(int)date('Y');
    $precioInscripcion=$sedeClave==='PALAPAS'?400.00:300.00;

    $sql="SELECT a.id,
        EXISTS(SELECT 1 FROM pagos p WHERE p.alumno_id=a.id AND p.tipo='INSCRIPCION' AND p.estado='VALIDO') AS inscripcion_pagada,
        EXISTS(SELECT 1 FROM mensualidades m WHERE m.alumno_id=a.id AND m.sede_id=:sede_m AND m.mes=:mes AND m.anio=:anio AND m.estado='PAGADA') AS mensualidad_pagada
      FROM alumnos a
      WHERE a.sede_id=:sede_a AND a.plan_actual_id IS NOT NULL";
    $st=$pdo->prepare($sql);
    $st->execute([':sede_m'=>$sedeId,':mes'=>$mes,':anio'=>$anio,':sede_a'=>$sedeId]);
    $items=[];
    foreach($st as $r){
        $ins=(int)$r['inscripcion_pagada']===1;
        $men=(int)$r['mensualidad_pagada']===1;
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
    out(['ok'=>true,'sede'=>$sedeClave,'mes'=>$mes,'anio'=>$anio,'alumnos'=>$items]);
}catch(Throwable $e){out(['ok'=>false,'error'=>$e->getMessage()],500);}
