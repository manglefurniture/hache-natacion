<?php

declare(strict_types=1);

require_once __DIR__.'/../config/auditoria-unificada.php';

function f8_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "F8_AUDIT_READ_FAIL: {$message}\n");
        exit(1);
    }
}

$generic = hache_auditoria_evento_normalizar([
    'id'=>'e-http',
    'usuario_id'=>'u1',
    'usuario_nombre'=>'admin',
    'accion'=>'MODIFICAR',
    'entidad'=>'alumno-rapido',
    'entidad_id'=>null,
    'detalle'=>json_encode(['http_status'=>200,'duracion_ms'=>14]),
    'metodo'=>'POST',
    'ruta'=>'/api/alumno-rapido.php',
    'created_at'=>'2026-09-18 15:00:00',
]);
f8_expect($generic['result']['level']==='technical','Un HTTP 2xx genérico no puede convertirse en cambio confirmado.');
f8_expect($generic['result']['code']===200,'El status HTTP real debe conservarse.');
f8_expect($generic['before']['available']===false&&$generic['before']['value']===null,'Before ausente debe permanecer explícitamente desconocido.');
f8_expect($generic['after']['available']===false&&$generic['after']['value']===null,'After ausente debe permanecer explícitamente desconocido.');
f8_expect($generic['entity']['id']===null,'No debe fabricarse ID de entidad para el audit técnico.');

$config = hache_auditoria_evento_normalizar([
    'id'=>'e-config',
    'usuario_id'=>'u1',
    'usuario_nombre'=>'admin',
    'accion'=>'CONFIG_ALERTA_ACTUALIZADA',
    'entidad'=>'configuracion',
    'entidad_id'=>'f5_example_days',
    'detalle'=>json_encode(['clave'=>'f5_example_days','anterior'=>'3','nuevo'=>'5']),
    'metodo'=>'POST',
    'ruta'=>'/api/configuracion.php',
    'created_at'=>'2026-09-18 15:01:00',
]);
f8_expect($config['result']['level']==='confirmed','El evento específico transaccional debe conservar resultado confirmado.');
f8_expect($config['before']['available']===true&&$config['before']['value']['valor']==='3','Debe proyectar únicamente el before durable.');
f8_expect($config['after']['available']===true&&$config['after']['value']['valor']==='5','Debe proyectar únicamente el after durable.');
f8_expect($config['coverage']['before_after']==='structured','La evidencia estructurada debe identificarse como tal.');

$state = hache_auditoria_evento_normalizar([
    'id'=>'e-state',
    'usuario_id'=>'u2',
    'usuario_nombre'=>'admin2',
    'accion'=>'ALUMNO_BAJA',
    'entidad'=>'alumno',
    'entidad_id'=>'a1',
    'detalle'=>json_encode(['sede_id'=>'s1','estado_anterior'=>'ACTIVO','estado_nuevo'=>'BAJA']),
    'metodo'=>'POST',
    'ruta'=>'/api/alumno-gestion.php',
    'created_at'=>'2026-09-18 15:02:00',
]);
f8_expect($state['scope']['sede_known']===true&&$state['scope']['sede_id']==='s1','La sede solo se expone cuando la evidencia la contiene.');
f8_expect($state['before']['value']['estado']==='ACTIVO'&&$state['after']['value']['estado']==='BAJA','El cambio de estado debe conservar before/after exactos.');

$unknownActor = hache_auditoria_evento_normalizar([
    'id'=>'e-unknown',
    'usuario_id'=>null,
    'usuario_nombre'=>null,
    'accion'=>'CREAR_O_EJECUTAR',
    'entidad'=>'api',
    'entidad_id'=>null,
    'detalle'=>json_encode(['http_status'=>500,'duracion_ms'=>2]),
    'metodo'=>'POST',
    'ruta'=>'/api/example.php',
    'created_at'=>'2026-09-18 15:03:00',
]);
f8_expect($unknownActor['actor']['known']===false&&$unknownActor['actor']['type']==='unknown','Actor ausente no debe etiquetarse como sistema.');

$history = hache_auditoria_historial_normalizar([
    'id'=>'h1',
    'alumno_id'=>'a1',
    'tipo'=>'PAGO',
    'fecha_hora'=>'2026-09-18 15:04:00',
    'descripcion'=>'Edición de pago. Antes: $100. Después: $120.',
    'usuario_id'=>'u1',
    'usuario_nombre'=>'admin',
    'referencia_tipo'=>'PAGO',
    'referencia_id'=>'p1',
]);
f8_expect($history['result']['level']==='confirmed','Historial durable debe conservar semántica de cambio confirmado.');
f8_expect($history['entity']['reference_id']==='p1','La referencia durable debe conservarse.');
f8_expect($history['before']['available']===false&&$history['after']['available']===false,'No se debe parsear texto histórico como before/after estructurado.');
f8_expect($history['coverage']['before_after']==='textual','La existencia de before/after textual puede declararse sin reconstruir estructura.');
f8_expect($history['scope']['sede_known']===false&&$history['scope']['sede_id']===null,'No debe inferirse sede histórica desde el alumno actual.');

$deletion = hache_auditoria_evento_normalizar([
    'id'=>'e-delete',
    'usuario_id'=>'u1',
    'usuario_nombre'=>'admin',
    'accion'=>'ELIMINAR_DEFINITIVO',
    'entidad'=>'alumno',
    'entidad_id'=>'a9',
    'detalle'=>json_encode(['alumno'=>'Nombre que no debe proyectarse','eliminados'=>['pagos'=>2]]),
    'metodo'=>'POST',
    'ruta'=>'/api/alumno-gestion.php',
    'created_at'=>'2026-09-18 15:05:00',
]);
f8_expect(!str_contains(json_encode($deletion, JSON_UNESCAPED_UNICODE), 'Nombre que no debe proyectarse'),'La proyección no debe copiar PII incidental del detalle crudo.');

$studentEdit = hache_auditoria_evento_normalizar([
    'id'=>'e-student-edit',
    'usuario_id'=>'u1',
    'usuario_nombre'=>'admin',
    'accion'=>'ALUMNO_DATOS_ACTUALIZADOS',
    'entidad'=>'alumno',
    'entidad_id'=>'a1',
    'detalle'=>json_encode([
        'sede_id'=>'s1',
        'cambios'=>[
            'horario_preferido_id'=>['anterior'=>'h1','nuevo'=>'h2'],
            'plan_actual_id'=>['anterior'=>null,'nuevo'=>'p1'],
            'whatsapp'=>['modificado'=>true,'valores_omitidos'=>'PII'],
        ],
    ]),
    'metodo'=>'POST',
    'ruta'=>'/public/editar-alumno.php',
    'created_at'=>'2026-09-18 15:06:00',
]);
f8_expect($studentEdit['result']['level']==='confirmed','La edición administrativa durable debe proyectarse como cambio confirmado.');
f8_expect($studentEdit['before']['available']===true&&$studentEdit['before']['value']['horario_preferido_id']==='h1','Debe conservar before operativo durable.');
f8_expect($studentEdit['after']['available']===true&&$studentEdit['after']['value']['horario_preferido_id']==='h2','Debe conservar after operativo durable.');
f8_expect($studentEdit['before']['value']['plan_actual_id']===null&&$studentEdit['after']['value']['plan_actual_id']==='p1','Los null reales deben conservarse sin convertirse en cero/falso.');
f8_expect(str_contains((string)$studentEdit['result']['detail'],'whatsapp'),'Debe informar qué campo PII cambió sin copiar sus valores.');
f8_expect(!str_contains(json_encode($studentEdit,JSON_UNESCAPED_UNICODE),'5550000000'),'La proyección no debe inventar ni copiar un valor PII ausente.');

$events = [$generic,$history,$state];
hache_auditoria_ordenar($events);
f8_expect($events[0]['source_id']==='h1'&&$events[2]['source_id']==='e-http','La mezcla de fuentes debe ordenarse por timestamp real sin inventar correlación.');

$endpoint = file_get_contents(__DIR__.'/../api/auditoria-unificada.php') ?: '';
f8_expect(str_contains($endpoint, "auth_require(['ADMIN'])"),'La lectura unificada debe permanecer ADMIN-only.');
f8_expect(!str_contains($endpoint, 'contact_hash'),'La API F8 no debe exponer hashes de contacto.');
f8_expect(!preg_match('/SELECT[^;]+\bip\b/is', $endpoint),'La API F8 no debe seleccionar IP del audit genérico.');
f8_expect(str_contains($endpoint, "'correlacion'=>'sin_heuristicas'"),'La respuesta debe declarar que no correlaciona fuentes heurísticamente.');

fwrite(STDOUT, "F8_AUDIT_READ_REGRESSION_OK\n");
