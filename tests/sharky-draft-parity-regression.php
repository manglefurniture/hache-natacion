<?php

declare(strict_types=1);

function hache_sharky_human_request(string $text): bool{return str_contains($text,'HANDOFF');}
function hache_sharky_answer_needs_human(string $answer): bool{return str_contains($answer,'NEEDS_HUMAN');}
function hache_sharky_orchestrator_contact_hash(string $contact): string{return hash('sha256','test|'.$contact);}

require __DIR__.'/../config/sharky-draft-parity.php';

function expect_draft_parity(bool $ok,string $message): void{if(!$ok){fwrite(STDERR,$message."\n");exit(1);}}

expect_draft_parity(hache_sharky_draft_requires_handoff('HANDOFF por regla compartida'),'El Draft debe delegar el handoff en la regla compartida del v2.');
expect_draft_parity(!hache_sharky_draft_requires_handoff('conversación normal'),'El Draft no debe inventar un handoff propio.');

$payload=['entry'=>[['changes'=>[['value'=>['metadata'=>['phone_number_id'=>'pn1'],'messages'=>[
    ['id'=>'a1','from'=>'5219981112233','timestamp'=>'1788380000','type'=>'audio','audio'=>['id'=>'media-1'],'referral'=>['source_type'=>'ad','source_id'=>'ad-9','ctwa_clid'=>'clid-9','headline'=>'Intensivo septiembre']],
]]]]]]];
$audio=hache_sharky_draft_extract_audio_events($payload);
expect_draft_parity(count($audio)===1,'El lab debe conservar notas de voz como eventos procesables.');
expect_draft_parity(($audio[0]['media_id']??'')==='media-1','El evento de audio debe conservar media_id.');
expect_draft_parity(($audio[0]['referral']['source_id']??'')==='ad-9','Una nota de voz llegada desde anuncio debe conservar referral.');

$message=hache_sharky_draft_registration_message([
    'ok'=>true,
    'result'=>['code'=>'CREATED','price'=>1200],
],[
    'sharky_pago_institucion'=>'Mercado Pago W',
    'sharky_pago_beneficiario'=>'Heidy Garcia Liranza',
    'sharky_pago_clabe'=>'722969010319748145',
    'sharky_recargo_tarjeta_pct'=>'5',
]);
expect_draft_parity(is_string($message)&&str_contains($message,'$1,200 MXN'),'El cierre debe usar el precio real de la operación.');
expect_draft_parity(str_contains((string)$message,'Reserva mínima (50%): $600 MXN'),'El cierre debe mostrar la reserva mínima del 50%.');
expect_draft_parity(str_contains((string)$message,'722969010319748145'),'El cierre debe reutilizar la transferencia configurada.');
expect_draft_parity(str_contains((string)$message,'5% de recargo'),'El cierre debe respetar el recargo configurable de tarjeta.');

$contact='5219989990011';
$path=hache_sharky_draft_escalation_path($contact);if($path!==''&&is_file($path))@unlink($path);
expect_draft_parity(!hache_sharky_draft_escalation_update($contact,'NEEDS_HUMAN intento 1',2),'El primer fallo no debe escalar antes del umbral.');
expect_draft_parity(hache_sharky_draft_escalation_update($contact,'NEEDS_HUMAN intento 2',2),'El segundo fallo debe respetar el umbral y escalar.');
expect_draft_parity(!hache_sharky_draft_escalation_update($contact,'respuesta resuelta',2),'Una respuesta resuelta debe reiniciar el contador.');
if($path!==''&&is_file($path))@unlink($path);

$batching=file_get_contents(__DIR__.'/../config/sharky-whatsapp-batching.php')?:'';
expect_draft_parity(str_contains($batching,"$batch['referral']")&&str_contains($batching,"$synthetic['referral']"),'El referral del batch debe llegar al turno sintético que verá Sharky.');

$helper=file_get_contents(__DIR__.'/../config/sharky-draft-parity.php')?:'';
expect_draft_parity(str_contains($helper,'UPDATE sharky_referrals SET alumno_id=:a'),'La conversión debe vincular la atribución previa con el alumno creado.');
expect_draft_parity(str_contains($helper,'hache_sharky_human_request'),'La política de handoff no debe duplicarse en regex dentro del Draft.');

$lab=file_get_contents(__DIR__.'/../public/api/whatsapp-orchestrator-lab.php')?:'';
foreach([
    'hache_sharky_draft_transcribe_audio',
    'hache_sharky_draft_requires_handoff',
    "'shared_v2_policy'",
    'hache_sharky_business_values',
    "'sharky_edad_minima'",
    'hache_sharky_draft_link_attribution',
    'hache_sharky_draft_escalation_update',
    'hache_sharky_draft_registration_message',
] as $marker)expect_draft_parity(str_contains($lab,$marker),'Falta paridad en lab: '.$marker);

echo "SHARKY_DRAFT_PARITY_REGRESSION_OK\n";
