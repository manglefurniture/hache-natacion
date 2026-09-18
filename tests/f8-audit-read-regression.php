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
            'inscripcion_historica_cubierta'=>['anterior'=>false,'nuevo'=>true],
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
f8_expect($studentEdit['before']['value']['inscripcion_historica_cubierta']===false&&$studentEdit['after']['value']['inscripcion_historica_cubierta']===true,'Los booleanos durables del toggle histórico deben conservarse sin reinterpretarlos.');
f8_expect(str_contains((string)$studentEdit['result']['detail'],'whatsapp'),'Debe informar qué campo PII cambió sin copiar sus valores.');
f8_expect(!str_contains(json_encode($studentEdit,JSON_UNESCAPED_UNICODE),'5550000000'),'La proyección no debe inventar ni copiar un valor PII ausente.');

$professorEdit = hache_auditoria_evento_normalizar([
    'id'=>'e-professor-edit',
    'usuario_id'=>'u1',
    'usuario_nombre'=>'admin',
    'accion'=>'PROFESOR_DATOS_ACTUALIZADOS',
    'entidad'=>'profesor',
    'entidad_id'=>'pr1',
    'detalle'=>json_encode([
        'cambios'=>[
            'activo'=>['anterior'=>true,'nuevo'=>false],
            'nombre'=>['modificado'=>true,'valores_omitidos'=>'PII'],
            'whatsapp'=>['modificado'=>true,'valores_omitidos'=>'PII'],
        ],
    ]),
    'metodo'=>'POST',
    'ruta'=>'/api/profesores.php',
    'created_at'=>'2026-09-18 15:07:00',
]);
f8_expect($professorEdit['module']==='profesores'&&$professorEdit['result']['level']==='confirmed','La edición durable del profesor debe proyectarse como cambio confirmado.');
f8_expect($professorEdit['entity']['id']==='pr1','Debe conservar el ID exacto del profesor.');
f8_expect($professorEdit['before']['available']===true&&$professorEdit['before']['value']['activo']===true,'Debe conservar el activo anterior real.');
f8_expect($professorEdit['after']['available']===true&&$professorEdit['after']['value']['activo']===false,'Debe conservar el activo nuevo real.');
f8_expect(str_contains((string)$professorEdit['result']['detail'],'nombre')&&str_contains((string)$professorEdit['result']['detail'],'whatsapp'),'Debe informar campos PII modificados sin copiar valores.');
f8_expect(!str_contains(json_encode($professorEdit,JSON_UNESCAPED_UNICODE),'Profesor Secreto'),'La proyección no debe inventar ni copiar PII ausente.');

$attendanceCorrection = hache_auditoria_evento_normalizar([
    'id'=>'e-attendance-correction',
    'usuario_id'=>'u1',
    'usuario_nombre'=>'admin',
    'accion'=>'ASISTENCIA_CORREGIDA',
    'entidad'=>'asistencia',
    'entidad_id'=>'as1',
    'detalle'=>json_encode([
        'sede_id'=>'s1',
        'sesion_id'=>'se1',
        'alumno_id'=>'a1',
        'cambios'=>[
            'estado'=>['anterior'=>'PRESENTE','nuevo'=>'AUSENTE_JUSTIFICADA'],
            'observacion'=>['modificado'=>true,'valores_omitidos'=>'contenido_libre'],
        ],
    ]),
    'metodo'=>'POST',
    'ruta'=>'/api/sesiones.php',
    'created_at'=>'2026-09-18 15:07:15',
]);
f8_expect($attendanceCorrection['module']==='operacion'&&$attendanceCorrection['result']['level']==='confirmed','La corrección durable de asistencia debe proyectarse como cambio confirmado.');
f8_expect($attendanceCorrection['entity']['id']==='as1','Debe conservar el ID exacto de la asistencia corregida.');
f8_expect($attendanceCorrection['entity']['reference_type']==='sesion'&&$attendanceCorrection['entity']['reference_id']==='se1','Debe conservar la referencia exacta a la sesión.');
f8_expect($attendanceCorrection['before']['available']===true&&$attendanceCorrection['before']['value']['estado']==='PRESENTE','Debe conservar el estado anterior real.');
f8_expect($attendanceCorrection['after']['available']===true&&$attendanceCorrection['after']['value']['estado']==='AUSENTE_JUSTIFICADA','Debe conservar el estado nuevo real.');
f8_expect(str_contains((string)$attendanceCorrection['result']['detail'],'observacion'),'Debe indicar que la observación cambió sin copiar su contenido.');
f8_expect(!str_contains(json_encode($attendanceCorrection,JSON_UNESCAPED_UNICODE),'Nota privada'),'No debe inventar ni proyectar contenido libre ausente.');
f8_expect($attendanceCorrection['scope']['sede_known']===true&&$attendanceCorrection['scope']['sede_id']==='s1','Debe conservar la sede demostrada por la operación.');

$paymentInvalidation = hache_auditoria_evento_normalizar([
    'id'=>'e-payment-invalidated',
    'usuario_id'=>'u1',
    'usuario_nombre'=>'admin',
    'accion'=>'PAGO_INVALIDADO',
    'entidad'=>'pago',
    'entidad_id'=>'p1',
    'detalle'=>json_encode([
        'sede_id'=>'s1',
        'estado_anterior'=>'VALIDO',
        'estado_nuevo'=>'INVALIDADO',
    ]),
    'metodo'=>'POST',
    'ruta'=>'/api/invalidar-pago.php',
    'created_at'=>'2026-09-18 15:07:30',
]);
f8_expect($paymentInvalidation['module']==='finanzas'&&$paymentInvalidation['result']['level']==='confirmed','La invalidación durable debe proyectarse como cambio financiero confirmado.');
f8_expect($paymentInvalidation['entity']['id']==='p1','Debe conservar el ID exacto del pago invalidado.');
f8_expect($paymentInvalidation['before']['available']===true&&$paymentInvalidation['before']['value']['estado']==='VALIDO','Debe conservar el estado anterior real del pago.');
f8_expect($paymentInvalidation['after']['available']===true&&$paymentInvalidation['after']['value']['estado']==='INVALIDADO','Debe conservar el estado nuevo real del pago.');
f8_expect($paymentInvalidation['scope']['sede_known']===true&&$paymentInvalidation['scope']['sede_id']==='s1','Debe conservar la sede demostrada por la operación.');
f8_expect($paymentInvalidation['coverage']['before_after']==='structured','La invalidación debe declarar before/after estructurado.');

$intensiveRemoval = hache_auditoria_evento_normalizar([
    'id'=>'e-intensive-removal',
    'usuario_id'=>'u1',
    'usuario_nombre'=>'admin',
    'accion'=>'INTENSIVO_ALUMNO_RETIRADO',
    'entidad'=>'intensivo-alumnos',
    'entidad_id'=>'rel1',
    'detalle'=>json_encode([
        'sede_id'=>'s1',
        'relacion_id'=>'rel1',
        'curso_intensivo_id'=>'ci1',
        'alumno_id'=>'a1',
        'presente_anterior'=>true,
        'presente_nuevo'=>false,
    ]),
    'metodo'=>'DELETE',
    'ruta'=>'/api/intensivo-alumnos.php',
    'created_at'=>'2026-09-18 15:08:00',
]);
f8_expect($intensiveRemoval['module']==='intensivos'&&$intensiveRemoval['result']['level']==='confirmed','El retiro durable debe proyectarse como cambio confirmado.');
f8_expect($intensiveRemoval['entity']['id']==='rel1','Debe conservar el ID exacto de la relación eliminada.');
f8_expect($intensiveRemoval['entity']['reference_type']==='curso_intensivo'&&$intensiveRemoval['entity']['reference_id']==='ci1','Debe conservar la referencia durable al curso.');
f8_expect($intensiveRemoval['before']['value']['presente']===true&&$intensiveRemoval['before']['value']['alumno_id']==='a1','El before debe conservar la relación real antes del borrado.');
f8_expect($intensiveRemoval['after']['value']['presente']===false,'El after debe representar únicamente la ausencia confirmada de la relación.');
f8_expect($intensiveRemoval['scope']['sede_known']===true&&$intensiveRemoval['scope']['sede_id']==='s1','Debe conservar la sede demostrada por la operación.');
f8_expect($intensiveRemoval['coverage']['before_after']==='structured','El retiro debe declarar before/after estructurado.');

$events = [$generic,$history,$state];
hache_auditoria_ordenar($events);
f8_expect($events[0]['source_id']==='h1'&&$events[2]['source_id']==='e-http','La mezcla de fuentes debe ordenarse por timestamp real sin inventar correlación.');

$endpoint = file_get_contents(__DIR__.'/../api/auditoria-unificada.php') ?: '';
f8_expect(str_contains($endpoint, "auth_require(['ADMIN'])"),'La lectura unificada debe permanecer ADMIN-only.');
f8_expect(!str_contains($endpoint, 'contact_hash'),'La API F8 no debe exponer hashes de contacto.');
f8_expect(!preg_match('/SELECT[^;]+\bip\b/is', $endpoint),'La API F8 no debe seleccionar IP del audit genérico.');
f8_expect(str_contains($endpoint, "'correlacion'=>'sin_heuristicas'"),'La respuesta debe declarar que no correlaciona fuentes heurísticamente.');

fwrite(STDOUT, "F8_AUDIT_READ_REGRESSION_OK\n");
