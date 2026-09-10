<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-product-boundary-guard.php';

function product_boundary_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY PRODUCT BOUNDARY FAIL: {$message}\n");exit(1);}
}

$intensive=[
    'identity'=>['kind'=>'prospect'],
    'commercial_context'=>[
        'program'=>'intensive',
        'recommended_program'=>'intensive',
        'entry_interest'=>'intensive',
        'swim_level'=>'beginner',
        'sede_clave'=>'MONTEVERDE',
    ],
];
$regular=$intensive;$regular['commercial_context']['program']='regular';
$recommended=$intensive;unset($recommended['commercial_context']['program']);

product_boundary_ok(hache_sharky_product_boundary_weekly_frequency('Me gustaría 2 veces por semana')===2,'Real conversation: 2 veces por semana must be recognized as a weekly-frequency constraint.');
product_boundary_ok(hache_sharky_product_boundary_weekly_frequency('Quiero tres clases a la semana')===3,'Spelled-out weekly frequencies must be recognized.');
product_boundary_ok(hache_sharky_product_boundary_weekly_frequency('Cinco días x semana')===5,'x semana wording must be recognized.');
product_boundary_ok(hache_sharky_product_boundary_weekly_frequency('El intensivo dura 3 semanas')===null,'Course duration must not be mistaken for weekly frequency.');

$two=hache_sharky_product_boundary_reply('Me gustaría 2 veces por semana',$intensive)??'';
product_boundary_ok($two!=='','An intensive conversation must intercept a weekly-frequency constraint before the model can mix products.');
$twoNorm=hache_sharky_product_boundary_normalize($two);
product_boundary_ok(str_contains($twoNorm,'productos diferentes'),'The response must explicitly distinguish intensive and regular as different products.');
product_boundary_ok(str_contains($twoNorm,'3 semanas')&&str_contains($twoNorm,'lunes a viernes'),'The intensive definition must remain 3 weeks, Monday through Friday.');
product_boundary_ok(str_contains($twoNorm,'clases regulares'),'A weekly-frequency need may be explained as belonging to regular classes, but not silently switched.');
product_boundary_ok(str_contains($twoNorm,'quieres cambiar'),'The prospect must explicitly decide whether to switch products.');

product_boundary_ok(hache_sharky_product_boundary_reply('Me gustaría 2 veces por semana',$regular)===null,'Once regular is confirmed, the product boundary guard must not block regular plan selection.');
product_boundary_ok(hache_sharky_product_boundary_reply('Prefiero clases regulares',$intensive)===null,'An explicit regular choice must be allowed through so the commercial state can switch.');
product_boundary_ok(hache_sharky_product_boundary_reply('Quiero la mensualidad',$intensive)===null,'An explicit monthly regular choice must remain a valid product switch.');
product_boundary_ok(hache_sharky_product_boundary_reply('2 veces por semana',$recommended)!==null,'An intensive recommendation/entry context must not be degraded into a regular plan before explicit product choice.');

product_boundary_ok(hache_sharky_product_boundary_ambiguous_plural('De las dos'),'The real “De las dos” edge must be recognized as an ambiguous plural reference.');
product_boundary_ok(!hache_sharky_product_boundary_explicit_regular_choice('De las dos'),'“De las dos” must never count as an explicit switch to regular classes.');
$plural=hache_sharky_product_boundary_reply('De las dos',$intensive)??'';
product_boundary_ok($plural!==''&&str_contains(hache_sharky_product_boundary_normalize($plural),'no cambia el tipo de servicio'),'A bare plural reference must be prevented from silently switching products.');

$dispatcher=file_get_contents(__DIR__.'/../public/api/sharky-whatsapp-dispatch.php')?:'';
product_boundary_ok(str_contains($dispatcher,'sharky-product-boundary-guard.php'),'WhatsApp dispatcher must load the product boundary guard.');
product_boundary_ok(str_contains($dispatcher,'hache_sharky_product_boundary_reply($deterministicInput,$state)'),'Product boundary must run before the open model path.');

fwrite(STDOUT,"SHARKY_PRODUCT_BOUNDARY_OK\n");
