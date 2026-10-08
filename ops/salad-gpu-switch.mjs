// Explicit manual GPU experiments on existing one-replica Salad Low PRL groups.
// Never print credentials or raw Salad API responses.
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';
export const ORG = 'hache', PROJECT = 'prl-tests';
const API = 'https://api.salad.com/api/public';
const classPath = '/organizations/' + ORG + '/gpu-classes';
const sleep = ms => new Promise(done => setTimeout(done, ms));
const trim = s => String(s ?? '').trim();
const same = (a,b) => trim(a).toLowerCase() === trim(b).toLowerCase();

export function validate(input) {
  const o = {group: trim(input.group), expected: trim(input.expected), target: trim(input.target),
    mode: trim(input.mode || 'plan'), confirm: trim(input.confirm)};
  if (!/^prl-low-[a-z0-9][a-z0-9-]{1,75}$/.test(o.group))
    throw new Error('Only explicit prl-low-* groups are allowed');
  if (!o.expected || !o.target || o.expected.length > 120 || o.target.length > 120)
    throw new Error('Expected and target GPU names are required');
  if (!['plan','apply'].includes(o.mode)) throw new Error('Invalid mode');
  if (o.mode === 'apply' && o.confirm !== o.group)
    throw new Error('Apply requires exact confirmation of the group name');
  return o;
}
export function planSwitch(group, catalog, input) {
  const o=validate(input);
  if (group?.name !== o.group) throw new Error('Wrong group in Salad response');
  if (group?.replicas !== 1) throw new Error('Expected exactly one replica');
  if (group?.pending_change !== false) throw new Error('Group has pending/unknown changes');
  if (!same(group?.priority,'low')) throw new Error('Group is not Low');
  if (!Array.isArray(catalog?.items)) throw new Error('GPU catalog unavailable');
  const ids=group?.container?.resources?.gpu_classes;
  if (!Array.isArray(ids) || ids.length !== 1) throw new Error('Expected exactly one configured GPU');
  const old=catalog.items.filter(g=>g.id === ids[0]);
  const goal=catalog.items.filter(g=>same(g.name,o.target));
  if (old.length !== 1 || goal.length !== 1) throw new Error('GPU model not uniquely verifiable');
  if (!same(old[0].name,o.expected)) throw new Error('Original GPU mismatch');
  if (!/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i.test(String(goal[0].id)))
    throw new Error('Invalid GPU class id');
  return {already:old[0].id===goal[0].id, oldClass:old[0].name, newClass:goal[0].name,
    oldId:old[0].id,newId:goal[0].id,
    patch:{container:{resources:{gpu_classes:[goal[0].id]}}}};
}
export function assertSameNonGpu(original, result) {
  if (result?.name !== original.name || result?.replicas !== original.replicas ||
      !same(result?.priority,original.priority)) throw new Error('Other group settings changed');
  const before=structuredClone(original.container),after=structuredClone(result.container);
  if (!before?.resources || !after?.resources) throw new Error('Container settings missing');
  delete before.resources.gpu_classes; delete after.resources.gpu_classes;
  if (JSON.stringify(before)!==JSON.stringify(after))
    throw new Error('Non-GPU settings changed unexpectedly');
}
export function verifyApplied(original, result, plan) {
  assertSameNonGpu(original, result);
  const cls=result?.container?.resources?.gpu_classes;
  return Array.isArray(cls)&&cls.length===1&&cls[0]===plan.newId&&result.pending_change===false;
}
async function request(key,method,path,payload) {
  const h={'Salad-Api-Key':key,Accept:'application/json'};
  if(payload!==undefined) h['Content-Type']='application/merge-patch+json';
  const r=await fetch(API+path,{method,headers:h,
    body:payload===undefined?undefined:JSON.stringify(payload),
    signal:AbortSignal.timeout(25000)});
  if(!r.ok) throw new Error('Salad HTTP '+r.status+' '+method+' (response withheld)');
  return r.json();
}
export async function run(input,call=request,delay=sleep) {
  const o=validate(input),key=process.env.SALAD_API_KEY;
  if(!key) throw new Error('Salad API key not configured');
  const path='/organizations/'+ORG+'/projects/'+PROJECT+'/containers/'+encodeURIComponent(o.group);
  const original=await call(key,'GET',path);
  const classes=await call(key,'GET',classPath);
  const p=planSwitch(original,classes,o);
  console.log('GRUPO:',o.group,'| Low | replicas: 1');
  console.log('GPU actual:',p.oldClass,'| objetivo:',p.newClass);
  console.log('Para revertir: actual esperada =',p.newClass,'; destino =',p.oldClass);
  if(p.already){ console.log('SIN CAMBIO: GPU ya configurada');return 'unchanged';}
  if(o.mode==='plan'){console.log('PREVISUALIZACION: sin cambios en Salad');return 'preview';}
  const fresh=await call(key,'GET',path);
  const check=planSwitch(fresh,classes,o);
  assertSameNonGpu(original, fresh);
  if(check.already||check.oldId!==p.oldId||check.newId!==p.newId)
    throw new Error('The group changed after preflight');
  const response=await call(key,'PATCH',path,p.patch);
  if(response?.name!==o.group) throw new Error('Unexpected PATCH result; verify before retry');
  console.log('PATCH aceptado; comprobando configuracion aplicada');
  for(let i=0;i<60;i++){
    const observed=await call(key,'GET',path);
    if(verifyApplied(fresh,observed,p)){
      console.log('GPU_CONFIGURADA:',p.newClass,'| estado:',observed.current_state?.status??'unknown',
        '| pending: false | NO confirma GPU lista ni hashrate');
      return 'applied';
    }
    if(i<59)await delay(5000);
  }
  throw new Error('Change remains pending; check Salad before retrying');
}
if(process.argv[1]&&resolve(process.argv[1])===fileURLToPath(import.meta.url))
  run({group:process.env.SALAD_GPU_GROUP,expected:process.env.SALAD_GPU_EXPECTED,
    target:process.env.SALAD_GPU_TARGET,mode:process.env.SALAD_GPU_MODE,
    confirm:process.env.SALAD_GPU_CONFIRM})
    .catch(e=>{console.error('GPU_EXPERIMENT_FAILED:',e.message);process.exitCode=1;});
