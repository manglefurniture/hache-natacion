import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const client = await readFile(new URL('../public/assets/field-rum.js', import.meta.url), 'utf8');
const endpoint = await readFile(new URL('../api/rum-web-vitals.php', import.meta.url), 'utf8');
const buildEndpoint = await readFile(new URL('../api/rum-build.php', import.meta.url), 'utf8');
const buildConfig = await readFile(new URL('../config/production-rum.php', import.meta.url), 'utf8');
const migration = await readFile(new URL('../database/migrations/20260905_production_rum.sql', import.meta.url), 'utf8');
const collector = await readFile(new URL('../bin/production-readiness-evidence.php', import.meta.url), 'utf8');
const hachi = await readFile(new URL('../public/assets/hachi.js', import.meta.url), 'utf8');
const docs = await readFile(new URL('../docs/production-readiness/FIELD-RUM.md', import.meta.url), 'utf8');

for (const fragment of [
  "const endpoint = '/api/rum-web-vitals.php'",
  "fetch('/api/rum-build.php'",
  "/^git-[a-f0-9]{12}$/.test(buildId)",
  "credentials: 'omit'",
  "referrerPolicy: 'no-referrer'",
  "cache: 'no-store'",
  "redirect: 'error'",
  'keepalive: true',
  "metric,\n        value,\n        route_group: routeGroup,\n        build_id: buildId,\n        form_factor: formFactor",
  "durationThreshold: 40",
  "new PerformanceObserver",
  "type: 'largest-contentful-paint'",
  "type: 'layout-shift'",
  "type: 'event'",
  "type: 'first-input'",
  "Math.floor(interactionCountEstimate() / 50)",
]) {
  assert.ok(client.includes(fragment), `missing RUM client contract: ${fragment}`);
}

for (const forbidden of [
  'localStorage',
  'sessionStorage',
  'document.cookie',
  'location.href',
  'location.pathname',
  'document.referrer',
  'navigator.userAgent',
  'sendBeacon',
]) {
  assert.ok(!client.includes(forbidden), `RUM client must not collect or transport identifier/context: ${forbidden}`);
}

for (const fragment of [
  "['schema_version', 'metric', 'value', 'route_group', 'build_id', 'form_factor']",
  "['LCP', 'INP', 'CLS']",
  "['home', 'registration', 'admin_payments']",
  "/^git-[a-f0-9]{12}$/",
  "hache_rum_deployed_build_id(dirname(__DIR__))",
  "hash_equals($authoritativeBuildId, $buildId)",
  "rum_out(409, ['ok' => false])",
  "['mobile', 'desktop']",
  "strlen($raw) > 1024",
  "created_at_utc >= UTC_TIMESTAMP(6) - INTERVAL 1 MINUTE",
  "DELETE FROM production_rum_samples WHERE created_at_utc < UTC_TIMESTAMP(6) - INTERVAL 35 DAY LIMIT 250",
  "sprintf('%.8F', $value)",
  "':build_id' => $authoritativeBuildId",
  "error_log('Hache RUM collector unavailable: ' . get_class($e))",
]) {
  assert.ok(endpoint.includes(fragment), `missing RUM server guard: ${fragment}`);
}

for (const forbidden of [
  '$_COOKIE',
  'session_start(',
  'HTTP_USER_AGENT',
  'HTTP_REFERER',
  'REMOTE_ADDR',
  'X_FORWARDED_FOR',
  'CF_CONNECTING_IP',
  'error_log($raw',
]) {
  assert.ok(!endpoint.includes(forbidden), `RUM endpoint must not retain client identity/context: ${forbidden}`);
}

for (const fragment of [
  "$_SERVER['REQUEST_METHOD']",
  "'GET'",
  "hache_rum_deployed_build_id(dirname(__DIR__))",
  "'build_id' => $buildId",
  'Cache-Control: no-store, max-age=0',
]) {
  assert.ok(buildEndpoint.includes(fragment), `missing RUM build endpoint boundary: ${fragment}`);
}
for (const forbidden of ['$_COOKIE', 'session_start(', 'REMOTE_ADDR', 'HTTP_USER_AGENT']) {
  assert.ok(!buildEndpoint.includes(forbidden), `RUM build endpoint must not inspect client identity: ${forbidden}`);
}

for (const fragment of [
  "function hache_rum_deployed_build_id",
  "'/HEAD'",
  "'/packed-refs'",
  "preg_match('/^[a-f0-9]{40}$/', $sha)",
  "return 'git-' . substr($sha, 0, 12)",
]) {
  assert.ok(buildConfig.includes(fragment), `missing authoritative RUM build derivation: ${fragment}`);
}
assert.ok(!buildConfig.includes('shell_exec'), 'RUM build lookup must not spawn Git per page');

for (const fragment of [
  'metric ENUM',
  'value DECIMAL(20,8) UNSIGNED NOT NULL',
  'route_group VARCHAR(64) NOT NULL',
  'build_id VARCHAR(64) NOT NULL',
  "form_factor ENUM('mobile','desktop') NOT NULL",
  'created_at_utc DATETIME(6) NOT NULL',
]) {
  assert.ok(migration.includes(fragment), `missing minimized RUM schema field: ${fragment}`);
}

for (const forbiddenColumn of [
  'ip_address',
  'user_agent',
  'referrer',
  'url ',
  'pathname',
  'session_id',
  'account_id',
  'alumno_id',
  'email',
  'telefono',
  'phone',
  'contact_hash',
  'payload',
]) {
  const createTableBody = migration.slice(migration.indexOf('CREATE TABLE'));
  assert.ok(!createTableBody.toLowerCase().includes(forbiddenColumn), `RUM schema contains forbidden client field: ${forbiddenColumn}`);
}

for (const fragment of [
  'function pr_nearest_rank_p75',
  'function pr_rum_summary',
  "'LCP' => 2500.0",
  "'INP' => 200.0",
  "'CLS' => 0.1",
  '$windowDays = 14',
  '$projectSampleFloor = 20',
  "'percentile_method' => 'nearest-rank'",
  "'production_readiness_state' => 'NOT EVALUATED'",
  "'decision' => 'HUMAN_REVIEW_REQUIRED'",
  "'field' => [",
  "'rum' => pr_rum_summary($pdo)",
  "'field' => 'NOT EVALUATED'",
]) {
  assert.ok(collector.includes(fragment), `missing RUM evidence boundary: ${fragment}`);
}
assert.ok(!collector.includes("'field' => 'PASS'"), 'collector must never auto-PASS Field');

for (const fragment of [
  "script.src = '/assets/field-rum.js?v=20260905-1'",
  "script.dataset.routeGroup = 'home'",
  "script.dataset.sampleRate = '1'",
]) {
  assert.ok(hachi.includes(fragment), `missing initial home RUM bootstrap: ${fragment}`);
}
assert.ok(!hachi.includes("script.dataset.buildId"), 'home bootstrap must not pin a stale build id');

for (const fragment of [
  'El gate continúa **`NOT EVALUATED`**',
  'primera ruta activada es `home`',
  '`git-<12 hex>`',
  'no se mezclan releases bajo una etiqueta fija',
  'no cuentan como cubiertas',
  'Retención del piloto: máximo **35 días**',
  'ventana móvil de **14 días**',
  'piso operativo de **20 muestras por grupo**',
  'inicia recolección; no cierra el gate',
]) {
  assert.ok(docs.includes(fragment), `missing RUM pilot documentation boundary: ${fragment}`);
}

console.log('PRODUCTION_READINESS_RUM_REGRESSION_OK');
