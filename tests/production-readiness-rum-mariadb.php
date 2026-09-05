<?php

declare(strict_types=1);

function rum_db_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param array<string,mixed> $row */
function rum_db_column_base_ok(array $row,string $name,string $dataType): bool
{
    return ($row['COLUMN_NAME']??null)===$name
        && strtolower((string)($row['DATA_TYPE']??''))===$dataType
        && ($row['IS_NULLABLE']??null)==='NO'
        && (int)($row['DEFAULT_IS_NULL']??0)===1;
}

$host = (string) (getenv('DELIVERY_DB_HOST') ?: '127.0.0.1');
$port = (int) (getenv('DELIVERY_DB_PORT') ?: 3306);
$db = (string) (getenv('DELIVERY_DB_NAME') ?: 'hache_delivery_test');
$user = (string) (getenv('DELIVERY_DB_USER') ?: 'root');
$pass = (string) (getenv('DELIVERY_DB_PASS') ?: 'root');

$pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$pdo->exec('DROP TABLE IF EXISTS production_rum_samples');
$sql = (string) file_get_contents(__DIR__ . '/../database/migrations/20260905_production_rum.sql');
$sqlWithoutLineComments = preg_replace('/^\s*--.*$/m', '', $sql);
rum_db_expect(is_string($sqlWithoutLineComments), 'Unable to normalize RUM migration SQL.');
$statements = array_values(array_filter(
    array_map('trim', explode(';', $sqlWithoutLineComments)),
    static fn(string $statement): bool => $statement !== ''
));
foreach ($statements as $statement) {
    $pdo->exec($statement);
}

$tableMeta=$pdo->query(
    "SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES "
    ."WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='production_rum_samples' LIMIT 1"
)->fetch();
rum_db_expect(is_array($tableMeta), 'RUM table metadata missing.');
rum_db_expect(strtoupper((string)$tableMeta['ENGINE'])==='INNODB', 'RUM engine drift.');
rum_db_expect(strtolower((string)$tableMeta['TABLE_COLLATION'])==='utf8mb4_unicode_ci', 'RUM table collation drift.');

$rows=$pdo->query(
    "SELECT COLUMN_NAME,DATA_TYPE,LOWER(COLUMN_TYPE) AS COLUMN_TYPE,IS_NULLABLE,"
    ."(COLUMN_DEFAULT IS NULL) AS DEFAULT_IS_NULL,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,"
    ."CHARACTER_MAXIMUM_LENGTH,NUMERIC_PRECISION,NUMERIC_SCALE,DATETIME_PRECISION "
    ."FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='production_rum_samples' ORDER BY ORDINAL_POSITION"
)->fetchAll();
rum_db_expect(count($rows)===7, 'RUM table must contain exactly seven minimized evidence columns.');
[$id,$metric,$value,$route,$build,$form,$created]=$rows;

rum_db_expect(rum_db_column_base_ok($id,'id','bigint'), 'RUM id base metadata drift.');
rum_db_expect(str_contains(strtolower((string)$id['COLUMN_TYPE']),'unsigned'), 'RUM id must remain unsigned.');
rum_db_expect(strtolower((string)$id['EXTRA'])==='auto_increment', 'RUM id must remain auto_increment.');
rum_db_expect($id['CHARACTER_SET_NAME']===null&&$id['COLLATION_NAME']===null, 'RUM id must not have text collation metadata.');

rum_db_expect(rum_db_column_base_ok($metric,'metric','enum'), 'RUM metric base metadata drift.');
rum_db_expect(strtolower((string)$metric['COLUMN_TYPE'])==="enum('lcp','inp','cls')", 'RUM metric enum drift.');
rum_db_expect($metric['CHARACTER_SET_NAME']==='utf8mb4'&&$metric['COLLATION_NAME']==='utf8mb4_unicode_ci', 'RUM metric charset/collation drift.');

rum_db_expect(rum_db_column_base_ok($value,'value','decimal'), 'RUM value base metadata drift.');
rum_db_expect(strtolower((string)$value['COLUMN_TYPE'])==='decimal(20,8) unsigned', 'RUM value type drift.');
rum_db_expect((int)$value['NUMERIC_PRECISION']===20&&(int)$value['NUMERIC_SCALE']===8, 'RUM value precision/scale drift.');
rum_db_expect($value['CHARACTER_SET_NAME']===null&&$value['COLLATION_NAME']===null, 'RUM value must not have text collation metadata.');

foreach ([[$route,'route_group'],[$build,'build_id']] as [$row,$name]) {
    rum_db_expect(rum_db_column_base_ok($row,$name,'varchar'), "RUM {$name} base metadata drift.");
    rum_db_expect((int)$row['CHARACTER_MAXIMUM_LENGTH']===64, "RUM {$name} length drift.");
    rum_db_expect($row['CHARACTER_SET_NAME']==='utf8mb4'&&$row['COLLATION_NAME']==='utf8mb4_unicode_ci', "RUM {$name} charset/collation drift.");
}

rum_db_expect(rum_db_column_base_ok($form,'form_factor','enum'), 'RUM form_factor base metadata drift.');
rum_db_expect(strtolower((string)$form['COLUMN_TYPE'])==="enum('mobile','desktop')", 'RUM form_factor enum drift.');
rum_db_expect($form['CHARACTER_SET_NAME']==='utf8mb4'&&$form['COLLATION_NAME']==='utf8mb4_unicode_ci', 'RUM form_factor charset/collation drift.');

rum_db_expect(rum_db_column_base_ok($created,'created_at_utc','datetime'), 'RUM created_at_utc base metadata drift.');
rum_db_expect((int)$created['DATETIME_PRECISION']===6, 'RUM created_at_utc precision drift.');
rum_db_expect($created['CHARACTER_SET_NAME']===null&&$created['COLLATION_NAME']===null, 'RUM created_at_utc must not have text collation metadata.');

$insert = $pdo->prepare(
    'INSERT INTO production_rum_samples(metric,value,route_group,build_id,form_factor,created_at_utc) '
    . "VALUES('CLS',:value,'home','git-0123456789ab','mobile',UTC_TIMESTAMP(6))"
);
$insert->execute([':value' => '0.10000001']);
$stored = $pdo->query('SELECT CAST(value AS CHAR) FROM production_rum_samples LIMIT 1')->fetchColumn();
rum_db_expect($stored === '0.10000001', 'RUM storage must preserve eight-decimal CLS evidence.');

$indexRows=$pdo->query(
    "SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,INDEX_TYPE,SUB_PART "
    ."FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='production_rum_samples' "
    ."ORDER BY INDEX_NAME,SEQ_IN_INDEX"
)->fetchAll();
$required=[
    'PRIMARY'=>['non_unique'=>0,'type'=>'BTREE','columns'=>[['name'=>'id','sub_part'=>null]]],
    'idx_production_rum_build'=>['non_unique'=>1,'type'=>'BTREE','columns'=>[['name'=>'build_id','sub_part'=>null],['name'=>'created_at_utc','sub_part'=>null]]],
    'idx_production_rum_window'=>['non_unique'=>1,'type'=>'BTREE','columns'=>[['name'=>'created_at_utc','sub_part'=>null],['name'=>'metric','sub_part'=>null],['name'=>'route_group','sub_part'=>null],['name'=>'form_factor','sub_part'=>null]]],
];
$seen=[];
foreach($indexRows as $row){
    $name=(string)$row['INDEX_NAME'];
    if(!isset($required[$name]))continue;
    if(!isset($seen[$name]))$seen[$name]=[
        'non_unique'=>(int)$row['NON_UNIQUE'],
        'type'=>strtoupper((string)$row['INDEX_TYPE']),
        'columns'=>[],
    ];
    $seen[$name]['columns'][]=[
        'name'=>(string)$row['COLUMN_NAME'],
        'sub_part'=>$row['SUB_PART']===null?null:(int)$row['SUB_PART'],
    ];
}
rum_db_expect(count($seen)===count($required), 'RUM required index set drift.');
foreach($required as $name=>$expectedIndex){
    rum_db_expect(($seen[$name]??null)===$expectedIndex, "RUM index {$name} definition drift.");
}

echo "PRODUCTION_READINESS_RUM_MARIADB_OK\n";
