<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-runtime.php';
require_once __DIR__.'/../config/sharky-contact-book.php';

if(PHP_SAPI!=='cli'){
    fwrite(STDERR,"CLI only\n");
    exit(2);
}

function hache_google_contacts_summary(PDO $pdo): array
{
    $counts=['PENDING'=>0,'SYNCED'=>0,'UNMANAGED'=>0,'FAILED'=>0];
    $rows=$pdo->query("SELECT sync_status,COUNT(*) total FROM sharky_contacts GROUP BY sync_status")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $row){
        $status=(string)($row['sync_status']??'');
        if(array_key_exists($status,$counts))$counts[$status]=(int)($row['total']??0);
    }
    $row=$pdo->query("SELECT COUNT(*) total,MAX(last_seen_at) last_seen_at,MAX(synced_at) synced_at,MAX(last_sync_attempt_at) last_sync_attempt_at FROM sharky_contacts")->fetch(PDO::FETCH_ASSOC)?:[];
    return [
        'counts'=>$counts,
        'total'=>(int)($row['total']??0),
        'last_seen_at'=>$row['last_seen_at']??null,
        'last_synced_at'=>$row['synced_at']??null,
        'last_sync_attempt_at'=>$row['last_sync_attempt_at']??null,
    ];
}

function hache_google_contacts_set_refresh_token(): never
{
    $token=trim((string)stream_get_contents(STDIN));
    if(strlen($token)<20||strlen($token)>2048||preg_match('/[^A-Za-z0-9._\/-]/',$token)){
        fwrite(STDERR,"Invalid Google refresh token\n");
        exit(2);
    }
    $env=dirname(__DIR__).'/.env';
    if(!is_file($env)||is_link($env)||!is_readable($env)||!is_writable($env)){
        fwrite(STDERR,"Environment file unavailable\n");
        exit(1);
    }
    $raw=file_get_contents($env);
    if(!is_string($raw)){
        fwrite(STDERR,"Unable to read environment file\n");
        exit(1);
    }
    $line='GOOGLE_CONTACTS_REFRESH_TOKEN='.$token;
    if(preg_match('/^(?:export\s+)?GOOGLE_CONTACTS_REFRESH_TOKEN=.*$/m',$raw)){
        $next=preg_replace('/^(?:export\s+)?GOOGLE_CONTACTS_REFRESH_TOKEN=.*$/m',$line,$raw,1);
    }else{
        $next=rtrim($raw,"\r\n")."\n".$line."\n";
    }
    if(!is_string($next)){
        fwrite(STDERR,"Unable to update environment content\n");
        exit(1);
    }
    $stat=stat($env);
    if(!is_array($stat)){
        fwrite(STDERR,"Unable to stat environment file\n");
        exit(1);
    }
    $tmp=$env.'.google-contacts.'.bin2hex(random_bytes(6)).'.tmp';
    if(file_put_contents($tmp,$next,LOCK_EX)===false){
        fwrite(STDERR,"Unable to write environment update\n");
        exit(1);
    }
    chmod($tmp,0600);
    @chown($tmp,(int)$stat['uid']);
    @chgrp($tmp,(int)$stat['gid']);
    chmod($tmp,(int)$stat['mode']&0777);
    if(!rename($tmp,$env)){
        @unlink($tmp);
        fwrite(STDERR,"Unable to publish environment update\n");
        exit(1);
    }
    fwrite(STDOUT,json_encode(['ok'=>true,'updated'=>true],JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(0);
}

$mode=$argv[1]??'--status';
if($mode==='--set-refresh-token')hache_google_contacts_set_refresh_token();

$pdo=hache_sharky_pdo();
if(!$pdo instanceof PDO){
    fwrite(STDERR,"Database unavailable\n");
    exit(1);
}

if($mode==='--status'){
    $configured=hache_sharky_google_contacts_configured();
    $tokenOk=$configured&&hache_sharky_google_contacts_access_token()!=='';
    $out=hache_google_contacts_summary($pdo);
    $out['configured']=$configured;
    $out['token_refresh_ok']=$tokenOk;
    fwrite(STDOUT,json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit($tokenOk?0:1);
}

if($mode==='--sync-once'){
    $stats=hache_sharky_contact_book_sync_pending($pdo,50);
    $out=['sync'=>$stats,'summary'=>hache_google_contacts_summary($pdo)];
    fwrite(STDOUT,json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(($stats['configured']??false)===true&&($stats['failed']??0)===0?0:1);
}

fwrite(STDERR,"Usage: --status | --sync-once | --set-refresh-token\n");
exit(2);
