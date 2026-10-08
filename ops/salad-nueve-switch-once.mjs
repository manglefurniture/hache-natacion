import { run } from './salad-gpu-switch.mjs';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';
const name = 'prl-low-4070-experimental-nueve';
const gpuBefore = 'RTX 4070 Ti Super (16 GB)';
const gpuAfter = 'RTX 4090 (24 GB)';
const api = 'https://api.salad.com/api/public/organizations/hache/projects/prl-tests/containers';

export function findGroup(response) {
  const groups = Array.isArray(response?.items) ? response.items : response?.container_groups;
  const matches = (groups || []).filter(g => g.display_name === name || g.name === name);
  if (matches.length !== 1) throw new Error('Cannot uniquely resolve NUEVE group');
  const g = matches[0];
  if (!/^prl-low-[a-z0-9-]+$/.test(g.name) || g.priority !== 'low' ||
      g.replicas !== 1 || g.pending_change !== false) {
    throw new Error('NUEVE group configuration is not safe to change');
  }
  return g.name;
}
async function main() {
  const key = process.env.SALAD_API_KEY;
  if (!key) throw new Error('Secret unavailable');
  const response = await fetch(api, {headers:{'Salad-Api-Key':key,Accept:'application/json'}});
  if (!response.ok) throw new Error('NUEVE listing HTTP '+response.status);
  const slug = findGroup(await response.json());
  console.log('Verified NUEVE group slug:',slug);
  await run({group:slug,expected:gpuBefore,target:gpuAfter,mode:'apply',confirm:slug});
}
if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url))
  main().catch(e=>{console.error('NUEVE_SWITCH_FAILED:',e.message);process.exitCode=1;});
