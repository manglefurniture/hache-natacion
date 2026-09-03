<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-runtime.php';
require_once __DIR__.'/../config/sharky-post-pr72.php';

function post72_expect(bool $ok,string $message):void
{
    if(!$ok){fwrite(STDERR,"POST72 FAIL: $message\n");exit(1);}
}

// Regla comercial: una reserva válida de al menos 50% puede liquidar saldo al inicio.
foreach([
    'Voy a pagar el 50% hoy y el resto el día que empieza.',
    'Ya pagué la mitad, ¿puedo liquidar el saldo el primer día?',
    'Quiero reservar pagando el total hoy.',
] as $phrase){
    post72_expect(!hache_sharky_post72_payment_exception_request($phrase),'Valid advance-payment case must not hand off: '.$phrase);
}

// 0% anticipado + pagar todo al inicio / apartar sin pago requiere decisión humana.
foreach([
    '¿Puedo pagar todo el mismo día que empieza?',
    'No quiero dar anticipo, pago completo el lunes cuando llegue.',
    '¿Me apartas el lugar y pago cuando empiece?',
    'Quiero entrar al curso y pagar ese día.',
    '¿Puedo reservar sin pagar nada por adelantado?',
] as $phrase){
    post72_expect(hache_sharky_post72_payment_exception_request($phrase),'No-advance exception must hand off: '.$phrase);
}

// Falsos positivos importantes: preguntar por formas de pago o saldo normal no es excepción.
foreach([
    '¿Puedo pagar con tarjeta?',
    '¿Cuál es la CLABE para transferir?',
    'Ya reservé, ¿cuándo puedo pagar lo que falta?',
    '¿Puedo pagar hoy el curso completo?',
] as $phrase){
    post72_expect(!hache_sharky_post72_payment_exception_request($phrase),'Ordinary payment question must not hand off: '.$phrase);
}

// Autoridad de fechas de inicio: intensivo solo lunes; regulares según sede.
$ref=new DateTimeImmutable('2026-09-01 12:00:00',new DateTimeZone('America/Cancun')); // martes
foreach([
    '¿Puedo empezar el intensivo el martes?',
    'Quiero incorporarme al curso intensivo el miércoles.',
    '¿Puedo empezar el intensivo hoy?',
    'Quiero empezar clases regulares el día 20.',
    '¿Puedo comenzar regular a mitad de mes en Monteverde?',
    '¿Puedo iniciar clases regulares a final de mes en Palapas?',
] as $phrase){
    post72_expect(is_array(hache_sharky_start_authority_handoff($phrase,$ref)),'Start-date exception must hand off: '.$phrase);
}
foreach([
    '¿Cuándo empieza el intensivo?',
    'Quiero empezar el intensivo el lunes.',
    '¿Las clases regulares empiezan a principios de mes?',
    'En Palapas, ¿puedo iniciar regular alrededor del 15?',
    'Quiero información de los horarios del intensivo por la mañana.',
    'Quiero empezar el intensivo por la mañana.',
] as $phrase){
    post72_expect(hache_sharky_start_authority_handoff($phrase,$ref)===null,'Normal start-date question must stay with Sharky: '.$phrase);
}
post72_expect(hache_sharky_start_authority_intensive_date_allowed('2026-09-07','2026-09-01'),'Future Monday must be automatable.');
post72_expect(!hache_sharky_start_authority_intensive_date_allowed('2026-09-01','2026-09-01'),'Tuesday must never be automatable.');
post72_expect(!hache_sharky_start_authority_intensive_date_allowed('2026-08-31','2026-09-01'),'Past Monday must require human authority.');

$policy=hache_sharky_post72_whatsapp_style_policy();
foreach(['sepáralos por sede o categoría','cada horario debe ir en una viñeta','Separa claramente precios de horarios','no inventes datos','al menos 50%','COMIENZAN LOS LUNES','Monteverde: inicio normal a inicios de mes','Palapas Protudec: inicio normal a inicios de mes o alrededor del día 15'] as $needle){
    post72_expect(str_contains($policy,$needle),'WhatsApp style/payment/start policy missing: '.$needle);
}

$business=[
    'sharky_pago_institucion'=>'Banco Prueba',
    'sharky_pago_beneficiario'=>'Persona Prueba',
    'sharky_pago_clabe'=>'123456789012345678',
    'sharky_recargo_tarjeta_pct'=>'5',
];
$message=hache_sharky_post72_registration_message([
    'ok'=>true,
    'code'=>'CREATED',
    'result'=>[
        'code'=>'CREATED','price'=>1200,
        'username'=>'juan.perez','temporary_password'=>'Temporal-2026',
    ],
],$business);
post72_expect(is_string($message),'Registration success message must render.');
foreach(['Total del curso: $1,200 MXN','Reserva mínima (50%): $600 MXN','Usuario: juan.perez','Contraseña temporal: Temporal-2026','CLABE: 123456789012345678','Tarjeta: 5% de recargo'] as $needle){
    post72_expect(str_contains((string)$message,$needle),'Registration message missing: '.$needle);
}

$adapter=file_get_contents(__DIR__.'/../config/sharky-whatsapp-adapter.php')?:'';
$worker=file_get_contents(__DIR__.'/../config/sharky-lab-worker.php')?:'';
$outbox=file_get_contents(__DIR__.'/../config/sharky-outbox.php')?:'';
$db=file_get_contents(__DIR__.'/../config/sharky-orchestrator-db.php')?:'';
$batching=file_get_contents(__DIR__.'/../config/sharky-whatsapp-batching.php')?:'';
$store=file_get_contents(__DIR__.'/../config/sharky-orchestrator-store.php')?:'';
$recovery=file_get_contents(__DIR__.'/../config/sharky-action-recovery.php')?:'';
$v2Webhook=file_get_contents(__DIR__.'/../public/api/whatsapp-webhook-v2.php')?:'';
$sharkyV2=file_get_contents(__DIR__.'/../public/api/sharky-v2.php')?:'';

// Presentación: el adaptador no hardcodea los horarios/precios del ejemplo y exige datos actuales.
post72_expect(str_contains($adapter,'cada horario'),'WhatsApp adapter must request one schedule per bullet.');
post72_expect(str_contains($adapter,'No inventes horarios, precios ni disponibilidad'),'WhatsApp adapter must keep backend as source of truth.');
post72_expect(!str_contains($adapter,'6:00–7:00'),'Example schedule must not be hardcoded in adapter.');

// La política nueva también protege el webhook v2 que seguirá activo con el flag apagado.
post72_expect(str_contains($v2Webhook,'hache_sharky_start_authority_handoff($text)'),'Current v2 webhook must hand off unauthorized start-date variations.');
post72_expect(str_contains($v2Webhook,"'start_date_exception'"),'Current v2 webhook must persist a distinct start-date takeover reason.');
post72_expect(str_contains($sharkyV2,'Los cursos intensivos COMIENZAN LOS LUNES'),'Current Sharky prompt must state Monday-only intensive starts.');
post72_expect(!str_contains($sharkyV2,'Puede incorporarse al curso si entra lunes o martes'),'Current Sharky prompt must not advertise Tuesday as an authorized start.');
post72_expect(str_contains($sharkyV2,'Monteverde: el inicio normal de clases regulares es a inicios de mes'),'Current Sharky prompt must state Monteverde regular start window.');
post72_expect(str_contains($sharkyV2,'Palapas Protudec: el inicio normal de clases regulares es a inicios de mes o alrededor del día 15'),'Current Sharky prompt must state Palapas regular start windows.');

// P1 Codex: outbox se persiste antes de completar receipts dentro de una transacción.
$queuePos=strpos($worker,'hache_sharky_outbox_enqueue');
$markPos=strpos($worker,'hache_sharky_orchestrator_mark_processed',$queuePos===false?0:$queuePos);
post72_expect($queuePos!==false&&$markPos!==false&&$queuePos<$markPos,'Outbound enqueue must precede receipt completion.');
post72_expect(str_contains($worker,'$pdo->beginTransaction()')&&str_contains($worker,'$pdo->commit()'),'Delivery boundary must be transactional.');
post72_expect(str_contains($worker,"'defer_receipt_completion'=>true"),'Lab adapter must defer receipt completion until outbox is durable.');
post72_expect(str_contains($batching,"defer_receipt_completion"),'Batching must preserve deferred receipt completion.');

// P1 Codex follow-up: las escrituras finales de la frontera no pueden ocultar fallos.
post72_expect(str_contains($worker,'if(!hache_sharky_action_delivery_queued_by_message'),'Delivery queue mark failure must abort the transaction.');
post72_expect(str_contains($worker,'if(!hache_sharky_orchestrator_mark_processed'),'Receipt completion failure must abort the transaction.');
post72_expect(str_contains($recovery,'function hache_sharky_action_delivery_queued_by_message(PDO $pdo,string $messageId): bool'),'Delivery queue marker must return success/failure.');
post72_expect(str_contains($store,'function hache_sharky_orchestrator_mark_processed(PDO $pdo,string $messageId): bool'),'Receipt marker must return success/failure.');

// P1 Codex follow-up: echo manual no puede despachar si takeover no quedó persistido.
$echoGuard=strpos($worker,"if(!hache_sharky_takeover_mark($contact,'manual'");
$echoDispatch=strpos($worker,"hache_sharky_outbox_dispatch($pdo,'hache_sharky_lab_send',20)");
post72_expect($echoGuard!==false&&$echoDispatch!==false&&$echoGuard<$echoDispatch,'Manual takeover must persist before pending outbox dispatch/cancellation.');

// P2 Codex: cada envío reclama su fila justo antes de mandar a Meta.
post72_expect(str_contains($outbox,'hache_sharky_outbox_claim($pdo,1)'),'Outbox dispatcher must claim one row immediately before send.');

// P2 Codex: el estado conversacional es durable/fail-closed; no cae a /var/tmp.
post72_expect(str_contains($db,'Sharky conversation state storage is unavailable'),'Durable state must fail closed when storage is unavailable.');
post72_expect(!str_contains($db,'return hache_sharky_orchestrator_state_save($contact,$state)'),'DB state save must not silently fall back to local state.');
post72_expect(!str_contains($db,'return hache_sharky_orchestrator_state_load($contact)'),'DB state load must not silently fall back to local state.');

// P2 Codex: un recovery de alta rota una credencial nueva que sí puede entregarse.
post72_expect(str_contains($db,"debe_cambiar_password=1"),'Recovered portal credential must force password change.');
post72_expect(str_contains($db,"'temporary_password'=>\$temporaryPassword"),'Recovered registration must return a deliverable temporary password.');

// Autoridad dura: el executor jamás automatiza un inicio intensivo fuera de lunes/futuro.
post72_expect(str_contains($db,'hache_sharky_start_authority_intensive_date_allowed'),'Executor must revalidate intensive start authority before business write.');
post72_expect(str_contains($db,'START_DATE_REQUIRES_HUMAN'),'Unauthorized intensive start date must have an explicit handoff code.');

fwrite(STDOUT,"SHARKY_POST_PR72_OK\n");
