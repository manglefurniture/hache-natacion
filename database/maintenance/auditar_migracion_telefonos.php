<?php
declare(strict_types=1);
// Uso: php database/maintenance/auditar_migracion_telefonos.php
// NO modifica datos. Genera un CSV para revisión humana antes de migrar teléfonos legacy.
$c=require __DIR__.'/../../config/database.php';require_once __DIR__.'/../../config/telefono.php';
$pdo=new PDO("mysql:host={$c['host']};dbname={$c['dbname']};charset={$c['charset']}",$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$rows=$pdo->query("SELECT a.id,a.nombre,a.whatsapp,s.clave sede FROM alumnos a INNER JOIN sedes s ON s.id=a.sede_id ORDER BY s.clave,a.nombre")->fetchAll();
$out=__DIR__.'/telefonos_migracion_revision.csv';$f=fopen($out,'wb');fputcsv($f,['id','nombre','sede','telefono_actual','estado','candidato_e164','accion']);
$stats=['E164_OK'=>0,'LEGACY_10_DIGITOS'=>0,'REVISAR'=>0,'VACIO'=>0];
foreach($rows as $r){$raw=trim((string)$r['whatsapp']);$digits=telefono_digitos($raw);$estado='REVISAR';$cand='';$accion='Verificar país manualmente';if($raw===''){$estado='VACIO';$accion='Solicitar teléfono';}elseif(telefono_es_e164($raw)){$estado='E164_OK';$cand=$raw;$accion='Ninguna';}elseif(strlen($digits)===10){$estado='LEGACY_10_DIGITOS';$cand='+52'.$digits;$accion='Candidato México: confirmar antes de aplicar';}$stats[$estado]++;fputcsv($f,[$r['id'],$r['nombre'],$r['sede'],$raw,$estado,$cand,$accion]);}
fclose($f);echo "Auditoría terminada. NO se modificó la base de datos.\nArchivo: {$out}\n";foreach($stats as $k=>$v)echo "$k: $v\n";
