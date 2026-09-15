<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-human-grace-runtime.php';

function hache_sharky_whatsapp_echo_resume_requested(array $echo): bool
{
    return hache_sharky_human_operator_command($echo)==='wake';
}

function hache_sharky_whatsapp_echo_sleep_requested(array $echo): bool
{
    return hache_sharky_human_operator_command($echo)==='sleep';
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
                $command=hache_sharky_human_operator_command(['type'=>$type,'text'=>$rawText]);
                $out[]=[
                    'id'=>$id,
                    'to'=>$to,
                    'phone_number_id'=>$phoneId,
                    'type'=>$type,
                    // Commands are control-plane metadata, never conversation text.
                    'text'=>$command===null?mb_substr($rawText,0,700):'',
                    'operator_command'=>$command??'',
                    'timestamp_ms'=>$timestampMs,
                ];
            }
        }
    }
    return $out;
}
