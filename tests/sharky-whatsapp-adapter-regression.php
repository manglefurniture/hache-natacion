<?php

declare(strict_types=1);

require __DIR__.'/../config/sharky-whatsapp-adapter.php';

function expect_adapter(bool $ok,string $message): void{if(!$ok){fwrite(STDERR,$message."\n");exit(1);}}

$payload=['entry'=>[['changes'=>[['value'=>['metadata'=>['phone_number_id'=>'pn1'],'messages'=>[
    ['id'=>'m1','from'=>'5219981112233','timestamp'=>'1788380000','type'=>'text','text'=>['body'=>'Hola'],'referral'=>['source_type'=>'ad','source_id'=>'ad-77','ctwa_clid'=>'clid-1','headline'=>'Intensivo septiembre']],
    ['id'=>'m2','from'=>'5219981112233','timestamp'=>'1788380001','type'=>'interactive','interactive'=>['type'=>'button_reply','button_reply'=>['id'=>'identity:student','title'=>'Ya soy alumno']]],
    ['id'=>'m3','from'=>'5219981112233','timestamp'=>'1788380002','type'=>'interactive','interactive'=>['type'=>'list_reply','list_reply'=>['id'=>'course:abc','title'=>'07/09/2026']]],
]]]]]]];
$events=hache_sharky_whatsapp_extract($payload);
expect_adapter(count($events)===3,'Debe extraer texto, botón y lista.');
expect_adapter(($events[0]['referral']['source_id']??'')==='ad-77','Debe conservar referral del anuncio.');
expect_adapter(($events[1]['interactive_id']??'')==='identity:student','Debe conservar el id del botón.');
expect_adapter(($events[2]['interactive_id']??'')==='course:abc','Debe conservar el id de lista.');

$clean=hache_sharky_whatsapp_clean_answer("Hola\nHola\n\nPrecio: 1200\nPrecio: 1200");
expect_adapter(substr_count($clean,'Hola')===1,'Debe quitar líneas repetidas consecutivas.');
expect_adapter(substr_count($clean,'Precio: 1200')===1,'Debe evitar repetición semántica exacta consecutiva.');
expect_adapter(mb_strlen(hache_sharky_whatsapp_clean_answer(str_repeat('texto largo. ',300)))<=1401,'Debe limitar una respuesta excesivamente larga.');

$decision=hache_sharky_orchestrator_decision('x','Elige',['type'=>'buttons','buttons'=>[
    hache_sharky_orchestrator_button('a','Uno'),hache_sharky_orchestrator_button('b','Dos'),hache_sharky_orchestrator_button('c','Tres'),hache_sharky_orchestrator_button('d','Cuatro')
]]);
$render=hache_sharky_whatsapp_render('529981112233',$decision);
expect_adapter(($render['type']??'')==='interactive','Los botones deben producir payload interactivo.');
expect_adapter(count($render['interactive']['action']['buttons']??[])===3,'WhatsApp debe recibir máximo tres botones.');

$listOptions=[];for($i=1;$i<=12;$i++)$listOptions[]=['id'=>'x'.$i,'title'=>'Opción '.$i,'description'=>'Descripción'];
$render=hache_sharky_whatsapp_render('529981112233',hache_sharky_orchestrator_decision('x','Elige',['type'=>'list','options'=>$listOptions]));
expect_adapter(count($render['interactive']['action']['sections'][0]['rows']??[])===10,'La lista debe limitarse a diez opciones.');

$birth=hache_sharky_business_validate_birthdate('2000-01-01',12,'2026-09-02');
expect_adapter(($birth['age']??0)===26,'Debe calcular la edad de forma determinista.');
$rejected=false;try{hache_sharky_business_validate_birthdate('2020-01-01',12,'2026-09-02');}catch(HacheSharkyBusinessException $e){$rejected=$e->codeName==='MIN_AGE';}
expect_adapter($rejected,'Debe rechazar menores antes de emitir una acción.');

echo "SHARKY_WHATSAPP_ADAPTER_REGRESSION_OK\n";
