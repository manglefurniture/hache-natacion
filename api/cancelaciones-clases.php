<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function usuario(PDO $pdo):string{$id=(string)$pdo->query("SELECT id FROM usuarios WHERE activo=1 ORDER BY CASE WHEN rol='ADMIN' THEN 0 ELSE 1 END,created_at LIMIT 1")->fetchColumn();if(!$id)throw new RuntimeException('No hay usuario administrativo activo.');return $id;}
try{
 if($_SERVER['REQUEST_METHOD']!=='POST')out(['ok'=>false,'error'=>'Método no permitido'],405);
 $in=json_decode(file_get_contents('php://input'),true)?:[];
 $nivel=strtoupper(trim((string)($in['nivel']??'')));
 $motivo=trim((string)($in['motivo']??''));
 $fecha=trim((string)($in['fecha']??''));
 $bloque=strtoupper(trim((string)($in['bloque']??'')));
 $claseId=trim((string)($in['clase_id']??''));
 if(!in_array($nivel,['CLASE','SESION','JORNADA'],true))out(['ok'=>false,'error'=>'Nivel de cancelación inválido'],422);
 if($motivo==='')out(['ok'=>false,'error'=>'El motivo de cancelación es obligatorio'],422);
 if($nivel==='CLASE'&&$claseId==='')out(['ok'=>false,'error'=>'Falta la clase'],422);
 if(in_array($nivel,['SESION','JORNADA'],true)&&$fecha==='')out(['ok'=>false,'error'=>'Falta la fecha de la jornada'],422);
 if($nivel==='SESION'&&!in_array($bloque,['AM','PM'],true))out(['ok'=>false,'error'=>'La sesión debe ser AM o PM'],422);
 $uid=usuario($pdo);
 $where='';$params=[];
 if($nivel==='CLASE'){$where='s.id=:id';$params[':id']=$claseId;}
 elseif($nivel==='SESION'){$where='s.fecha=:fecha AND s.bloque=:bloque';$params=[':fecha'=>$fecha,':bloque'=>$bloque];}
 else{$where='s.fecha=:fecha';$params=[':fecha'=>$fecha];}
 $st=$pdo->prepare("SELECT s.id,s.fecha,s.horario_id,s.bloque FROM sesiones s WHERE $where AND s.estado<>'CANCELADA'");$st->execute($params);$clases=$st->fetchAll();
 if(!$clases)out(['ok'=>false,'error'=>'No hay clases activas que cancelar en esa selección'],422);
 $pdo->beginTransaction();$afectadas=0;$reposicionesIntensivo=0;
 foreach($clases as $clase){
   $u=$pdo->prepare("UPDATE sesiones SET estado='CANCELADA',motivo_cancelacion=:m,cerrada=1,fecha_cierre=NOW(),cerrada_por=:u WHERE id=:id AND estado<>'CANCELADA'");
   $u->execute([':m'=>$motivo,':u'=>$uid,':id'=>$clase['id']]);
   if(!$u->rowCount())continue;
   $afectadas++;
   if(!empty($clase['horario_id'])){
     $r=$pdo->prepare("UPDATE curso_intensivo_alumnos cia JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id SET cia.reposiciones_cancelacion=cia.reposiciones_cancelacion+1 WHERE cia.horario_id=:h AND ci.estado IN ('PROGRAMADO','EN_CURSO') AND :f BETWEEN ci.fecha_inicio AND ci.fecha_fin");
     $r->execute([':h'=>$clase['horario_id'],':f'=>$clase['fecha']]);$reposicionesIntensivo+=$r->rowCount();
   }
 }
 $pdo->commit();
 out(['ok'=>true,'nivel'=>$nivel,'clases_canceladas'=>$afectadas,'reposiciones_intensivo_generadas'=>$reposicionesIntensivo,'mensaje'=>$nivel==='CLASE'?'Clase cancelada':($nivel==='SESION'?'Sesión cancelada':'Jornada cancelada')]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();out(['ok'=>false,'error'=>$e->getMessage()],500);}
