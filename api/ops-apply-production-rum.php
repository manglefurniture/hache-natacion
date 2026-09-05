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

/** @param array<string,mixed> $row */
function ops_rum_column_base_ok(array $row,string $name,string $dataType): bool
{
    return ($row['COLUMN_NAME']??null)===$name
        && strtolower((string)($row['DATA_TYPE']??''))===$dataType
        && ($row['IS_NULLABLE']??null)==='NO'
        && (int)($row['DEFAULT_IS_NULL']??0)===1;
}

function ops_rum_schema_ready(PDO $pdo): bool
{
    $tableMeta=$pdo->query("SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='production_rum_samples' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if(!is_array($tableMeta)
        || strtoupper((string)($tableMeta['ENGINE']??''))!=='INNODB'
        || strtolower((string)($tableMeta['TABLE_COLLATION']??''))!=='utf8mb4_unicode_ci')return false;

    $rows=$pdo->query(
        "SELECT COLUMN_NAME,DATA_TYPE,LOWER(COLUMN_TYPE) AS COLUMN_TYPE,IS_NULLABLE,"
        ."(COLUMN_DEFAULT IS NULL) AS DEFAULT_IS_NULL,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,"
        ."CHARACTER_MAXIMUM_LENGTH,NUMERIC_PRECISION,NUMERIC_SCALE,DATETIME_PRECISION "
        ."FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='production_rum_samples' ORDER BY ORDINAL_POSITION"
    )->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==7)return false;

    [$id,$metric,$value,$route,$build,$form,$created]=$rows;
    if(!ops_rum_column_base_ok($id,'id','bigint')
        || !str_contains(strtolower((string)($id['COLUMN_TYPE']??'')),'unsigned')
        || strtolower((string)($id['EXTRA']??''))!=='auto_increment'
        || $id['CHARACTER_SET_NAME']!==null
        || $id['COLLATION_NAME']!==null)return false;

    if(!ops_rum_column_base_ok($metric,'metric','enum')
        || strtolower((string)($metric['COLUMN_TYPE']??''))!=="enum('lcp','inp','cls')"
        || ($metric['CHARACTER_SET_NAME']??null)!=='utf8mb4'
        || ($metric['COLLATION_NAME']??null)!=='utf8mb4_unicode_ci')return false;

    if(!ops_rum_column_base_ok($value,'value','decimal')
        || strtolower((string)($value['COLUMN_TYPE']??''))!=='decimal(20,8) unsigned'
        || (int)($value['NUMERIC_PRECISION']??0)!==20
        || (int)($value['NUMERIC_SCALE']??-1)!==8
        || $value['CHARACTER_SET_NAME']!==null
        || $value['COLLATION_NAME']!==null)return false;

    foreach([[$route,'route_group'],[$build,'build_id']] as [$row,$name]){
        if(!ops_rum_column_base_ok($row,$name,'varchar')
            || (int)($row['CHARACTER_MAXIMUM_LENGTH']??0)!==64
            || ($row['CHARACTER_SET_NAME']??null)!=='utf8mb4'
            || ($row['COLLATION_NAME']??null)!=='utf8mb4_unicode_ci')return false;
    }

    if(!ops_rum_column_base_ok($form,'form_factor','enum')
        || strtolower((string)($form['COLUMN_TYPE']??''))!=="enum('mobile','desktop')"
        || ($form['CHARACTER_SET_NAME']??null)!=='utf8mb4'
        || ($form['COLLATION_NAME']??null)!=='utf8mb4_unicode_ci')return false;

    if(!ops_rum_column_base_ok($created,'created_at_utc','datetime')
        || (int)($created['DATETIME_PRECISION']??-1)!==6
        || $created['CHARACTER_SET_NAME']!==null
        || $created['COLLATION_NAME']!==null)return false;

    $indexRows=$pdo->query("SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,INDEX_TYPE,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='production_rum_samples' ORDER BY INDEX_NAME,SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC);
    $required=[
        'PRIMARY'=>['non_unique'=>0,'type'=>'BTREE','columns'=>[['name'=>'id','sub_part'=>null]]],
        'idx_production_rum_build'=>['non_unique'=>1,'type'=>'BTREE','columns'=>[['name'=>'build_id','sub_part'=>null],['name'=>'created_at_utc','sub_part'=>null]]],
        'idx_production_rum_window'=>['non_unique'=>1,'type'=>'BTREE','columns'=>[['name'=>'created_at_utc','sub_part'=>null],['name'=>'metric','sub_part'=>null],['name'=>'route_group','sub_part'=>null],['name'=>'form_factor','sub_part'=>null]]],
    ];
    $seen=[];
    foreach($indexRows as $row){
        $name=(string)($row['INDEX_NAME']??'');
        if(!isset($required[$name]))continue;
        if(!isset($seen[$name]))$seen[$name]=[
            'non_unique'=>(int)($row['NON_UNIQUE']??-1),
            'type'=>strtoupper((string)($row['INDEX_TYPE']??'')),
            'columns'=>[],
        ];
        $seen[$name]['columns'][]=[
            'name'=>(string)($row['COLUMN_NAME']??''),
            'sub_part'=>$row['SUB_PART']===null?null:(int)$row['SUB_PART'],
        ];
    }
    if(count($seen)!==count($required))return false;
    foreach($required as $name=>$expectedIndex){if(($seen[$name]??null)!==$expectedIndex)return false;}
    return true;
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
    if(!ops_rum_schema_ready($pdo))throw new RuntimeException('verify');

    ops_rum_out(200,[
        'ok'=>true,
        'migration'=>'20260905_production_rum.sql',
        'table'=>'production_rum_samples',
        'engine'=>'InnoDB',
        'table_collation_verified'=>true,
        'columns_verified'=>true,
        'column_types_verified'=>true,
        'nullability_verified'=>true,
        'column_defaults_verified'=>true,
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
