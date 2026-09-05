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
  'function ops_rum_schema_ready(PDO $pdo)',
  'TABLE_COLLATION',
  'utf8mb4_unicode_ci',
  'DATA_TYPE',
  'COLUMN_TYPE',
  'IS_NULLABLE',
  '(COLUMN_DEFAULT IS NULL) AS DEFAULT_IS_NULL',
  'CHARACTER_MAXIMUM_LENGTH',
  'NUMERIC_PRECISION',
  'NUMERIC_SCALE',
  'DATETIME_PRECISION',
  "ops_rum_column_base_ok($id,'id','bigint')",
  "str_contains(strtolower((string)($id['COLUMN_TYPE']??'')),'unsigned')",
  "enum('lcp','inp','cls')",
  "decimal(20,8) unsigned",
  "ops_rum_column_base_ok($row,$name,'varchar')",
  "(int)($row['CHARACTER_MAXIMUM_LENGTH']??0)!==64",
  "enum('mobile','desktop')",
  "(int)($created['DATETIME_PRECISION']??-1)!==6",
  'SUB_PART',
  "'PRIMARY'=>['non_unique'=>0,'type'=>'BTREE'",
  "'idx_production_rum_build'=>['non_unique'=>1,'type'=>'BTREE'",
  "'idx_production_rum_window'=>['non_unique'=>1,'type'=>'BTREE'",
  "if(count($seen)!==count($required))return false",
  "if(($seen[$name]??null)!==$expectedIndex)return false",
  "if(!ops_rum_schema_ready($pdo))throw new RuntimeException('verify')",
  "'table_collation_verified'=>true",
  "'column_types_verified'=>true",
  "'nullability_verified'=>true",
  "'column_defaults_verified'=>true",
  "'value_precision_verified'=>true",
  "'enum_contract_verified'=>true",
  "'indexes_verified'=>true",
]) {
  assert.ok(endpoint.includes(fragment), `temporary endpoint must verify semantic RUM schema contract: ${fragment}`);
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
