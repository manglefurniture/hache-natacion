<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-commerce-flows.php';

function hache_sharky_commerce_flow_v2_specs(): array
{
    return [
        'enrollment'=>[
            'name'=>'Hache_Sharky_Enrollment_v2',
            'asset'=>'enrollment-v1.json',
            'env'=>'WHATSAPP_ENROLLMENT_FLOW_ID',
        ],
        'payment_method'=>[
            'name'=>'Hache_Sharky_Payment_Method_v2',
            'asset'=>'payment-method-v1.json',
            'env'=>'WHATSAPP_PAYMENT_METHOD_FLOW_ID',
        ],
        'payment_transfer'=>[
            'name'=>'Hache_Sharky_Payment_Transfer_v2',
            'asset'=>'payment-transfer-v1.json',
            'env'=>'WHATSAPP_PAYMENT_TRANSFER_FLOW_ID',
        ],
        'payment_card'=>[
            'name'=>'Hache_Sharky_Payment_Card_v2',
            'asset'=>'payment-card-v1.json',
            'env'=>'WHATSAPP_PAYMENT_CARD_FLOW_ID',
        ],
    ];
}

function hache_sharky_commerce_flow_v2_cache_path(string $key): string
{
    $dir=hache_sharky_whatsapp_birthdate_flow_cache_dir();
    $safe=preg_replace('/[^a-z0-9_-]+/i','-',$key)?:'';
    return $dir===''||$safe===''?'':$dir.'/commerce-v2-'.$safe.'.id';
}

function hache_sharky_commerce_flow_v2_retry_path(string $key): string
{
    $dir=hache_sharky_whatsapp_birthdate_flow_cache_dir();
    $safe=preg_replace('/[^a-z0-9_-]+/i','-',$key)?:'';
    return $dir===''||$safe===''?'':$dir.'/commerce-v2-'.$safe.'.retry';
}

function hache_sharky_commerce_flow_v2_cached_id(string $key): ?string
{
    $path=hache_sharky_commerce_flow_v2_cache_path($key);
    if($path===''||!is_file($path))return null;
    $id=preg_replace('/\D+/','',trim((string)@file_get_contents($path)))?:'';
    return $id!==''?$id:null;
}

function hache_sharky_commerce_flow_v2_cache_id(string $key,string $flowId): bool
{
    $flowId=preg_replace('/\D+/','',$flowId)?:'';
    $path=hache_sharky_commerce_flow_v2_cache_path($key);
    if($flowId===''||$path==='')return false;
    $ok=@file_put_contents($path,$flowId,LOCK_EX)!==false;
    if($ok)@chmod($path,0600);
    return $ok;
}

function hache_sharky_commerce_flow_v2_inject(string $env,string $flowId): void
{
    $flowId=preg_replace('/\D+/','',$flowId)?:'';
    if($env===''||$flowId==='')return;
    putenv($env.'='.$flowId);
    $_ENV[$env]=$flowId;
}

function hache_sharky_commerce_flow_v2_drop_legacy_cache(string $key): void
{
    $path=hache_sharky_commerce_flow_cache_path($key);
    if($path!==''&&is_file($path))@unlink($path);
}

/**
 * Existing commerce code already prioritizes explicit WHATSAPP_*_FLOW_ID values.
 * Load the v2 runtime cache into those variables so all current send paths switch
 * to the corrected published resources without duplicating the commerce engine.
 * If no v2 resource exists yet, remove the legacy v1 runtime cache so Sharky uses
 * its safe chat/buttons fallback instead of showing known-bad literal placeholders.
 */
function hache_sharky_commerce_flow_v2_bootstrap(): array
{
    $ready=[];
    foreach(hache_sharky_commerce_flow_v2_specs() as $key=>$spec){
        $env=(string)$spec['env'];
        $configured=preg_replace('/\D+/','',hache_sharky_whatsapp_flow_secret($env))?:'';
        if($configured!==''){
            $ready[(string)$key]=$configured;
            continue;
        }
        $cached=hache_sharky_commerce_flow_v2_cached_id((string)$key);
        hache_sharky_commerce_flow_v2_drop_legacy_cache((string)$key);
        if($cached===null)continue;
        hache_sharky_commerce_flow_v2_inject($env,$cached);
        $ready[(string)$key]=$cached;
    }
    return $ready;
}

function hache_sharky_commerce_flow_v2_retry_allowed(string $key,int $now): bool
{
    $path=hache_sharky_commerce_flow_v2_retry_path($key);
    if($path===''||!is_file($path))return true;
    $mtime=(int)@filemtime($path);
    return $mtime<=0||$mtime<=$now-900;
}

function hache_sharky_commerce_flow_v2_mark_retry(string $key,int $now): void
{
    $path=hache_sharky_commerce_flow_v2_retry_path($key);
    if($path==='')return;
    @file_put_contents($path,(string)$now,LOCK_EX);
    @touch($path,$now);
    @chmod($path,0600);
}

function hache_sharky_commerce_flow_v2_clear_retry(string $key): void
{
    $path=hache_sharky_commerce_flow_v2_retry_path($key);
    if($path!=='')@unlink($path);
}

function hache_sharky_commerce_flow_v2_ensure(string $key,string $wabaId,callable $secretResolver): ?string
{
    $specs=hache_sharky_commerce_flow_v2_specs();
    $spec=is_array($specs[$key]??null)?$specs[$key]:null;
    if(!$spec)return null;

    $env=(string)$spec['env'];
    $configured=preg_replace('/\D+/','',(string)$secretResolver($env))?:'';
    if($configured!=='')return $configured;

    $cached=hache_sharky_commerce_flow_v2_cached_id($key);
    if($cached!==null){
        hache_sharky_commerce_flow_v2_drop_legacy_cache($key);
        hache_sharky_commerce_flow_v2_inject($env,$cached);
        return $cached;
    }

    $wabaId=preg_replace('/\D+/','',$wabaId)?:'';
    $token=trim((string)$secretResolver('WHATSAPP_ACCESS_TOKEN'));
    if($wabaId===''||$token==='')return null;
    $version=trim((string)$secretResolver('WHATSAPP_GRAPH_VERSION'));
    if(preg_match('/^v\d+\.\d+$/',$version)!==1)$version='v26.0';
    $base='https://graph.facebook.com/'.rawurlencode($version);

    $list=hache_sharky_whatsapp_flow_graph_json('GET',$base.'/'.rawurlencode($wabaId).'/flows?fields=id%2Cname%2Cstatus&limit=100',$token);
    $existing=is_array($list)?hache_sharky_commerce_flow_existing($list,(string)$spec['name']):null;
    if(is_array($existing)&&$existing['status']==='PUBLISHED'){
        $id=(string)$existing['id'];
        hache_sharky_commerce_flow_v2_cache_id($key,$id);
        hache_sharky_commerce_flow_v2_drop_legacy_cache($key);
        hache_sharky_commerce_flow_v2_inject($env,$id);
        return $id;
    }

    $flowId=is_array($existing)?(string)$existing['id']:'';
    if($flowId===''){
        $created=hache_sharky_whatsapp_flow_graph_json('POST',$base.'/'.rawurlencode($wabaId).'/flows',$token,[
            'name'=>(string)$spec['name'],
            'categories'=>'["SIGN_UP"]',
        ]);
        $flowId=preg_replace('/\D+/','',(string)($created['id']??''))?:'';
        if($flowId==='')return null;
    }

    if(!hache_sharky_commerce_flow_upload($base,$flowId,$token,(string)$spec['asset']))return null;
    $published=hache_sharky_whatsapp_flow_graph_json('POST',$base.'/'.rawurlencode($flowId).'/publish',$token,[]);
    if(!is_array($published)){
        $check=hache_sharky_whatsapp_flow_graph_json('GET',$base.'/'.rawurlencode($wabaId).'/flows?fields=id%2Cname%2Cstatus&limit=100',$token);
        $after=is_array($check)?hache_sharky_commerce_flow_existing($check,(string)$spec['name']):null;
        if(!is_array($after)||$after['status']!=='PUBLISHED')return null;
    }

    hache_sharky_commerce_flow_v2_cache_id($key,$flowId);
    hache_sharky_commerce_flow_v2_drop_legacy_cache($key);
    hache_sharky_commerce_flow_v2_inject($env,$flowId);
    return $flowId;
}

/**
 * Keep the same bounded post-ACK behavior as commerce v1: at most one unresolved
 * Flow touches Graph per webhook and failures wait 15 minutes before retrying.
 */
function hache_sharky_commerce_flow_v2_prime_throttled(array $payload,?callable $secretResolver=null,?int $now=null): array
{
    $waba=hache_sharky_whatsapp_birthdate_flow_first_waba($payload);
    if($waba==='')return [];
    $secretResolver??=static fn(string $name):string=>hache_sharky_whatsapp_flow_secret($name);
    $now??=time();
    $ready=[];
    $networkAttempted=false;

    foreach(hache_sharky_commerce_flow_v2_specs() as $key=>$spec){
        $env=(string)$spec['env'];
        $configured=preg_replace('/\D+/','',(string)$secretResolver($env))?:'';
        if($configured!==''){
            $ready[(string)$key]=$configured;
            hache_sharky_commerce_flow_v2_clear_retry((string)$key);
            continue;
        }
        $cached=hache_sharky_commerce_flow_v2_cached_id((string)$key);
        if($cached!==null){
            hache_sharky_commerce_flow_v2_drop_legacy_cache((string)$key);
            hache_sharky_commerce_flow_v2_inject($env,$cached);
            $ready[(string)$key]=$cached;
            hache_sharky_commerce_flow_v2_clear_retry((string)$key);
            continue;
        }
        if($networkAttempted||!hache_sharky_commerce_flow_v2_retry_allowed((string)$key,$now))continue;

        $networkAttempted=true;
        try{
            $id=hache_sharky_commerce_flow_v2_ensure((string)$key,$waba,$secretResolver);
            if($id!==null){
                $ready[(string)$key]=$id;
                hache_sharky_commerce_flow_v2_clear_retry((string)$key);
            }else{
                hache_sharky_commerce_flow_v2_mark_retry((string)$key,$now);
            }
        }catch(Throwable $e){
            hache_sharky_commerce_flow_v2_mark_retry((string)$key,$now);
            error_log('[sharky-commerce-flow-v2] provisioning unavailable key='.(string)$key);
        }
    }
    return $ready;
}
