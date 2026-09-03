<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-activation.php';

if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only\n");exit(2);}

$json=in_array('--json',$argv,true);
$allowEnabled=in_array('--allow-enabled',$argv,true);

try{
    /** @var PDO $pdo */
    $pdo=require __DIR__.'/../config/pdo.php';
    $report=hache_sharky_activation_preflight($pdo,$allowEnabled);
    if($json){
        fwrite(STDOUT,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);
    }else{
        $line=static function(string $label,bool $ok,string $detail=''):void{
            fwrite(STDOUT,sprintf("[%s] %s%s\n",$ok?'OK':'FAIL',$label,$detail!==''?' — '.$detail:''));
        };
        $flag=(string)($report['feature_flag']??'');
        $line('Feature flag seguro',($report['feature_flag_ok']??false)===true,'valor='.($flag===''?'AUSENTE':$flag));
        foreach(($report['extensions']??[]) as $name=>$ok)$line('PHP extension '.$name,$ok===true);
        foreach(($report['secrets']??[]) as $name=>$check)$line('Secret '.$name,($check['ok']??false)===true,($check['present']??false)?'presente':'ausente');
        $schema=$report['schema']??[];
        $line('Schema Sharky 2.0',($schema['ok']??false)===true);
        foreach(($schema['missing_tables']??[]) as $item)fwrite(STDOUT,"  - tabla faltante: $item\n");
        foreach(($schema['missing_columns']??[]) as $item)fwrite(STDOUT,"  - columna faltante: $item\n");
        foreach(($schema['missing_indexes']??[]) as $item)fwrite(STDOUT,"  - índice faltante: $item\n");
        foreach(($schema['missing_constraints']??[]) as $item)fwrite(STDOUT,"  - constraint faltante: $item\n");
        if(!$allowEnabled)$line('Colas limpias para cutover',($report['clean_cutover_ok']??false)===true);
        if(is_array($report['data']??null)){
            foreach($report['data'] as $name=>$count)fwrite(STDOUT,sprintf("[INFO] %s=%s\n",$name,$count===null?'n/a':(string)$count));
        }
        fwrite(STDOUT,($report['ok']??false)?"SHARKY_PREFLIGHT_OK\n":"SHARKY_PREFLIGHT_BLOCKED\n");
    }
    exit(($report['ok']??false)?0:1);
}catch(Throwable $e){
    if($json)fwrite(STDOUT,json_encode(['ok'=>false,'error'=>'PREFLIGHT_EXCEPTION'],JSON_UNESCAPED_SLASHES).PHP_EOL);
    else fwrite(STDERR,'Sharky preflight: '.$e->getMessage().PHP_EOL);
    exit(1);
}
