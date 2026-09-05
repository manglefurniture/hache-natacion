import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';

const endpoint = await readFile(new URL('../api/ops-apply-production-rum.php', import.meta.url), 'utf8');
const migration = await readFile(new URL('../database/migrations/20260905_production_rum.sql', import.meta.url), 'utf8');
const databaseGate = await readFile(new URL('../config/database.php', import.meta.url), 'utf8');

for (const fragment of [
  "$_SERVER['REMOTE_ADDR']",
  "['127.0.0.1','::1']",
  "$_SERVER['REQUEST_METHOD']",
  "'POST'",
  "'/tmp/hache-rum-migration-token'",
  "$_SERVER['HTTP_X_HACHE_RUM_MIGRATION_TOKEN']",
  "preg_match('/^[a-f0-9]{64}$/',$expected)",
  "hash_equals($expected,$provided)",
  "hash_file('sha256',$migration)",
  "20260905_production_rum.sql",
  "production_rum_samples",
  "GET_LOCK",
  "RELEASE_LOCK",
  "Cache-Control: no-store",
  "X-Robots-Tag: noindex, nofollow",
]) {
  assert.ok(endpoint.includes(fragment), `missing temporary RUM migration guard: ${fragment}`);
}

const expectedHash = createHash('sha256').update(migration).digest('hex');
assert.ok(endpoint.includes(`$expectedSha256='${expectedHash}'`), 'temporary endpoint must pin the reviewed RUM migration hash');
assert.ok(endpoint.includes("$expectedColumns=['id','metric','value','route_group','build_id','form_factor','created_at_utc']"), 'temporary endpoint must verify exact minimized RUM columns');
assert.ok(endpoint.includes("(int)$valueMeta['NUMERIC_PRECISION']===20"), 'temporary endpoint must verify value precision');
assert.ok(endpoint.includes("(int)$valueMeta['NUMERIC_SCALE']===8"), 'temporary endpoint must verify value scale');
assert.ok(endpoint.includes("idx_production_rum_window"), 'temporary endpoint must verify window index');
assert.ok(endpoint.includes("idx_production_rum_build"), 'temporary endpoint must verify build index');

for (const forbidden of [
  'HTTP_X_FORWARDED_FOR',
  'HTTP_CF_CONNECTING_IP',
  '$e->getMessage()',
  "'password'",
]) {
  assert.ok(!endpoint.includes(forbidden), `temporary endpoint exposes or trusts forbidden data: ${forbidden}`);
}

const route = "'/api/ops-apply-production-rum.php'";
assert.equal(databaseGate.split(route).length - 1, 2, 'temporary endpoint must bypass browser auth and generic IP audit only through the two explicit central-gate lists');
assert.ok(databaseGate.includes('$publicApi = ['), 'central public API list missing');
assert.ok(databaseGate.includes('$skipAudit = ['), 'central audit exclusion list missing');

console.log('OPS_PRODUCTION_RUM_MIGRATION_ENDPOINT_REGRESSION_OK');
