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

assert.match(source, /smb_message_echoes/);
assert.match(source, /message_echoes/);
assert.match(source, /whatsapp_extract_business_echoes/);
assert.match(source, /whatsapp_mark_human_takeover/);
assert.match(source, /whatsapp_human_takeover_active/);
assert.match(source, /\/var\/tmp\/hache-whatsapp-human/);
assert.match(source, /human takeover activated/);
assert.match(source, /inbound text skipped human_takeover=1/);
assert.match(source, /queued text skipped human_takeover=1/);
assert.ok(
  source.indexOf('whatsapp_extract_business_echoes($payload)') < source.indexOf('whatsapp_extract_text_messages($payload)'),
  'Los ecos de atención humana deben procesarse antes de encolar mensajes del cliente'
);
assert.ok(
  source.indexOf('whatsapp_human_takeover_active($job[\'from\'])') < source.indexOf('whatsapp_answer_with_history($job[\'from\'], $job[\'text\'])'),
  'Debe revalidarse el takeover antes de llamar a Sharky'
);

assert.doesNotMatch(source, /error_log\([^\n]*(?:\$raw|\$payload|\$message\['text'\]|\$job\['text'\]|\$job\['from'\]|\$echo\['to'\])/);

console.log('WhatsApp webhook static checks: OK');
