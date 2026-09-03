<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-orchestrator.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$state = null;
$context = [
    'today' => date('Y-m-d'),
    'now' => time(),
    'identity' => ['found'=>false],
    'min_age' => 12,
    'intensive_options' => [],
];

fwrite(STDOUT, "Sharky 2.0 offline simulator (no OpenAI / no Meta / no DB writes)\n");
fwrite(STDOUT, "Commands: /known, /unknown, /human on|off, /reset, /state, /quit\n\n");
$human=false;
$counter=0;
while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') continue;
    if ($line === '/quit') break;
    if ($line === '/reset') { $state=null; fwrite(STDOUT,"state reset\n"); continue; }
    if ($line === '/known') {
        $context['identity']=['found'=>true,'student_id'=>'demo-student','name'=>'Alumno Demo','sede_clave'=>'MONTEVERDE','status'=>'ACTIVO'];
        fwrite(STDOUT,"identity: known student\n"); continue;
    }
    if ($line === '/unknown') { $context['identity']=['found'=>false]; fwrite(STDOUT,"identity: unknown\n"); continue; }
    if (str_starts_with($line,'/human ')) { $human=trim(substr($line,7))==='on'; fwrite(STDOUT,'human takeover: '.($human?'on':'off')."\n"); continue; }
    if ($line === '/state') { fwrite(STDOUT,json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n"); continue; }

    $context['now']=time();
    $context['human_takeover']=$human;
    $event=['id'=>'demo.'.(++$counter),'from'=>'529900000000','text'=>$line];
    $result=hache_sharky_orchestrate($state,$event,$context);
    $state=$result['state'];
    fwrite(STDOUT,json_encode($result['decision'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");
}
