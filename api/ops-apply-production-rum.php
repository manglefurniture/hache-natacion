<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('X-Robots-Tag: noindex, nofollow');

function ops_rum_out(int $status,array $body): never
{
    http_response_code($status);
    echo json_encode($body,JSON_UNESCAPED_SLASHES);
    exit;
}

$remote=(string)($_SERVER['REMOTE_ADDR']??'');
if(!in_array($remote,['127.0.0.1','::1'],true))ops_rum_out(404,['ok'=>false]);
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='POST'){
    header('Allow: POST');
    ops_rum_out(405,['ok'=>false]);
}

$tokenPath='/tmp/hache-rum-migration-token';
$mtime=@filemtime($tokenPath);
if(!is_int($mtime)||$mtime<time()-120||!is_readable($tokenPath))ops_rum_out(404,['ok'=>false]);
$expected=trim((string)@file_get_contents($tokenPath));
$provided=trim((string)($_SERVER['HTTP_X_HACHE_RUM_MIGRATION_TOKEN']??''));
if(!preg_match('/^[a-f0-9]{64}$/',$expected)||!hash_equals($expected,$provided))ops_rum_out(404,['ok'=>false]);

$root=dirname(__DIR__);
$migration=$root.'/database/migrations/20260905_production_rum.sql';
$expectedSha256='552548723adb25f392acecacc09c193f77acf8cc6412f48fd025f2e75bf595d0';
$actualSha256=is_readable($migration)?hash_file('sha256',$migration):false;
if(!is_string($actualSha256)||!hash_equals($expectedSha256,$actualSha256)){
    ops_rum_out(409,['ok'=>false,'error'=>'MIGRATION_HASH_MISMATCH']);
}

/** @var PDO $pdo */
$pdo=require $root.'/config/pdo.php';
$lockName='hache_production_rum_migration_20260905';
$locked=false;

try{
    $locked=(int)$pdo->query('SELECT GET_LOCK('.$pdo->quote($lockName).',10)')->fetchColumn()===1;
    if(!$locked)throw new RuntimeException('lock');
    $sql=file_get_contents($migration);
    if(!is_string($sql)||trim($sql)==='')throw new RuntimeException('migration');
    $pdo->exec($sql);

    $table=$pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='production_rum_samples' LIMIT 1")->fetchColumn();
    $columns=$pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='production_rum_samples' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
    $expectedColumns=['id','metric','value','route_group','build_id','form_factor','created_at_utc'];
    $valueMeta=$pdo->query("SELECT NUMERIC_PRECISION,NUMERIC_SCALE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='production_rum_samples' AND COLUMN_NAME='value' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $indexes=$pdo->query("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='production_rum_samples'")->fetchAll(PDO::FETCH_COLUMN);

    $columnsOk=$columns===$expectedColumns;
    $precisionOk=is_array($valueMeta)&&(int)$valueMeta['NUMERIC_PRECISION']===20&&(int)$valueMeta['NUMERIC_SCALE']===8;
    $indexesOk=in_array('PRIMARY',$indexes,true)&&in_array('idx_production_rum_window',$indexes,true)&&in_array('idx_production_rum_build',$indexes,true);
    if(strtoupper((string)$table)!=='INNODB'||!$columnsOk||!$precisionOk||!$indexesOk)throw new RuntimeException('verify');

    ops_rum_out(200,[
        'ok'=>true,
        'migration'=>'20260905_production_rum.sql',
        'table'=>'production_rum_samples',
        'engine'=>'InnoDB',
        'columns_verified'=>true,
        'value_precision_verified'=>true,
        'indexes_verified'=>true,
    ]);
}catch(Throwable $e){
    ops_rum_out(500,['ok'=>false,'error'=>'PRODUCTION_RUM_MIGRATION_FAILED']);
}finally{
    if($locked){
        try{$pdo->query('SELECT RELEASE_LOCK('.$pdo->quote($lockName).')');}catch(Throwable $e){}
    }
}
