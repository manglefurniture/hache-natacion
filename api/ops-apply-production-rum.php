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

    $tableMeta=$pdo->query("SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='production_rum_samples' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $tableOk=is_array($tableMeta)
        && strtoupper((string)($tableMeta['ENGINE']??''))==='INNODB'
        && strtolower((string)($tableMeta['TABLE_COLLATION']??''))==='utf8mb4_unicode_ci';

    $columnRows=$pdo->query("SELECT COLUMN_NAME,LOWER(COLUMN_TYPE) AS COLUMN_TYPE,IS_NULLABLE,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='production_rum_samples' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
    $expectedColumns=[
        ['COLUMN_NAME'=>'id','COLUMN_TYPE'=>'bigint unsigned','IS_NULLABLE'=>'NO','EXTRA'=>'auto_increment','CHARACTER_SET_NAME'=>null,'COLLATION_NAME'=>null],
        ['COLUMN_NAME'=>'metric','COLUMN_TYPE'=>"enum('lcp','inp','cls')",'IS_NULLABLE'=>'NO','EXTRA'=>'','CHARACTER_SET_NAME'=>'utf8mb4','COLLATION_NAME'=>'utf8mb4_unicode_ci'],
        ['COLUMN_NAME'=>'value','COLUMN_TYPE'=>'decimal(20,8) unsigned','IS_NULLABLE'=>'NO','EXTRA'=>'','CHARACTER_SET_NAME'=>null,'COLLATION_NAME'=>null],
        ['COLUMN_NAME'=>'route_group','COLUMN_TYPE'=>'varchar(64)','IS_NULLABLE'=>'NO','EXTRA'=>'','CHARACTER_SET_NAME'=>'utf8mb4','COLLATION_NAME'=>'utf8mb4_unicode_ci'],
        ['COLUMN_NAME'=>'build_id','COLUMN_TYPE'=>'varchar(64)','IS_NULLABLE'=>'NO','EXTRA'=>'','CHARACTER_SET_NAME'=>'utf8mb4','COLLATION_NAME'=>'utf8mb4_unicode_ci'],
        ['COLUMN_NAME'=>'form_factor','COLUMN_TYPE'=>"enum('mobile','desktop')",'IS_NULLABLE'=>'NO','EXTRA'=>'','CHARACTER_SET_NAME'=>'utf8mb4','COLLATION_NAME'=>'utf8mb4_unicode_ci'],
        ['COLUMN_NAME'=>'created_at_utc','COLUMN_TYPE'=>'datetime(6)','IS_NULLABLE'=>'NO','EXTRA'=>'','CHARACTER_SET_NAME'=>null,'COLLATION_NAME'=>null],
    ];
    $columnsOk=$columnRows===$expectedColumns;

    $indexRows=$pdo->query("SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='production_rum_samples' ORDER BY INDEX_NAME,SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC);
    $requiredIndexes=[
        'PRIMARY'=>['non_unique'=>0,'type'=>'BTREE','columns'=>['id']],
        'idx_production_rum_build'=>['non_unique'=>1,'type'=>'BTREE','columns'=>['build_id','created_at_utc']],
        'idx_production_rum_window'=>['non_unique'=>1,'type'=>'BTREE','columns'=>['created_at_utc','metric','route_group','form_factor']],
    ];
    $seenIndexes=[];
    foreach($indexRows as $indexRow){
        $name=(string)($indexRow['INDEX_NAME']??'');
        if(!isset($requiredIndexes[$name]))continue;
        if(!isset($seenIndexes[$name])){
            $seenIndexes[$name]=[
                'non_unique'=>(int)($indexRow['NON_UNIQUE']??-1),
                'type'=>strtoupper((string)($indexRow['INDEX_TYPE']??'')),
                'columns'=>[],
            ];
        }
        $seenIndexes[$name]['columns'][]=(string)($indexRow['COLUMN_NAME']??'');
    }
    $indexesOk=$seenIndexes===$requiredIndexes;

    if(!$tableOk||!$columnsOk||!$indexesOk)throw new RuntimeException('verify');

    ops_rum_out(200,[
        'ok'=>true,
        'migration'=>'20260905_production_rum.sql',
        'table'=>'production_rum_samples',
        'engine'=>'InnoDB',
        'table_collation_verified'=>true,
        'columns_verified'=>true,
        'column_types_verified'=>true,
        'nullability_verified'=>true,
        'value_precision_verified'=>true,
        'enum_contract_verified'=>true,
        'indexes_verified'=>true,
    ]);
}catch(Throwable $e){
    ops_rum_out(500,['ok'=>false,'error'=>'PRODUCTION_RUM_MIGRATION_FAILED']);
}finally{
    if($locked){
        try{$pdo->query('SELECT RELEASE_LOCK('.$pdo->quote($lockName).')');}catch(Throwable $e){}
    }
}
