<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-contact-book.php';

/**
 * Meta includes value.contacts[].profile.name on inbound WhatsApp messages.
 * Capture it as a naming hint only; student/teacher records remain authoritative.
 * This helper never creates schema and never makes an external request.
 */
function hache_sharky_contact_book_capture_profiles_payload(PDO $pdo,array $payload): int
{
    if(!hache_sharky_contact_book_schema_ready($pdo))return 0;
    $captured=0;$seen=[];
    foreach(($payload['entry']??[]) as $entry){
        if(!is_array($entry))continue;
        foreach(($entry['changes']??[]) as $change){
            if(!is_array($change))continue;
            $value=$change['value']??null;if(!is_array($value))continue;
            foreach(($value['contacts']??[]) as $contact){
                if(!is_array($contact))continue;
                $waId=preg_replace('/\D+/','',(string)($contact['wa_id']??''))?:'';
                $name=hache_sharky_contact_book_clean_name((string)($contact['profile']['name']??''));
                if($waId===''||$name==='')continue;
                $normalized=hache_sharky_contact_book_normalize_phone($waId);if($normalized===null)continue;
                $key=$normalized['digits'].'|'.$name;if(isset($seen[$key]))continue;$seen[$key]=true;
                if(hache_sharky_contact_book_capture_event($pdo,[
                    'id'=>'profile:'.hash('sha256',$key),
                    'from'=>$normalized['digits'],
                    'kind'=>'profile',
                    'profile_name'=>$name,
                ]))$captured++;
            }
        }
    }
    return $captured;
}
