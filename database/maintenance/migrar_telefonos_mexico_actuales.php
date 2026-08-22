<?php
declare(strict_types=1);
// Migra SOLO teléfonos legacy actuales de 10 dígitos a E.164 México (+52).
// Uso seguro:
//   php database/maintenance/migrar_telefonos_mexico_actuales.php          # vista previa
//   php database/maintenance/migrar_telefonos_mexico_actuales.php --apply  # aplica cambios
// Regla autorizada: todos los teléfonos legacy de 10 dígitos existentes hoy se consideran México.

$c=require __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../config/telefono.php';
$pdo=new PDO("mysql:host={$c['host']};dbname={$c['dbname']};charset={$c['charset']}",$c['user'],$c['password'],[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
$apply=in_array('--apply',$argv,true);
$rows=$pdo->query("SELECT id,nombre,whatsapp FROM alumnos ORDER BY nombre")->fetchAll();
$changes=[];$skipE164=0;$skipOther=0;$empty=0;
foreach($rows as $r){
    $raw=trim((string)$r['whatsapp']);
    if($raw===''){ $empty++; continue; }
    if(telefono_es_e164($raw)){ $skipE164++; continue; }
    $digits=telefono_digitos($raw);
    if(strlen($digits)!==10){ $skipOther++; continue; }
    $nuevo='+52'.$digits;
    $changes[]=['id'=>$r['id'],'nombre'=>$r['nombre'],'antes'=>$raw,'despues'=>$nuevo];
}

echo ($apply?'MODO APLICAR':'MODO VISTA PREVIA')."\n";
echo "Convertibles a +52: ".count($changes)."\nYa E.164: {$skipE164}\nVacíos: {$empty}\nOtros/revisar: {$skipOther}\n\n";
foreach($changes as $x) echo $x['nombre'].': '.$x['antes'].' -> '.$x['despues']."\n";
if(!$apply){
    echo "\nNo se modificó la base. Si la vista previa es correcta, ejecuta de nuevo con --apply.\n";
    exit(0);
}

$pdo->beginTransaction();
try{
    $dup=$pdo->prepare("SELECT id,nombre FROM alumnos WHERE whatsapp=:w AND id<>:id LIMIT 1");
    $up=$pdo->prepare("UPDATE alumnos SET whatsapp=:w,updated_at=NOW() WHERE id=:id");
    $done=0;
    foreach($changes as $x){
        $dup->execute([':w'=>$x['despues'],':id'=>$x['id']]);
        if($d=$dup->fetch()) throw new RuntimeException('Duplicado E.164: '.$x['nombre'].' coincide con '.$d['nombre'].' ('.$x['despues'].')');
        $up->execute([':w'=>$x['despues'],':id'=>$x['id']]);
        $done++;
    }
    $pdo->commit();
    echo "\nMigración terminada: {$done} teléfonos actualizados a E.164 +52.\n";
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    fwrite(STDERR,"ERROR: ".$e->getMessage()."\nNo se aplicaron cambios.\n");
    exit(1);
}
