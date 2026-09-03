<?php

declare(strict_types=1);

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
                $out[]=['id'=>$id,'to'=>$to,'phone_number_id'=>$phoneId];
            }
        }
    }
    return $out;
}
