<?php

declare(strict_types=1);

function outbox_expect(bool $ok,string $message):void
{
    if(!$ok){fwrite(STDERR,"OUTBOX CONCURRENCY FAIL: $message\n");exit(1);}
}

$outbox=file_get_contents(__DIR__.'/../config/sharky-outbox.php')?:'';
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

fwrite(STDOUT,"SHARKY_OUTBOX_CONCURRENCY_OK\n");
