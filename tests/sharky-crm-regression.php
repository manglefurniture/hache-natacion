<?php
declare(strict_types=1);

require_once __DIR__.'/../config/sharky-crm.php';

function crm_expect(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,$message."\n");exit(1);}
}

$direct=['commercial_context'=>['entry_source'=>'direct']];
crm_expect(hache_sharky_crm_source($direct,null)==='direct','La fuente estructurada direct debe conservarse.');
crm_expect(hache_sharky_crm_source([],['source_type'=>'ad','ctwa_clid'=>'abc'])==='meta_ad','Un referral publicitario persistente debe conservar Meta Ads.');
crm_expect(hache_sharky_crm_source([],['source_type'=>'post','ctwa_clid'=>''])==='referral','Un referral no publicitario no debe convertirse en Meta Ads.');
crm_expect(hache_sharky_crm_source([],null)===null,'Sin evidencia no se debe inventar fuente web/directa.');

crm_expect(hache_sharky_crm_stage([],['status'=>'COMPLETED'])==='INSCRITO','Una acción de registro completada acredita Inscrito.');
crm_expect(hache_sharky_crm_stage(['flow'=>['name'=>'register_regular','step'=>'form']],null)==='INSCRIPCION_INICIADA','Un Flow regular vigente acredita inscripción iniciada.');
crm_expect(hache_sharky_crm_stage(['flow'=>['name'=>'register_intensive','step'=>'form']],null)==='INSCRIPCION_INICIADA','Un Flow intensivo vigente acredita inscripción iniciada.');
crm_expect(hache_sharky_crm_stage(['commercial_context'=>['program'=>'regular']],null)==='PROSPECTO','Producto confirmado no debe inventar una etapa comercial adicional.');

$helper=file_get_contents(__DIR__.'/../config/sharky-crm.php')?:'';
$api=file_get_contents(__DIR__.'/../api/prospectos.php')?:'';
$page=file_get_contents(__DIR__.'/../public/prospectos.php')?:'';
$configPage=file_get_contents(__DIR__.'/../public/configuracion.php')?:'';
crm_expect(!preg_match('/\b(?:INSERT|UPDATE|DELETE)\s+(?:INTO|FROM)?/i',$helper),'La proyección CRM debe ser de solo lectura.');
crm_expect(str_contains($helper,"(status='COMPLETED') DESC"),'Una conversión completada debe prevalecer sobre intentos posteriores fallidos o cancelados.');
crm_expect(str_contains($helper,'hache_sharky_crm_bulk_states')&&str_contains($helper,'hache_sharky_crm_bulk_referrals')&&str_contains($helper,'hache_sharky_crm_bulk_registrations'),'El listado debe resolver fuentes CRM en consultas agrupadas y evitar una consulta completa por contacto.');
crm_expect(str_contains($api,"auth_require(['ADMIN'])"),'El CRM debe limitar PII a ADMIN.');
crm_expect(str_contains($api,"REQUEST_METHOD")&&str_contains($api,"'GET'"),'La API debe exponer únicamente lectura GET.');
crm_expect(str_contains($page,'No cambia el funnel')&&str_contains($page,'no envía mensajes'),'La vista debe declarar su alcance de solo lectura.');
crm_expect(str_contains($configPage,'href="/prospectos.php"')&&str_contains($configPage,'CRM de prospectos'),'El CRM debe quedar accesible desde Configuración.');

echo "Sharky CRM read-only regression: OK\n";
