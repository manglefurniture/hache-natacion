import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const sharkyWrapper = read('api/sharky.php');
const whatsappWrapper = read('api/whatsapp-webhook.php');
const sharky = read('public/api/sharky-v2.php');
const webhook = read('public/api/whatsapp-webhook-v2.php');
const runtime = read('config/sharky-runtime.php');
const adminApi = read('api/sharky-admin.php');
const adminPage = read('public/sharky-admin.php');
const configPage = read('public/configuracion.php');

assert.match(sharkyWrapper, /sharky-v2\.php/);
assert.match(whatsappWrapper, /whatsapp-webhook-v2\.php/);

assert.match(sharky, /CANAL ACTUAL: WHATSAPP/);
assert.match(sharky, /NUNCA le des al cliente el número de WhatsApp ni un enlace wa\.me/);
assert.match(sharky, /hache_sharky_dynamic_context/);
assert.match(sharky, /sharky-internal-whatsapp/);
assert.match(sharky, /'channel' => \$channel/);
assert.match(sharky, /sharky_precio_intensivo/);
assert.match(sharky, /sharky_inscripcion_monteverde/);
assert.match(sharky, /sharky_kit_gorro_goggles/);

assert.match(runtime, /'sharky_inscripcion_monteverde' => \['valor' => '500'/);
assert.match(runtime, /'sharky_kit_gorro_goggles' => \['valor' => '300'/);
assert.match(runtime, /sharky_cupo_maximo_intensivo/);
assert.match(runtime, /SELECT s\.nombre sede,h\.hora_inicio,h\.hora_fin/);
assert.match(runtime, /cursos_intensivos/);
assert.match(runtime, /function hache_sharky_human_request/);
assert.match(runtime, /function hache_sharky_frustration/);
assert.match(runtime, /function hache_sharky_takeover_resume_hash/);
assert.match(runtime, /function hache_sharky_metric_increment/);

assert.match(webhook, /'channel'=>'whatsapp'/);
assert.match(webhook, /hache_sharky_human_request/);
assert.match(webhook, /requested_human/);
assert.match(webhook, /hache_sharky_frustration/);
assert.match(webhook, /unresolved/);
assert.match(webhook, /audio\/transcriptions/);
assert.match(webhook, /gpt-4o-mini-transcribe/);
assert.match(webhook, /sharky_escalado_intentos/);
assert.match(webhook, /messages_skipped_takeover/);

assert.match(adminApi, /'RESUME'/);
assert.match(adminApi, /'CONFIG'/);
assert.match(adminApi, /ON DUPLICATE KEY UPDATE/);
assert.match(adminPage, /Reactivar Sharky/);
assert.match(adminPage, /Fuente de verdad comercial/);
assert.match(adminPage, /Últimos 7 días/);
assert.match(configPage, /href="\/sharky-admin\.php"/);

console.log('Sharky operations suite regressions: OK');
