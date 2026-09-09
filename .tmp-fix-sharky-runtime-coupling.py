from pathlib import Path

p=Path('config/sharky-whatsapp-adapter.php')
s=p.read_text()
old="require_once __DIR__.'/sharky-commercial-memory.php';\nrequire_once __DIR__.'/sharky-deterministic-replies.php';"
new="require_once __DIR__.'/sharky-commercial-memory.php';"
if s.count(old)!=1:
    raise SystemExit(f'include coupling: expected 1 match, got {s.count(old)}')
s=s.replace(old,new,1)
old="""    $pdo=hache_sharky_pdo();
    $business=hache_sharky_business_values($pdo instanceof PDO?$pdo:null);
    $selected=$commercial['course_price']??($state['selected_course_price']??null);
    $price=is_numeric($selected)?(float)$selected:(float)hache_sharky_config_int($business,'sharky_precio_intensivo',1200,0,100000);
    $priceText=number_format($price,0,'.',',');
    $message=rtrim($prefix).' En '.$sedeLabel.', el curso intensivo tiene un precio total de $'.$priceText.' MXN. Es un solo pago por el curso completo.';
    $hours=[];
    if($pdo instanceof PDO){
        try{$hours=hache_sharky_deterministic_active_schedules($pdo,'intensive',$sede);}catch(Throwable $ignored){}
    }
"""
new="""    $pdo=function_exists('hache_sharky_pdo')?hache_sharky_pdo():null;
    $business=function_exists('hache_sharky_business_values')?hache_sharky_business_values($pdo instanceof PDO?$pdo:null):[];
    $selected=$commercial['course_price']??($state['selected_course_price']??null);
    $configured=function_exists('hache_sharky_config_int')?hache_sharky_config_int($business,'sharky_precio_intensivo',1200,0,100000):1200;
    $price=is_numeric($selected)?(float)$selected:(float)$configured;
    $priceText=number_format($price,0,'.',',');
    $message=rtrim($prefix).' En '.$sedeLabel.', el curso intensivo tiene un precio total de $'.$priceText.' MXN. Es un solo pago por el curso completo.';
    $hours=[];
    if($pdo instanceof PDO){
        try{
            $st=$pdo->prepare("SELECT h.hora_inicio,h.hora_fin FROM horarios h JOIN sedes s ON s.id=h.sede_id WHERE s.clave=:c AND s.activo=1 AND h.activo=1 AND h.intensivo=1 ORDER BY h.hora_inicio");
            $st->execute([':c'=>$sede]);
            foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
                $start=substr((string)($row['hora_inicio']??''),0,5);$end=substr((string)($row['hora_fin']??''),0,5);
                if($start!==''&&$end!=='')$hours[]=$start.'–'.$end;
            }
            $hours=array_values(array_unique($hours));
        }catch(Throwable $ignored){}
    }
"""
if s.count(old)!=1:
    raise SystemExit(f'information runtime block: expected 1 match, got {s.count(old)}')
s=s.replace(old,new,1)
p.write_text(s)
