<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-human-grace-runtime.php';

/**
 * Detect takeover controls inside an outbound human text.
 *
 * The last matching control phrase wins when one message contains more than
 * one instruction. "profe Ariel" intentionally maps to the same exclusive
 * takeover as "Sharky duerme".
 */
function hache_sharky_whatsapp_echo_operator_command(array $echo): ?string
{
    $declared=strtolower(trim((string)($echo['operator_command']??'')));
    if(in_array($declared,['sleep','wake'],true))return $declared;

    $type=trim((string)($echo['type']??''));
    if($type!==''&&$type!=='text')return null;

    $text=(string)($echo['text']??'');
    if(trim($text)==='')return null;

    $matches=[];
    foreach([
        'sleep'=>['/\bsharky\s+duerme\b/ui','/\bprofe\s+ariel\b/ui'],
        'wake'=>['/\bsharky\s+despierta\b/ui'],
    ] as $command=>$patterns){
        foreach($patterns as $pattern){
            if(preg_match_all($pattern,$text,$found,PREG_OFFSET_CAPTURE)!==false){
                foreach(($found[0]??[]) as $match){
                    $matches[]=['command'=>$command,'offset'=>(int)($match[1]??-1)];
                }
            }
        }
    }
    if(!$matches)return null;
    usort($matches,static fn(array $a,array $b):int=>$a['offset']<=>$b['offset']);
    return (string)$matches[array_key_last($matches)]['command'];
}

function hache_sharky_whatsapp_echo_resume_requested(array $echo): bool
{
    return hache_sharky_whatsapp_echo_operator_command($echo)==='wake';
}

function hache_sharky_whatsapp_echo_sleep_requested(array $echo): bool
{
    return hache_sharky_whatsapp_echo_operator_command($echo)==='sleep';
}

function hache_sharky_whatsapp_extract_echoes(array $payload): array
{
    $out=[];
    foreach(($payload['entry']??[]) as $entry){
        if(!is_array($entry))continue;
        foreach(($entry['changes']??[]) as $change){
            if(!is_array($change)||($change['field']??'')!=='smb_message_echoes')continue;
            $value=$change['value']??null;if(!is_array($value))continue;
            $phoneId=trim((string)($value['metadata']['phone_number_id']??''));
            foreach(($value['message_echoes']??[]) as $echo){
                if(!is_array($echo))continue;
                $id=trim((string)($echo['id']??''));$to=preg_replace('/\D+/','',(string)($echo['to']??''))?:'';
                if($id===''||$to==='')continue;
                $rawText=trim((string)($echo['text']['body']??''));
                $type=trim((string)($echo['type']??($rawText!==''?'text':'')));
                $timestamp=(string)($echo['timestamp']??'');$timestampMs=ctype_digit($timestamp)?((int)$timestamp*1000):0;
                $exactCommand=hache_sharky_human_operator_command(['type'=>$type,'text'=>$rawText]);
                $command=$exactCommand??hache_sharky_whatsapp_echo_operator_command(['type'=>$type,'text'=>$rawText]);
                $out[]=[
                    'id'=>$id,
                    'to'=>$to,
                    'phone_number_id'=>$phoneId,
                    'type'=>$type,
                    // A pure command is control-plane only. If the trigger is
                    // embedded in a real human message, preserve that message as
                    // HUMANO_HACHE while carrying the control separately.
                    'text'=>$exactCommand===null?mb_substr($rawText,0,700):'',
                    'operator_command'=>$command??'',
                    'timestamp_ms'=>$timestampMs,
                ];
            }
        }
    }
    return $out;
}
