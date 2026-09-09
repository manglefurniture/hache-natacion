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

contact_book_expect(hache_sharky_contact_book_managed_name('Juan Pérez','PROSPECT','529981234567')==='Juan Pérez — Prospecto Hache','Prospect naming contract changed.');
contact_book_expect(hache_sharky_contact_book_managed_name('Juan Pérez','STUDENT','529981234567')==='Juan Pérez — Alumno Hache','Student promotion must rename the managed contact.');
contact_book_expect(hache_sharky_contact_book_managed_name('','PROSPECT','529981234567')==='Prospecto Hache · 4567','Nameless prospects need a useful non-PII fallback label.');

$sealed=hache_sharky_contact_book_encrypt(['e164'=>'+529981234567','managed_name'=>'Juan Pérez — Prospecto Hache']);
$opened=hache_sharky_contact_book_decrypt(['contact_ciphertext'=>$sealed['ciphertext'],'contact_iv'=>$sealed['iv'],'contact_tag'=>$sealed['tag']]);
contact_book_expect(($opened['e164']??'')==='+529981234567','Contact PII must round-trip through AES-GCM encryption.');

$person=hache_sharky_google_contacts_person_body(['managed_name'=>'Juan Pérez — Alumno Hache','e164'=>'+529981234567','role'=>'STUDENT']);
contact_book_expect(($person['names'][0]['givenName']??'')==='Juan Pérez — Alumno Hache','Google contact must use the managed Hache name.');
contact_book_expect(($person['organizations'][0]['name']??'')==='Hache Natación'&&($person['organizations'][0]['title']??'')==='Alumno Hache','Google contact must carry Hache organization/status.');
contact_book_expect(hache_sharky_google_contacts_person_managed($person),'Hache-created contacts must carry an explicit ownership marker.');
contact_book_expect(!hache_sharky_google_contacts_person_managed(['phoneNumbers'=>[['value'=>'+529981234567']]]),'Pre-existing user contacts must never be treated as Hache-managed.');
contact_book_expect(hache_sharky_google_contacts_person_has_phone(['phoneNumbers'=>[['value'=>'+52 998 123 4567']]],'+529981234567'),'Google phone matching must normalize formatting.');
contact_book_expect(!hache_sharky_google_contacts_person_has_phone(['phoneNumbers'=>[['value'=>'+529981234568']]],'+529981234567'),'Google phone matching must not accept a neighboring number.');
contact_book_expect(hache_sharky_google_contacts_get('token','not-a-resource')['status']===0,'Invalid Google resource names must fail before network access.');

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
$profiles=file_get_contents(__DIR__.'/../config/sharky-contact-profiles.php')?:'';
$delivery=file_get_contents(__DIR__.'/../config/sharky-delivery-status.php')?:'';
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
contact_book_expect(str_contains($source,'people:createContact')&&str_contains($source,':updateContact'),'Google sync must support both contact creation and managed-contact updates.');
contact_book_expect(str_contains($source,'GOOGLE_DUPLICATE_PHONE')&&str_contains($source,"'UNMANAGED'"),'Ambiguous matches and pre-existing unmanaged contacts must fail/skip safely.');
contact_book_expect(str_contains($source,'GOOGLE_SEARCH_FAILED')&&str_contains($source,"return ['ok'=>false,'matches'=>[]]"),'A failed Google lookup must never be treated as a clean no-match/create decision.');

fwrite(STDOUT,"SHARKY_CONTACT_BOOK_OK\n");
