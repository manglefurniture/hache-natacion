<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit("Solo CLI\n");}
require_once __DIR__.'/../../config/reglas-acceso.php';
$c=require __DIR__.'/../../config/database.php';
$pdo=new PDO("mysql:host={$c['host']};dbname={$c['dbname']};charset={$c['charset']}",$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$args=$argv;array_shift($args);$apply=in_array('--apply',$args,true);$file='';foreach($args as $a)if(str_starts_with($a,'--file='))$file=substr($a,7);
if($file===''||!is_file($file)){fwrite(STDERR,"Uso: php database/maintenance/marcar_inscripciones_historicas.php --file=/ruta/alumnos.csv [--apply]\nCSV: alumno_id,nombre (alumno_id recomendado). Sin --apply solo revisa.\n");exit(1);}
regla_asegurar_tabla_negocio($pdo);$fh=fopen($file,'r');$head=fgetcsv($fh);if(!$head)exit("CSV vacío\n");$map=array_flip(array_map(fn($x)=>strtolower(trim((string)$x)),$head));$ok=0;$err=0;
while(($row=fgetcsv($fh))!==false){$id=isset($map['alumno_id'])?trim((string)($row[$map['alumno_id']]??'')):'';$nombre=isset($map['nombre'])?trim((string)($row[$map['nombre']]??'')):'';$a=null;if($id!==''){$st=$pdo->prepare('SELECT id,nombre FROM alumnos WHERE id=:id');$st->execute([':id'=>$id]);$a=$st->fetch();}elseif($nombre!==''){$st=$pdo->prepare('SELECT id,nombre FROM alumnos WHERE nombre=:n');$st->execute([':n'=>$nombre]);$rows=$st->fetchAll();if(count($rows)===1)$a=$rows[0];elseif(count($rows)>1){echo "AMBIGUO: {$nombre}\n";$err++;continue;}}
if(!$a){echo "NO ENCONTRADO: ".($id?:$nombre)."\n";$err++;continue;}echo ($apply?'MARCAR':'REVISAR').": {$a['nombre']} ({$a['id']})\n";if($apply){regla_marcar_inscripcion_historica($pdo,(string)$a['id'],true,'Migración validada: inscripción cubierta antes del sistema');regla_recalcular_alumno_regular($pdo,(string)$a['id']);}$ok++;}
fclose($fh);echo "\n".($apply?'Aplicados':'Revisados').": {$ok} · Errores/ambiguos: {$err}\n";if(!$apply)echo "No se modificó la base. Repite con --apply cuando la lista esté confirmada.\n";
