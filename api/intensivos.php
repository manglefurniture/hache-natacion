<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$config=require __DIR__.'/../config/database.php';
function out(array $d,int $c=200):never{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;}
function getSede(PDO $pdo,string $clave):array{$st=$pdo->prepare("SELECT id,clave,nombre FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");$st->execute([':c'=>$clave]);$s=$st->fetch();if(!$s)out(['ok'=>false,'error'=>'Sede inválida'],422);return $s;}
try{
 $pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 $method=$_SERVER['REQUEST_METHOD'];
 if($method==='GET'){
   $clave=strtoupper(trim((string)($_GET['sede']??'MONTEVERDE')));$s=getSede($pdo,$clave);
   $stmt=$pdo->prepare("SELECT ci.id,ci.sede_id,s.clave sede_clave,s.nombre sede_nombre,ci.fecha_inicio,ci.fecha_fin,ci.precio,ci.estado,ci.observaciones,ci.created_by,ci.created_at,COUNT(cia.id) total_alumnos FROM cursos_intensivos ci INNER JOIN sedes s ON s.id=ci.sede_id LEFT JOIN curso_intensivo_alumnos cia ON cia.curso_intensivo_id=ci.id WHERE ci.sede_id=:sede GROUP BY ci.id,ci.sede_id,s.clave,s.nombre,ci.fecha_inicio,ci.fecha_fin,ci.precio,ci.estado,ci.observaciones,ci.created_by,ci.created_at ORDER BY ci.fecha_inicio DESC");
   $stmt->execute([':sede'=>$s['id']]);$rows=$stmt->fetchAll();out(['ok'=>true,'sede'=>$s,'total'=>count($rows),'intensivos'=>$rows]);
 }
 if($method!=='POST')out(['ok'=>false,'error'=>'Método no permitido'],405);
 $input=json_decode(file_get_contents('php://input'),true);if(!is_array($input))out(['ok'=>false,'error'=>'JSON inválido'],400);
 $fechaInicioTexto=trim((string)($input['fecha_inicio']??''));$precio=$input['precio']??1200;$observaciones=$input['observaciones']??null;$createdBy=trim((string)($input['created_by']??''));$clave=strtoupper(trim((string)($input['sede']??'MONTEVERDE')));$s=getSede($pdo,$clave);
 if($fechaInicioTexto==='')out(['ok'=>false,'error'=>'Selecciona la fecha de inicio'],422);if(!is_numeric($precio)||(float)$precio<0)out(['ok'=>false,'error'=>'El precio es inválido'],422);if($createdBy==='')out(['ok'=>false,'error'=>'La sesión administrativa no está disponible'],422);
 $fechaInicio=DateTimeImmutable::createFromFormat('!Y-m-d',$fechaInicioTexto);$err=DateTimeImmutable::getLastErrors();if(!$fechaInicio||(is_array($err)&&($err['warning_count']>0||$err['error_count']>0)))out(['ok'=>false,'error'=>'La fecha de inicio no es válida'],422);if((int)$fechaInicio->format('N')!==1)out(['ok'=>false,'error'=>'Los cursos intensivos solo pueden iniciar en lunes'],422);
 $fechaFin=$fechaInicio->modify('+18 days');$fi=$fechaInicio->format('Y-m-d');$ff=$fechaFin->format('Y-m-d');
 $stmt=$pdo->prepare("SELECT id FROM usuarios WHERE id=:id LIMIT 1");$stmt->execute([':id'=>$createdBy]);if(!$stmt->fetch())out(['ok'=>false,'error'=>'El usuario administrativo no existe'],422);
 $stmt=$pdo->prepare("SELECT id FROM cursos_intensivos WHERE sede_id=:sede AND fecha_inicio=:fi LIMIT 1");$stmt->execute([':sede'=>$s['id'],':fi'=>$fi]);if($stmt->fetch())out(['ok'=>false,'error'=>'Ya existe un curso intensivo en esta sede con esa fecha de inicio'],422);
 $id=(string)$pdo->query('SELECT UUID()')->fetchColumn();
 $stmt=$pdo->prepare("INSERT INTO cursos_intensivos(id,sede_id,fecha_inicio,fecha_fin,precio,estado,observaciones,created_by) VALUES(:id,:sede,:fi,:ff,:precio,'PROGRAMADO',:obs,:u)");$stmt->execute([':id'=>$id,':sede'=>$s['id'],':fi'=>$fi,':ff'=>$ff,':precio'=>number_format((float)$precio,2,'.',''),':obs'=>$observaciones,':u'=>$createdBy]);
 $stmt=$pdo->prepare("SELECT ci.*,s.clave sede_clave,s.nombre sede_nombre,0 total_alumnos FROM cursos_intensivos ci INNER JOIN sedes s ON s.id=ci.sede_id WHERE ci.id=:id LIMIT 1");$stmt->execute([':id'=>$id]);out(['ok'=>true,'mensaje'=>'Curso intensivo creado correctamente en '.$s['nombre'],'intensivo'=>$stmt->fetch()],201);
}catch(Throwable $e){out(['ok'=>false,'error'=>$e->getMessage()],500);}
