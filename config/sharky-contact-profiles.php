<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-contact-book.php';

/**
 * Meta includes value.contacts[].profile.name on inbound WhatsApp messages.
 * Capture it as a naming hint only; student/teacher records remain authoritative.
 * This helper never creates schema and never makes an external request.
 *
 * The raw signed payload is inspected before the normal group filter, so a
 * profile is eligible only when the same WhatsApp id also appears as the sender
 * of a direct (non-group) message in this change value. Group participants must
 * never populate the personal contact book merely by speaking in a group.
 */
function hache_sharky_contact_book_capture_profiles_payload(PDO $pdo,array $payload,string $configuredPhoneId=''): int
{
    if(!hache_sharky_contact_book_schema_ready($pdo))return 0;
    $captured=0;$seen=[];$configuredPhoneId=trim($configuredPhoneId);
    foreach(($payload['entry']??[]) as $entry){
        if(!is_array($entry))continue;
        foreach(($entry['changes']??[]) as $change){
            if(!is_array($change))continue;
            $value=$change['value']??null;if(!is_array($value))continue;
            $phoneId=trim((string)($value['metadata']['phone_number_id']??''));
            if($configuredPhoneId!==''&&($phoneId===''||!hash_equals($configuredPhoneId,$phoneId)))continue;

            $directSenders=[];
            foreach(($value['messages']??[]) as $message){
                if(!is_array($message))continue;
                if(trim((string)($message['group_id']??''))!=='')continue;
                $from=preg_replace('/\D+/','',(string)($message['from']??''))?:'';
                if($from!=='')$directSenders[$from]=true;
            }
            if(!$directSenders)continue;

            foreach(($value['contacts']??[]) as $contact){
                if(!is_array($contact))continue;
                $waId=preg_replace('/\D+/','',(string)($contact['wa_id']??''))?:'';
                $name=hache_sharky_contact_book_clean_name((string)($contact['profile']['name']??''));
                if($waId===''||$name===''||!isset($directSenders[$waId]))continue;
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
