<?php
declare(strict_types=1);

putenv('SHARKY_CONTACT_HASH_KEY='.str_repeat('k',64));
require_once __DIR__.'/../config/centro-pendientes-prospectos.php';

function prospect_pending_expect(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

$hash=str_repeat('a',64);
$sedes=['MONTEVERDE'=>['nombre'=>'Monteverde'],'PALAPAS'=>['nombre'=>'Palapas']];
$base=[
    'contact_hash'=>$hash,
    'ultimo_contacto'=>'2026-09-16 10:00:00',
    'gestion_estado'=>'SIN_GESTION',
    'sede'=>null,
    'horas_sin_seguimiento'=>30,
    'umbral_horas'=>24,
];
$sinSede=centro_pendientes_prospectos_desde_candidatos([$base],$sedes);
prospect_pending_expect(count($sinSede)===1,'Un candidato F5 debe producir un único pendiente F1.');
$identidad=(string)array_key_first($sinSede);
$item=$sinSede[$identidad];
prospect_pending_expect($item['tipo']===CENTRO_PENDIENTES_PROSPECTO_TIPO&&$item['origen_id']===$hash,'El pendiente debe conservar tipo y contact_hash como origen estable.');
prospect_pending_expect($item['sede_id']===null&&$item['sede_nombre']==='Sin sede confirmada','Un prospecto sin sede debe seguir global sin sede ficticia.');
prospect_pending_expect($item['href']==='/prospectos.php'&&$item['alumno_id']===null,'La integración debe volver al CRM sin inventar alumno.');

$withSite=$base;$withSite['sede']='MONTEVERDE';$withSite['horas_sin_seguimiento']=48;
$conSede=centro_pendientes_prospectos_desde_candidatos([$withSite],$sedes);
prospect_pending_expect((string)array_key_first($conSede)===$identidad,'Confirmar sede o aumentar horas no debe crear otra identidad de gestión.');
$conSedeItem=$conSede[$identidad];
prospect_pending_expect($conSedeItem['sede_id']===null&&$conSedeItem['sede_nombre']==='Monteverde','La sede confirmada es contexto de presentación, no scope persistido.');

$duplicado=centro_pendientes_prospectos_desde_candidatos([$base,$withSite],$sedes);
prospect_pending_expect(count($duplicado)===1,'Repetir la misma causa no debe duplicar el pendiente.');
prospect_pending_expect(centro_pendientes_prospectos_descripcion_tipo(CENTRO_PENDIENTES_PROSPECTO_TIPO)==='Prospecto sin seguimiento','El tipo debe tener etiqueta administrativa explícita.');
prospect_pending_expect(centro_pendientes_prospectos_href_historico(CENTRO_PENDIENTES_PROSPECTO_TIPO)==='/prospectos.php','El histórico debe volver al CRM.');

$api=file_get_contents(__DIR__.'/../api/pendientes.php')?:'';
prospect_pending_expect(str_contains($api,"require_once __DIR__.'/../config/centro-pendientes-prospectos.php'"),'La API debe cargar la extensión global de prospectos.');
prospect_pending_expect(str_contains($api,'$includeGlobalProspects=($me[\'rol\'] ?? \'\')===\'ADMIN\';'),'Solo ADMIN debe incorporar pendientes globales.');
prospect_pending_expect(str_contains($api,'centro_pendientes_prospectos_fuentes_activas($pdo)'),'La API debe consumir la misma fuente F5 que activa la alerta.');
prospect_pending_expect(str_contains($api,"':sede'=>\$pendiente['sede_id'] ?? null"),'La gestión debe persistir NULL para el pendiente global.');
prospect_pending_expect(str_contains($api,'pendientes_gestion_alcance_valido'),'La resolución debe validar explícitamente el scope global o de sede.');
prospect_pending_expect(str_contains($api,"return (string)(\$gestion['tipo']??'')===CENTRO_PENDIENTES_PROSPECTO_TIPO"),'NULL solo debe aceptarse para el tipo global de prospecto.');
prospect_pending_expect(str_contains($api,"(\$me['rol'] ?? '') !== 'ADMIN'"),'VERIFICADOR no debe obtener permisos de gestión.');

$migration=file_get_contents(__DIR__.'/../database/migrations/20260915_centro_pendientes.sql')?:'';
$runner=file_get_contents(__DIR__.'/../bin/migrate-centro-pendientes.php')?:'';
prospect_pending_expect(str_contains($migration,'ALTER TABLE pendientes_gestion MODIFY sede_id CHAR(36) NULL'),'La migración debe permitir scope global sin eliminar la FK.');
prospect_pending_expect(str_contains($migration,'fk_pendientes_gestion_sede FOREIGN KEY (sede_id) REFERENCES sedes(id)'),'La FK de sede debe seguir existiendo para casos con sede.');
prospect_pending_expect(str_contains($runner,"column_name='sede_id'")&&str_contains($runner,"!== 'YES'"),'El runner debe verificar que sede_id quedó nullable.');

$page=file_get_contents(__DIR__.'/../public/pendientes.php')?:'';
prospect_pending_expect(str_contains($page,'pendientes globales visibles solo para ADMIN'),'La UI debe explicar el scope global.');
prospect_pending_expect(!str_contains($page,'permanecen fuera del Centro de pendientes'),'La UI no debe conservar el diferido ya resuelto.');
prospect_pending_expect(str_contains($page,"x.tipo==='PROSPECTO_SIN_SEGUIMIENTO'?'Prospecto'"),'La tarjeta debe identificar el caso sin inventar nombre o alumno.');

$doc=file_get_contents(__DIR__.'/../docs/F5-PROSPECT-FOLLOWUP-ALERT.md')?:'';
prospect_pending_expect(str_contains($doc,'pendientes globales')&&str_contains($doc,'sede ficticia'),'La decisión de scope global y no inventar sede debe quedar documentada.');
prospect_pending_expect(str_contains($doc,'VERIFICADOR no recibe ni gestiona estos pendientes'),'La documentación debe conservar el límite de permisos.');

echo "PROSPECT_PENDING_INTEGRATION_REGRESSION_OK\n";
