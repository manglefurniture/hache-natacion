import fs from 'node:fs';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../public/api/whatsapp-webhook.php', import.meta.url), 'utf8');

assert.match(source, /WHATSAPP_VERIFY_TOKEN/);
assert.match(source, /hub\.challenge/);
assert.match(source, /hub\.verify_token/);
assert.match(source, /hash_equals\(/);
assert.match(source, /REQUEST_METHOD/);
assert.match(source, /file_get_contents\('php:\/\/input'\)/);
assert.match(source, /http_response_code\(200\)/);

console.log('WhatsApp webhook static checks: OK');
