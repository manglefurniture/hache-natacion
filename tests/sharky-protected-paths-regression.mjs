import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const read=p=>readFileSync(new URL(`../${p}`,import.meta.url),'utf8');
for(const file of ['config/sharky-contact-book.php','config/sharky-inbox.php','config/sharky-outbox.php','public/api/whatsapp-orchestrator-lab.php','public/api/whatsapp-webhook-v2.php','public/api/whatsapp-webhook.php']){
  assert.match(read(file),/hache_sharky_is_protected_number\(/,`${file} lacks the protection gate`);
}
const inbox=read('config/sharky-inbox.php');
assert.ok(inbox.indexOf('hache_sharky_contact_book_capture_event')>inbox.indexOf('INSERT IGNORE INTO sharky_message_receipts'),'inbound receipt must precede contact changes');
assert.ok(inbox.indexOf('hache_sharky_is_protected_number($pdo,hache_sharky_inbox_contact($event))')<inbox.indexOf('$processor($event)'),'the worker must gate before automated processing');
const outbox=read('config/sharky-outbox.php');
assert.ok(outbox.indexOf("'PROTECTED_NUMBER'")<outbox.indexOf('$sender($payload)'),'pending sends must be cancelled before delivery');
const admin=read('api/sharky-admin.php');
assert.match(admin,/auth_require\(\['ADMIN'\]\)/);
assert.match(admin,/auth_csrf_validate/);
assert.match(read('public/sharky-admin.php'),/Números protegidos/);
assert.match(read('ops/production-readiness/deploy-hache-natacion'),/migrate-sharky-protected-numbers\.php/);
console.log('SHARKY_PROTECTED_PATHS_OK');
