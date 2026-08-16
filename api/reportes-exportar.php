<?php
declare(strict_types=1);
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$desde=(string)($_GET['desde']??date('Y-m-01'));$hasta=(string)($_GET['hasta']??date('Y-m-t'));if($hasta<$desde){[$desde,$hasta]=[$hasta,$desde];}
header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="hache-reporte-'.$desde.'-'.$hasta.'.csv"');echo "\xEF\xBB\xBF";$out=fopen('php://output','w');fputcsv($out,['Folio','Alumno','Tipo','Importe','Método','Fecha','Estado']);$st=$pdo->prepare("SELECT p.folio,a.nombre,p.tipo,p.importe,p.metodo,p.fecha,p.estado FROM pagos p JOIN alumnos a ON a.id=p.alumno_id WHERE DATE(p.fecha) BETWEEN :d AND :h ORDER BY p.fecha,p.folio");$st->execute([':d'=>$desde,':h'=>$hasta]);foreach($st as $r)fputcsv($out,$r);fclose($out);
