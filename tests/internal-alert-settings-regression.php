<?php
declare(strict_types=1);

require_once __DIR__.'/../config/internal-alert-settings.php';
require_once __DIR__.'/../config/consecutive-absence-alert.php';
require_once __DIR__.'/../config/prospect-followup-alert.php';

function alert_settings_expect(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

alert_settings_expect(in_array('sqlite',PDO::getAvailableDrivers(),true),'La regresión requiere PDO SQLite.');
$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE configuracion (clave TEXT PRIMARY KEY, valor TEXT NOT NULL)');

$defaults=hache_internal_alert_settings($pdo);
alert_settings_expect($defaults['prospect_followup_hours']===24,'El default de seguimiento debe conservar 24 horas.');
alert_settings_expect($defaults['consecutive_absences']===3,'El default general debe conservar 3 ausencias.');
alert_settings_expect($defaults['consecutive_unjustified']===2,'El default de injustificadas debe conservar 2 ausencias.');
alert_settings_expect($defaults['intensive_no_continuity_days']===null&&$defaults['intensive_no_continuity_scope']===null,'La continuidad futura debe permanecer deshabilitada hasta decisión explícita.');

$insert=$pdo->prepare('INSERT INTO configuracion(clave,valor) VALUES(:k,:v)');
foreach([
    HACHE_INTERNAL_ALERT_KEY_PROSPECT_FOLLOWUP_HOURS=>'48',
    HACHE_INTERNAL_ALERT_KEY_CONSECUTIVE_ABSENCES=>'4',
    HACHE_INTERNAL_ALERT_KEY_CONSECUTIVE_UNJUSTIFIED=>'3',
    HACHE_INTERNAL_ALERT_KEY_INTENSIVE_CONTINUITY_DAYS=>'7',
    HACHE_INTERNAL_ALERT_KEY_INTENSIVE_CONTINUITY_SCOPE=>'SIN_EVALUAR',
] as$key=>$value)$insert->execute([':k'=>$key,':v'=>$value]);
$custom=hache_internal_alert_settings($pdo);
alert_settings_expect($custom['prospect_followup_hours']===48,'El backend debe poder cambiar el umbral de seguimiento.');
alert_settings_expect($custom['consecutive_absences']===4&&$custom['consecutive_unjustified']===3,'El backend debe poder cambiar ambos umbrales de ausencia.');
alert_settings_expect($custom['intensive_no_continuity_days']===7&&$custom['intensive_no_continuity_scope']==='SIN_EVALUAR','Los parámetros futuros de continuidad deben poder persistirse sin activar todavía la regla.');

alert_settings_expect(hache_internal_alert_value_valid(HACHE_INTERNAL_ALERT_KEY_PROSPECT_FOLLOWUP_HOURS,'1'),'Una hora debe ser válida.');
alert_settings_expect(!hache_internal_alert_value_valid(HACHE_INTERNAL_ALERT_KEY_PROSPECT_FOLLOWUP_HOURS,'0'),'Cero horas debe rechazarse.');
alert_settings_expect(!hache_internal_alert_value_valid(HACHE_INTERNAL_ALERT_KEY_CONSECUTIVE_ABSENCES,'31'),'Más de 30 ausencias debe rechazarse.');
alert_settings_expect(hache_internal_alert_value_valid(HACHE_INTERNAL_ALERT_KEY_INTENSIVE_CONTINUITY_DAYS,''),'Continuidad vacía debe equivaler a no habilitada.');
alert_settings_expect(!hache_internal_alert_value_valid(HACHE_INTERNAL_ALERT_KEY_INTENSIVE_CONTINUITY_SCOPE,'OTRO'),'Un alcance desconocido debe rechazarse.');

$rows=[
    ['estado'=>'AUSENTE_NO_JUSTIFICADA','fecha'=>'2026-09-17'],
    ['estado'=>'AUSENTE_NO_JUSTIFICADA','fecha'=>'2026-09-16'],
    ['estado'=>'AUSENTE_JUSTIFICADA','fecha'=>'2026-09-15'],
];
alert_settings_expect(hache_internal_consecutive_absence_state($rows)['alerta']===true,'Los defaults 3/2 deben conservar el comportamiento actual.');
alert_settings_expect(hache_internal_consecutive_absence_state($rows,4,3)['alerta']===false,'Los umbrales configurados deben cambiar la evaluación sin cambiar las marcas.');

$now=new DateTimeImmutable('2026-09-17 12:00:00',new DateTimeZone('America/Cancun'));
$prospect=['gestion_disponible'=>true,'gestion_estado'=>'SIN_GESTION','ultimo_contacto'=>'2026-09-16 12:00:00','pausa_durable_disponible'=>true,'seguimiento_pausado'=>false];
alert_settings_expect(hache_internal_prospect_followup_due($prospect,[],$now,24*3600),'24 horas deben activar con el default vigente.');
alert_settings_expect(!hache_internal_prospect_followup_due($prospect,[],$now,48*3600),'Cambiar a 48 horas debe aplazar la misma alerta.');

$api=file_get_contents(__DIR__.'/../api/configuracion.php')?:'';
$page=file_get_contents(__DIR__.'/../public/configuracion.php')?:'';
$alertsApi=file_get_contents(__DIR__.'/../api/alertas.php')?:'';
alert_settings_expect(str_contains($api,'CONFIG_ALERTA_ACTUALIZADA')&&str_contains($api,"'anterior'=>\$before,'nuevo'=>\$after"),'Cada cambio real de alerta debe quedar auditado con antes y después.');
alert_settings_expect(str_contains($api,"$alertRows = []")&&str_contains($api,'hache_internal_alert_config_rows($pdo)'),'Solo ADMIN debe recibir la superficie de configuración de alertas.');
alert_settings_expect(str_contains($page,'Alertas internas')&&str_contains($page,'d.alertas_internas'),'El back debe mostrar un bloque específico de alertas internas.');
alert_settings_expect(str_contains($alertsApi,"$f5Settings=hache_internal_alert_settings($pdo)")&&str_contains($alertsApi,"$hours=(int)$f5Settings['prospect_followup_hours']"),'La presentación debe usar la misma configuración que la detección.');

echo "INTERNAL_ALERT_SETTINGS_REGRESSION_OK\n";
