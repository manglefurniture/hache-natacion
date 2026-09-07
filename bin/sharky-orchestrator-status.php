<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-activation.php';

if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only\n");exit(2);}

function hache_sharky_status_member_schema(PDO $pdo): array
{
    $required=['profesores','profesor_horarios','profesor_cancelaciones','sharky_ausencia_evidencias','sharky_member_payment_intents'];
    $marks=implode(',',array_fill(0,count($required),'?'));
    $st=$pdo->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ($marks)");
    $st->execute($required);
    $present=array_values(array_unique(array_map('strval',$st->fetchAll(PDO::FETCH_COLUMN))));
    $missing=array_values(array_diff($required,$present));
    return [
        'ok'=>$missing===[],
        'required_tables'=>count($required),
        'present_tables'=>count($present),
        'missing_tables'=>$missing,
    ];
}

try{
    /** @var PDO $pdo */
    $pdo=require __DIR__.'/../config/pdo.php';
    $schema=hache_sharky_activation_schema_report($pdo);
    $data=($schema['ok']??false)?hache_sharky_activation_data_report($pdo):[];
    $rawFlag=hache_sharky_orchestrator_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED');
    $flag=in_array($rawFlag,['0','1'],true)?$rawFlag:($rawFlag===''?'MISSING':'INVALID');
    $memberSchema=hache_sharky_status_member_schema($pdo);
    $report=[
        'ok'=>($schema['ok']??false)===true&&in_array($rawFlag,['0','1'],true),
        'feature_flag'=>$flag,
        'schema'=>$schema,
        'queues'=>$data,
        'member_ops'=>[
            'schema'=>$memberSchema,
            'routing_ready'=>($memberSchema['ok']??false)===true&&$rawFlag==='1',
            'note'=>($memberSchema['ok']??false)===true
                ?($rawFlag==='1'?'Member ops schema present and Sharky enabled.':'Member ops schema present; routing remains off while Sharky is disabled.')
                :'Member ops routing remains dormant until the additive migrations are applied.',
        ],
    ];
    fwrite(STDOUT,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit($report['ok']?0:1);
}catch(Throwable $e){
    fwrite(STDERR,'Sharky status: '.$e->getMessage().PHP_EOL);exit(1);
}
