<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

function sharky_backfill_internal_out(int $status,array $body): never
{
    http_response_code($status);
    echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

$remote=(string)($_SERVER['REMOTE_ADDR']??'');
if(!in_array($remote,['127.0.0.1','::1'],true)){
    sharky_backfill_internal_out(404,['ok'=>false]);
}
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='POST'){
    header('Allow: POST');
    sharky_backfill_internal_out(405,['ok'=>false]);
}

$runId=trim((string)($_SERVER['HTTP_X_HACHE_BACKFILL_RUN_ID']??''));
if($runId===''||!preg_match('/^[0-9]{1,20}$/',$runId)){
    sharky_backfill_internal_out(404,['ok'=>false]);
}
$tokenPath='/tmp/hache-sharky-backfill-token-'.$runId;
$mtime=@filemtime($tokenPath);
if(!is_int($mtime)||$mtime<time()-120||!is_readable($tokenPath)){
    sharky_backfill_internal_out(404,['ok'=>false]);
}
$expected=trim((string)@file_get_contents($tokenPath));
$provided=trim((string)($_SERVER['HTTP_X_HACHE_BACKFILL_TOKEN']??''));
if(!preg_match('/^[a-f0-9]{64}$/',$expected)||!hash_equals($expected,$provided)){
    sharky_backfill_internal_out(404,['ok'=>false]);
}

try{
    require_once dirname(__DIR__).'/bin/sharky-reengagement-backfill-dry-run.php';
    $stats=hache_sharky_reengagement_backfill_dry_run();
    echo json_encode(['ok'=>true]+$stats,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
}catch(Throwable $e){
    error_log('[sharky-backfill-dry-run] internal scan failed');
    sharky_backfill_internal_out(500,['ok'=>false,'reason'=>'DRY_RUN_UNAVAILABLE']);
}
