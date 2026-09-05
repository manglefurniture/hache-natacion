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

for (const fragment of [
  'TABLE_COLLATION',
  "utf8mb4_unicode_ci",
  "['COLUMN_NAME'=>'id','COLUMN_TYPE'=>'bigint unsigned','IS_NULLABLE'=>'NO','EXTRA'=>'auto_increment'",
  "['COLUMN_NAME'=>'metric','COLUMN_TYPE'=>\"enum('lcp','inp','cls')\"",
  "['COLUMN_NAME'=>'value','COLUMN_TYPE'=>'decimal(20,8) unsigned'",
  "['COLUMN_NAME'=>'route_group','COLUMN_TYPE'=>'varchar(64)'",
  "['COLUMN_NAME'=>'build_id','COLUMN_TYPE'=>'varchar(64)'",
  "['COLUMN_NAME'=>'form_factor','COLUMN_TYPE'=>\"enum('mobile','desktop')\"",
  "['COLUMN_NAME'=>'created_at_utc','COLUMN_TYPE'=>'datetime(6)'",
  "'PRIMARY'=>['non_unique'=>0,'type'=>'BTREE','columns'=>['id']]",
  "'idx_production_rum_build'=>['non_unique'=>1,'type'=>'BTREE','columns'=>['build_id','created_at_utc']]",
  "'idx_production_rum_window'=>['non_unique'=>1,'type'=>'BTREE','columns'=>['created_at_utc','metric','route_group','form_factor']]",
  "'table_collation_verified'=>true",
  "'column_types_verified'=>true",
  "'nullability_verified'=>true",
  "'value_precision_verified'=>true",
  "'enum_contract_verified'=>true",
  "'indexes_verified'=>true",
]) {
  assert.ok(endpoint.includes(fragment), `temporary endpoint must verify full RUM schema contract: ${fragment}`);
}

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
