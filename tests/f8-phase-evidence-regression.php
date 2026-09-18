<?php

declare(strict_types=1);

require_once __DIR__.'/../config/auditoria-unificada.php';

function f8_phase_expect(bool $condition,string $message):void
{
    if(!$condition){
        fwrite(STDERR,"F8_PHASE_EVIDENCE_FAIL: {$message}\n");
        exit(1);
    }
}

$known=hache_auditoria_evento_normalizar([
    'id'=>'known','usuario_id'=>'u1','usuario_nombre'=>'admin',
    'accion'=>'CONFIG_ALERTA_ACTUALIZADA','entidad'=>'configuracion','entidad_id'=>'cfg1',
    'detalle'=>json_encode(['anterior'=>'3','nuevo'=>'5']),
    'metodo'=>'POST','ruta'=>'/api/configuracion.php','created_at'=>'2026-09-18 10:00:00',
]);
f8_phase_expect($known['before']['available']===true&&$known['after']['available']===true,'Debe existir un caso con before/after durable.');
f8_phase_expect($known['actor']['known']===true&&$known['occurred_at']['known']===true,'Debe conservar actor y timestamp reales.');
f8_phase_expect($known['entity']['id']==='cfg1','Debe conservar entidad/referencia exacta.');
f8_phase_expect($known['result']['level']==='confirmed','El cambio durable debe seguir siendo confirmado.');

$unknown=hache_auditoria_evento_normalizar([
    'id'=>'unknown','usuario_id'=>null,'usuario_nombre'=>null,
    'accion'=>'MODIFICAR','entidad'=>'api','entidad_id'=>null,
    'detalle'=>json_encode(['http_status'=>204]),
    'metodo'=>'POST','ruta'=>'/api/example.php','created_at'=>'2026-09-18 10:01:00',
]);
f8_phase_expect($unknown['before']['available']===false&&$unknown['before']['value']===null,'Before inexistente debe permanecer desconocido/null.');
f8_phase_expect($unknown['actor']['known']===false&&$unknown['actor']['type']==='unknown','Actor ausente debe permanecer desconocido.');
f8_phase_expect($unknown['result']['level']==='technical','Resultado técnico no puede elevarse a cambio confirmado.');

$history=hache_auditoria_historial_normalizar([
    'id'=>'h1','alumno_id'=>'a1','tipo'=>'PAGO','fecha_hora'=>'2026-09-18 10:02:00',
    'descripcion'=>'Edición de pago. Antes: $100. Después: $120.',
    'usuario_id'=>'u1','usuario_nombre'=>'admin','referencia_tipo'=>'PAGO','referencia_id'=>'p1',
]);
f8_phase_expect($history['coverage']['before_after']==='textual','Historial textual debe convivir sin convertirse en snapshot estructurado.');
f8_phase_expect($history['before']['available']===false&&$history['after']['available']===false,'No debe reconstruirse before/after desde texto histórico.');
f8_phase_expect($history['entity']['reference_id']==='p1','Debe conservar referencia original de historial.');

$events=[$known,$unknown,$history];
$count=count($events);
hache_auditoria_ordenar($events);
f8_phase_expect(count($events)===$count,'La ordenación no debe deduplicar evidencias.');
f8_phase_expect(count(array_unique(array_column($events,'id')))===$count,'Fuentes distintas deben conservar IDs independientes.');

$api=file_get_contents(__DIR__.'/../api/auditoria-unificada.php')?:'';
$ui=file_get_contents(__DIR__.'/../public/auditoria.php')?:'';
f8_phase_expect(str_contains($api,"auth_require(['ADMIN'])"),'El backend F8 debe permanecer ADMIN-only.');
f8_phase_expect(str_contains($ui,"page_require(['ADMIN'])"),'La UI F8 debe permanecer ADMIN-only.');
f8_phase_expect(str_contains($api,"'read_only'=>true"),'La API debe declarar lectura read-only.');
f8_phase_expect(!preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\b/i',$api),'La lectura F8 no debe escribir en fuentes de dominio.');
f8_phase_expect(str_contains($api,"'correlacion'=>'sin_heuristicas'"),'Debe declarar ausencia de correlación heurística.');
f8_phase_expect(!str_contains($api,'contact_hash')&&!preg_match('/SELECT[^;]+\bip\b/is',$api),'La proyección no debe ampliar datos sensibles.');

fwrite(STDOUT,"F8_PHASE_EVIDENCE_REGRESSION_OK\n");
