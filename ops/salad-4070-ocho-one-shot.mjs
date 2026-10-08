// One-time, explicitly approved change: hache/prl-tests/prl-low-4070-ocho -> desktop RTX 4090.
// Only the existing reusable GPU-only code makes the PATCH.
import {fileURLToPath} from 'node:url';
import {resolve} from 'node:path';
import {run} from './salad-gpu-switch.mjs';
const NAME='prl-low-4070-ocho';
const ROOT='https://api.salad.com/api/public/organizations/hache';
const path=ROOT+'/projects/prl-tests/containers/'+NAME;

export function identifySource(group,catalog) {
  if(group?.name!==NAME)throw new Error('Wrong group; abort');
  if(group?.replicas!==1 || String(group?.priority).toLowerCase()!=='low'
    || group?.pending_change!==false)throw new Error('Must be one Low replica with no pending change');
  const ids=group?.container?.resources?.gpu_classes;
  if(!Array.isArray(ids)||ids.length!==1)throw new Error('Expected exactly one current GPU class');
  const original=(catalog?.items??[]).filter(c=>c.id===ids[0]);
  if(original.length!==1)throw new Error('Original GPU class unknown');
  const name=String(original[0].name??'');
  if(name==='RTX 4090 (24 GB)')return {name,already:true};
  if(!/^RTX 4070(?: Ti)?(?: Super)? \(\d+ GB\)$/i.test(name))
    throw new Error('Not an expected RTX 4070 desktop group; abort');
  return {name,already:false};
}
async function get(url,key) {
  const r=await fetch(url,{headers:{Accept:'application/json','Salad-Api-Key':key},
    signal:AbortSignal.timeout(25000)});
  if(!r.ok)throw new Error('Salad GET failed HTTP '+r.status+' (response suppressed)');
  return r.json();
}
export async function main() {
  const key=process.env.SALAD_API_KEY;
  if(!key)throw new Error('Secret not configured');
  const group=await get(path,key),catalog=await get(ROOT+'/gpu-classes',key);
  const source=identifySource(group,catalog);
  console.log('Grupo verificado:',NAME,'| clase actual:',source.name,'| prioridad Low | 1 replica');
  if(source.already){console.log('GPU_ALREADY_4090: no change sent');return;}
  await run({group:NAME,expected:source.name,target:'RTX 4090 (24 GB)',mode:'apply',confirm:NAME});
}
if(process.argv[1]&&resolve(process.argv[1])===fileURLToPath(import.meta.url))
  main().catch(e=>{console.error('ONE_SHOT_GPU_FAILED:',e.message);process.exitCode=1;});
