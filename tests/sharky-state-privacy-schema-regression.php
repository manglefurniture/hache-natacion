<?php

declare(strict_types=1);

function sharky_privacy_expect(bool $ok,string $message):void
{
    if(!$ok){fwrite(STDERR,"SHARKY PRIVACY/SCHEMA FAIL: $message\n");exit(1);}
}

$db=file_get_contents(__DIR__.'/../config/sharky-orchestrator-db.php')?:'';
$env=file_get_contents(__DIR__.'/../.env.example')?:'';
$inboxWorker=file_get_contents(__DIR__.'/../bin/sharky-inbox-dispatch.php')?:'';
$baseMigration=file_get_contents(__DIR__.'/../database/migrations/20260902_sharky_orchestrator.sql')?:'';
$hardeningMigration=file_get_contents(__DIR__.'/../database/migrations/20260903_sharky_orchestrator_hardening.sql')?:'';

sharky_privacy_expect(str_contains($db,'SHARKY_STATE_ENCRYPTION_KEY'),'Conversation state must use a dedicated encryption secret.');
sharky_privacy_expect(!str_contains($db,'hache-sharky-state-cli-regression-key'),'Production state encryption must never fall back to a public CLI key.');
sharky_privacy_expect(str_contains($db,"throw new RuntimeException('SHARKY_STATE_ENCRYPTION_KEY is required before enabling Sharky 2.0')"),'State encryption must fail closed when the dedicated key is missing.');
sharky_privacy_expect(str_contains($env,'SHARKY_STATE_ENCRYPTION_KEY='),'The example environment must declare the dedicated state encryption key.');
sharky_privacy_expect(str_contains($inboxWorker,"hache_sharky_orchestrator_secret('SHARKY_STATE_ENCRYPTION_KEY')")&&str_contains($inboxWorker,'SHARKY_STATE_ENCRYPTION_KEY missing'),'The CLI inbox worker must validate the dedicated state key before processing.');
sharky_privacy_expect(str_contains($db,"aes-256-gcm")&&str_contains($db,"sharky-state-v1"),'Conversation state must use authenticated encryption.');
sharky_privacy_expect(str_contains($db,'state_ciphertext')&&str_contains($db,'state_iv')&&str_contains($db,'state_tag'),'Encrypted state fields must be used by runtime.');
sharky_privacy_expect(str_contains($db,'state_json=NULL'),'Runtime must clear the legacy plaintext state column when persisting.');
sharky_privacy_expect(str_contains($db,'function hache_sharky_db_state_purge_expired')&&str_contains($db,'DELETE FROM sharky_conversation_state WHERE expires_at<NOW()'),'Expired states must be deleted globally, not only when the same contact returns.');
sharky_privacy_expect(str_contains($db,"information_schema.columns")&&str_contains($db,"'state_ciphertext'"),'State readiness must validate the encrypted schema, not only table existence.');

foreach(['state_ciphertext MEDIUMTEXT','state_iv VARCHAR(32)','state_tag VARCHAR(32)'] as $needle){
    sharky_privacy_expect(str_contains($baseMigration,$needle),'Fresh migration missing encrypted state field: '.$needle);
}
sharky_privacy_expect(str_contains($baseMigration,'state_json JSON NULL'),'Fresh schema must not require plaintext conversation state.');

foreach([
    'ADD COLUMN IF NOT EXISTS payload_ciphertext MEDIUMTEXT',
    'ADD COLUMN IF NOT EXISTS payload_iv VARCHAR(32)',
    'ADD COLUMN IF NOT EXISTS payload_tag VARCHAR(32)',
    'ADD COLUMN IF NOT EXISTS lease_until DATETIME',
    'ADD COLUMN IF NOT EXISTS attempt_count INT UNSIGNED',
    'ADD COLUMN IF NOT EXISTS last_error VARCHAR(255)',
    'ADD COLUMN IF NOT EXISTS handoff_pending_at DATETIME',
    'ADD INDEX IF NOT EXISTS idx_sharky_receipts_lease',
    'ADD COLUMN IF NOT EXISTS state_ciphertext MEDIUMTEXT',
    'ADD COLUMN IF NOT EXISTS state_iv VARCHAR(32)',
    'ADD COLUMN IF NOT EXISTS state_tag VARCHAR(32)',
    'ADD COLUMN IF NOT EXISTS owner_token CHAR(48)',
    'ADD COLUMN IF NOT EXISTS image_url VARCHAR(1000)',
    'ADD COLUMN IF NOT EXISTS referral_json JSON',
] as $needle){
    sharky_privacy_expect(str_contains($hardeningMigration,$needle),'Incremental migration missing prior-install upgrade: '.$needle);
}
sharky_privacy_expect(str_contains($hardeningMigration,"MODIFY COLUMN state_json JSON NULL"),'Incremental migration must make legacy plaintext state nullable before resealing.');
sharky_privacy_expect(str_contains($hardeningMigration,"MODIFY COLUMN status ENUM('PENDING','COMPLETED','FAILED','CANCELLED')"),'Action audit upgrade must include CANCELLED state.');
sharky_privacy_expect(str_contains($hardeningMigration,"MODIFY COLUMN status ENUM('PENDING','SENT','DEAD','CANCELLED')"),'Outbox upgrade must include CANCELLED state.');

fwrite(STDOUT,"SHARKY_STATE_PRIVACY_SCHEMA_OK\n");
