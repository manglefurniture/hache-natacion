<?php

declare(strict_types=1);

function rollback_expect(bool $ok,string $message):void
{
    if(!$ok){fwrite(STDERR,"SHARKY ROLLBACK FAIL: $message\n");exit(1);}
}

$root=dirname(__DIR__);
$inbox=file_get_contents($root.'/config/sharky-inbox.php')?:'';
$outbox=file_get_contents($root.'/config/sharky-outbox.php')?:'';
$worker=file_get_contents($root.'/bin/sharky-inbox-dispatch.php')?:'';
$outboxWorker=file_get_contents($root.'/bin/sharky-outbox-dispatch.php')?:'';
$webhook=file_get_contents($root.'/public/api/whatsapp-orchestrator-lab.php')?:'';

rollback_expect(str_contains($inbox,'?callable $shouldContinue=null'),'Inbox dispatcher must accept a per-event continuation guard.');
$guardPos=strpos($inbox,'if($shouldContinue!==null)');
$breakPos=strpos($inbox,'if(!$continue)break;',$guardPos===false?0:$guardPos);
$decryptPos=strpos($inbox,'hache_sharky_inbox_decrypt($row)',$guardPos===false?0:$guardPos);
rollback_expect($guardPos!==false&&$breakPos!==false&&$decryptPos!==false&&$guardPos<$breakPos&&$breakPos<$decryptPos,'Inbox rollback guard must run before decrypting/processing each event.');
rollback_expect(str_contains($worker,'$enabled=static fn():bool=>hache_sharky_orchestrator_secret(\'SHARKY_ORCHESTRATOR_LAB_ENABLED\')===\'1\';'),'CLI inbox worker must centralize the live feature-flag predicate.');
rollback_expect(str_contains($worker,'hache_sharky_inbox_dispatch($pdo,$processor,10,$enabled)'),'CLI inbox worker must pass the live flag predicate into every-event dispatch with a bounded recovery batch.');
rollback_expect(str_contains($worker,'hache_sharky_outbox_dispatch($pdo,\'hache_sharky_lab_send\',10)'),'CLI inbox worker must keep its follow-up outbox batch bounded.');
rollback_expect(str_contains($worker,'if($enabled())hache_sharky_outbox_dispatch'),'CLI inbox worker must not start final outbox dispatch after rollback.');
rollback_expect(str_contains($outboxWorker,"if(hache_sharky_orchestrator_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED')!=='1')return false;"),'Direct CLI Meta sender must independently fail closed after rollback.');
rollback_expect(str_contains($outboxWorker,'hache_sharky_outbox_dispatch($pdo,\'hache_sharky_outbox_meta_send\',10)'),'Standalone outbox worker must use a bounded batch.');

rollback_expect(str_contains($outbox,"if(hache_sharky_orchestrator_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED')!=='1')return \$stats;"),'Outbox dispatcher must fail closed unless the lab flag is exactly 1.');
rollback_expect(substr_count($outbox,"SHARKY_ORCHESTRATOR_LAB_ENABLED')!=='1'")>=4,'Outbox must revalidate the kill switch throughout the dispatch loop.');
rollback_expect(str_contains($outbox,'function hache_sharky_outbox_release_owner'),'Rollback must release a claimed PENDING outbox row without consuming a retry.');
$renewPos=strpos($outbox,'hache_sharky_outbox_renew_owner($pdo,$id,$owner)');
$flagAfterRenew=$renewPos===false?false:strpos($outbox,"SHARKY_ORCHESTRATOR_LAB_ENABLED')!=='1'",$renewPos);
$releaseAfterRenew=$renewPos===false?false:strpos($outbox,'hache_sharky_outbox_release_owner($pdo,$id,$owner)',$renewPos);
$senderPos=$renewPos===false?false:strpos($outbox,'$sender($payload)',$renewPos);
rollback_expect($renewPos!==false&&$flagAfterRenew!==false&&$releaseAfterRenew!==false&&$senderPos!==false&&$renewPos<$flagAfterRenew&&$flagAfterRenew<$releaseAfterRenew&&$releaseAfterRenew<$senderPos,'After acquiring the delivery lock, rollback must release ownership before any sender side effect.');
$lastFlag=strrpos(substr($outbox,0,(int)$senderPos),"SHARKY_ORCHESTRATOR_LAB_ENABLED')!=='1'");
rollback_expect($lastFlag!==false&&$lastFlag>$renewPos,'Outbox must revalidate the feature flag immediately before sender execution.');

$loopPos=strpos($webhook,'foreach($processing as $event){');
$webFlag=$loopPos===false?false:strpos($webhook,"SHARKY_ORCHESTRATOR_LAB_ENABLED')!=='1'",$loopPos);
$processPos=$loopPos===false?false:strpos($webhook,'hache_sharky_lab_process_event($pdo,$event',$loopPos);
rollback_expect($loopPos!==false&&$webFlag!==false&&$processPos!==false&&$loopPos<$webFlag&&$webFlag<$processPos,'In-flight webhook batches must stop before the next event when rollback flips the flag.');
rollback_expect(str_contains($webhook,"if(hache_sharky_lab_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED')==='1')hache_sharky_outbox_dispatch"),'Webhook final outbox pass must remain gated after event processing.');

fwrite(STDOUT,"SHARKY_ROLLBACK_KILL_SWITCH_OK\n");
