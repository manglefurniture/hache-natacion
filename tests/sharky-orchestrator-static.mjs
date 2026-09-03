import fs from 'node:fs';
import assert from 'node:assert/strict';

const core = fs.readFileSync(new URL('../config/sharky-orchestrator.php', import.meta.url), 'utf8');
const store = fs.readFileSync(new URL('../config/sharky-orchestrator-store.php', import.meta.url), 'utf8');
const migration = fs.readFileSync(new URL('../database/migrations/20260902_sharky_orchestrator.sql', import.meta.url), 'utf8');
const docs = fs.readFileSync(new URL('../docs/SHARKY-2-ORCHESTRATOR.md', import.meta.url), 'utf8');

for (const marker of [
  'HACHE_SHARKY_FLOW_TTL',
  'HACHE_SHARKY_BATCH_WINDOW_MS',
  'requires_revalidation',
  'create_absence',
  'register_intensive',
  'verification_required',
  'silent_human_takeover',
  'ctwa_clid',
]) assert.ok(core.includes(marker), `core must contain ${marker}`);

assert.ok(core.indexOf('hache_sharky_orchestrator_capture_referral') < core.indexOf("$intent = hache_sharky_orchestrator_contextual_intent"), 'referral is captured before contextual intent classification');
assert.doesNotMatch(core, /curl_(?:init|exec)|api\.openai\.com|graph\.facebook\.com|\bINSERT\b|\bUPDATE\b|\bDELETE\b/i, 'deterministic core cannot call remote services or mutate DB');

for (const marker of [
  'sharky_message_receipts',
  'sharky_referrals',
  'sharky_action_audit',
  'INSERT IGNORE',
  'contact_hash',
  'payload_hash',
  'hache_sharky_orchestrator_lock',
  'hache_sharky_orchestrator_batch_enqueue_and_wait',
]) assert.ok(store.includes(marker), `store must contain ${marker}`);

assert.doesNotMatch(store, /OPENAI_API_KEY|WHATSAPP_ACCESS_TOKEN|api\.openai\.com|graph\.facebook\.com/, 'orchestrator store cannot own external API credentials/calls');
assert.match(store, /\/var\/tmp|sys_get_temp_dir\(\)/, 'ephemeral state must use local runtime storage');

for (const table of ['sharky_message_receipts', 'sharky_referrals', 'sharky_action_audit']) {
  assert.match(migration, new RegExp(`CREATE TABLE IF NOT EXISTS ${table}`), `${table} migration must be idempotent`);
}
assert.match(migration, /PRIMARY KEY[\s\S]*message_id|message_id[^\n]*PRIMARY KEY/, 'message receipt id must be unique');
assert.match(migration, /UNIQUE KEY uq_sharky_referral_message \(message_id\)/, 'one durable referral per Meta message');
assert.match(migration, /UNIQUE KEY uq_sharky_action_idempotency \(idempotency_key\)/, 'actions require durable idempotency');
assert.doesNotMatch(migration, /phone|telefono|whatsapp_number/i, 'durable orchestration tables must not persist raw phone numbers');

assert.match(docs, /laboratorio \/ no conectado al webhook de producción/i);
assert.match(docs, /Referral \/ atribución de Meta/);
assert.match(docs, /backend como autoridad/i);
assert.match(docs, /Codex \*\*solo al final\*\*/i);

console.log('Sharky orchestrator static checks: OK');
