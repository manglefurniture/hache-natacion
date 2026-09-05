<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-delivery-status.php';

function delivery_db_expect(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
}

$host=(string)(getenv('DELIVERY_DB_HOST')?:'127.0.0.1');
$port=(int)(getenv('DELIVERY_DB_PORT')?:3306);
$db=(string)(getenv('DELIVERY_DB_NAME')?:'hache_delivery_test');
$user=(string)(getenv('DELIVERY_DB_USER')?:'root');
$pass=(string)(getenv('DELIVERY_DB_PASS')?:'root');

$pdo=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);

$pdo->exec('DROP TABLE IF EXISTS sharky_delivery_status');
$pdo->exec('DROP TABLE IF EXISTS sharky_outbox');
$pdo->exec("CREATE TABLE sharky_outbox (
    id CHAR(36) NOT NULL PRIMARY KEY,
    status ENUM('PENDING','SENT','DEAD','CANCELLED') NOT NULL DEFAULT 'PENDING',
    sent_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$sql=(string)file_get_contents(__DIR__.'/../database/migrations/20260905_sharky_delivery_status.sql');
$sqlWithoutLineComments=preg_replace('/^\s*--.*$/m','',$sql);
delivery_db_expect(is_string($sqlWithoutLineComments),'Unable to normalize delivery migration SQL.');
$statements=array_values(array_filter(array_map('trim',explode(';',$sqlWithoutLineComments)),static fn(string $statement):bool=>$statement!==''));
foreach($statements as $statement)$pdo->exec($statement);

delivery_db_expect(hache_sharky_delivery_schema_ready($pdo),'Delivery schema should be ready after migration.');

$payload=static function(string $status,int $timestamp,string $phone='PHONE-1'):array{
    return ['entry'=>[['changes'=>[['value'=>[
        'metadata'=>['phone_number_id'=>$phone],
        'statuses'=>[['id'=>'wamid.DB-1','status'=>$status,'timestamp'=>(string)$timestamp]],
    ]]]]]];
};

$base=1788570000;
$result=hache_sharky_delivery_store_payload($pdo,$payload('delivered',$base+20),'PHONE-1');
delivery_db_expect($result['eligible']===1&&$result['stored']===1,'Delivered event should persist.');

// Older provider events may arrive later; they must not regress the stored state.
hache_sharky_delivery_store_payload($pdo,$payload('sent',$base+10),'PHONE-1');
$row=$pdo->query("SELECT status,status_rank,provider_event_at_utc FROM sharky_delivery_status WHERE provider_message_id='wamid.DB-1'")->fetch();
delivery_db_expect(is_array($row)&&$row['status']==='DELIVERED'&&(int)$row['status_rank']===20,'Older SENT must not regress DELIVERED.');

hache_sharky_delivery_store_payload($pdo,$payload('read',$base+30),'PHONE-1');
$row=$pdo->query("SELECT status,status_rank,provider_event_at_utc FROM sharky_delivery_status WHERE provider_message_id='wamid.DB-1'")->fetch();
delivery_db_expect(is_array($row)&&$row['status']==='READ'&&(int)$row['status_rank']===30,'Newer READ should advance delivery state.');
delivery_db_expect($row['provider_event_at_utc']===gmdate('Y-m-d H:i:s',$base+30),'Provider event time must be stored as UTC derived from Unix epoch.');

// A stale failure must not overwrite a later successful provider event.
hache_sharky_delivery_store_payload($pdo,$payload('failed',$base+25),'PHONE-1');
$row=$pdo->query("SELECT status FROM sharky_delivery_status WHERE provider_message_id='wamid.DB-1'")->fetch();
delivery_db_expect(is_array($row)&&$row['status']==='READ','Stale FAILED must not overwrite newer READ.');

$mismatch=hache_sharky_delivery_store_payload($pdo,$payload('read',$base+40,'OTHER-PHONE'),'PHONE-1');
delivery_db_expect($mismatch['seen']===1&&$mismatch['eligible']===0&&$mismatch['stored']===0,'Wrong phone-number-id status must be ignored.');

$pdo->prepare("INSERT INTO sharky_outbox(id,status,sent_at,provider_message_id) VALUES(:id,'SENT',NOW(),:pm)")
    ->execute([':id'=>'00000000-0000-0000-0000-000000000001',':pm'=>'wamid.DB-1']);
$summary=hache_sharky_delivery_correlated_summary($pdo);
delivery_db_expect($summary['correlated_total']===1,'Exactly one provider status should correlate to an outbox row.');
delivery_db_expect(($summary['status_counts']['READ']??0)===1,'Correlated status should be READ.');
delivery_db_expect($summary['latest_provider_event_at_utc']===gmdate('Y-m-d H:i:s',$base+30),'Correlated latest provider time drift.');

$uniqueBlocked=false;
try{
    $pdo->prepare("INSERT INTO sharky_outbox(id,status,sent_at,provider_message_id) VALUES(:id,'SENT',NOW(),:pm)")
        ->execute([':id'=>'00000000-0000-0000-0000-000000000002',':pm'=>'wamid.DB-1']);
}catch(PDOException $e){$uniqueBlocked=true;}
delivery_db_expect($uniqueBlocked,'Provider message id must map to at most one outbox row.');

echo "SHARKY_DELIVERY_STATUS_MARIADB_OK\n";
