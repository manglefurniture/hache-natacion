import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const collector = await readFile(new URL('../bin/production-readiness-evidence.php', import.meta.url), 'utf8');
const internalEndpoint = await readFile(new URL('../api/production-readiness-evidence.php', import.meta.url), 'utf8');
const workflow = await readFile(new URL('../.github/workflows/production-readiness-evidence.yml', import.meta.url), 'utf8');
const quality = await readFile(new URL('../.github/workflows/quality.yml', import.meta.url), 'utf8');
const pilot = await readFile(new URL('../docs/production-readiness/PILOT-C.md', import.meta.url), 'utf8');
const communicationReview = await readFile(new URL('../docs/production-readiness/COMMUNICATION-DELIVERY-REVIEW-20260905.md', import.meta.url), 'utf8');

for (const fragment of [
  "PHP_SAPI !== 'cli'",
  "HACHE_PR_INTERNAL_HTTP",
  "'/config/database.local.php'",
  "'pagos'",
  "'sharky_outbox'",
  "'latest_sent_day_stored'",
  "'sent_at_semantics'",
  "'provider_delivery_status' => $providerEvidenceState",
  "'field' => 'NOT EVALUATED'",
  "'restore' => 'PARTIAL'",
  "'communication_delivery' => 'PARTIAL'",
  "'contains_personal_rows' => false",
  "'contains_message_payloads' => false",
  "'contains_contact_identifiers' => false",
  "'contains_credentials' => false",
  'safe.directory=%s',
  'rev-parse HEAD',
]) {
  assert.ok(collector.includes(fragment), `missing collector guard: ${fragment}`);
}

for (const fragment of [
  "'EVIDENCE AVAILABLE — HUMAN REVIEW REQUIRED'",
  "'NOT EVALUATED'",
  "deliverySummary['correlated_total'] > 0",
]) {
  assert.ok(collector.includes(fragment), `missing communication evidence boundary: ${fragment}`);
}

assert.ok(!collector.includes("'communication_delivery' => 'PASS'"), 'collector must never auto-PASS communication delivery');
assert.ok(!collector.includes('git config --global --add safe.directory'), 'collector must not mutate global Git configuration');

for (const forbidden of [
  'latest_sent_day_utc',
  'SELECT * FROM alumnos',
  'payload_ciphertext',
  'payload_iv',
  'payload_tag',
  'contact_hash',
  'WHATSAPP_ACCESS_TOKEN',
  'HACHE_RESEND_API_KEY',
]) {
  assert.ok(!collector.includes(forbidden), `collector must not expose sensitive or misleading field: ${forbidden}`);
}

for (const fragment of [
  "$_SERVER['REMOTE_ADDR']",
  "['127.0.0.1', '::1']",
  "$_SERVER['REQUEST_METHOD']",
  "'POST'",
  "$_SERVER['HTTP_X_HACHE_EVIDENCE_RUN_ID']",
  "/^[0-9]{1,20}$/",
  "'/tmp/hache-pr-evidence-token'",
  "'/tmp/hache-pr-evidence-token-' . $runId",
  "$_SERVER['HTTP_X_HACHE_EVIDENCE_TOKEN']",
  "time() - 120",
  "/^[a-f0-9]{64}$/",
  "hash_equals($expected, $provided)",
  "define('HACHE_PR_INTERNAL_HTTP', true)",
  "'/bin/production-readiness-evidence.php'",
  'Cache-Control: no-store, max-age=0',
  'X-Robots-Tag: noindex, nofollow',
]) {
  assert.ok(internalEndpoint.includes(fragment), `missing internal evidence endpoint guard: ${fragment}`);
}
assert.ok(!internalEndpoint.includes('HTTP_X_FORWARDED_FOR'), 'internal endpoint must not trust forwarded-for');
assert.ok(!internalEndpoint.includes('HTTP_CF_CONNECTING_IP'), 'internal endpoint must not trust Cloudflare client headers');
assert.ok(!internalEndpoint.includes('database.local.php'), 'internal endpoint must delegate config handling to collector');
assert.ok(!internalEndpoint.includes('production-readiness-evidence-v1'), 'endpoint must not use a repository-static authorization token');

assert.match(workflow, /workflow_dispatch:/);
assert.doesNotMatch(workflow, /^\s{0,4}(push|schedule):/m, 'evidence workflow must remain manual-only');
for (const fragment of [
  'production_snapshot',
  'restore_lab',
  'openssl rand -hex 32',
  '/tmp/hache-pr-evidence-token',
  'chmod 644 /tmp/hache-pr-evidence-token',
  'cleanup_token()',
  'trap cleanup_token EXIT',
  'rm -f /tmp/hache-pr-evidence-token',
  'X-Hache-Evidence-Token: $token',
  '--resolve hnatacion.com:443:127.0.0.1',
  'https://hnatacion.com/api/production-readiness-evidence.php',
  'Verify evidence endpoint does not expose evidence externally',
  '[[ "$code" =~ ^4[0-9]{2}$ ]]',
  'EXPECTED_SHA: ${{ github.sha }}',
  'hash_equals($expected,$deployed)',
  'workflow-context.json',
  '"workflow_sha": "$GITHUB_SHA"',
  'https://hnatacion.com/',
  'mariadb:11.8',
  'hache_pr_restore_source',
  'hache_pr_restore_target',
  'HACHE_PR_RESTORE_MARKER_V1',
  'mariadb-dump',
  'rm -f evidence/database.sql',
  'production_backup_used',
  'RESTORE_REMAINS_PARTIAL',
  'actions/upload-artifact@v4',
]) {
  assert.ok(workflow.includes(fragment), `missing evidence workflow contract: ${fragment}`);
}

assert.ok(!workflow.includes('X-Hache-Ops: production-readiness-evidence-v1'), 'workflow must not use the old static evidence guard');
assert.ok(workflow.indexOf('rm -f evidence/database.sql') < workflow.lastIndexOf('Upload restore evidence'), 'raw SQL dump must be removed before artifact upload');
assert.ok(workflow.includes('path: evidence/restore-lab.json'), 'restore artifact must contain only the minimized report');
assert.ok(!workflow.includes('cancel-in-progress: true'), 'evidence runs must not cancel one another');
assert.ok(quality.includes('node tests/production-readiness-pilot-regression.mjs'), 'pilot regression must stay connected to Quality CI');

for (const fragment of [
  'Nivel: C — Crítico',
  'CUF-C-01',
  'CUF-C-02',
  'CUF-C-03',
  '| Campo | `NOT EVALUATED` |',
  '| Restore | `PARTIAL` |',
  '| Communication status | `PASS` |',
  'COMMUNICATION-DELIVERY-REVIEW-20260905.md',
  'no ejecutar restore sobre la DB de producción',
  'read-only',
]) {
  assert.ok(pilot.includes(fragment), `missing pilot documentation boundary: ${fragment}`);
}

for (const fragment of [
  '**Gate: Communication status — PASS.**',
  '33945691437',
  '35c305c0c92bf12915612a72b4a563744a0d09b1',
  '`correlated_total = 36`',
  '`DELIVERED = 10`',
  '`READ = 26`',
  '`FAILED = 0`',
  '9963245317',
  'aprobó explícitamente el snapshot',
  'Este PASS no convierte los gates de Field ni Restore en PASS',
]) {
  assert.ok(communicationReview.includes(fragment), `missing reviewed communication evidence: ${fragment}`);
}

assert.ok(!communicationReview.includes('contains_contact_identifiers = true'), 'review must not claim contact identifiers are present');
assert.ok(!communicationReview.includes('contains_credentials = true'), 'review must not claim credentials are present');

console.log('PRODUCTION_READINESS_PILOT_REGRESSION_OK');
