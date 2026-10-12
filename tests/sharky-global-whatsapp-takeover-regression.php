<?php
declare(strict_types=1);
require_once __DIR__.'/../config/sharky-whatsapp-global-takeover.php';
function assert_global(bool $ok,string $note): void{if(!$ok){fwrite(STDERR,"Global takeover regression failed: ".$note.PHP_EOL);exit(1);}}
foreach(['hache_pago_confirmado','hache_registro_recibido_portal_mx','hache_inicio_curso','hache_clase_cancelada','hache_reposicion_confirmada','hache_cambio_horario'] as $name)assert_global(hache_sharky_wa_global_operational(['type'=>'template','template'=>['name'=>$name]]),'template '.$name);
foreach(['hache_retomar_inscripcion','hache_inscripciones_abiertas_mx','hache_continuidad_intensivo','otro_nombre'] as $name)assert_global(!hache_sharky_wa_global_operational(['type'=>'template','template'=>['name'=>$name]]),'blocked '.$name);
assert_global(hache_sharky_wa_global_operational(['type'=>'text','_sharky_payment_reminder'=>['token'=>'test']]),'payment reminder text');
assert_global(!hache_sharky_wa_global_operational(['type'=>'interactive','_sharky_payment_reminder'=>['token'=>'test']]),'interactive not allowed');
assert_global(!hache_sharky_wa_global_operational(['type'=>'text','_sharky_allow_takeover'=>true]),'legacy takeover bypass blocked');
$root=dirname(__DIR__);
$files=[
  'api/sharky-admin.php'=>['GLOBAL_WHATSAPP_TAKEOVER','auth_csrf_validate'],
  'public/sharky-admin.php'=>['global-whatsapp-button','globalWhatsappRender'],
  'config/sharky-outbox.php'=>['hache_sharky_wa_global_lock($pdo,15)','hache_sharky_wa_global_active($pdo)'],
  'config/sharky-inbox.php'=>['hache_sharky_wa_global_active($pdo)'],
  'public/api/whatsapp-orchestrator-lab.php'=>['hache_sharky_wa_global_active($pdo)'],
  'public/api/whatsapp-webhook-v2.php'=>['hache_sharky_wa_global_guarded_send($pdo','hache_sharky_wa_global_active($pdo)'],
  'public/api/whatsapp-webhook.php'=>['hache_sharky_wa_global_guarded_send($pdo','hache_sharky_wa_global_active($pdo)'],
  'config/sharky-human-worker.php'=>['hache_sharky_wa_global_operational($payload)'],
];
foreach($files as $file=>$required){$src=file_get_contents($root.'/'.$file);assert_global(is_string($src),'file '.$file);foreach($required as $needle)assert_global(str_contains($src,$needle),$file.' missing '.$needle);}
$web=file_get_contents($root.'/api/sharky.php');
if(is_string($web))assert_global(!str_contains($web,'sharky-whatsapp-global-takeover'),'web unaffected');
assert_global(str_contains(file_get_contents($root.'/config/sharky-outbox.php'),'$globalOperational&&hache_sharky_wa_global_active($pdo)'),'operational notices survive individual takeover during global pause');
assert_global(str_contains(file_get_contents($root.'/config/sharky-whatsapp-global-takeover.php'),'function hache_sharky_wa_global_guarded_send'),'last-moment fallback guard exists');
echo "Sharky global WhatsApp takeover static regression OK\n";
