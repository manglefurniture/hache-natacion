import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const workflow = await readFile(new URL('../.github/workflows/ops-field-evidence-once.yml', import.meta.url), 'utf8');
const manualEvidenceWorkflow = await readFile(new URL('../.github/workflows/production-readiness-evidence.yml', import.meta.url), 'utf8');
const internalEndpoint = await readFile(new URL('../api/production-readiness-evidence.php', import.meta.url), 'utf8');
const home = await readFile(new URL('../public/home.php', import.meta.url), 'utf8');

for (const fragment of [
  'workflows: ["Deploy"]',
  "github.event.workflow_run.conclusion == 'success'",
  "github.event.workflow_run.head_branch == 'main'",
  'EXPECTED_SHA: ${{ github.event.workflow_run.head_sha }}',
  'Verify deployed RUM bootstrap chain at origin',
  'bash -s -- "$EXPECTED_SHA"',
  'curl_origin=(curl --silent --show-error --fail --resolve hnatacion.com:443:127.0.0.1',
  'https://hnatacion.com/',
  "https://hnatacion.com/assets/hachi.js?v=20260905-rum1",
  "https://hnatacion.com/assets/field-rum.js?v=20260905-1",
  'https://hnatacion.com/api/rum-build.php',
  "script.src = '/assets/field-rum.js?v=20260905-1'",
  "script.dataset.routeGroup = 'home'",
  "script.dataset.sampleRate = '1'",
  "const endpoint = '/api/rum-web-vitals.php'",
  "fetch('/api/rum-build.php'",
  'expected_build="git-${expected_sha:0:12}"',
  'origin RUM build id does not match deployed workflow SHA',
  'openssl rand -hex 32',
  'token_path="/tmp/hache-pr-evidence-token-${GITHUB_RUN_ID}"',
  "X-Hache-Evidence-Run-Id: $GITHUB_RUN_ID",
  "X-Hache-Evidence-Token: $token",
  "rm -f '$token_path'",
  "--resolve hnatacion.com:443:127.0.0.1",
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

assert.ok(
  workflow.includes('group: hache-natacion-ops-field-evidence-once'),
  'automatic field evidence must keep its own concurrency group instead of sharing the manual snapshot group',
);
assert.ok(
  !workflow.includes('group: hache-natacion-production-readiness-production_snapshot'),
  'automatic evidence must not share GitHub concurrency with manually requested snapshots because pending runs can be replaced',
);
assert.ok(
  manualEvidenceWorkflow.includes('group: hache-natacion-production-readiness-${{ github.event.inputs.mode }}'),
  'manual evidence workflow must keep the mode-derived production readiness concurrency contract',
);
assert.ok(
  internalEndpoint.includes("$_SERVER['HTTP_X_HACHE_EVIDENCE_RUN_ID']") &&
    internalEndpoint.includes("'/tmp/hache-pr-evidence-token-' . $runId"),
  'internal evidence endpoint must support a strictly scoped per-run automatic token path',
);
assert.ok(
  internalEndpoint.includes("/^[0-9]{1,20}$/"),
  'automatic evidence run ids must be validated before deriving a token path',
);
assert.ok(
  manualEvidenceWorkflow.includes('/tmp/hache-pr-evidence-token'),
  'manual evidence keeps the legacy fixed token path behind its own manual concurrency contract',
);

assert.ok(!workflow.includes('Verify public RUM bootstrap chain'), 'field evidence must not depend on GitHub-hosted runner access through public WAF');
assert.ok(!workflow.includes('path: evidence/\n'), 'workflow must never upload the whole evidence directory');
assert.ok(!workflow.includes('path: evidence/raw-production-snapshot.json'), 'raw production snapshot must never be uploaded');
assert.ok(workflow.indexOf('rm -f evidence/raw-production-snapshot.json') < workflow.indexOf('Upload minimized field evidence'), 'raw snapshot must be removed before artifact upload');
assert.ok(!workflow.includes("--request POST https://hnatacion.com/api/rum-web-vitals.php"), 'bootstrap verification must never inject synthetic RUM samples');

assert.ok(
  home.includes('<script src="/assets/hachi.js?v=20260905-rum1" defer></script>'),
  'home must cache-bust the hachi bootstrap version that loads production RUM',
);
assert.ok(
  !home.includes('<script src="/assets/hachi.js?v=20260822-seo1" defer></script>'),
  'home must not retain the pre-RUM hachi asset URL',
);

console.log('ops field evidence one-shot regression ok');
