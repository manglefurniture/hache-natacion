<?php

declare(strict_types=1);

putenv('SHARKY_STATE_ENCRYPTION_KEY=contact-book-regression-state-key-2026-abcdef');
putenv('SHARKY_CONTACT_HASH_KEY=contact-book-regression-hash-key-2026-abcdef');
require_once __DIR__.'/../config/sharky-contact-book.php';
require_once __DIR__.'/../config/sharky-contact-profiles.php';

function contact_book_expect(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"SHARKY CONTACT BOOK FAIL: {$message}\n");exit(1);}
}

$mx=hache_sharky_contact_book_normalize_phone('521 998 123 4567');
contact_book_expect(is_array($mx)&&$mx['e164']==='+529981234567','Meta 521 legacy numbers must normalize to current Mexican E.164.');
contact_book_expect(hache_sharky_contact_book_normalize_phone('abc')===null,'Invalid contacts must fail closed.');

$name=hache_sharky_contact_book_event_name(['commerce'=>['full_name'=>'  Juan   Pérez  ']]);
contact_book_expect($name==='Juan Pérez','Enrollment full_name must become the durable contact base name.');
contact_book_expect(hache_sharky_contact_book_event_name(['profile_name'=>'  María   López '])==='María López','WhatsApp profile name must be accepted as a naming hint.');
contact_book_expect(hache_sharky_contact_book_event_name(['text'=>'hola'])==='','Ordinary text must not be guessed as a person name.');

contact_book_expect(hache_sharky_contact_naming_person('Juan Pérez Gómez')==='JUAN PÉREZ','Contacts must keep first name + first surname in uppercase.');
contact_book_expect(hache_sharky_contact_naming_time('08:00:00')==='8 AM','Morning schedule format changed.');
contact_book_expect(hache_sharky_contact_naming_time('20:00:00')==='8 PM','Evening schedule format changed.');
contact_book_expect(hache_sharky_contact_naming_time('08:30:00')==='8:30 AM','Non-zero minutes must remain visible.');
contact_book_expect(hache_sharky_contact_naming_date('2026-09-14')==='SEP 14','Intensive start date must use Spanish three-letter month + day.');
contact_book_expect(hache_sharky_contact_naming_default_sigla('MONTEVERDE')==='MV','Monteverde default contact sigla changed.');
contact_book_expect(hache_sharky_contact_naming_default_sigla('PALAPAS')==='PAL','Palapas default contact sigla changed.');
contact_book_expect(hache_sharky_contact_naming_default_sigla('NUEVA_SEDE')==='NUE','New venues need a deterministic scalable fallback sigla.');
contact_book_expect(hache_sharky_contact_naming_config_value_valid('sharky_contact_sigla_monteverde','MV'),'Valid contact sigla should be accepted.');
contact_book_expect(!hache_sharky_contact_naming_config_value_valid('sharky_contact_sigla_monteverde','MV PAL'),'Contact sigla must stay compact.');

contact_book_expect(hache_sharky_contact_book_managed_name('Juan Pérez','PROSPECT','529981234567')==='JUAN PÉREZ — PROSPECTO HACHE','Prospect naming contract changed.');
contact_book_expect(hache_sharky_contact_book_managed_name('Juan Pérez','STUDENT','529981234567')==='JUAN PÉREZ — ALUMNO HACHE','Generic student fallback must remain uppercase.');
contact_book_expect(hache_sharky_contact_book_managed_name('','PROSPECT','529981234567')==='PROSPECTO HACHE · 4567','Nameless prospects need a useful fallback label.');

$sealed=hache_sharky_contact_book_encrypt(['e164'=>'+529981234567','managed_name'=>'JUAN PÉREZ — PROSPECTO HACHE']);
$opened=hache_sharky_contact_book_decrypt(['contact_ciphertext'=>$sealed['ciphertext'],'contact_iv'=>$sealed['iv'],'contact_tag'=>$sealed['tag']]);
contact_book_expect(($opened['e164']??'')==='+529981234567','Contact PII must round-trip through AES-GCM encryption.');

$person=hache_sharky_google_contacts_person_body(['managed_name'=>'MV SEP 14 - JUAN PÉREZ (8 AM)','e164'=>'+529981234567','role'=>'STUDENT']);
contact_book_expect(($person['names'][0]['givenName']??'')==='MV SEP 14 - JUAN PÉREZ (8 AM)','Google contact must use the managed Hache name.');
contact_book_expect(($person['organizations'][0]['name']??'')==='Hache Natación'&&($person['organizations'][0]['title']??'')==='Alumno Hache','Google contact must carry Hache organization/status.');
contact_book_expect(hache_sharky_google_contacts_person_managed($person),'Hache-created contacts must carry an explicit ownership marker.');
contact_book_expect(!hache_sharky_google_contacts_person_managed(['phoneNumbers'=>[['value'=>'+529981234567']]]),'An owner-created contact should still be distinguishable from an Hache-created one.');
contact_book_expect(hache_sharky_google_contacts_person_has_phone(['phoneNumbers'=>[['value'=>'+52 998 123 4567']]],'+529981234567'),'Google phone matching must normalize formatting.');
contact_book_expect(!hache_sharky_google_contacts_person_has_phone(['phoneNumbers'=>[['value'=>'+529981234568']]],'+529981234567'),'Google phone matching must not accept a neighboring number.');
contact_book_expect(hache_sharky_google_contacts_get('token','not-a-resource')['status']===0,'Invalid Google resource names must fail before network access.');

$existing=[
    'names'=>[['givenName'=>'Juan piscina']],
    'phoneNumbers'=>[['value'=>'+529981234567'],['value'=>'+529981234568']],
    'organizations'=>[['name'=>'Empresa personal','title'=>'Cliente']],
    'userDefined'=>[['key'=>'Nota privada','value'=>'No borrar']],
    'metadata'=>['sources'=>[['type'=>'CONTACT','etag'=>'abc']]],
];
$update=hache_sharky_google_contacts_update_body(['managed_name'=>'MV SEP 14 - JUAN PÉREZ (8 AM)','role'=>'STUDENT'],$existing);
contact_book_expect(($update['names'][0]['givenName']??'')==='MV SEP 14 - JUAN PÉREZ (8 AM)','Unique owner-created Google contact must be renameable.');
contact_book_expect(count($update['organizations']??[])===2,'Existing non-Hache organizations must be preserved.');
contact_book_expect(($update['userDefined'][0]['key']??'')==='Nota privada','Existing custom fields must be preserved.');
contact_book_expect(!isset($update['phoneNumbers']),'Google update body must not replace existing phone numbers.');

putenv('GOOGLE_CONTACTS_SYNC_ENABLED=0');
contact_book_expect(!hache_sharky_google_contacts_configured(),'Google sync must remain off without explicit enablement.');
putenv('GOOGLE_CONTACTS_SYNC_ENABLED=1');
putenv('GOOGLE_CONTACTS_CLIENT_ID=test-client');
putenv('GOOGLE_CONTACTS_CLIENT_SECRET=test-secret');
putenv('GOOGLE_CONTACTS_REFRESH_TOKEN=test-refresh');
contact_book_expect(hache_sharky_google_contacts_configured(),'Google sync must require the full OAuth credential set.');

$sql=file_get_contents(__DIR__.'/../database/migrations/20260908_sharky_contact_book.sql')?:'';
$inbox=file_get_contents(__DIR__.'/../config/sharky-inbox.php')?:'';
$worker=file_get_contents(__DIR__.'/../bin/sharky-inbox-dispatch.php')?:'';
$source=file_get_contents(__DIR__.'/../config/sharky-contact-book.php')?:'';
$naming=file_get_contents(__DIR__.'/../config/sharky-contact-naming.php')?:'';
$profiles=file_get_contents(__DIR__.'/../config/sharky-contact-profiles.php')?:'';
$delivery=file_get_contents(__DIR__.'/../config/sharky-delivery-status.php')?:'';
$admin=file_get_contents(__DIR__.'/../api/sharky-admin.php')?:'';
$business=file_get_contents(__DIR__.'/../config/sharky-business-actions.php')?:'';
contact_book_expect(str_contains($sql,'CREATE TABLE IF NOT EXISTS sharky_contacts'),'Contact book migration must be additive/idempotent.');
contact_book_expect(str_contains($sql,'contact_ciphertext MEDIUMTEXT')&&str_contains($sql,'desired_hash CHAR(64)'),'Contact book must encrypt PII and keep only a deterministic desired-state hash searchable.');
contact_book_expect(!str_contains($sql,'whatsapp VARCHAR')&&!str_contains($sql,'nombre VARCHAR')&&!str_contains($sql,'phone VARCHAR'),'Migration must not create searchable plaintext phone/name columns.');
contact_book_expect(str_contains($inbox,"require_once __DIR__.'/sharky-contact-book.php'")&&str_contains($inbox,'hache_sharky_contact_book_capture_event($pdo,$event)'),'Every durable direct inbound/echo must feed the contact authority.');
contact_book_expect(str_contains($profiles,"\$contact['profile']['name']")&&str_contains($profiles,"\$contact['wa_id']"),'Meta contacts profile name must be captured only with its wa_id.');
contact_book_expect(str_contains($profiles,"metadata']['phone_number_id")&&str_contains($profiles,'hash_equals($configuredPhoneId,$phoneId)'),'Profile capture must reject events for another WhatsApp business number.');
contact_book_expect(str_contains($delivery,'hache_sharky_contact_book_capture_profiles_payload($pdo,$payload,$configuredPhoneId)'),'Signed raw webhook processing must feed profile names with the same configured phone-number gate.');
contact_book_expect(str_contains($worker,'hache_sharky_contact_book_apply_additive_migration($pdo)'),'Only the CLI worker may apply the additive contact migration.');
contact_book_expect(str_contains($worker,"__DIR__.'/../database/migrations/20260908_sharky_contact_book.sql'"),'CLI migration must use the tracked SQL source of truth.');
contact_book_expect(str_contains($worker,'hache_sharky_contact_book_sync_pending($pdo,10)'),'Existing one-minute worker must drive external contact sync outside webhook latency.');
contact_book_expect(str_contains($source,"GET_LOCK('")&&str_contains($source,'GOOGLE_CONTACTS_SYNC_ENABLED'),'Google writes must be serialized and explicitly enabled.');
contact_book_expect(str_contains($source,'people:createContact')&&str_contains($source,':updateContact'),'Google sync must support both contact creation and updates.');
contact_book_expect(str_contains($source,'GOOGLE_DUPLICATE_PHONE')&&str_contains($source,'updatePersonFields\'=>\'names,organizations,userDefined'),'Google exact-phone duplicates must fail closed while unique matches can be renamed without replacing phones.');
contact_book_expect(str_contains($source,'GOOGLE_SEARCH_FAILED')&&str_contains($source,"return ['ok'=>false,'matches'=>[]]"),'A failed Google lookup must never be treated as a clean no-match/create decision.');
contact_book_expect(str_contains($naming,"'MONTEVERDE')return 'MV'")&&str_contains($naming,"'PALAPAS')return 'PAL'"),'Current venue siglas must remain explicit defaults.');
contact_book_expect(str_contains($admin,'hache_sharky_contact_naming_config_rows($pdo)'),'Sharky Admin must expose contact siglas dynamically for every active venue.');
contact_book_expect(str_contains($business,"'kind'=>'registration_created'")&&str_contains($business,'hache_sharky_contact_book_capture_event'),'Successful registration must refresh the contact immediately.');

fwrite(STDOUT,"SHARKY_CONTACT_BOOK_OK\n");
