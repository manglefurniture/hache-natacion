<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-orchestrator-store.php';
require_once __DIR__.'/telefono.php';

const HACHE_SHARKY_CONTACT_BOOK_MARKER_KEY='Hache Natación';
const HACHE_SHARKY_CONTACT_BOOK_MARKER_VALUE='managed-contact-v1';
const HACHE_SHARKY_GOOGLE_CONTACTS_LOCK='hache_sharky_google_contacts_sync';

function hache_sharky_contact_book_schema_ready(PDO $pdo): bool
{
    static $ready=[];
    $key=spl_object_id($pdo);
    if(($ready[$key]??false)===true)return true;
    try{
        $st=$pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sharky_contacts'");
        $st->execute();
        $columns=array_fill_keys(array_map('strval',$st->fetchAll(PDO::FETCH_COLUMN)),true);
        foreach(['contact_hash','contact_ciphertext','contact_iv','contact_tag','desired_hash','role','sync_status','google_resource_name','last_seen_at'] as $column){
            if(!isset($columns[$column]))return false;
        }
        return $ready[$key]=true;
    }catch(Throwable $e){return false;}
}

function hache_sharky_contact_book_key(): string
{
    $secret=hache_sharky_orchestrator_secret('SHARKY_STATE_ENCRYPTION_KEY');
    if(strlen($secret)<32){
        if(PHP_SAPI==='cli')$secret='hache-sharky-contact-book-cli-regression-key-2026';
        else throw new RuntimeException('SHARKY_STATE_ENCRYPTION_KEY is required for contact book encryption');
    }
    return hash_hmac('sha256','hache-sharky-contact-book-v1',$secret,true);
}

function hache_sharky_contact_book_encrypt(array $payload): array
{
    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if($json===false)throw new RuntimeException('Unable to encode Sharky contact');
    $iv=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($json,'aes-256-gcm',hache_sharky_contact_book_key(),OPENSSL_RAW_DATA,$iv,$tag,'sharky-contact-book-v1');
    if(!is_string($cipher)||strlen($tag)!==16)throw new RuntimeException('Unable to encrypt Sharky contact');
    return ['ciphertext'=>base64_encode($cipher),'iv'=>base64_encode($iv),'tag'=>base64_encode($tag)];
}

function hache_sharky_contact_book_decrypt(array $row): ?array
{
    $cipher=base64_decode((string)($row['contact_ciphertext']??''),true);
    $iv=base64_decode((string)($row['contact_iv']??''),true);
    $tag=base64_decode((string)($row['contact_tag']??''),true);
    if(!is_string($cipher)||!is_string($iv)||!is_string($tag)||strlen($iv)!==12||strlen($tag)!==16)return null;
    $json=openssl_decrypt($cipher,'aes-256-gcm',hache_sharky_contact_book_key(),OPENSSL_RAW_DATA,$iv,$tag,'sharky-contact-book-v1');
    if(!is_string($json))return null;
    $decoded=json_decode($json,true);
    return is_array($decoded)?$decoded:null;
}

/** @return array{digits:string,e164:string}|null */
function hache_sharky_contact_book_normalize_phone(string $value): ?array
{
    $digits=telefono_digitos($value);
    if(strlen($digits)===13&&str_starts_with($digits,'521'))$digits='52'.substr($digits,3);
    $e164='+'.$digits;
    if($digits===''||!telefono_es_e164($e164))return null;
    return ['digits'=>$digits,'e164'=>$e164];
}

function hache_sharky_contact_book_clean_name(string $value): string
{
    $value=preg_replace('/[\x00-\x1F\x7F]+/u',' ',trim($value))??'';
    $value=preg_replace('/\s+/u',' ',$value)??'';
    return mb_substr(trim($value),0,120);
}

function hache_sharky_contact_book_event_name(array $event): string
{
    $candidates=[
        $event['profile_name']??'',
        $event['commerce']['full_name']??'',
        $event['commerce']['name']??'',
        $event['flow_data']['full_name']??'',
        $event['data']['full_name']??'',
    ];
    foreach($candidates as $candidate){
        $name=hache_sharky_contact_book_clean_name((string)$candidate);
        if($name!=='')return $name;
    }
    return '';
}

function hache_sharky_contact_book_role_label(string $role): string
{
    return match($role){
        'STUDENT'=>'Alumno Hache',
        'TEACHER'=>'Coach Hache',
        'PROSPECT'=>'Prospecto Hache',
        default=>'Contacto Hache',
    };
}

function hache_sharky_contact_book_managed_name(string $baseName,string $role,string $digits): string
{
    $baseName=hache_sharky_contact_book_clean_name($baseName);
    $label=hache_sharky_contact_book_role_label($role);
    if($baseName!=='')return mb_substr($baseName.' — '.$label,0,180);
    $last4=substr($digits,-4);
    return $label.($last4!==''?' · '.$last4:'');
}

function hache_sharky_contact_book_table_exists(PDO $pdo,string $table): bool
{
    try{
        $st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:t');
        $st->execute([':t'=>$table]);
        return (int)$st->fetchColumn()===1;
    }catch(Throwable $e){return false;}
}

/** @return array{role:string,base_name:string,student_id:?string,teacher_id:?string} */
function hache_sharky_contact_book_identity(PDO $pdo,string $e164,string $fallbackName=''): array
{
    if(hache_sharky_contact_book_table_exists($pdo,'profesores')){
        try{
            $st=$pdo->prepare('SELECT id,nombre FROM profesores WHERE whatsapp=:w AND activo=1 LIMIT 2');
            $st->execute([':w'=>$e164]);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
            if(count($rows)===1){
                return ['role'=>'TEACHER','base_name'=>hache_sharky_contact_book_clean_name((string)$rows[0]['nombre']),'student_id'=>null,'teacher_id'=>(string)$rows[0]['id']];
            }
            if(count($rows)>1)return ['role'=>'UNKNOWN','base_name'=>$fallbackName,'student_id'=>null,'teacher_id'=>null];
        }catch(Throwable $e){}
    }

    try{
        $st=$pdo->prepare('SELECT id,nombre FROM alumnos WHERE whatsapp=:w LIMIT 2');
        $st->execute([':w'=>$e164]);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)===1){
            return ['role'=>'STUDENT','base_name'=>hache_sharky_contact_book_clean_name((string)$rows[0]['nombre']),'student_id'=>(string)$rows[0]['id'],'teacher_id'=>null];
        }
        if(count($rows)>1)return ['role'=>'UNKNOWN','base_name'=>$fallbackName,'student_id'=>null,'teacher_id'=>null];
    }catch(Throwable $e){}

    return ['role'=>'PROSPECT','base_name'=>hache_sharky_contact_book_clean_name($fallbackName),'student_id'=>null,'teacher_id'=>null];
}

function hache_sharky_contact_book_existing_payload(PDO $pdo,string $contactHash): ?array
{
    try{
        $st=$pdo->prepare('SELECT contact_ciphertext,contact_iv,contact_tag FROM sharky_contacts WHERE contact_hash=:c LIMIT 1');
        $st->execute([':c'=>$contactHash]);$row=$st->fetch(PDO::FETCH_ASSOC);
        return $row?hache_sharky_contact_book_decrypt($row):null;
    }catch(Throwable $e){return null;}
}

function hache_sharky_contact_book_capture_event(PDO $pdo,array $event): bool
{
    if(!hache_sharky_contact_book_schema_ready($pdo))return true;
    if(trim((string)($event['group_id']??''))!=='')return true;
    $contact=preg_replace('/\D+/','',(string)($event['from']??$event['to']??''))?:'';
    $normalized=hache_sharky_contact_book_normalize_phone($contact);
    if($normalized===null)return true;

    try{
        $contactHash=hache_sharky_orchestrator_contact_hash($normalized['digits']);
        $existing=hache_sharky_contact_book_existing_payload($pdo,$contactHash);
        $eventName=hache_sharky_contact_book_event_name($event);
        $fallbackName=$eventName!==''?$eventName:hache_sharky_contact_book_clean_name((string)($existing['base_name']??''));
        $identity=hache_sharky_contact_book_identity($pdo,$normalized['e164'],$fallbackName);
        $managedName=hache_sharky_contact_book_managed_name((string)$identity['base_name'],(string)$identity['role'],$normalized['digits']);
        $payload=[
            'e164'=>$normalized['e164'],
            'base_name'=>(string)$identity['base_name'],
            'managed_name'=>$managedName,
            'role'=>(string)$identity['role'],
            'student_id'=>$identity['student_id'],
            'teacher_id'=>$identity['teacher_id'],
        ];
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if($json===false)return false;
        $desiredHash=hash('sha256',$json);$sealed=hache_sharky_contact_book_encrypt($payload);
        $sql="INSERT INTO sharky_contacts(contact_hash,contact_ciphertext,contact_iv,contact_tag,desired_hash,role,alumno_id,profesor_id,sync_status,first_seen_at,last_seen_at)\n"
            ."VALUES(:c,:p,:iv,:tag,:d,:r,:a,:pr,'PENDING',NOW(),NOW())\n"
            ."ON DUPLICATE KEY UPDATE\n"
            ."sync_status=IF(sharky_contacts.desired_hash<>VALUES(desired_hash),'PENDING',sharky_contacts.sync_status),\n"
            ."last_error=IF(sharky_contacts.desired_hash<>VALUES(desired_hash),NULL,sharky_contacts.last_error),\n"
            ."last_sync_attempt_at=IF(sharky_contacts.desired_hash<>VALUES(desired_hash),NULL,sharky_contacts.last_sync_attempt_at),\n"
            ."contact_ciphertext=VALUES(contact_ciphertext),contact_iv=VALUES(contact_iv),contact_tag=VALUES(contact_tag),\n"
            ."desired_hash=VALUES(desired_hash),role=VALUES(role),alumno_id=VALUES(alumno_id),profesor_id=VALUES(profesor_id),last_seen_at=NOW()";
        $st=$pdo->prepare($sql);
        $st->execute([':c'=>$contactHash,':p'=>$sealed['ciphertext'],':iv'=>$sealed['iv'],':tag'=>$sealed['tag'],':d'=>$desiredHash,':r'=>$identity['role'],':a'=>$identity['student_id'],':pr'=>$identity['teacher_id']]);
        return true;
    }catch(Throwable $e){error_log('[sharky-contact-book] capture failed');return false;}
}

function hache_sharky_google_contacts_configured(): bool
{
    return hache_sharky_orchestrator_secret('GOOGLE_CONTACTS_SYNC_ENABLED')==='1'
        &&hache_sharky_orchestrator_secret('GOOGLE_CONTACTS_CLIENT_ID')!==''
        &&hache_sharky_orchestrator_secret('GOOGLE_CONTACTS_CLIENT_SECRET')!==''
        &&hache_sharky_orchestrator_secret('GOOGLE_CONTACTS_REFRESH_TOKEN')!=='';
}

/** @return array{status:int,json:?array} */
function hache_sharky_google_contacts_http(string $method,string $url,array $headers=[],?array $body=null): array
{
    $ch=curl_init($url);if($ch===false)return ['status'=>0,'json'=>null];
    $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers];
    if($body!==null){
        $json=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if($json===false){curl_close($ch);return ['status'=>0,'json'=>null];}
        $opts[CURLOPT_POSTFIELDS]=$json;
    }
    curl_setopt_array($ch,$opts);$response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    $decoded=is_string($response)?json_decode($response,true):null;
    return ['status'=>$status,'json'=>is_array($decoded)?$decoded:null];
}

function hache_sharky_google_contacts_access_token(): string
{
    $ch=curl_init('https://oauth2.googleapis.com/token');if($ch===false)return '';
    $fields=http_build_query([
        'client_id'=>hache_sharky_orchestrator_secret('GOOGLE_CONTACTS_CLIENT_ID'),
        'client_secret'=>hache_sharky_orchestrator_secret('GOOGLE_CONTACTS_CLIENT_SECRET'),
        'refresh_token'=>hache_sharky_orchestrator_secret('GOOGLE_CONTACTS_REFRESH_TOKEN'),
        'grant_type'=>'refresh_token',
    ]);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_POSTFIELDS=>$fields]);
    $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if(!is_string($response)||$status<200||$status>=300)return '';
    $decoded=json_decode($response,true);return is_array($decoded)?trim((string)($decoded['access_token']??'')):'';
}

function hache_sharky_google_contacts_phone_equal(string $a,string $b): bool
{
    $aa=hache_sharky_contact_book_normalize_phone($a);$bb=hache_sharky_contact_book_normalize_phone($b);
    return $aa!==null&&$bb!==null&&hash_equals($aa['digits'],$bb['digits']);
}

function hache_sharky_google_contacts_person_has_phone(array $person,string $e164): bool
{
    foreach(($person['phoneNumbers']??[]) as $phone){
        if(is_array($phone)&&hache_sharky_google_contacts_phone_equal((string)($phone['value']??''),$e164))return true;
    }
    return false;
}

function hache_sharky_google_contacts_person_managed(array $person): bool
{
    foreach(($person['userDefined']??[]) as $field){
        if(!is_array($field))continue;
        if((string)($field['key']??'')===HACHE_SHARKY_CONTACT_BOOK_MARKER_KEY&&(string)($field['value']??'')===HACHE_SHARKY_CONTACT_BOOK_MARKER_VALUE)return true;
    }
    return false;
}

function hache_sharky_google_contacts_person_body(array $contact,?array $metadata=null): array
{
    $body=[
        'names'=>[['givenName'=>(string)$contact['managed_name']]],
        'phoneNumbers'=>[['value'=>(string)$contact['e164'],'type'=>'mobile']],
        'organizations'=>[['name'=>'Hache Natación','title'=>hache_sharky_contact_book_role_label((string)$contact['role']),'type'=>'work']],
        'userDefined'=>[['key'=>HACHE_SHARKY_CONTACT_BOOK_MARKER_KEY,'value'=>HACHE_SHARKY_CONTACT_BOOK_MARKER_VALUE]],
    ];
    if(is_array($metadata))$body['metadata']=$metadata;
    return $body;
}

/** @return array{ok:bool,matches:array<int,array>} */
function hache_sharky_google_contacts_search_exact(string $accessToken,string $e164): array
{
    $readMask='names,phoneNumbers,organizations,userDefined,metadata';
    $base='https://people.googleapis.com/v1/people:searchContacts';
    // Google requires a warm-up query before searchContacts so the contact cache is fresh.
    $warmup=hache_sharky_google_contacts_http('GET',$base.'?'.http_build_query(['query'=>'','pageSize'=>1,'readMask'=>$readMask]),['Authorization: Bearer '.$accessToken]);
    if($warmup['status']<200||$warmup['status']>=300)return ['ok'=>false,'matches'=>[]];
    $response=hache_sharky_google_contacts_http('GET',$base.'?'.http_build_query(['query'=>$e164,'pageSize'=>30,'readMask'=>$readMask]),['Authorization: Bearer '.$accessToken]);
    if($response['status']<200||$response['status']>=300||!is_array($response['json']))return ['ok'=>false,'matches'=>[]];
    $matches=[];
    foreach(($response['json']['results']??[]) as $result){
        $person=is_array($result['person']??null)?$result['person']:null;
        if($person!==null&&hache_sharky_google_contacts_person_has_phone($person,$e164))$matches[]=$person;
    }
    return ['ok'=>true,'matches'=>$matches];
}

function hache_sharky_google_contacts_get(string $accessToken,string $resourceName): array
{
    if(!preg_match('#^people/[A-Za-z0-9_-]+$#',$resourceName))return ['status'=>0,'json'=>null];
    $url='https://people.googleapis.com/v1/'.rawurlencode(explode('/',$resourceName,2)[0]).'/'.rawurlencode(explode('/',$resourceName,2)[1]).'?'.http_build_query(['personFields'=>'names,phoneNumbers,organizations,userDefined,metadata']);
    return hache_sharky_google_contacts_http('GET',$url,['Authorization: Bearer '.$accessToken]);
}

function hache_sharky_google_contacts_create(string $accessToken,array $contact): array
{
    $url='https://people.googleapis.com/v1/people:createContact?'.http_build_query(['personFields'=>'names,phoneNumbers,organizations,userDefined,metadata']);
    return hache_sharky_google_contacts_http('POST',$url,['Authorization: Bearer '.$accessToken,'Content-Type: application/json'],hache_sharky_google_contacts_person_body($contact));
}

function hache_sharky_google_contacts_update(string $accessToken,string $resourceName,array $contact,array $latest): array
{
    $parts=explode('/',$resourceName,2);if(count($parts)!==2)return ['status'=>0,'json'=>null];
    $url='https://people.googleapis.com/v1/'.rawurlencode($parts[0]).'/'.rawurlencode($parts[1]).':updateContact?'.http_build_query([
        'updatePersonFields'=>'names,phoneNumbers,organizations,userDefined',
        'personFields'=>'names,phoneNumbers,organizations,userDefined,metadata',
    ]);
    $body=hache_sharky_google_contacts_person_body($contact,is_array($latest['metadata']??null)?$latest['metadata']:null);
    $body['resourceName']=$resourceName;
    return hache_sharky_google_contacts_http('PATCH',$url,['Authorization: Bearer '.$accessToken,'Content-Type: application/json'],$body);
}

function hache_sharky_contact_book_mark_sync(PDO $pdo,string $contactHash,string $status,?string $resourceName=null,string $error=''): void
{
    $allowed=['SYNCED','FAILED','UNMANAGED'];if(!in_array($status,$allowed,true))$status='FAILED';
    try{
        $sql='UPDATE sharky_contacts SET sync_status=:s,last_sync_attempt_at=NOW(),last_error=:e,google_resource_name=COALESCE(:g,google_resource_name),synced_at=' . ($status==='SYNCED'?'NOW()':'synced_at') . ' WHERE contact_hash=:c';
        $st=$pdo->prepare($sql);$st->execute([':s'=>$status,':e'=>$error===''?null:mb_substr($error,0,255),':g'=>$resourceName!==null&&$resourceName!==''?$resourceName:null,':c'=>$contactHash]);
    }catch(Throwable $e){error_log('[sharky-contact-book] sync marker failed');}
}

function hache_sharky_contact_book_clear_google_resource(PDO $pdo,string $contactHash): void
{
    try{$st=$pdo->prepare("UPDATE sharky_contacts SET google_resource_name=NULL,sync_status='PENDING',last_error=NULL,last_sync_attempt_at=NULL WHERE contact_hash=:c");$st->execute([':c'=>$contactHash]);}catch(Throwable $e){}
}

/** @return array<int,array{contact_hash:string,google_resource_name:string,contact:array}> */
function hache_sharky_contact_book_pending(PDO $pdo,int $limit=20): array
{
    $limit=max(1,min(50,$limit));$out=[];
    try{
        $rows=$pdo->query("SELECT contact_hash,google_resource_name,contact_ciphertext,contact_iv,contact_tag FROM sharky_contacts WHERE sync_status='PENDING' OR (sync_status='FAILED' AND (last_sync_attempt_at IS NULL OR last_sync_attempt_at<DATE_SUB(NOW(),INTERVAL 15 MINUTE))) ORDER BY last_seen_at,contact_hash LIMIT ".$limit)->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $row){
            $contact=hache_sharky_contact_book_decrypt($row);if(!is_array($contact))continue;
            $out[]=['contact_hash'=>(string)$row['contact_hash'],'google_resource_name'=>(string)($row['google_resource_name']??''),'contact'=>$contact];
        }
    }catch(Throwable $e){error_log('[sharky-contact-book] pending read failed');}
    return $out;
}

/** @return array{configured:bool,processed:int,synced:int,unmanaged:int,failed:int,locked:bool} */
function hache_sharky_contact_book_sync_pending(PDO $pdo,int $limit=20): array
{
    $stats=['configured'=>hache_sharky_google_contacts_configured(),'processed'=>0,'synced'=>0,'unmanaged'=>0,'failed'=>0,'locked'=>false];
    if(!$stats['configured']||!hache_sharky_contact_book_schema_ready($pdo))return $stats;
    try{$locked=(int)$pdo->query("SELECT GET_LOCK('".HACHE_SHARKY_GOOGLE_CONTACTS_LOCK."',0)")->fetchColumn();}catch(Throwable $e){$locked=0;}
    if($locked!==1)return $stats;
    $stats['locked']=true;
    try{
        $token=hache_sharky_google_contacts_access_token();
        if($token==='')return array_replace($stats,['failed'=>1]);
        foreach(hache_sharky_contact_book_pending($pdo,$limit) as $row){
            $stats['processed']++;$hash=$row['contact_hash'];$contact=$row['contact'];$resource=trim($row['google_resource_name']);
            try{
                $latest=null;
                if($resource!==''){
                    $get=hache_sharky_google_contacts_get($token,$resource);
                    if($get['status']===404){hache_sharky_contact_book_clear_google_resource($pdo,$hash);$resource='';}
                    elseif($get['status']>=200&&$get['status']<300&&is_array($get['json']))$latest=$get['json'];
                    else{hache_sharky_contact_book_mark_sync($pdo,$hash,'FAILED',$resource,'GOOGLE_GET_FAILED');$stats['failed']++;continue;}
                }

                if($resource===''){
                    $search=hache_sharky_google_contacts_search_exact($token,(string)$contact['e164']);
                    if(($search['ok']??false)!==true){hache_sharky_contact_book_mark_sync($pdo,$hash,'FAILED',null,'GOOGLE_SEARCH_FAILED');$stats['failed']++;continue;}
                    $matches=is_array($search['matches']??null)?$search['matches']:[];
                    if(count($matches)>1){hache_sharky_contact_book_mark_sync($pdo,$hash,'FAILED',null,'GOOGLE_DUPLICATE_PHONE');$stats['failed']++;continue;}
                    if(count($matches)===1){
                        $latest=$matches[0];$resource=trim((string)($latest['resourceName']??''));
                        if($resource===''){hache_sharky_contact_book_mark_sync($pdo,$hash,'FAILED',null,'GOOGLE_RESOURCE_MISSING');$stats['failed']++;continue;}
                    }
                }

                if(is_array($latest)&&!hache_sharky_google_contacts_person_managed($latest)){
                    hache_sharky_contact_book_mark_sync($pdo,$hash,'UNMANAGED',$resource,'');$stats['unmanaged']++;continue;
                }

                $response=$resource===''
                    ?hache_sharky_google_contacts_create($token,$contact)
                    :hache_sharky_google_contacts_update($token,$resource,$contact,$latest??[]);
                if($response['status']<200||$response['status']>=300||!is_array($response['json'])){
                    hache_sharky_contact_book_mark_sync($pdo,$hash,'FAILED',$resource,'GOOGLE_WRITE_FAILED');$stats['failed']++;continue;
                }
                $savedResource=trim((string)($response['json']['resourceName']??$resource));
                hache_sharky_contact_book_mark_sync($pdo,$hash,'SYNCED',$savedResource,'');$stats['synced']++;
            }catch(Throwable $e){hache_sharky_contact_book_mark_sync($pdo,$hash,'FAILED',$resource,'GOOGLE_SYNC_EXCEPTION');$stats['failed']++;}
        }
    }finally{
        try{$pdo->query("SELECT RELEASE_LOCK('".HACHE_SHARKY_GOOGLE_CONTACTS_LOCK."')");}catch(Throwable $e){}
    }
    return $stats;
}
