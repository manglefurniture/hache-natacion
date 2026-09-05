import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const endpoint = await readFile(new URL('../public/api/ops-apply-sharky-delivery-status.php', import.meta.url), 'utf8');
const migration = await readFile(new URL('../database/migrations/20260905_sharky_delivery_status.sql', import.meta.url), 'utf8');
const crypto = await import('node:crypto');

for (const fragment of [
  "$_SERVER['REMOTE_ADDR']",
  "['127.0.0.1','::1']",
  "$_SERVER['REQUEST_METHOD']",
  "'POST'",
  "$_SERVER['HTTP_X_HACHE_OPS']",
  "apply-sharky-delivery-status-20260905",
  "hash_file('sha256',$migration)",
  "hache_sharky_activation_split_sql($sql)",
  "20260905_sharky_delivery_status.sql",
  "Cache-Control: no-store",
  "X-Robots-Tag: noindex, nofollow",
]) {
  assert.ok(endpoint.includes(fragment), `missing temporary ops endpoint guard: ${fragment}`);
}

const expectedHash = crypto.createHash('sha256').update(migration).digest('hex');
assert.ok(endpoint.includes(`$expectedSha256='${expectedHash}'`), 'temporary endpoint must pin the reviewed migration hash');
assert.ok(!endpoint.includes('HTTP_X_FORWARDED_FOR'), 'loopback authorization must not trust forwarded-for');
assert.ok(!endpoint.includes('HTTP_CF_CONNECTING_IP'), 'loopback authorization must not trust Cloudflare client headers');
assert.ok(!endpoint.includes('$e->getMessage()'), 'temporary endpoint must not expose exception details');
assert.ok(!endpoint.includes("'password'"), 'temporary endpoint must not contain database credentials');

console.log('OPS_DELIVERY_MIGRATION_ENDPOINT_REGRESSION_OK');
