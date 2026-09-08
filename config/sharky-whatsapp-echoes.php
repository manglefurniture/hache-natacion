<?php

declare(strict_types=1);

function hache_sharky_whatsapp_echo_resume_requested(array $echo): bool
{
    $type=trim((string)($echo['type']??''));
    if($type!==''&&$type!=='text')return false;
    $text=mb_strtolower(trim((string)($echo['text']??'')),'UTF-8');
    $text=preg_replace('/\s+/u',' ',$text)??$text;
    $text=preg_replace('/^[¿?¡!.,;:\s]+|[¿?¡!.,;:\s]+$/u','',$text)??$text;
    return $text==='sharky vuelve ahora';
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
                $text=trim((string)($echo['text']['body']??''));
                $type=trim((string)($echo['type']??($text!==''?'text':'')));
                $out[]=[
                    'id'=>$id,
                    'to'=>$to,
                    'phone_number_id'=>$phoneId,
                    'type'=>$type,
                    'text'=>mb_substr($text,0,700),
                ];
            }
        }
    }
    return $out;
}
