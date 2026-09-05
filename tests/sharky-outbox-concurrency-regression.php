<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-outbox.php';

function outbox_expect(bool $ok,string $message):void
{
    if(!$ok){fwrite(STDERR,"OUTBOX CONCURRENCY FAIL: $message\n");exit(1);}
}

$outbox=file_get_contents(__DIR__.'/../config/sharky-outbox.php')?:'';
$orchestrator=file_get_contents(__DIR__.'/../config/sharky-orchestrator.php')?:'';
$migration=file_get_contents(__DIR__.'/../database/migrations/20260902_sharky_orchestrator.sql')?:'';

outbox_expect(str_contains($migration,'ALTER TABLE sharky_outbox ADD COLUMN IF NOT EXISTS owner_token CHAR(48) NULL'),'Existing installs must receive the outbox ownership fence.');
outbox_expect(str_contains($outbox,"function hache_sharky_outbox_claim(PDO \$pdo,int \$limit=10,string \$contactHash='')"),'Outbox claim must support contact-scoped claiming.');
outbox_expect(str_contains($outbox,'contact_hash=:c'),'A worker holding one contact lock must be able to claim only that contact.');
outbox_expect(str_contains($outbox,'owner_token=:o'),'Each claimed row must receive a fenced owner token.');
outbox_expect(str_contains($outbox,'AND owner_token=:o'),'Outbox mutations must require the current owner token.');
outbox_expect(str_contains($outbox,'function hache_sharky_outbox_renew_owner'),'A row must prove/renew ownership after waiting for the delivery lock.');
outbox_expect(str_contains($outbox,"SELECT 1 FROM sharky_outbox WHERE id=:id AND status='PENDING' AND owner_token=:o AND lease_until>=NOW() LIMIT 1"),'Same-second MariaDB renewals must explicitly verify the fenced lease when UPDATE reports zero changed rows.');
outbox_expect(str_contains($outbox,'if($st->rowCount()===1)return true;'),'A changed lease renewal must succeed without a fallback read.');
outbox_expect(str_contains($outbox,'return (bool)$check->fetchColumn();'),'An unchanged same-second lease must still prove current ownership before delivery.');
outbox_expect(str_contains($outbox,'$lockedHash=$lockedContact!==\'\'?hache_sharky_orchestrator_contact_hash($lockedContact):\'\''),'Dispatcher must derive the already-locked contact scope.');
outbox_expect(str_contains($outbox,'hache_sharky_outbox_claim($pdo,1,$lockedHash):hache_sharky_outbox_claim($pdo,1)'),'A worker-owned delivery lock must never claim another contact and invert lock order.');
$renewPos=strpos($outbox,'hache_sharky_outbox_renew_owner($pdo,$id,$owner)');
$takeoverPos=strpos($outbox,'hache_sharky_takeover_active($contact)',$renewPos===false?0:$renewPos);
$senderPos=strpos($outbox,'$sender($payload)',$renewPos===false?0:$renewPos);
outbox_expect($renewPos!==false&&$takeoverPos!==false&&$senderPos!==false&&$renewPos<$takeoverPos&&$takeoverPos<$senderPos,'Ownership renewal, takeover revalidation and sender must run in the fenced order.');
outbox_expect(str_contains($outbox,'owner_token=NULL'),'Terminal/retry transitions must release outbox ownership.');

// Venue choice hints are a presentation-only outbox decoration. They must not
// change interactive IDs, routing, state or any other Sharky payload semantics.
$venuePayload=[
    'messaging_product'=>'whatsapp',
    'recipient_type'=>'individual',
    'to'=>'529900000001',
    'type'=>'interactive',
    'interactive'=>[
        'type'=>'button',
        'body'=>['text'=>'Tenemos dos sedes en Cancún. ¿Cuál de las dos te queda mejor?'],
        'action'=>['buttons'=>[
            ['type'=>'reply','reply'=>['id'=>'sede:monteverde','title'=>'Monteverde']],
            ['type'=>'reply','reply'=>['id'=>'sede:palapas','title'=>'Palapas']],
        ]],
    ],
];
$decorated=hache_sharky_outbox_add_venue_hints($venuePayload);
$body=(string)($decorated['interactive']['body']['text']??'');
outbox_expect(str_contains($body,'Colegio Monteverde — al inicio de Av. Bonampak'),'Venue choices must explain the Colegio Monteverde reference.');
outbox_expect(str_contains($body,'Palapas Protudec — a 100 m del Parque de las Palapas'),'Venue choices must explain the Palapas Protudec reference.');
outbox_expect(array_column(array_column($decorated['interactive']['action']['buttons']??[],'reply'),'id')===['sede:monteverde','sede:palapas'],'Venue decoration must preserve the exact interactive IDs that carry conversation context.');
outbox_expect((string)($decorated['interactive']['action']['buttons'][0]['reply']['title']??'')==='Colegio Monteverde','Monteverde button must use the customer-facing venue name.');
outbox_expect((string)($decorated['interactive']['action']['buttons'][1]['reply']['title']??'')==='Palapas Protudec','Palapas button must use the customer-facing venue name.');
$decoratedTwice=hache_sharky_outbox_add_venue_hints($decorated);
outbox_expect(substr_count((string)($decoratedTwice['interactive']['body']['text']??''),'Av. Bonampak')===1,'Retry/de-dup paths must not duplicate the venue legend.');
$textPayload=['type'=>'text','text'=>['body'=>'Hola']];
outbox_expect(hache_sharky_outbox_add_venue_hints($textPayload)===$textPayload,'Non-venue payloads must remain byte-for-byte equivalent as arrays.');

// The intensive-registration offer is the commercial close. Improve only its
// presentation while preserving the existing controlled-flow IDs and adding
// the already-supported human takeover route.
$salesPayload=[
    'messaging_product'=>'whatsapp',
    'recipient_type'=>'individual',
    'to'=>'529900000002',
    'type'=>'interactive',
    'interactive'=>[
        'type'=>'button',
        'body'=>['text'=>'Perfecto. Ya tengo: curso intensivo en Palapas Protudec. ¿Quieres que te ayude a registrarte al curso intensivo?'],
        'action'=>['buttons'=>[
            ['type'=>'reply','reply'=>['id'=>'flow:yes','title'=>'Sí']],
            ['type'=>'reply','reply'=>['id'=>'flow:no','title'=>'No']],
            ['type'=>'reply','reply'=>['id'=>'flow:cancel','title'=>'Cancelar']],
        ]],
    ],
];
$salesClose=hache_sharky_outbox_add_sales_close($salesPayload);
$salesBody=(string)($salesClose['interactive']['body']['text']??'');
$salesReplies=array_column($salesClose['interactive']['action']['buttons']??[],'reply');
outbox_expect(str_contains($salesBody,'curso intensivo en Palapas Protudec'),'Sales close must keep the already-selected program and venue visible.');
outbox_expect(str_contains($salesBody,'apartar tu lugar ahora mismo'),'Sales close must use a clear reservation-oriented CTA.');
outbox_expect(str_contains($salesBody,'¿Cómo quieres continuar?'),'Sales close must invite one explicit next decision.');
outbox_expect(array_column($salesReplies,'id')===['flow:yes','action:human','flow:no'],'Sales close must preserve yes/no flow routing and expose the established human handoff route.');
outbox_expect(array_column($salesReplies,'title')===['Apartar mi lugar','Hablar con un profe','Ahora no'],'Sales close must use the agreed customer-facing labels.');
outbox_expect(!in_array('flow:cancel',array_column($salesReplies,'id'),true),'Sales close must not show duplicate No/Cancelar exits.');
outbox_expect(mb_strlen($salesBody)<=1024,'Sales close body must stay inside WhatsApp interactive-body limits.');
foreach($salesReplies as $reply)outbox_expect(mb_strlen((string)($reply['title']??''))<=20,'Sales close button titles must stay inside WhatsApp reply-title limits.');
outbox_expect(hache_sharky_outbox_add_sales_close($salesClose)===$salesClose,'Sales close decoration must be idempotent on retries.');
outbox_expect(str_contains($orchestrator,"'human' => ['action:human']"),'Hablar con un profe must keep using the orchestrator human-takeover intent.');

$unrelatedOffer=$salesPayload;
$unrelatedOffer['interactive']['body']['text']='¿Confirmas registrar la ausencia para mañana?';
outbox_expect(hache_sharky_outbox_add_sales_close($unrelatedOffer)===$unrelatedOffer,'Unrelated yes/no/cancel flows must not be rewritten as a sales close.');

fwrite(STDOUT,"SHARKY_OUTBOX_CONCURRENCY_OK\n");
