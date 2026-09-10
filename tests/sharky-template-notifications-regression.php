<?php
declare(strict_types=1);

function expect_template(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

$root=dirname(__DIR__);
$helper=file_get_contents($root.'/config/sharky-template-notifications.php');
$registrationNotifier=file_get_contents($root.'/config/notificaciones-email.php');
$publicRegistration=file_get_contents($root.'/public/registro.php');
$payments=file_get_contents($root.'/api/pagos.php');
$endpoint=file_get_contents($root.'/api/pago-notificacion.php');
$quickPay=file_get_contents($root.'/public/assets/alumnos-quick-pay.js');
expect_template(is_string($helper)&&is_string($registrationNotifier)&&is_string($publicRegistration)&&is_string($payments)&&is_string($endpoint)&&is_string($quickPay),'No se pudieron leer los archivos de integración');

$templates=[
    'hache_pago_confirmado',
    'hache_inscripcion_confirmada_mx',
    'hache_inicio_curso',
    'hache_clase_cancelada',
    'hache_reposicion_confirmada',
    'hache_cambio_horario',
    'hache_retomar_inscripcion',
    'hache_inscripciones_abiertas_mx',
    'hache_continuidad_intensivo',
];
foreach($templates as $template){
    expect_template(str_contains($helper,"'{$template}'"),"Falta registrar la plantilla {$template}");
}
expect_template(substr_count($helper,"_mx';")===2,'Solo inscripción confirmada e inscripciones abiertas deben usar el sufijo _mx');
expect_template(str_contains($helper,"HACHE_SHARKY_TEMPLATE_LANGUAGE_MX = 'es_MX'"),'Las plantillas deben enviarse como Spanish (MEX)');
expect_template(str_contains($helper,"'type'=>'template'"),'El payload debe usar el tipo template de WhatsApp');
expect_template(str_contains($helper,"\$template['components']=[['type'=>'body','parameters'=>\$parameters]]"),'Las variables deben viajar como parámetros del cuerpo');
expect_template(str_contains($helper,'hache_sharky_outbox_enqueue_raw'),'Las plantillas deben pasar por el outbox cifrado/idempotente');
expect_template(str_contains($helper,"SHARKY_ORCHESTRATOR_LAB_ENABLED')!=='1'"),'El envío debe respetar el kill switch de Sharky');

expect_template(str_contains($helper,'function hache_sharky_notify_enrollment_confirmed'),'Debe existir el disparador de inscripción confirmada');
expect_template(str_contains($helper,'HACHE_SHARKY_TEMPLATE_ENROLLMENT_CONFIRMED'),'La inscripción debe usar la plantilla canónica con sufijo _mx');
expect_template(str_contains($helper,"'enrollment-confirmed|student:'.\$studentId"),'La inscripción confirmada debe deduplicarse por alumno');
expect_template(str_contains($helper,"==='BAJA'"),'Una alta histórica/inactiva no debe recibir confirmación de inscripción');
expect_template(str_contains($helper,'curso_intensivo_alumnos'),'La inscripción intensiva debe poder resolver el horario desde su relación de curso');
expect_template(str_contains($helper,'hache_sharky_enrollment_date_text'),'La fecha de inicio debe convertirse a texto apto para la plantilla');

expect_template(str_contains($registrationNotifier,'function hache_notificar_nueva_inscripcion_whatsapp'),'El notificador canónico de altas debe integrar WhatsApp');
expect_template(str_contains($registrationNotifier,'hache_sharky_notify_enrollment_confirmed($pdo,$alumno,$detalle)'),'El alta debe delegar la plantilla al helper idempotente');
expect_template(str_contains($registrationNotifier,'hache_notificar_nueva_inscripcion_whatsapp($alumno,$detalle);'),'La confirmación WhatsApp debe intentarse aunque la alerta interna por correo no esté configurada');
expect_template(str_contains($publicRegistration,'hache_notificar_nueva_inscripcion($alertaAlumno,$tipo,$alertaDetalle)'),'El registro web debe pasar por el notificador canónico de inscripción');
$publicCommitPos=strpos($publicRegistration,'$pdo->commit();');
$publicNotifyPos=strpos($publicRegistration,'hache_notificar_nueva_inscripcion($alertaAlumno,$tipo,$alertaDetalle)');
expect_template($publicCommitPos!==false&&$publicNotifyPos!==false&&$publicNotifyPos>$publicCommitPos,'La web solo debe disparar la confirmación después de guardar el registro');

expect_template(str_contains($helper,"'payment-confirmed|folio:'.\$folio"),'La confirmación de pago debe deduplicarse por folio');
expect_template(str_contains($helper,"a.nombre,a.whatsapp"),'La confirmación debe resolver nombre y WhatsApp desde el alumno registrado');
expect_template(str_contains($helper,"'HISTORICAL_PAYMENT'"),'Los pagos históricos de intensivos no deben disparar mensajes actuales');
expect_template(str_contains($helper,"return 'mensualidad de '.\$months[\$month].' '.\$year"),'La mensualidad debe identificar su periodo real');

expect_template(str_contains($endpoint,"auth_require(['ADMIN'])"),'El endpoint de notificación debe exigir ADMIN');
expect_template(str_contains($endpoint,'hache_sharky_notify_payment_confirmed($pdo,$folio)'),'El endpoint debe delegar en el helper transaccional');

$decodePos=strpos($payments,"\$json=json_decode((string)\$salida,true)");
$notifyPos=strpos($payments,'hache_sharky_notify_payment_confirmed');
expect_template($decodePos!==false&&$notifyPos!==false&&$notifyPos>$decodePos,'El pago administrativo debe notificarse solo después de confirmarse');
expect_template(str_contains($payments,"(\$json['ok']??false)===true"),'Un pago fallido no debe disparar confirmación');

$paymentPost=strpos($quickPay,"fetch('/api/pagos-smart.php'");
$notificationPost=strpos($quickPay,"fetch('/api/pago-notificacion.php'");
expect_template($paymentPost!==false,'Pago rápido debe conservar su endpoint transaccional actual');
expect_template($notificationPost!==false&&$notificationPost>$paymentPost,'Pago rápido debe encolar la notificación solo después del pago');
expect_template(str_contains($quickPay,'if (data.pago?.folio)'),'Pago rápido solo debe intentar notificar con un folio confirmado');
expect_template(str_contains($quickPay,'Pago registrado, pero no se pudo preparar la confirmación por WhatsApp.'),'Un fallo de mensajería no debe revertir ni ocultar un pago ya registrado');

echo "SHARKY_TEMPLATE_NOTIFICATIONS_OK\n";
