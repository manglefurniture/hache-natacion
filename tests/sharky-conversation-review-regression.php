<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-conversation-review.php';

function review_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY CONVERSATION REVIEW FAIL: {$message}\n");exit(1);}
}

function finding_types(array $findings): array{return array_values(array_map(static fn(array $f):string=>(string)$f['type'],$findings));}

$good=[
    ['direction'=>'out','id'=>'o1','ts'=>100,'text'=>'¿Ya sabes nadar o estás empezando desde cero?'],
    ['direction'=>'in','id'=>'i1','ts'=>110,'text'=>'Desde cero'],
    ['direction'=>'out','id'=>'o2','ts'=>120,'text'=>'El curso intensivo dura 3 semanas y cuesta $1,200 MXN. ¿Te funciona Colegio Monteverde?'],
    ['direction'=>'in','id'=>'i2','ts'=>130,'text'=>'Sí'],
    ['direction'=>'out','id'=>'o3','ts'=>140,'text'=>'Perfecto. ¿Qué horario te funciona mejor?'],
];
review_ok(hache_sharky_conversation_review_analyze($good)===[],'A healthy qualification path must remain OK.');

$repeat=[
    ['direction'=>'out','id'=>'o1','ts'=>100,'text'=>'¿Ya sabes nadar o estás empezando desde cero?'],
    ['direction'=>'in','id'=>'i1','ts'=>110,'text'=>'Sé un poco'],
    ['direction'=>'out','id'=>'o2','ts'=>120,'text'=>'¿Ya sabes nadar o estás empezando desde cero?'],
];
review_ok(in_array('REPEATED_QUESTION',finding_types(hache_sharky_conversation_review_analyze($repeat)),true),'Repeated qualification questions must be detected.');

$never=[
    ['direction'=>'out','id'=>'o1','ts'=>100,'text'=>'¿Has tomado clases formales con profesor o aprendiste por tu cuenta?'],
    ['direction'=>'in','id'=>'i1','ts'=>110,'text'=>'Nunca'],
    ['direction'=>'out','id'=>'o2','ts'=>120,'text'=>'¿Nunca te refieres a que no quieres asistir al intensivo?'],
];
$neverFindings=hache_sharky_conversation_review_analyze($never);
review_ok(in_array('CONTEXT_LOSS_SHORT_ANSWER',finding_types($neverFindings),true),'The real bare-Nunca context-loss pattern must raise a finding.');
review_ok(count(array_filter($neverFindings,static fn(array $f):bool=>$f['type']==='CONTEXT_LOSS_SHORT_ANSWER'&&$f['severity']==='HIGH'))===1,'Bare-Nunca context loss must be high severity.');

$price=[
    ['direction'=>'out','id'=>'o1','ts'=>100,'text'=>'¿Ya sabes nadar o estás empezando desde cero?'],
    ['direction'=>'in','id'=>'i1','ts'=>110,'text'=>'¿Cuál es el precio de las clases?'],
    ['direction'=>'out','id'=>'o2','ts'=>120,'text'=>'Primero dime: ¿ya sabes nadar o empiezas desde cero?'],
];
review_ok(in_array('DIRECT_PRICE_QUESTION_DEFERRED',finding_types(hache_sharky_conversation_review_analyze($price)),true),'A direct price question deferred into qualification must be reviewable.');

$drift=[
    ['direction'=>'out','id'=>'o1','ts'=>100,'text'=>'Para ti corresponde el curso intensivo.'],
    ['direction'=>'in','id'=>'i1','ts'=>110,'text'=>'¿Y el precio?'],
    ['direction'=>'out','id'=>'o2','ts'=>120,'text'=>'Las clases regulares tienen varias opciones.'],
];
review_ok(in_array('PRODUCT_DRIFT',finding_types(hache_sharky_conversation_review_analyze($drift)),true),'Silent product drift after a generic question must be high-signal.');

$explicit=[
    ['direction'=>'out','id'=>'o1','ts'=>100,'text'=>'Te explico el curso intensivo.'],
    ['direction'=>'in','id'=>'i1','ts'=>110,'text'=>'Quiero saber de las clases regulares'],
    ['direction'=>'out','id'=>'o2','ts'=>120,'text'=>'Te explico las clases regulares de forma informativa.'],
];
review_ok(!in_array('PRODUCT_DRIFT',finding_types(hache_sharky_conversation_review_analyze($explicit)),true),'An explicit cross-product question must not be flagged as silent drift.');

$correction=[
    ['direction'=>'in','id'=>'i1','ts'=>100,'text'=>'Eso ya te lo dije'],
];
review_ok(in_array('USER_CORRECTION',finding_types(hache_sharky_conversation_review_analyze($correction)),true),'Explicit user correction must raise a high-signal finding.');

$migration=(string)file_get_contents(__DIR__.'/../database/migrations/20260912_sharky_conversation_review.sql');
review_ok(str_contains($migration,'sharky_conversation_findings'),'Review findings must be durable.');
review_ok(!preg_match('/\b(?:raw_text|message_text|conversation_text)\b/i',$migration),'Review tables must not duplicate raw conversation text.');
$worker=(string)file_get_contents(__DIR__.'/../bin/sharky-inbox-dispatch.php');
review_ok(str_contains($worker,'hache_sharky_conversation_review_maybe_run($pdo)'),'Existing inbox timer must trigger the hourly review without a new systemd timer.');

fwrite(STDOUT,"SHARKY_CONVERSATION_REVIEW_OK\n");
