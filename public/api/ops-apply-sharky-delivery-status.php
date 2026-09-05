<?php

declare(strict_types=1);

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

function ops_delivery_out(int $status,array $body): never
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($body,JSON_UNESCAPED_SLASHES);
    exit;
}

$remote=(string)($_SERVER['REMOTE_ADDR']??'');
if(!in_array($remote,['127.0.0.1','::1'],true))ops_delivery_out(404,['ok'=>false]);
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='POST'){
    header('Allow: POST');
    ops_delivery_out(405,['ok'=>false]);
}
if(!hash_equals('apply-sharky-delivery-status-20260905',trim((string)($_SERVER['HTTP_X_HACHE_OPS']??'')))){
    ops_delivery_out(403,['ok'=>false]);
}

$root=dirname(__DIR__,2);
$migration=$root.'/database/migrations/20260905_sharky_delivery_status.sql';
$expectedSha256='fce763d5533cfef60bce84684aa8ebe82f0090870375beea02afcea43343a33a';
$actualSha256=is_readable($migration)?hash_file('sha256',$migration):false;
if(!is_string($actualSha256)||!hash_equals($expectedSha256,$actualSha256)){
    ops_delivery_out(409,['ok'=>false,'error'=>'MIGRATION_HASH_MISMATCH']);
}

require_once $root.'/config/sharky-activation.php';
/** @var PDO $pdo */
$pdo=require $root.'/config/pdo.php';
$lockName='hache_delivery_status_migration_20260905';
$locked=false;

try{
    $locked=(int)$pdo->query('SELECT GET_LOCK('.$pdo->quote($lockName).',10)')->fetchColumn()===1;
    if(!$locked)throw new RuntimeException('lock');

    $sql=file_get_contents($migration);
    if(!is_string($sql)||trim($sql)==='')throw new RuntimeException('migration');
    $statements=hache_sharky_activation_split_sql($sql);
    if($statements===[])throw new RuntimeException('statements');
    foreach($statements as $statement)$pdo->exec($statement);

    $column=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sharky_outbox' AND COLUMN_NAME='provider_message_id'")->fetchColumn();
    $uniqueIndex=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sharky_outbox' AND INDEX_NAME='uq_sharky_outbox_provider_message' AND NON_UNIQUE=0")->fetchColumn();
    $deliveryTable=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sharky_delivery_status'")->fetchColumn();
    $deliveryColumns=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sharky_delivery_status' AND COLUMN_NAME IN ('provider_message_id','status','status_rank','provider_event_at_utc','received_at','updated_at')")->fetchColumn();

    if($column!==1||$uniqueIndex<1||$deliveryTable!==1||$deliveryColumns!==6)throw new RuntimeException('verify');

    ops_delivery_out(200,[
        'ok'=>true,
        'migration'=>'20260905_sharky_delivery_status.sql',
        'provider_message_id_column'=>true,
        'provider_message_id_unique'=>true,
        'delivery_status_table'=>true,
        'delivery_status_columns_verified'=>true,
    ]);
}catch(Throwable $e){
    ops_delivery_out(500,['ok'=>false,'error'=>'DELIVERY_MIGRATION_FAILED']);
}finally{
    if($locked){
        try{$pdo->query('SELECT RELEASE_LOCK('.$pdo->quote($lockName).')');}catch(Throwable $e){}
    }
}
