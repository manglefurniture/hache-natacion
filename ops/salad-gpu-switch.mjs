// One-time Salad GPU switch, intentionally restricted to the named experimental group.
// Never print the Salad API key or full API responses (which may contain workload secrets).
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';

export const ORG = 'hache';
export const PROJECT = 'prl-tests';
export const GROUP = 'prl-low-4070-experimental';
const API = 'https://api.salad.com/api/public';
const groupPath = '/organizations/' + ORG + '/projects/' + PROJECT + '/containers/' + GROUP;
const classPath = '/organizations/' + ORG + '/gpu-classes';

export function planSwitch(group, gpuClasses) {
  if (group?.name !== GROUP) throw new Error('Different container group: abort');
  if (group?.replicas !== 1) throw new Error('Expected one replica: abort');
  if (group?.pending_change === true) throw new Error('Pending config change: abort');
  if (String(group?.container?.priority ?? '').toLowerCase() !== 'low')
    throw new Error('Priority is not Low or cannot be verified: abort');
  if (!Array.isArray(gpuClasses?.items)) throw new Error('Cannot verify Salad GPU class catalog');
  const matches = gpuClasses.items.filter(g =>
    /^RTX 4090(?: \(\s*24\s*GB\s*\))?$/i.test(String(g.name ?? '')));
  if (matches.length !== 1 || !/^[0-9a-f]{8}-[0-9a-f-]{27,}$/i.test(String(matches[0].id)))
    throw new Error('Desktop RTX 4090 class not uniquely verified: abort');
  const old = group?.container?.resources?.gpu_classes;
  if (!Array.isArray(old) || old.length !== 1) throw new Error('Expected exactly one existing GPU class: abort');
  const gpu = matches[0];
  if (old[0] === gpu.id) return { already: true, oldClass: gpu.name, newClass: gpu.name };
  const prev = gpuClasses.items.find(g => g.id === old[0]);
  if (!prev || !/RTX 4070/i.test(String(prev.name ?? '')))
    throw new Error('Original GPU is not a verified RTX 4070 class: abort');
  return {
    already: false,
    oldClass: prev.name,
    newClass: gpu.name,
    oldIds: old,
    newId: gpu.id,
    patch: { container: { resources: { gpu_classes: [gpu.id] } } },
  };
}

async function request(key, method, path, payload) {
  const headers = { 'Salad-Api-Key': key, Accept: 'application/json' };
  if (payload !== undefined) headers['Content-Type'] = 'application/merge-patch+json';
  const response = await fetch(API + path, {
    method,
    headers,
    body: payload === undefined ? undefined : JSON.stringify(payload),
    signal: AbortSignal.timeout(25000),
  });
  if (!response.ok) throw new Error('Salad HTTP ' + response.status + ' on ' + method + ' (response suppressed)');
  return response.json();
}
const sleep = ms => new Promise(done => setTimeout(done, ms));

export async function run() {
  const key = process.env.SALAD_API_KEY;
  if (!key) throw new Error('SALAD_API_KEY secret is missing; no changes made');
  const original = await request(key, 'GET', groupPath);
  const classes = await request(key, 'GET', classPath);
  const plan = planSwitch(original, classes);
  console.log('Target:', ORG + '/' + PROJECT + '/' + GROUP);
  console.log('Before:', plan.oldClass, '| priority:', original.container.priority,
    '| replicas:', original.replicas, '| state:', original.current_state?.status ?? 'unknown');
  if (plan.already) {
    console.log('ALREADY_CONFIGURED: RTX 4090 desktop; no PATCH issued');
    return;
  }
  console.log('Requested:', plan.newClass);
  const modified = await request(key, 'PATCH', groupPath, plan.patch);
  if (modified?.name !== GROUP)
    throw new Error('PATCH responded with wrong group; do not perform further writes');
  for (let i = 0; i < 9; i++) {
    const observed = await request(key, 'GET', groupPath);
    const newClasses = observed?.container?.resources?.gpu_classes;
    if (Array.isArray(newClasses) && newClasses.length === 1 && newClasses[0] === plan.newId) {
      if (observed.replicas !== original.replicas ||
          String(observed.container.priority).toLowerCase() !== 'low' ||
          observed.container.image !== original.container.image)
        throw new Error('Post-check failed: non-GPU configuration unexpectedly differs');
      console.log('GPU_SWITCH_CONFIRMED:', plan.newClass, '| group:', observed.name,
        '| state:', observed.current_state?.status ?? 'unknown',
        '| pending:', Boolean(observed.pending_change));
      return;
    }
    await sleep(5000);
  }
  throw new Error('PATCH accepted, but GPU change not yet visible in GET; inspect Salad before retrying');
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url))
  run().catch(error => { console.error('GPU_SWITCH_FAILED:', error.message); process.exitCode = 1; });
