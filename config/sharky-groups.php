<?php

declare(strict_types=1);

const HACHE_SHARKY_GROUPS_KEY = 'sharky_grupos_habilitado';

function hache_sharky_groups_config_row(): array
{
    return [
        'clave'=>HACHE_SHARKY_GROUPS_KEY,
        'valor'=>'0',
        'descripcion'=>'Permite a Sharky procesar y responder mensajes de grupos de WhatsApp que incluyan group_id. Desactivado por defecto.',
    ];
}

function hache_sharky_groups_config_valid(string $value): bool
{
    return in_array($value, ['0','1'], true);
}

function hache_sharky_groups_enabled(?PDO $pdo=null): bool
{
    if(!$pdo instanceof PDO){
        if(!function_exists('hache_sharky_pdo'))return false;
        $pdo=hache_sharky_pdo();
    }
    if(!$pdo instanceof PDO)return false;
    try{
        $stmt=$pdo->prepare('SELECT valor FROM configuracion WHERE clave=:clave LIMIT 1');
        $stmt->execute([':clave'=>HACHE_SHARKY_GROUPS_KEY]);
        return trim((string)$stmt->fetchColumn())==='1';
    }catch(Throwable $e){
        error_log('[sharky-groups] configuration read failed');
        return false;
    }
}

function hache_sharky_groups_message_map(array $payload): array
{
    $map=[];
    foreach(($payload['entry']??[]) as $entry){
        if(!is_array($entry))continue;
        foreach(($entry['changes']??[]) as $change){
            if(!is_array($change))continue;
            $value=$change['value']??null;if(!is_array($value))continue;
            foreach(($value['messages']??[]) as $message){
                if(!is_array($message))continue;
                $id=trim((string)($message['id']??''));
                $groupId=trim((string)($message['group_id']??''));
                if($id!==''&&$groupId!=='')$map[$id]=$groupId;
            }
        }
    }
    return $map;
}

function hache_sharky_groups_count_messages(array $payload): int
{
    $count=0;
    foreach(($payload['entry']??[]) as $entry){
        if(!is_array($entry))continue;
        foreach(($entry['changes']??[]) as $change){
            if(!is_array($change))continue;
            $value=$change['value']??null;if(!is_array($value))continue;
            foreach(($value['messages']??[]) as $message){
                if(is_array($message)&&trim((string)($message['group_id']??''))!=='')$count++;
            }
        }
    }
    return $count;
}

function hache_sharky_groups_filter_payload(array $payload,bool $enabled): array
{
    if($enabled)return $payload;
    foreach(($payload['entry']??[]) as $entryIndex=>$entry){
        if(!is_array($entry))continue;
        foreach(($entry['changes']??[]) as $changeIndex=>$change){
            if(!is_array($change))continue;
            $value=$change['value']??null;if(!is_array($value)||!is_array($value['messages']??null))continue;
            $payload['entry'][$entryIndex]['changes'][$changeIndex]['value']['messages']=array_values(array_filter(
                $value['messages'],
                static fn($message):bool=>!is_array($message)||trim((string)($message['group_id']??''))===''
            ));
        }
    }
    return $payload;
}

function hache_sharky_groups_decorate_events(array $events,array $payload): array
{
    $map=hache_sharky_groups_message_map($payload);
    foreach($events as &$event){
        if(!is_array($event))continue;
        $id=trim((string)($event['id']??''));
        if($id!==''&&isset($map[$id]))$event['group_id']=$map[$id];
    }
    unset($event);
    return $events;
}

function hache_sharky_groups_prepare_outbound(array $payload,string $groupId): array
{
    $groupId=trim($groupId);
    if($groupId==='')return $payload;

    $body='';
    if(($payload['type']??'')==='text'){
        $body=trim((string)($payload['text']['body']??''));
    }elseif(($payload['type']??'')==='interactive'){
        $body=trim((string)($payload['interactive']['body']['text']??''));
        $options=[];
        $interactive=$payload['interactive']??[];
        if(($interactive['type']??'')==='button'){
            foreach(($interactive['action']['buttons']??[]) as $button){
                $title=trim((string)($button['reply']['title']??''));
                if($title!=='')$options[]=$title;
            }
        }elseif(($interactive['type']??'')==='list'){
            foreach(($interactive['action']['sections']??[]) as $section){
                foreach(($section['rows']??[]) as $row){
                    $title=trim((string)($row['title']??''));
                    if($title!=='')$options[]=$title;
                }
            }
        }
        if($options)$body.=($body!==''?"\n\n":'').'Opciones: '.implode(' · ',array_slice($options,0,10));
    }
    if($body==='')$body='¿En qué te puedo ayudar?';

    $out=[
        'messaging_product'=>'whatsapp',
        'recipient_type'=>'group',
        'to'=>$groupId,
        'type'=>'text',
        'text'=>['preview_url'=>false,'body'=>mb_substr($body,0,4000)],
        '_sharky_group'=>true,
    ];
    foreach($payload as $key=>$value){
        if(str_starts_with((string)$key,'_sharky_')&&$key!=='_sharky_group')$out[$key]=$value;
    }
    return $out;
}
