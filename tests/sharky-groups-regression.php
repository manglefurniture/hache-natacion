<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-groups.php';

function groups_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY GROUPS FAIL: $message\n");exit(1);}
}

$row=hache_sharky_groups_config_row();
groups_ok(($row['clave']??'')==='sharky_grupos_habilitado','The backend key must be stable.');
groups_ok(($row['valor']??'')==='0','Group replies must be disabled by default.');
groups_ok(hache_sharky_groups_config_valid('0')&&hache_sharky_groups_config_valid('1'),'The toggle must accept 0/1.');
groups_ok(!hache_sharky_groups_config_valid('2')&&!hache_sharky_groups_config_valid('true'),'The toggle must reject ambiguous values.');

$payload=['entry'=>[['changes'=>[['value'=>['messages'=>[
    ['id'=>'direct.1','from'=>'529980000001','type'=>'text','text'=>['body'=>'Hola']],
    ['id'=>'group.1','from'=>'529980000002','group_id'=>'GROUP_ABC','type'=>'text','text'=>['body'=>'Hola grupo']],
]]]]]]];
groups_ok(hache_sharky_groups_count_messages($payload)===1,'Exactly one group message must be detected.');
$filtered=hache_sharky_groups_filter_payload($payload,false);
$filteredMessages=$filtered['entry'][0]['changes'][0]['value']['messages']??[];
groups_ok(count($filteredMessages)===1&&($filteredMessages[0]['id']??'')==='direct.1','Disabled mode must drop group traffic before normalization.');
$enabled=hache_sharky_groups_filter_payload($payload,true);
groups_ok(count($enabled['entry'][0]['changes'][0]['value']['messages']??[])===2,'Enabled mode must preserve group traffic.');

$events=[['id'=>'direct.1','from'=>'529980000001'],['id'=>'group.1','from'=>'529980000002']];
$decorated=hache_sharky_groups_decorate_events($events,$payload);
groups_ok(!isset($decorated[0]['group_id']),'Direct events must stay direct.');
groups_ok(($decorated[1]['group_id']??'')==='GROUP_ABC','Enabled group events must retain group_id.');

$direct=['messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>'529980000002','type'=>'text','text'=>['preview_url'=>false,'body'=>'Respuesta']];
$queued=hache_sharky_groups_prepare_outbound($direct,'GROUP_ABC');
groups_ok(($queued['to']??'')==='529980000002','Durable outbox payload must remain fenced to participant contact.');
groups_ok(($queued['recipient_type']??'')==='individual','Recipient type must not switch to group before the final network boundary.');
groups_ok(($queued['_sharky_group']??false)===true&&($queued['_sharky_group_target']??'')==='GROUP_ABC','Durable group target must be internal metadata.');
$final=hache_sharky_groups_finalize_outbound($queued);
groups_ok(($final['recipient_type']??'')==='group'&&($final['to']??'')==='GROUP_ABC','Final network payload must target the group.');
groups_ok(!array_key_exists('_sharky_group',$final)&&!array_key_exists('_sharky_group_target',$final),'Internal group metadata must never reach Meta.');

$interactive=[
    'messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>'529980000002','type'=>'interactive',
    'interactive'=>['type'=>'button','body'=>['text'=>'¿Qué quieres ver?'],'action'=>['buttons'=>[
        ['type'=>'reply','reply'=>['id'=>'price','title'=>'Precio']],
        ['type'=>'reply','reply'=>['id'=>'hours','title'=>'Horarios']],
    ]]],
];
$groupInteractive=hache_sharky_groups_prepare_outbound($interactive,'GROUP_ABC');
groups_ok(($groupInteractive['type']??'')==='text','Unsupported interactive group UI must degrade to text.');
$body=(string)($groupInteractive['text']['body']??'');
groups_ok(str_contains($body,'Precio')&&str_contains($body,'Horarios'),'Text fallback must preserve visible choices.');

groups_ok(hache_sharky_groups_prepare_outbound($direct,'')===$direct,'No group id must leave direct payload unchanged.');

$webhook=file_get_contents(__DIR__.'/../public/api/whatsapp-orchestrator-lab.php')?:'';
$worker=file_get_contents(__DIR__.'/../config/sharky-lab-worker.php')?:'';
$batching=file_get_contents(__DIR__.'/../config/sharky-whatsapp-batching.php')?:'';
$outbox=file_get_contents(__DIR__.'/../config/sharky-outbox.php')?:'';
$inboxWorker=file_get_contents(__DIR__.'/../bin/sharky-inbox-dispatch.php')?:'';
$outboxWorker=file_get_contents(__DIR__.'/../bin/sharky-outbox-dispatch.php')?:'';
$adminApi=file_get_contents(__DIR__.'/../api/sharky-admin.php')?:'';
$adminUi=file_get_contents(__DIR__.'/../public/sharky-admin.php')?:'';
$filterPos=strpos($webhook,'hache_sharky_groups_filter_payload');
$storePos=strpos($webhook,'hache_sharky_inbox_store');
groups_ok($filterPos!==false&&$storePos!==false&&$filterPos<$storePos,'Disabled group traffic must be filtered before durable inbox persistence.');
groups_ok(str_contains($worker,"hache_sharky_outbox_enqueue_raw(\$pdo,\$contact,\$payload"),'Group replies must bypass idle sales-followup arming.');
groups_ok(str_contains($worker,'hache_sharky_groups_finalize_outbound($payload)'),'Group target must be swapped only at the final Meta send boundary.');
groups_ok(str_contains($batching,"trim((string)(\$event['group_id']??''))!==''")&&str_contains($batching,'hache_sharky_whatsapp_process_with_delivery_lock'),'Group turns must bypass contact-only debounce batching.');
groups_ok(str_contains($outbox,"'_sharky_group'")&&str_contains($outbox,'hache_sharky_groups_enabled($pdo)')&&str_contains($outbox,'GROUPS_DISABLED'),'Queued group replies must be cancelled if the admin toggle is off before send or retry.');
groups_ok(str_contains($inboxWorker,"\$groupId!==''&&!hache_sharky_groups_enabled(\$pdo)")&&str_contains($inboxWorker,"hache_sharky_metric_increment('messages_skipped_group')"),'Recovered group inbox events must be consumed without Sharky processing after groups are disabled.');
groups_ok(str_contains($outboxWorker,'hache_sharky_groups_finalize_outbound($payload)'),'Background outbox retries must finalize the durable group target before Meta send.');
groups_ok(str_contains($adminApi,"\$groupConfig['tipo']='checkbox'")&&str_contains($adminApi,'Responder en grupos de WhatsApp'),'Admin API must expose a clear checkbox.');
groups_ok(str_contains($adminUi,"type=\"checkbox\""),'Sharky backend must render checkbox configuration.');

echo "SHARKY_GROUPS_REGRESSION_OK\n";
