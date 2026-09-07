<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-whatsapp-flows.php';

function hache_sharky_whatsapp_flow_secret(string $name): string
{
    $value=trim((string)getenv($name));if($value!=='')return $value;
    if(function_exists('hache_sharky_orchestrator_secret'))return trim((string)hache_sharky_orchestrator_secret($name));
    return '';
}

function hache_sharky_whatsapp_birthdate_text_compat(array $event): array
{
    if(trim((string)($event['interactive_id']??''))!=='')return $event;
    $text=trim((string)($event['text']??''));if($text==='')return $event;
    $months='enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre|ene|feb|mar|abr|may|jun|jul|ago|sept|sep|set|oct|nov|dic';
    $normalized=preg_replace('/\b(\d{1,2})\s+(?:de\s+)?('.$months.')\.?\s+del\s+(\d{4})\b/iu','$1 de $2 de $3',$text);
    if(is_string($normalized)&&$normalized!==$text)$event['text']=$normalized;
    return $event;
}

function hache_sharky_whatsapp_birthdate_flow_cache_dir(): string
{
    if(function_exists('hache_sharky_orchestrator_runtime_dir')){
        try{
            $dir=hache_sharky_orchestrator_runtime_dir('whatsapp-flows');
            if(is_string($dir)&&$dir!=='')return $dir;
        }catch(Throwable $e){}
    }
    $dir=rtrim(sys_get_temp_dir(),'/').'/hache-sharky-whatsapp-flows';
    if(!is_dir($dir))@mkdir($dir,0700,true);
    return is_dir($dir)?$dir:'';
}

function hache_sharky_whatsapp_birthdate_flow_cache_path(string $suffix): string
{
    $dir=hache_sharky_whatsapp_birthdate_flow_cache_dir();
    return $dir===''?'':$dir.'/birthdate-v1.'.$suffix;
}

function hache_sharky_whatsapp_birthdate_flow_cached_id(): ?string
{
    $configured=preg_replace('/\D+/','',hache_sharky_whatsapp_flow_secret('WHATSAPP_BIRTHDATE_FLOW_ID'))?:'';
    if($configured!=='')return $configured;
    $path=hache_sharky_whatsapp_birthdate_flow_cache_path('id');if($path===''||!is_file($path))return null;
    $id=preg_replace('/\D+/','',trim((string)@file_get_contents($path)))?:'';
    return $id!==''?$id:null;
}

function hache_sharky_whatsapp_birthdate_flow_cache_id(string $flowId): bool
{
    $flowId=preg_replace('/\D+/','',$flowId)?:'';$path=hache_sharky_whatsapp_birthdate_flow_cache_path('id');
    if($flowId===''||$path==='')return false;
    $ok=@file_put_contents($path,$flowId,LOCK_EX)!==false;if($ok)@chmod($path,0600);
    $retry=hache_sharky_whatsapp_birthdate_flow_cache_path('retry');if($retry!=='')@unlink($retry);
    return $ok;
}

function hache_sharky_whatsapp_birthdate_flow_retry_allowed(int $now): bool
{
    $path=hache_sharky_whatsapp_birthdate_flow_cache_path('retry');if($path===''||!is_file($path))return true;
    $mtime=(int)@filemtime($path);return $mtime<=0||$mtime<=$now-900;
}

function hache_sharky_whatsapp_birthdate_flow_mark_retry(int $now): void
{
    $path=hache_sharky_whatsapp_birthdate_flow_cache_path('retry');if($path==='')return;
    @file_put_contents($path,(string)$now,LOCK_EX);@touch($path,$now);@chmod($path,0600);
}

function hache_sharky_whatsapp_birthdate_flow_first_waba(array $payload): string
{
    foreach(($payload['entry']??[]) as $entry){
        if(!is_array($entry))continue;
        $hasMessage=false;
        foreach(($entry['changes']??[]) as $change){
            if(!is_array($change))continue;
            $messages=$change['value']['messages']??null;
            if(is_array($messages)&&count($messages)>0){$hasMessage=true;break;}
        }
        if(!$hasMessage)continue;
        $waba=preg_replace('/\D+/','',(string)($entry['id']??''))?:'';
        if($waba!=='')return $waba;
    }
    return '';
}

/**
 * Runs only after the webhook ACK. A cached/pinned id returns immediately; Graph
 * provisioning is throttled after failure so lack of management permission can
 * never slow every inbound message or block the text fallback.
 */
function hache_sharky_whatsapp_birthdate_flow_prime(array $payload,?callable $secretResolver=null,?int $now=null): ?string
{
    $cached=hache_sharky_whatsapp_birthdate_flow_cached_id();if($cached!==null)return $cached;
    $now??=time();if(!hache_sharky_whatsapp_birthdate_flow_retry_allowed($now))return null;
    $waba=hache_sharky_whatsapp_birthdate_flow_first_waba($payload);if($waba==='')return null;
    $secretResolver??=static fn(string $name):string=>hache_sharky_whatsapp_flow_secret($name);
    $flowId=hache_sharky_whatsapp_birthdate_flow_ensure($waba,$secretResolver);
    if($flowId===null){hache_sharky_whatsapp_birthdate_flow_mark_retry($now);return null;}
    hache_sharky_whatsapp_birthdate_flow_cache_id($flowId);
    return $flowId;
}

function hache_sharky_whatsapp_birthdate_prompt_payload(array $payload): bool
{
    $body='';
    if(($payload['type']??'')==='text')$body=(string)($payload['text']['body']??'');
    elseif(($payload['type']??'')==='interactive')$body=(string)($payload['interactive']['body']['text']??'');
    $body=trim($body);if($body==='')return false;
    $normalized=function_exists('hache_sharky_orchestrator_normalize')
        ?hache_sharky_orchestrator_normalize($body)
        :mb_strtolower($body,'UTF-8');

    // Los mensajes de reintento no siempre repiten literalmente “fecha de nacimiento”.
    // Detectarlos antes del guard genérico permite volver a ofrecer el DatePicker.
    if(str_starts_with($normalized,'no pude reconocer esa fecha')
        ||str_starts_with($normalized,'la fecha de nacimiento no es valida'))return true;

    if(!str_contains($normalized,'fecha de nacimiento'))return false;
    return str_contains($normalized,'validar la edad minima');
}

/**
 * Presentation-only upgrade. If no published Flow id is available, returns the
 * original text payload byte-for-byte so the permissive parser stays available.
 */
function hache_sharky_whatsapp_birthdate_flow_upgrade_cached(array $payload): array
{
    if(!hache_sharky_whatsapp_birthdate_prompt_payload($payload))return $payload;
    $flowId=hache_sharky_whatsapp_birthdate_flow_cached_id();if($flowId===null)return $payload;
    $to=preg_replace('/\D+/','',(string)($payload['to']??''))?:'';if($to==='')return $payload;
    $body=($payload['type']??'')==='text'
        ?(string)($payload['text']['body']??'')
        :(string)($payload['interactive']['body']['text']??'');
    return hache_sharky_whatsapp_birthdate_flow_payload($to,$body,$flowId);
}
