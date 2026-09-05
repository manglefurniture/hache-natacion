import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const workflow = await readFile(new URL('../.github/workflows/ops-field-evidence-once.yml', import.meta.url), 'utf8');
const home = await readFile(new URL('../public/home.php', import.meta.url), 'utf8');

for (const fragment of [
  'workflows: ["Deploy"]',
  "github.event.workflow_run.conclusion == 'success'",
  "github.event.workflow_run.head_branch == 'main'",
  'EXPECTED_SHA: ${{ github.event.workflow_run.head_sha }}',
  'openssl rand -hex 32',
  '/tmp/hache-pr-evidence-token',
  "--resolve hnatacion.com:443:127.0.0.1",
  "X-Hache-Evidence-Token: $token",
  'rm -f /tmp/hache-pr-evidence-token',
  'production SHA does not match deployed workflow SHA',
  'contains_personal_rows',
  'contains_message_payloads',
  'contains_contact_identifiers',
  'contains_credentials',
  'production_rum_field_snapshot',
  'field_rum',
  'HUMAN_REVIEW_REQUIRED',
  'rm -f evidence/raw-production-snapshot.json',
  'Verify internal evidence endpoint remains blocked externally',
  'path: evidence/field-evidence.json',
]) {
  assert.ok(workflow.includes(fragment), `missing one-shot field evidence guard: ${fragment}`);
}

assert.ok(!workflow.includes('path: evidence/\n'), 'workflow must never upload the whole evidence directory');
assert.ok(!workflow.includes('path: evidence/raw-production-snapshot.json'), 'raw production snapshot must never be uploaded');
assert.ok(workflow.indexOf('rm -f evidence/raw-production-snapshot.json') < workflow.indexOf('Upload minimized field evidence'), 'raw snapshot must be removed before artifact upload');

assert.ok(
  home.includes('<script src="/assets/hachi.js?v=20260905-rum1" defer></script>'),
  'home must cache-bust the hachi bootstrap version that loads production RUM',
);
assert.ok(
  !home.includes('<script src="/assets/hachi.js?v=20260822-seo1" defer></script>'),
  'home must not retain the pre-RUM hachi asset URL',
);

console.log('ops field evidence one-shot regression ok');
