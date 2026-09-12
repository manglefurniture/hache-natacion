<?php

declare(strict_types=1);

function learning_ok(bool $condition,string $message): void{if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
$schema=file_get_contents(__DIR__.'/../database/migrations/20260912_sharky_learning_inbox.sql')?:'';
$service=file_get_contents(__DIR__.'/../config/sharky-learning.php')?:'';
$worker=file_get_contents(__DIR__.'/../bin/sharky-inbox-dispatch.php')?:'';
$cli=file_get_contents(__DIR__.'/../bin/sharky-learning-review.php')?:'';
$wrapper=file_get_contents(__DIR__.'/../ops/production-readiness/deploy-hache-natacion-wrapper')?:'';
$api=file_get_contents(__DIR__.'/../api/sharky-learning.php')?:'';
$ui=file_get_contents(__DIR__.'/../public/sharky-learning.php')?:'';

learning_ok(str_contains($schema,'sharky_learning_cases'),'learning table missing');
learning_ok(!str_contains($schema,'transcript')&&!str_contains($schema,'raw_text'),'schema must not persist raw conversation text');
learning_ok(str_contains($service,"'GOOD_SAMPLE'")&&str_contains($service,"'FINDING'"),'positive and negative cases must be queued');
learning_ok(str_contains($service,"'CORRECTO','MEJORABLE','ERROR_REAL','FALSO_POSITIVO','GOOD_PATTERN'"),'verdict contract missing');
learning_ok(str_contains($service,"reviewer='chatgpt'"),'ChatGPT reviewer marker missing');
learning_ok(str_contains($service,'REGRESSION_CANDIDATE'),'validated regression candidate state missing');
learning_ok(str_contains($service,'hache_sharky_learning_context_is_reviewable'),'learning export must verify Sharky participation');
learning_ok(str_contains($service,"if($in<1||$out<1)return false")&&str_contains($service,"$kind!=='GOOD_SAMPLE'||$out>=2"),'origin filter must require inbound/outbound and stronger positive sample evidence');
learning_ok(str_contains($service,'hache_sharky_learning_auto_dismiss_non_sharky')&&str_contains($service,"reviewer='system_filter'"),'non-Sharky historical cases must be auto-dismissed');
learning_ok(str_contains($worker,'hache_sharky_learning_apply_additive_migration')&&str_contains($worker,"['learning_queue']"),'worker must migrate and sync learning queue');
learning_ok(str_contains($cli,"--pending")&&str_contains($cli,"--verdict")&&str_contains($cli,"--summary"),'learning CLI modes missing');
learning_ok(str_contains($wrapper,'sharky-learning-pending')&&str_contains($wrapper,'sharky-learning-verdict')&&str_contains($wrapper,'sharky-learning-summary'),'restricted wrapper commands missing');
learning_ok(str_contains($api,"auth_require(['ADMIN'])"),'learning API must be admin-only');
learning_ok(str_contains($ui,'Bandeja de aprendizaje de Sharky'),'learning UI missing');

echo "SHARKY_LEARNING_INBOX_OK\n";
