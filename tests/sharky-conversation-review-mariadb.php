<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-activation.php';
require_once __DIR__.'/../config/sharky-conversation-review.php';

function review_db_ok(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"SHARKY CONVERSATION REVIEW DB FAIL: {$message}\n");exit(1);}
}

$host=(string)(getenv('DELIVERY_DB_HOST')?:'127.0.0.1');
$port=(int)(getenv('DELIVERY_DB_PORT')?:3306);
$name=(string)(getenv('DELIVERY_DB_NAME')?:'hache_delivery_test');
$user=(string)(getenv('DELIVERY_DB_USER')?:'root');
$pass=(string)(getenv('DELIVERY_DB_PASS')?:'root');
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);

$file=__DIR__.'/../database/migrations/20260912_sharky_conversation_review.sql';
$sql=(string)file_get_contents($file);
foreach(hache_sharky_activation_split_sql($sql) as $statement)$pdo->exec($statement);
review_db_ok(hache_sharky_conversation_review_schema_ready($pdo),'Migration must create all review tables.');

$state=$pdo->query('SELECT id,last_status FROM sharky_conversation_review_state WHERE id=1')->fetch(PDO::FETCH_ASSOC);
review_db_ok(is_array($state)&&($state['last_status']??'')==='NEVER','Review scheduler singleton must initialize idempotently.');

// Re-applying the additive migration must remain safe.
foreach(hache_sharky_activation_split_sql($sql) as $statement)$pdo->exec($statement);
review_db_ok(hache_sharky_conversation_review_schema_ready($pdo),'Migration must remain idempotent.');

fwrite(STDOUT,"SHARKY_CONVERSATION_REVIEW_MARIADB_OK\n");
