<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
$config=require __DIR__.'/../config/database.php';
function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
 auth_require(['ADMIN','VERIFICADOR']);
 $pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 $folio=(int)($_GET['folio']??0);if($folio<=0)out(['ok'=>false,'error'=>'Folio inválido'],422);
 $siteKey=auth_active_sede_clave();
 $st=$pdo->prepare("SELECT p.id,p.folio,p.alumno_id,a.nombre alumno_nombre,a.sede_id,s.clave sede_clave,s.nombre sede_nombre,p.inscripcion_id,p.mensualidad_id,p.intensivo_id,p.tipo,p.importe,p.metodo,p.fecha,p.estado,p.observacion,p.created_by,u.usuario created_by_usuario,p.created_at,m.mes mensualidad_mes,m.anio mensualidad_anio,ci.fecha_inicio intensivo_inicio,ci.fecha_fin intensivo_fin,ci.estado intensivo_estado,i.fecha inscripcion_fecha FROM pagos p JOIN alumnos a ON a.id=p.alumno_id JOIN sedes s ON s.id=a.sede_id LEFT JOIN usuarios u ON u.id=p.created_by LEFT JOIN mensualidades m ON m.id=p.mensualidad_id AND m.sede_id=a.sede_id LEFT JOIN cursos_intensivos ci ON ci.id=p.intensivo_id AND ci.sede_id=a.sede_id LEFT JOIN inscripciones i ON i.id=p.inscripcion_id AND i.sede_id=a.sede_id WHERE p.folio=:f AND s.clave=:site LIMIT 1");
 $st->execute([':f'=>$folio,':site'=>$siteKey]);$p=$st->fetch();if(!$p)out(['ok'=>false,'error'=>'Pago no encontrado en la sede activa'],404);
 $periodo=null;if($p['tipo']==='MENSUALIDAD'&&$p['mensualidad_mes'])$periodo=sprintf('%04d-%02d',(int)$p['mensualidad_anio'],(int)$p['mensualidad_mes']);elseif($p['tipo']==='INTENSIVO'&&$p['intensivo_inicio'])$periodo=substr((string)$p['intensivo_inicio'],0,7);elseif($p['tipo']==='INSCRIPCION'&&$p['inscripcion_fecha'])$periodo=substr((string)$p['inscripcion_fecha'],0,7);else $periodo=substr((string)$p['fecha'],0,7);
 out(['ok'=>true,'pago'=>$p,'periodo_economico'=>$periodo]);
}catch(Throwable $e){error_log('[pago-detalle] '.$e->getMessage());out(['ok'=>false,'error'=>'No se pudo cargar el detalle del pago'],500);}
