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
crm_expect(hache_sharky_crm_stage(['commercial_context'=>['program'=>'regular','sede_clave'=>'PALAPAS']],null)==='SEDE_CONFIRMADA','Producto y sede estructurados deben acreditar Sede confirmada.');
crm_expect(hache_sharky_crm_stage(['commercial_context'=>['program'=>'regular']],null)==='PRODUCTO_CONFIRMADO','Producto estructurado debe acreditar Producto confirmado sin inventar interés adicional.');
crm_expect(hache_sharky_crm_stage([],null)==='PROSPECTO','Sin otro hecho verificable debe mantenerse Prospecto.');

crm_expect(hache_sharky_crm_product_evidence(['commercial_context'=>['program'=>'intensive','program_button_choice'=>'learn']],'intensive')==='EXPLICITO','Aprende a nadar debe conservarse como selección explícita de intensivo.');
crm_expect(hache_sharky_crm_product_evidence(['commercial_context'=>['program'=>'regular','program_button_choice'=>'regular']],'regular')==='EXPLICITO','Clases regulares debe conservarse como selección explícita de regular.');
crm_expect(hache_sharky_crm_product_evidence(['commercial_context'=>['program'=>'intensive','program_button_choice'=>'regular','background'=>'no_formal']],'intensive')==='ELEGIBILIDAD','Regular→sin clases previas→intensivo debe distinguirse de una elección explícita de intensivo.');
crm_expect(hache_sharky_crm_product_evidence(['commercial_context'=>['program'=>'regular']],'regular')==='ESTRUCTURADO','Un producto sin marca explícita debe presentarse solo como contexto estructurado.');
crm_expect(str_contains(hache_sharky_crm_stage_evidence('INSCRITO'),'COMPLETED'),'La etapa Inscrito debe explicar su evidencia durable.');

$helper=file_get_contents(__DIR__.'/../config/sharky-crm.php')?:'';
$api=file_get_contents(__DIR__.'/../api/prospectos.php')?:'';
$page=file_get_contents(__DIR__.'/../public/prospectos.php')?:'';
$configPage=file_get_contents(__DIR__.'/../public/configuracion.php')?:'';
crm_expect(!preg_match('/\b(?:INSERT|UPDATE|DELETE)\s+(?:INTO|FROM)?/i',$helper),'La proyección CRM debe ser de solo lectura.');
crm_expect(str_contains($helper,"(status='COMPLETED') DESC"),'Una conversión completada debe prevalecer sobre intentos posteriores fallidos o cancelados.');
crm_expect(str_contains($helper,"['student_id']")&&str_contains($helper,'resolved_alumno_id'),'El CRM debe recuperar el alumno creado desde el resultado durable del registro cuando el audit no tenga alumno_id.');
crm_expect(str_contains($helper,'hache_sharky_crm_bulk_states')&&str_contains($helper,'hache_sharky_crm_bulk_referrals')&&str_contains($helper,'hache_sharky_crm_bulk_registrations'),'El listado debe resolver fuentes CRM en consultas agrupadas y evitar una consulta completa por contacto.');
crm_expect(str_contains($helper,'function hache_sharky_crm_page')&&str_contains($helper,"'total_all'"),'El CRM debe exponer paginación y el total completo del universo elegible.');
crm_expect(str_contains($helper,"'PRODUCTO_CONFIRMADO'")&&str_contains($helper,"'SEDE_CONFIRMADA'"),'El CRM debe mapear hitos intermedios solo desde estado estructurado.');
crm_expect(str_contains($helper,"'producto_evidencia_etiqueta'")&&str_contains($helper,"'estado_crm_evidencia'"),'La proyección debe explicar la evidencia de producto y etapa.');
crm_expect(!str_contains($helper,'LIMIT 300'),'El CRM no debe truncar silenciosamente a 300 contactos.');
crm_expect(str_contains($api,"auth_require(['ADMIN'])"),'El CRM debe limitar PII a ADMIN.');
crm_expect(str_contains($api,"REQUEST_METHOD")&&str_contains($api,"'GET'"),'La API debe exponer únicamente lectura GET.');
crm_expect(str_contains($api,"\$_GET['page']")&&str_contains($api,"\$_GET['q']")&&str_contains($api,"'paginacion'"),'La API debe ofrecer paginación y búsqueda global por nombre/WhatsApp.');
crm_expect(str_contains($page,'Buscar en todo el CRM por nombre o WhatsApp')&&str_contains($page,'id="prev"')&&str_contains($page,'id="next"'),'La vista debe permitir navegar y buscar fuera de la primera página.');
crm_expect(str_contains($page,'PRODUCTO_CONFIRMADO')&&str_contains($page,'SEDE_CONFIRMADA'),'La vista debe permitir filtrar los hitos verificables nuevos.');
crm_expect(str_contains($page,'estado_crm_evidencia')&&str_contains($page,'producto_evidencia_etiqueta'),'La UI debe mostrar de dónde sale cada hito sin inferencias ocultas.');
crm_expect(str_contains($page,'No cambia el funnel')&&str_contains($page,'no envía mensajes'),'La vista debe declarar que la gestión administrativa no altera Sharky ni dispara mensajes.');
crm_expect(str_contains($configPage,'href="/prospectos.php"')&&str_contains($configPage,'CRM de prospectos'),'El CRM debe quedar accesible desde Configuración.');

$management=file_get_contents(__DIR__.'/../config/sharky-crm-management.php')?:'';
$managementApi=file_get_contents(__DIR__.'/../api/prospectos-gestion.php')?:'';
$managementMigration=file_get_contents(__DIR__.'/../database/migrations/20260917_sharky_crm_managements.sql')?:'';
$managementRunner=file_get_contents(__DIR__.'/../bin/migrate-sharky-crm-managements.php')?:'';
crm_expect(str_contains($managementMigration,'CREATE TABLE IF NOT EXISTS sharky_crm_managements'),'F4.3.2 debe persistir la gestión en una tabla histórica propia.');
crm_expect(str_contains($managementMigration,'contact_hash')&&str_contains($managementMigration,'admin_user_id')&&str_contains($managementMigration,'managed_at')&&str_contains($managementMigration,'observed_last_contact_at'),'La persistencia mínima debe conservar identidad estable, ADMIN, momento y ancla temporal.');
crm_expect(!preg_match('/\b(?:nombre|telefono|whatsapp|producto|sede|campana|mensaje)\b/i',$managementMigration),'La tabla de gestión no debe duplicar PII ni contexto comercial del prospecto.');
crm_expect(str_contains($management,'hache_sharky_crm_last_contact')&&str_contains($management,"status='SENT'")&&str_contains($management,"aa.status='COMPLETED'"),'El ancla debe recalcularse desde las mismas fuentes verificables del CRM y limitarse a su universo elegible.');
crm_expect(str_contains($management,'INSERT INTO sharky_crm_managements')&&!str_contains($management,'sharky_conversation_state SET'),'La escritura debe quedar aislada de Sharky y del estado conversacional.');
crm_expect(str_contains($managementApi,"auth_require(['ADMIN'])")&&str_contains($managementApi,'auth_csrf_validate'),'Registrar gestión debe ser una acción ADMIN protegida por CSRF.');
crm_expect(str_contains($managementApi,"'REGISTRAR_GESTION'")&&str_contains($managementApi,'hache_sharky_crm_record_management'),'El endpoint de F4.3.2 debe aceptar únicamente el registro explícito previsto.');
crm_expect(str_contains($page,'Registrar gestión')&&str_contains($page,'/api/prospectos-gestion.php'),'La UI debe exponer la acción explícita sin reutilizar la API de lectura.');
crm_expect(str_contains($page,'no pausa ni reactiva Sharky')&&str_contains($page,'Centro de pendientes'),'La UI debe dejar claro que la gestión no altera takeover, follow-up ni F1.');
crm_expect(str_contains($managementRunner,'20260917_sharky_crm_managements.sql')&&str_contains($managementRunner,'sharky_crm_managements_schema_ready'),'La migración debe disponer de un runner idempotente y verificable.');

echo "Sharky CRM read-only + explicit management regression: OK\n";
