import fs from 'node:fs';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../public/api/whatsapp-webhook.php', import.meta.url), 'utf8');

assert.match(source, /WHATSAPP_VERIFY_TOKEN/);
assert.match(source, /META_APP_SECRET/);
assert.match(source, /WHATSAPP_ACCESS_TOKEN/);
assert.match(source, /WHATSAPP_PHONE_NUMBER_ID/);
assert.match(source, /hub\.challenge/);
assert.match(source, /hub\.verify_token/);
assert.match(source, /HTTP_X_HUB_SIGNATURE_256/);
assert.match(source, /hash_hmac\('sha256'/);
assert.match(source, /hash_equals\(/);
assert.match(source, /REQUEST_METHOD/);
assert.match(source, /file_get_contents\('php:\/\/input'\)/);
assert.match(source, /fastcgi_finish_request/);
assert.match(source, /\['messages'\]/);
assert.match(source, /\(\$message\['type'\] \?\? ''\) !== 'text'/);
assert.match(source, /hache-whatsapp-dedupe/);
assert.match(source, /hache-whatsapp-history/);
assert.match(source, /\/api\/sharky\.php/);
assert.match(source, /graph\.facebook\.com/);
assert.match(source, /\/messages'/);
assert.match(source, /processed text message sent=/);
assert.doesNotMatch(source, /error_log\([^\n]*(?:\$raw|\$payload|\$message\['text'\]|\$job\['text'\]|\$job\['from'\])/);

console.log('WhatsApp webhook static checks: OK');
