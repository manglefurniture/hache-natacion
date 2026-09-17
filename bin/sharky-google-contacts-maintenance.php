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

function hache_google_contacts_secret_valid(string $value,int $max=2048): bool
{
    return strlen($value)>=16&&strlen($value)<=$max&&!preg_match('/[\x00-\x20\x7F]/',$value);
}

function hache_google_contacts_refresh_token_valid(string $token): bool
{
    return strlen($token)>=20
        &&strlen($token)<=2048
        &&!preg_match('/[\x00-\x20\x7F]/',$token);
}

function hache_google_contacts_store_credentials(string $clientSecret,string $refreshToken): bool
{
    if(!hache_google_contacts_secret_valid($clientSecret,1024)||!hache_google_contacts_refresh_token_valid($refreshToken))return false;
    $env=dirname(__DIR__).'/.env';
    if(!is_file($env)||is_link($env)||!is_readable($env)||!is_writable($env))return false;
    $raw=file_get_contents($env);
    if(!is_string($raw))return false;
    $replacements=[
        'GOOGLE_CONTACTS_CLIENT_SECRET'=>$clientSecret,
        'GOOGLE_CONTACTS_REFRESH_TOKEN'=>$refreshToken,
    ];
    $next=$raw;
    foreach($replacements as $key=>$value){
        $line=$key.'='.$value;
        $pattern='/^(?:export\s+)?'.preg_quote($key,'/').'=.*$/m';
        if(preg_match($pattern,$next)){
            $next=preg_replace_callback($pattern,static fn():string=>$line,$next,1);
        }else{
            $next=rtrim((string)$next,"\r\n")."\n".$line."\n";
        }
        if(!is_string($next))return false;
    }
    $stat=stat($env);
    if(!is_array($stat))return false;
    $tmp=$env.'.google-contacts.'.bin2hex(random_bytes(6)).'.tmp';
    if(file_put_contents($tmp,$next,LOCK_EX)===false)return false;
    chmod($tmp,0600);
    @chown($tmp,(int)$stat['uid']);
    @chgrp($tmp,(int)$stat['gid']);
    chmod($tmp,(int)$stat['mode']&0777);
    if(!rename($tmp,$env)){
        @unlink($tmp);
        return false;
    }
    return true;
}

function hache_google_contacts_store_refresh_token(string $token): bool
{
    $clientSecret=hache_sharky_orchestrator_secret('GOOGLE_CONTACTS_CLIENT_SECRET');
    return hache_google_contacts_store_credentials($clientSecret,$token);
}

function hache_google_contacts_stage_path(): string
{
    return '/var/tmp/hache-sharky-secrets/google-contacts-oauth-stage.json';
}

function hache_google_contacts_promote_staged(): never
{
    $path=hache_google_contacts_stage_path();
    if(!is_file($path)||is_link($path)||!is_readable($path)){
        fwrite(STDERR,"Google OAuth staged renewal unavailable\n");
        exit(2);
    }
    $stat=stat($path);
    if(!is_array($stat)||(((int)$stat['mode'])&0777)!==0600){
        fwrite(STDERR,"Google OAuth staged renewal has unsafe permissions\n");
        exit(1);
    }
    $raw=file_get_contents($path);
    $data=is_string($raw)?json_decode($raw,true):null;
    $issuedAt=is_array($data)?(int)($data['issued_at']??0):0;
    $clientSecret=is_array($data)?trim((string)($data['client_secret']??'')):'';
    $refreshToken=is_array($data)?trim((string)($data['refresh_token']??'')):'';
    if($issuedAt<=0||$issuedAt<time()-900||$issuedAt>time()+60){
        @unlink($path);
        fwrite(STDERR,"Google OAuth staged renewal expired\n");
        exit(2);
    }
    if(!hache_google_contacts_secret_valid($clientSecret,1024)||!hache_google_contacts_refresh_token_valid($refreshToken)){
        fwrite(STDERR,"Google OAuth staged renewal invalid\n");
        exit(1);
    }
    if(!hache_google_contacts_store_credentials($clientSecret,$refreshToken)){
        fwrite(STDERR,"Unable to promote Google OAuth credentials\n");
        exit(1);
    }
    @unlink($path);
    putenv('GOOGLE_CONTACTS_CLIENT_SECRET='.$clientSecret);
    putenv('GOOGLE_CONTACTS_REFRESH_TOKEN='.$refreshToken);
    unset($clientSecret,$refreshToken,$data,$raw);

    $pdo=hache_sharky_pdo();
    if(!$pdo instanceof PDO){
        fwrite(STDERR,"Database unavailable after Google OAuth promotion\n");
        exit(1);
    }
    $configured=hache_sharky_google_contacts_configured();
    $tokenOk=$configured&&hache_sharky_google_contacts_access_token()!=='';
    if(!$tokenOk){
        fwrite(STDERR,"Google OAuth promotion completed but refresh validation failed\n");
        exit(1);
    }
    $sync=hache_sharky_contact_book_sync_pending($pdo,50);
    $out=[
        'ok'=>true,
        'promoted'=>true,
        'token_refresh_ok'=>true,
        'sync'=>$sync,
        'summary'=>hache_google_contacts_summary($pdo),
    ];
    fwrite(STDOUT,json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(($sync['failed']??0)===0?0:1);
}

function hache_google_contacts_set_refresh_token(): never
{
    $token=trim((string)stream_get_contents(STDIN));
    if(!hache_google_contacts_refresh_token_valid($token)){
        fwrite(STDERR,"Invalid Google refresh token\n");
        exit(2);
    }
    if(!hache_google_contacts_store_refresh_token($token)){
        fwrite(STDERR,"Unable to update Google refresh token\n");
        exit(1);
    }
    fwrite(STDOUT,json_encode(['ok'=>true,'updated'=>true],JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(0);
}

function hache_google_contacts_exchange_auth_code(): never
{
    $code=trim((string)stream_get_contents(STDIN));
    if($code==='')hache_google_contacts_promote_staged();
    if(strlen($code)<20||strlen($code)>4096||preg_match('/[\x00-\x20\x7F]/',$code)){
        fwrite(STDERR,"Invalid Google authorization code\n");
        exit(2);
    }
    $clientId=hache_sharky_orchestrator_secret('GOOGLE_CONTACTS_CLIENT_ID');
    $clientSecret=hache_sharky_orchestrator_secret('GOOGLE_CONTACTS_CLIENT_SECRET');
    if($clientId===''||$clientSecret===''){
        fwrite(STDERR,"Google OAuth client unavailable\n");
        exit(1);
    }
    $ch=curl_init('https://oauth2.googleapis.com/token');
    if($ch===false){
        fwrite(STDERR,"Unable to initialize Google OAuth exchange\n");
        exit(1);
    }
    $fields=http_build_query([
        'code'=>$code,
        'client_id'=>$clientId,
        'client_secret'=>$clientSecret,
        'redirect_uri'=>'https://developers.google.com/oauthplayground',
        'grant_type'=>'authorization_code',
    ]);
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>20,
        CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS=>$fields,
    ]);
    $response=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded=is_string($response)?json_decode($response,true):null;
    $refreshToken=is_array($decoded)?trim((string)($decoded['refresh_token']??'')):'';
    if($status<200||$status>=300||!hache_google_contacts_refresh_token_valid($refreshToken)){
        fwrite(STDERR,"Google authorization code exchange failed\n");
        exit(1);
    }
    if(!hache_google_contacts_store_refresh_token($refreshToken)){
        fwrite(STDERR,"Unable to update Google refresh token\n");
        exit(1);
    }
    fwrite(STDOUT,json_encode(['ok'=>true,'updated'=>true,'token_exchange_ok'=>true],JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(0);
}

$mode=$argv[1]??'--status';
if($mode==='--set-refresh-token')hache_google_contacts_set_refresh_token();
if($mode==='--exchange-auth-code')hache_google_contacts_exchange_auth_code();

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

fwrite(STDERR,"Usage: --status | --sync-once | --set-refresh-token | --exchange-auth-code\n");
exit(2);
