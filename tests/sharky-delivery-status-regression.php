<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-delivery-status.php';

function delivery_expect(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
}

$payload=[
    'entry'=>[[
        'changes'=>[[
            'value'=>[
                'metadata'=>['phone_number_id'=>'12345'],
                'statuses'=>[
                    ['id'=>'wamid.ABC123','status'=>'sent','timestamp'=>'1788570000'],
                    ['id'=>'wamid.ABC123','status'=>'delivered','timestamp'=>'1788570001'],
                    ['id'=>'wamid.ABC123','status'=>'read','timestamp'=>'1788570002'],
                    ['id'=>'wamid.FAIL','status'=>'failed','timestamp'=>'1788570003','recipient_id'=>'5219999999999'],
                    ['id'=>'wamid.UNKNOWN','status'=>'deleted','timestamp'=>'1788570004'],
                    ['id'=>'wamid.BADTIME','status'=>'sent','timestamp'=>'not-a-time'],
                ],
            ],
        ]],
    ]],
];
$events=hache_sharky_delivery_extract($payload);
delivery_expect(count($events)===4,'Only supported delivery statuses with valid timestamps should be extracted.');
delivery_expect(array_column($events,'status')===['SENT','DELIVERED','READ','FAILED'],'Status normalization/order drift.');
delivery_expect($events[0]['status_rank']===10&&$events[2]['status_rank']===30&&$events[3]['status_rank']===0,'Status ranks drift.');
delivery_expect($events[0]['provider_event_at_utc']==='2026-09-05 01:00:00','Provider Unix timestamp must normalize explicitly to UTC.');
delivery_expect(!array_key_exists('recipient_id',$events[3]),'Recipient identifiers must not survive extraction.');

$response=json_encode(['messaging_product'=>'whatsapp','messages'=>[['id'=>'wamid.PROVIDER-1']]],JSON_UNESCAPED_SLASHES);
delivery_expect(is_string($response)&&hache_sharky_delivery_provider_message_id($response)==='wamid.PROVIDER-1','Provider message id extraction failed.');
delivery_expect(hache_sharky_delivery_provider_message_id('{"messages":[]}')==='','Missing provider id must remain empty.');

$root=dirname(__DIR__);
$migration=(string)file_get_contents($root.'/database/migrations/20260905_sharky_delivery_status.sql');
$migrateRunner=(string)file_get_contents($root.'/bin/migrate-sharky-orchestrator.php');
$outbox=(string)file_get_contents($root.'/config/sharky-outbox.php');
$webhook=(string)file_get_contents($root.'/public/api/whatsapp-orchestrator-lab.php');
$collector=(string)file_get_contents($root.'/bin/production-readiness-evidence.php');

foreach([
    'provider_message_id VARCHAR(191) NULL',
    'CREATE TABLE IF NOT EXISTS sharky_delivery_status',
    "ENUM('SENT','DELIVERED','READ','FAILED')",
    'provider_event_at_utc DATETIME NOT NULL',
] as $fragment)delivery_expect(str_contains($migration,$fragment),'Missing delivery migration contract: '.$fragment);
delivery_expect(!str_contains($migration,'recipient_id'),'Delivery table must not persist recipient ids.');
delivery_expect(!str_contains($migration,'contact_hash'),'Delivery table must not persist contact hashes.');
delivery_expect(str_contains($migrateRunner,'20260905_sharky_delivery_status.sql'),'Sharky migration runner must include delivery migration.');

foreach([
    "require_once __DIR__.'/sharky-delivery-status.php'",
    "['hache_sharky_lab_send','hache_sharky_outbox_meta_send']",
    "hache_sharky_delivery_meta_send",
    'provider_message_id=:pm',
    "($sendResult['provider_message_id']??'')",
] as $fragment)delivery_expect(str_contains($outbox,$fragment),'Missing outbox delivery correlation contract: '.$fragment);

$signaturePos=strpos($webhook,'hash_equals($expectedSignature,$signature)');
$storePos=strpos($webhook,'hache_sharky_delivery_store_payload');
delivery_expect($signaturePos!==false&&$storePos!==false&&$signaturePos<$storePos,'Delivery statuses must be persisted only after Meta signature verification.');
delivery_expect(str_contains($webhook,"'Unable to persist delivery status'"),'Migrated status persistence failure must request provider retry.');

foreach([
    'delivery_schema_ready',
    'provider_delivery',
    'EVIDENCE AVAILABLE — HUMAN REVIEW REQUIRED',
    "'communication_delivery' => 'PARTIAL",
    'DELIVERED/READ evidence comes only from signed Meta status webhooks correlated by provider message id.',
] as $fragment)delivery_expect(str_contains($collector,$fragment),'Missing pilot evidence boundary: '.$fragment);
delivery_expect(!str_contains($collector,"'communication_delivery' => 'PASS'"),'Collector must never auto-PASS communication delivery.');

echo "SHARKY_DELIVERY_STATUS_REGRESSION_OK\n";
